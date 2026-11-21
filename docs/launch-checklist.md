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
- [ ] Owner reviewed `resources/views/site/privacy.blade.php`, `terms.blade.php`, `refund.blade.php` (each carries a DRAFT comment); DRAFT comment removed after review
- [ ] `BUSINESS_NAME`, `BUSINESS_LEGAL_NAME`, `BUSINESS_EMAIL`, `BUSINESS_PHONE`, `BUSINESS_ADDRESS`, `BUSINESS_JURISDICTION` set (config/business.php) and displayed correctly
- [ ] `BILLING_GST_REGISTERED=false` (not GST-registered; receipt titled "Payment Receipt", no GST lines)
- [ ] `BILLING_ALERT_EMAIL` set to a monitored mailbox

## Razorpay
- [ ] New Dhiran-only Razorpay account (no sharing with any other product)
- [ ] Test-mode: `RAZORPAY_KEY`/`SECRET`/`WEBHOOK_SECRET` (test) set; webhook `https://<domain>/subscription/payment/webhook` with events `payment.captured`, `payment.failed`, `refund.created`
- [ ] Test-mode end-to-end run (below) passed
- [ ] Live keys and live webhook secret set in `.env`; config cache rebuilt; worker restarted
- [ ] Live webhook test delivery returns 200; bad-signature request returns 401

## Test-mode end-to-end run
- [ ] Register a new owner
- [ ] Email OTP arrives (real mail, not log) and verifies
- [ ] Choose a plan; pay with a Razorpay test card
- [ ] Shop is created only after the captured payment
- [ ] Create business (shop), create a customer, create a loan
- [ ] Record a loan payment and open the receipt
- [ ] Subscription "Payment Receipt" is visible and emailed (needs queue worker)
- [ ] Replay the same webhook (Razorpay dashboard "resend"): response ok, no second subscription period, no duplicate receipt
- [ ] Failed test payment (`payment.failed`) leaves no shop

## Mail
- [ ] SMTP credentials valid; SPF, DKIM and DMARC set for the sending domain
- [ ] OTP, receipt and admin password-reset emails land in inbox, not spam

## Backups, logs, monitoring
- [ ] Nightly `pg_dump` plus `storage/app/private` and `.env` backup, stored off the server
- [ ] A restore was tested on a scratch database
- [ ] Someone reads `storage/logs/dhiran-YYYY-MM-DD.log` (daily files, 14 kept)
- [ ] `/up` monitored; `queue:failed` and `subscription_events` unresolved payments reviewed (`/admin/unresolved-payments`)

## Admin
- [ ] First super admin created (GAP in `docs/deployment.md` section 8: no command exists)
- [ ] Admin signs in at `/admin/login` with password plus TOTP; recovery codes stored
- [ ] Only needed admins exist; each has least permissions

## Results

| Check | Date | Result (pass/fail) | Notes / owner |
|---|---|---|---|
| Full test suite (`scripts/test`) count | | | |
| Test-mode registration and OTP | | | |
| Test-mode payment and shop creation | | | |
| Loan and payment receipt | | | |
| Webhook replay, no duplicates | | | |
| Live webhook delivery | | | |
| Mail deliverability | | | |
| Backup restore test | | | |
| Admin MFA sign-in | | | |
| Legal pages reviewed | | | |
