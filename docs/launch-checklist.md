# Launch checklist

Tick each box; fill the Results table at the end.

## Pre-launch config
- [ ] `.env` complete (see `docs/deployment.md` section 4); `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` is https
- [ ] App boots: `php artisan about` has no "runtime target refused" error
- [ ] `php artisan migrate --force` and `php artisan db:seed --class=PlanSeeder --force` done; plans `dhiran_monthly` (1852) and `dhiran_yearly` (19999) present
- [ ] HTTPS certificate valid, http redirects to https, `.env`/dotfiles return 403/404
- [ ] Cron `schedule:run` installed; queue worker (`dhiran-queue`) running with `--queue` equal to `QUEUE_NAME`
- [ ] Config cached (`config:cache`, `route:cache`, `view:cache`)

## Legal and business details
- [ ] Owner reviewed `resources/views/site/privacy.blade.php`, `terms.blade.php` (each carries a DRAFT comment); DRAFT comment removed after review
- [ ] `BUSINESS_NAME`, `BUSINESS_LEGAL_NAME`, `BUSINESS_EMAIL`, `BUSINESS_PHONE`, `BUSINESS_ADDRESS`, `BUSINESS_JURISDICTION` set (config/business.php) and displayed correctly

## End-to-end run
- [ ] Register a new owner
- [ ] Email OTP arrives (real mail, not log) and verifies
- [ ] Send the business form; the waiting page shows until an admin approves
- [ ] Admin approves at `/admin/requests`; the 1-year access term starts and the owner gets the decision email
- [ ] Create a customer, create a loan
- [ ] Record a loan payment and open the receipt
- [ ] "Your plan" page shows the term dates and the contact line
- [ ] An ended term blocks the shop until an admin extends it

## Mail
- [ ] SMTP credentials valid; SPF, DKIM and DMARC set for the sending domain
- [ ] OTP, shop decision and admin password-reset emails land in inbox, not spam

## Backups, logs, monitoring
- [ ] Nightly `pg_dump` plus `storage/app/private` and `.env` backup, stored off the server
- [ ] A restore was tested on a scratch database
- [ ] Someone reads `storage/logs/dhiran-YYYY-MM-DD.log` (daily files, 14 kept)
- [ ] `/up` monitored; `queue:failed` reviewed

## Admin
- [ ] First super admin created (GAP in `docs/deployment.md` section 8: no command exists)
- [ ] Admin signs in at `/admin/login` with password plus TOTP; recovery codes stored
- [ ] Only needed admins exist; each has least permissions

## Results

| Check | Date | Result (pass/fail) | Notes / owner |
|---|---|---|---|
| Full test suite (`scripts/test`) count | | | |
| Test-mode registration and OTP | | | |
| Shop approval and access term | | | |
| Loan and payment receipt | | | |
| Mail deliverability | | | |
| Backup restore test | | | |
| Admin MFA sign-in | | | |
| Legal pages reviewed | | | |
