# Dhiran as an independent product — launch design

Status: ready for owner approval · 2026-11-20
Supersedes the scope limits of `2026-11-20-dhiran-standalone-design.md` (local-only milestone). Its isolation and tenant-safety rules still apply.

## Intent

Dhiran (gold/silver pledge loans, "girvi") is going to market soon as its own product. A pawn-lending shop owner must be able to find it, register, pay for a plan with real money, run their whole pledge-loan business in it, and be managed and billed by the platform owner — with **no runtime relation to JewelFlow**: no shared database, accounts, sessions, domains, APIs or code dependencies.

JewelFlow (`/home/himanshu/Desktop/JewelFlow`, HEAD `018b3d8`) already contains Dhiran plus the platform pieces a product needs (plans, Razorpay billing, invoices, email OTP, platform admin). Those pieces are proven in production with real money, so the job is **copy (read-only), trim to a single product, wire, and test** — not reinvent.

## Goals

1. **Complete Dhiran feature set** — everything Dhiran does in JewelFlow works here: loans, borrowers, items, evidence, interest/repay/pre-close/close, release, renew, forfeiture, documents, reports, settings, old-loan import, staff/roles. (Mostly done; verify parity.)
2. **Self-serve signup to paid shop** — register (with Terms/Privacy consent) → verify email (OTP) → choose plan → pay via Razorpay → create business → dashboard. No free trial. Forgot/reset password for shop users.
3. **Real billing** — plans as data (monthly/yearly, price, grace days, limits), subscription history and events, Razorpay order/checkout/callback, webhook (captured, failed, refunded), periodic reconciliation, payment receipts (no GST while unregistered) emailed to the owner, renewal, daily expiry job (active → grace → expired), plan limits enforced (staff, items/loans).
4. **Lapsed behaviour** — keep the existing collect-only mode: a lapsed shop can still settle existing loans (collect, release, close, forfeit) but cannot start new business.
5. **Platform admin for the owner** — shops (list, detail, status, export, data export/delete), shop users, plans CRUD, subscriptions, invoices, revenue, unresolved payments, impersonation (audited), announcements, platform settings, admin accounts, MFA, audit log.
6. **Real email** — SMTP (or a provider configured via `.env`) for OTP, password reset, invoices, admin alerts; queued where JewelFlow queues.
7. **Deployable** — runs on a normal server from `.env`; the local strict guard remains for development and tests.

## Non-goals

- SMS/WhatsApp delivery (JewelFlow never shipped it; the overdue-reminder command keeps logging only). Mobile app / API. Multi-product, editions, ERP↔Dhiran realms, cross-promotion, ERP "add Dhiran" path, JewelFlow subdomain redirects.
- Rewriting the loan engine or billing logic. Changes are limited to decoupling and single-product trimming.

## Anti-goals (failure even if it "works")

- Any request, credential, session cookie, database, storage or mail path that touches JewelFlow.
- Money taken without a subscription being granted (or granted without money), duplicate grants on retried webhooks, or a lost payment with no admin-visible record.
- Cross-shop data visible through any ID, file, invoice, impersonation or admin screen.
- Production booting with debug on, without HTTPS, without webhook secret, or with the log mailer.
- Edits to the JewelFlow repository.

## Constraints

- JewelFlow is read-only: copy files, never run its app, never write there, avoid index-refreshing git commands.
- Stack stays: PHP 8.4, Laravel 12, PostgreSQL 16, Blade, plain CSS/Vite, PHPUnit.
- Money, ledger, migration and security decisions are reviewed in the main session before commit.
- Tests run only on the dedicated local test database (existing guard).
- Secrets only in `.env` (ignored); never committed.

## Approaches considered

| | Approach | Trade-off |
|---|---|---|
| **A (chosen by owner)** | Copy JewelFlow billing/email/admin code, then trim to one product | Reuses money code proven with real payments (retries, refunds, closed-browser captures); needs careful trimming where multi-product logic reaches in |
| B | Write a smaller billing layer from scratch | Less code, but untested money handling right before launch |
| C | Copy verbatim including products/editions/realm tables | Fastest copy, but leaves multi-product concepts in a single-product app |

## Design

### Copy-and-trim map (JewelFlow → Dhiran)

| Area | Copy | Trim / adapt |
|---|---|---|
| Plans | `plans` table, `Plan` model, `PlanSeeder` Dhiran rows (`dhiran_monthly` ₹1,852, `dhiran_yearly` ₹19,999; features `staff_limit` 3/7, `max_items` 1000/3000) | drop `platform_product_id`, `grantsEdition()`; every plan is a Dhiran plan |
| Subscriptions | `shop_subscriptions`, `subscription_events`, `SubscriptionPaymentService`, `SubscriptionWebhookService`, `SubscriptionController`, `EnsureSubscriptionIsActive`, `SubscriptionGateService`, `subscription:check-expiry`, `subscription:reconcile-payments` | replace the current single-row `subscriptions` table (migrate existing rows); `Shop::hasEdition('dhiran')` becomes "has active or grace subscription"; remove product families and edition sync |
| Billing documents | `platform_invoices`, `PlatformInvoiceService`, `SendPlatformInvoiceEmail` job, `PlatformInvoiceMail` | issued as simple **Payment Receipt** (no GST) while `billing.gst_registered=false`; GST path kept behind the switch |
| Onboarding | `DhiranOnboardingController` (plans → subscribe → create business, pending subscription linked to shop), existing orphaned `onboarding/plans` and `create` views | registration creates user only; shop created only after captured payment; no trial path |
| Email OTP & reset | `OtpService`, `EmailVerificationOtpController`, `EnsureDhiranEmailVerified`, `EmailOtpMail`, password broker for shop users | single realm: no `realm` column, standard broker |
| Platform admin | `routes/admin.php` screens for shops, users, plans, subscriptions, invoices, revenue, unresolved payments, impersonation, announcements, settings; `AdminLoginOtpMail` only if JewelFlow admin login uses it | merge into the existing `/admin` (keeps TOTP MFA, path-scoped cookie, audit); drop edition grant/requests, feature flags, fraud/compliance/backups pages unless trivially Dhiran-relevant |
| Announcements | `platform_announcements`, banner component | drop `realm` column |
| Runtime | — | see below |

### Runtime / deployment

`RuntimeGuard` becomes environment-aware:
- `local` / `testing`: current strict checks unchanged (dedicated socket cluster, fixed roles, log mailer, private storage).
- `production`: refuses to boot unless `APP_DEBUG=false`, `APP_URL` is https, `APP_KEY` set, DB is pgsql from `.env`, mail mailer is not `log`/`array`, Razorpay key/secret/webhook secret present, queue is a persistent driver, session cookie `secure`. Hard-coded laptop path and "no git remote" checks apply to local only.
- Scheduler (`schedule:run` cron) and a queue worker are documented deployment requirements. Webhook route is CSRF-exempt and signature-verified.

### Data flow (signup → paid)

register (name, email, mobile, password) → email OTP verify → plans page → Razorpay order → checkout → callback verifies signature/amount/currency/captured → pending `shop_subscriptions` row (shop_id null) → create-business form → shop + owner role + `dhiran_settings` + link subscription + invoice issued & emailed → dashboard. The webhook path finalizes the same payment idempotently if the browser never returns; reconciliation catches anything both missed and lists failures under admin "unresolved payments".

### Testing

- Port JewelFlow's billing, onboarding, OTP, webhook, expiry, plan-limit and admin tests that apply; adapt to single product.
- Razorpay calls mocked at the HTTP layer in tests; a manual end-to-end run with Razorpay **test** keys before switching to live.
- Existing 189 tests stay green; add tests for lapsed collect-only gating and production guard.

### Public website

Public, unauthenticated pages served by the same app: landing (what Dhiran does, who it is for, screenshots), pricing (rendered from active `plans`), Terms of Service, Privacy Policy, Refund & Cancellation Policy, Contact (business name, email, phone, address from config). Footer links on every public page and on the register/checkout pages; signup has a required "I agree to Terms and Privacy" checkbox, recorded with timestamp. Legal text is drafted by the builder as a starting point and **must be reviewed by the owner** before go-live (Razorpay live activation checks these pages).

## Decisions

### Owner decisions (2026-11-20)

1. **No free trial.** Only paid plans. Remove the current 30-day `local-trial` on registration, trial statuses, trial services and trial settings. A shop exists only after a captured payment (or an admin grant).
2. **Not GST-registered yet → simple payment receipt.** Billing documents are "Payment Receipt" with no GST lines, no GSTIN, no tax split; amount charged = plan price. Keep the GST back-calculation path behind a config switch (`billing.gst_registered=false`) so a tax invoice can be enabled later without schema changes (seller GSTIN, rate, invoice series then become required).
3. **New, separate Razorpay account** for Dhiran. Keys and webhook secret only in Dhiran's `.env`; no JewelFlow account, webhook or notes conventions reused.
4. **Build website pages** (above).
5. **Approach A** (copy and trim).

### Builder calls (owner may override)

- **Plans:** seed both JewelFlow Dhiran plans as paid plans — `dhiran_monthly` ₹1,852 (grace 7 days, 3 staff, 1,000 items) and `dhiran_yearly` ₹19,999 (grace 14 days, 7 staff, 3,000 items). Signup shows all active plans; prices/limits/grace editable in admin plans CRUD.
- **No existing production data:** replace the single-row `subscriptions` table outright and consolidate migrations for a fresh product database.
- **Renewal:** Razorpay Orders + Checkout (one-time payments, as JewelFlow). Renewal = owner pays again; no autopay/Razorpay Subscriptions. Payment methods: Razorpay defaults.
- **Admin sign-in:** keep current password + TOTP MFA (stronger than JewelFlow's emailed OTP); don't copy `AdminLoginOtpMail`.
- **Impersonation** (overrides the earlier "no impersonation" rule): super admin only, reason required, 30-minute limit, visible banner, every action audited, ended explicitly or on expiry.
- **Accounts/onboarding:** copy JewelFlow — register with mobile + password (+ email), email OTP verification, one shop per owner, paid-but-no-business is resumable and visible to admin.
- **Limits and grace:** copy JewelFlow semantics for `staff_limit` and `max_items` and for grace (full access during grace, collect-only after expiry). No automatic data deletion; only admin export/delete.
- **Deployment:** Linux + nginx + PHP-FPM + PostgreSQL, cron `schedule:run`, `database` queue with a supervised worker, evidence files on the server's private disk, mail provider via `.env`.

## Left to the builder

File-by-file copy order, migration consolidation for a fresh product database, naming of config keys, view polish, which JewelFlow admin screens are "trivially relevant".
