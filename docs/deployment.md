# Deployment guide

Dhiran is a Laravel 12 app (PHP 8.4, PostgreSQL 16). Everything below was checked against the code in this repository. Items the code does not provide are marked `GAP:`.

## 1. Local scripts versus production

`scripts/dhiran`, `scripts/test`, `scripts/setup-local` and `scripts/verify.php` are dev-only. They hard-code the workspace path `/home/himanshu/Desktop/Dhiran ERP`, a private PostgreSQL cluster (socket under `.runtime/`, port 55439) and a `.runtime/targets.json` manifest. Do not use them on a server. In production run plain `php artisan ...`.

`RuntimeGuard::verify()` runs on every app boot (`AppServiceProvider::boot`). With `APP_ENV=local|testing` it enforces the workspace/socket checks above. With `APP_ENV=production` it runs only `verifyProduction` (section 5); the connection-identity check (`verifyConnection`) runs only for local/testing. Any `APP_ENV` other than `local`, `testing`, `production` is refused.

## 2. Server packages

- PHP 8.4 with FPM and CLI. Required by `composer.json`: `bcmath`, `pdo`, `pdo_pgsql`. Required transitively (composer.lock): `ctype`, `filter`, `hash`, `mbstring`, `openssl`, `session`, `tokenizer`, and for `phpoffice/phpspreadsheet`: `dom`, `fileinfo`, `gd`, `iconv`, `libxml`, `simplexml`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `zlib`. Also `json`. Customer photo upload (`CustomerProfilePhotoEncoder`) calls `imagewebp()` and `exif_read_data()`, so build GD with WebP support and install `exif`; without them the encoder takes its fallback branch.
- PostgreSQL 16.
- nginx, Composer 2, git (or any way to ship the code).
- Node is not needed on the server: `public/build/` (Vite output) is committed to git. Node (`npm ci && npm run build`) is only needed if you change `resources/` CSS/JS; commit the rebuilt `public/build/`. The `assets:verify`/`build:verify` scripts were removed (their artisan command never existed); use `npm run build`.

## 3. nginx + PHP-FPM

```nginx
server {
    listen 80;
    server_name dhiran.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name dhiran.example.com;
    root /var/www/dhiran/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/dhiran.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/dhiran.example.com/privkey.pem;

    client_max_body_size 32m;   # customer photos / attachments (see the note below)

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param HTTPS on;               # Laravel must see https (session.secure, URLs)
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\.(?!well-known).* { deny all; }   # .env, .git, ...
}
```

### Compression and caching (nginx)

Measured read-only on the live site (2026-11-26): HTML is gzipped (4,780 B to 2,023 B) but `/build/assets/*.js` (378,542 B) and `*.css` (87,082 B for `dhiran-*.css`) are sent uncompressed, with no `Cache-Control`/`Expires`, so every visit revalidates or re-downloads them. nginx's default `gzip_types` is only `text/html`. The app does not serve `/build/*` (nginx serves the files straight from `public/`), so this is nginx config. Put the `gzip` lines in the `http {}` block (or the server block) and the `location` blocks in the server block, before `location ~ \.php$`:

```nginx
# http {} or server {}
gzip on;
gzip_vary on;
gzip_comp_level 5;
gzip_min_length 1024;
gzip_proxied any;
gzip_types text/css application/javascript text/javascript application/json image/svg+xml text/plain;

# server {}
# Vite output is content-hashed: a changed file gets a new name, so cache forever.
location /build/assets/ {
    access_log off;
    add_header Cache-Control "public, max-age=31536000, immutable";
    try_files $uri =404;
}
# manifest is read by PHP, not browsers; never cache it.
location = /build/manifest.json { add_header Cache-Control "no-cache"; }
```

Notes:
- `add_header` in a `location` replaces headers inherited from the server block; the app's security headers (X-Frame-Options, HSTS, ...) are added by PHP and are not affected, but headers you set with `add_header` at server level are lost inside these two locations. Repeat them there if you have any.
- Brotli is optional (needs `libnginx-mod-http-brotli-filter` or a build with ngx_brotli; `brotli on; brotli_comp_level 5; brotli_types <same list as gzip_types>;`). Measured sizes of `app-*.js` (349,631 B raw): gzip -9 108,050 B, brotli 94,759 B (about 12 percent smaller than gzip). On this shared host gzip alone is the safe default.
- Verify after `nginx -t && systemctl reload nginx`:
  `curl -s -o /dev/null -D - -H 'Accept-Encoding: gzip' https://mydhiranbook.shop/build/assets/<file>.js` must show `content-encoding: gzip` and `cache-control: public, max-age=31536000, immutable`, and `curl -w '%{size_download}'` must be well below the raw size.
- `public/build` files are replaced, never edited, on deploy; old hashed files may stay on disk, so a long cache is safe.

Set nginx `client_max_body_size`, PHP `post_max_size` and `upload_max_filesize` to at least 32M, because a New Loan form can carry several photos and documents (each file is limited to 8 MB by the app).

Only `public/` is web-reachable. Trusted proxies come from `TRUSTED_PROXIES` in `.env` (comma list of IPs/CIDRs, or `*`; empty = trust none). With this nginx terminating TLS directly leave it empty and keep `fastcgi_param HTTPS on`; behind a load balancer set it to the balancer's address so `X-Forwarded-Proto` is honoured.

`GET /up` is the Laravel health route (`withRouting(health: '/up')`).

## 4. `.env` keys

Create `.env` on the server (it is git-ignored) from `.env.example`. Never commit it. Generate the key with `php artisan key:generate` (or `--show`).

App
- `APP_NAME=MyDhiranBook` (`BUSINESS_NAME` falls back to it), `APP_ENV=production`, `APP_KEY`, `APP_DEBUG=false`, `APP_URL=https://mydhiranbook.shop`

Database (config/database.php; only `pgsql` exists)
- `DB_URL` (leave empty), `DB_HOST`, `DB_PORT` (code default `55439`; set `5432`), `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE` (`.env.example`: `require`; code default `disable`)
- `LocalPostgresConnector` single-quotes `DB_HOST` in the DSN; libpq accepts quoted TCP hosts and socket paths (unit-tested for both). Run `php artisan migrate:status` first as a sanity check.

Session / cache / queue
- `SESSION_DRIVER=file`, `SESSION_COOKIE`, `SESSION_DOMAIN` (empty), `SESSION_SECURE_COOKIE=true`
- `CACHE_STORE=file`
- `QUEUE_CONNECTION=database` (code default), `QUEUE_NAME` (queue name on the `jobs` table; code default `dhiran-local`, `.env.example` uses `dhiran-production`)
- Sessions and cache are file based under `storage/framework/`; with several app servers they would need shared storage. Not configured.
- Optional, for several app servers or heavy use: only the `file` cache store and the `file` session driver are configured in this repo (`config/cache.php` has just that store; `config/database.php` has no redis connection). To move to Redis or the database you must first add that store/connection to the config (`redis`: PHP extension or predis plus `REDIS_*` keys; `database`: the `session:table` / `cache:table` migrations, at the price of a PostgreSQL query per request), then set `SESSION_DRIVER` / `CACHE_STORE`. Local/testing environments are pinned to `file` by `RuntimeGuard`, so this is a production-only choice. The default `file` is fine for one server.

Mail (config/mail.php; only the `smtp` mailer is defined besides `log`)
- `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT` (587), `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`

Business details (config/business.php; shown on the legal pages and the "Your access" page)
- `BUSINESS_NAME`, `BUSINESS_LEGAL_NAME`, `BUSINESS_EMAIL`, `BUSINESS_PHONE`, `BUSINESS_ADDRESS`, `BUSINESS_JURISDICTION` (default `India`)

Other keys read by config but not in `.env.example`: `BCRYPT_ROUNDS` (default 12), `MATERIALS_FETCH_ENABLED` (default false; the metal-rate fetch job is never scheduled).

## 5. What production refuses to boot without

`RuntimeGuard::verifyProduction` throws `RuntimeException('Dhiran runtime target refused: ...')` unless all of these hold:

- `APP_DEBUG` is `false`
- `APP_URL` starts with `https://`
- `APP_KEY` is non-empty
- default DB connection is `pgsql`
- `queue.default` is not `sync`, `null` or empty
- `session.secure` is `true` (`SESSION_SECURE_COOKIE=true`)
- `mail.default` is not `log`, `array` or empty

Nothing else is enforced (it does not check that SMTP works, or that a worker is running). Because the guard runs on app boot, `.env` must be complete before any `php artisan` call, including the `package:discover` that Composer runs after install.

### Domain: mydhiranbook.shop
At the registrar, point an `A` record (and `AAAA` if the server has IPv6) for `mydhiranbook.shop` and `www` at the server. In nginx set `server_name mydhiranbook.shop www.mydhiranbook.shop;`, issue the TLS certificate (`certbot --nginx -d mydhiranbook.shop -d www.mydhiranbook.shop`), redirect `www` and port 80 to `https://mydhiranbook.shop`. Then `APP_URL=https://mydhiranbook.shop`. Leave `SESSION_DOMAIN` empty (host-only cookies). Production mail (Gmail SMTP or other) is set only in the server `.env` (local runs stay on the log mailer).

## 6. Deploy steps

First deploy:

```bash
git clone <repo> /var/www/dhiran && cd /var/www/dhiran
# create .env now (section 4), then:
composer install --no-dev --optimize-autoloader
php artisan key:generate            # only if APP_KEY is empty
php artisan migrate --force
php artisan db:seed --class=PlanSeeder --force
php artisan optimize                # = config:cache + event:cache + route:cache + view:cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R u+rwX,g+rwX storage bootstrap/cache
```

- Speed settings on the server: enable OPcache (`opcache.enable=1`, `opcache.memory_consumption=128`, `opcache.validate_timestamps=0` and reload PHP-FPM on each deploy), keep `composer install --no-dev --optimize-autoloader`, and run `php artisan optimize` after every deploy (and `php artisan optimize:clear` first if a cache looks stale). `config:cache` means `.env` is read only when caches are rebuilt, so rebuild after editing `.env`.
- The list and search indexes (`dhiran_loans` by shop/created_at and shop/loan_date, `customers` by shop/first_name, `dhiran_payments` by shop/payment_date) come with `php artisan migrate --force`. Name and address search uses `ILIKE '%text%'`, which a normal index cannot serve; on a book of tens of thousands of customers the next step would be the `pg_trgm` extension with GIN indexes (needs a database superuser to create the extension, so it is not in the migrations).

- Seeder class is `Database\Seeders\PlanSeeder` (also what `DatabaseSeeder` calls). It defines `dhiran_monthly` and `dhiran_yearly`. Do not run `DemoSeeder` in production: it creates demo owners and a synthetic admin (`admin@dhiran.test`).
- `storage:link` is not needed: `config/filesystems.php` has only the private `local` disk (`storage/app/private`) and `links` is empty. Uploads are served through controllers.
- `route:cache` was not run here; `routes/web.php` uses controller routes only (no closures found by a text search), but run it once and check `php artisan route:list` on the server.
- Logs: daily files `storage/logs/dhiran-YYYY-MM-DD.log`, 14 days kept (config/logging.php, level debug); no logrotate needed.

### Cron

```
* * * * * cd /var/www/dhiran && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled in `routes/console.php`, all `withoutOverlapping()`:
- `dhiran:accrue-interest` daily
- `dhiran:forfeiture-check` daily
- `subscription:check-expiry` daily (active -> grace -> expired bookkeeping)
- `dhiran:purge-old-loan-uploads` daily
- `dhiran:prune-idempotency-keys` daily

### Queue worker

The app currently queues no work: Email OTP (`OtpService`) and the shop decision mail are sent synchronously. Production still refuses `sync`, so run a worker for future queued work.

`/etc/systemd/system/dhiran-queue.service`:

```ini
[Unit]
Description=Dhiran queue worker
After=network.target postgresql.service

[Service]
User=www-data
WorkingDirectory=/var/www/dhiran
ExecStart=/usr/bin/php artisan queue:work database --queue=dhiran-production --tries=3 --sleep=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

`--queue` must equal `QUEUE_NAME`. `--max-time` makes the worker exit hourly and systemd restarts it, which also picks up new code. Failed jobs go to the `failed_jobs` table (`php artisan queue:failed`).

## 7. First platform admin

Create the first super admin on the server: `php artisan dhiran:create-admin you@example.com --name="Your Name"`. It prints a random temporary password once. Sign in at `/admin/login` within 24 hours: the app then shows a TOTP secret, enrols TOTP on the first valid code and issues recovery codes. First enrolment is refused after 24 hours from creation or the last password reset; use "Forgot password" on `/admin/login`: the emailed reset reopens the window and discards any authenticator secret that was shown but never confirmed. Admin sign-in is password plus TOTP.

## 8. Backups

```bash
# database (nightly, keep copies off the server)
pg_dump -Fc -h <host> -U <user> <database> > dhiran-$(date +%F).dump
# files: private uploads (customer photos, attachments) and .env
tar czf dhiran-files-$(date +%F).tgz -C /var/www/dhiran storage/app/private .env
```

Restore: `pg_restore -d <new_db> dhiran-YYYY-MM-DD.dump`, unpack `storage/app/private`, then run the verification steps below. Store `.env` backups encrypted; it holds `APP_KEY` (without the same `APP_KEY`, encrypted columns cannot be read: admin `two_factor_secret` and borrower PII in `customers.pan`, `customers.id_number`, `dhiran_loans.kyc_pan` — losing the key loses that data; never rotate it without re-encrypting). Test a restore before launch.

## 9. Upgrade and rollback

Upgrade:

```bash
php artisan down
git pull        # or deploy the new release
composer install --no-dev --optimize-autoloader
pg_dump -Fc ... > pre-upgrade.dump
php artisan migrate --force
php artisan optimize
sudo systemctl restart dhiran-queue
php artisan up
```

Rollback: put the app down, restore the previous code and `composer install`, then if the release included migrations restore `pre-upgrade.dump` (migrations are not assumed to have working `down()` methods; this was not checked) and rebuild the caches. Never delete shop data: the product has no automatic data deletion.
