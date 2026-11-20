# Dhiran Product Launch Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the standalone Dhiran app into an independently sellable product: paid plans via Razorpay, simple payment receipts, email OTP/reset, full platform admin, public website, deployable to a real server.

**Architecture:** Copy JewelFlow's proven billing, email and admin code (read-only source `/home/himanshu/Desktop/JewelFlow`, HEAD `018b3d8`) into this repo and trim it to a single product: no products/editions/realms/trials. Existing loan engine and screens stay. `RuntimeGuard` becomes environment-aware so `production` boots from `.env` with safety checks.

**Tech Stack:** PHP 8.4, Laravel 12, PostgreSQL 16, Blade, plain CSS/Vite, PHPUnit, `razorpay/razorpay` ^2.9.

**Spec:** `docs/superpowers/specs/2026-11-20-dhiran-product-launch-design.md`

## Global Constraints

- JewelFlow is read-only: copy files, never run its app, never write there, only `git --no-optional-locks log/show` there.
- No runtime relation to JewelFlow: no shared database, accounts, sessions, domains, APIs, Razorpay account or code dependencies.
- **No free trial.** A shop exists only after a captured payment (or an admin grant). Remove `local-trial`, trial statuses, `SubscriptionTrialService`, trial settings.
- **Not GST-registered:** billing document is "Payment Receipt", no GST lines; GST path kept behind `billing.gst_registered=false`.
- **New, separate Razorpay account:** keys and webhook secret only in Dhiran's `.env`.
- Plans seeded: `dhiran_monthly` ₹1,852 (grace 7 days, `staff_limit` 3, `max_items` 1000); `dhiran_yearly` ₹19,999 (grace 14 days, `staff_limit` 7, `max_items` 3000).
- Renewal = Razorpay Orders + Checkout one-time payment; no autopay.
- Admin sign-in stays password + TOTP MFA. Impersonation: super admin only, reason required, 30-minute limit, visible banner, every action audited.
- Grace = full access; after expiry = collect-only. No automatic data deletion.
- Secrets only in `.env`; never committed. Tests run only via `scripts/test` on `dhiran_test`.
- Money, ledger, migration and security changes are reviewed in the main session before commit.
- Git: no global identity on this machine; commit with `git -c user.name=himanshu -c user.email=<owner email> commit …`; never push (no remote).
- Ignore and never commit: `output/`, `.claude/`, `.idea`, `.vscode`, `.ripgreprc`, root dotfiles (sandbox `/dev/null` mounts).

## Review Focus

1. Same Razorpay payment reaches callback, webhook and reconcile (any order, retried) → exactly one `shop_subscriptions` row and one receipt. Test in Task 3.
2. Owner pays, browser closes before callback → webhook finalizes a pending subscription; on next login the owner lands on create-business, not plans. Tests in Tasks 3 and 5.
3. Order amount/currency differs from the plan price at capture time (tampered order, or admin changed price meanwhile) → no grant, `recordUnresolvedPayment` row visible in admin. Test in Task 3.
4. Impersonation by a non-super admin, without reason, after 30 minutes, or on a shop-owner session that later hits `/admin` → denied/ended and audited. Test in Task 8.
5. Expired shop: collect-only actions and the renew page still reachable; new loans blocked; after renewal payment full access returns. Test in Task 6.

---

## File Map

| Area | Files |
|---|---|
| Runtime | `app/Support/RuntimeGuard.php`, `config/*.php` (env-driven), `.env.example`, `tests/Unit/RuntimeGuardTest.php` |
| Billing models | `app/Models/Platform/{Plan,ShopSubscription,SubscriptionEvent,PlatformInvoice,PlatformSetting,PlatformAnnouncement,PlatformImpersonationSession}.php`, migration `database/migrations/2026_11_21_000000_create_billing_platform_tables.php`, `database/seeders/PlanSeeder.php` |
| Payments | `app/Services/Billing/{SubscriptionPaymentService,SubscriptionWebhookService,SubscriptionGateService}.php`, `app/Http/Controllers/SubscriptionController.php`, `app/Console/Commands/{CheckSubscriptionExpiry,ReconcileCapturedPayments}.php`, `config/services.php` (`razorpay`) |
| Receipts | `app/Services/Billing/PlatformInvoiceService.php`, `app/Jobs/SendPlatformInvoiceEmail.php`, `app/Mail/PlatformInvoiceMail.php`, `resources/views/emails/platform-invoice.blade.php`, `config/billing.php` |
| Signup | `app/Http/Controllers/ShopAuthController.php`, `app/Http/Controllers/DhiranOnboardingController.php`, `app/Http/Controllers/EmailVerificationOtpController.php`, `app/Services/OtpService.php`, `app/Http/Middleware/{EnsureDhiranEmailVerified,EnsureShopExists}.php`, `app/Mail/EmailOtpMail.php`, views `auth/*`, `dhiran/onboarding/*`, `subscription/*` |
| Gating | `app/Http/Middleware/{EnsureDhiranEnabled,EnsureSubscriptionIsActive}.php`, `app/Models/Shop.php` |
| Admin | `app/Http/Controllers/Admin/{ShopManagementController,UserManagementController,PlanController,SubscriptionManagementController,PlatformInvoiceAdminController,RevenueAnalyticsController,UnresolvedPaymentController,BillingManagementController,ImpersonationController,AnnouncementController,PlatformSettingsController}.php`, `resources/views/admin/**`, `routes/admin.php` |
| Website | `app/Http/Controllers/SiteController.php`, `resources/views/site/{home,pricing,terms,privacy,refund,contact}.blade.php`, `config/business.php` |

JewelFlow sources for each copied file have the same name; models live in JewelFlow `app/Models/Platform/`, services in `app/Services/`.

---

### Task 1: Environment-aware runtime guard and production config

**Files:**
- Modify: `app/Support/RuntimeGuard.php`, `app/Providers/AppServiceProvider.php`, `config/{app,database,mail,queue,session,filesystems,cache,services}.php`, `scripts/dhiran`, `scripts/test`
- Create: `.env.example` (no secrets), `config/billing.php`
- Test: `tests/Unit/RuntimeGuardTest.php`

**Interfaces:**
- Produces: `RuntimeGuard::verify(): void` (unchanged name); `config('services.razorpay.{key,secret,webhook_secret}')`; `config('billing.gst_registered')` (bool, default false), `config('billing.gst_rate')` (18.0), `config('billing.receipt_prefix')` (`DHR`).

- [ ] **Step 1: Write failing tests** in `RuntimeGuardTest`: `test_production_refuses_debug_true`, `test_production_refuses_http_app_url`, `test_production_refuses_log_or_array_mailer`, `test_production_refuses_missing_razorpay_webhook_secret`, `test_production_refuses_sync_queue`, `test_production_refuses_insecure_session_cookie`, `test_production_accepts_valid_config`, `test_local_checks_unchanged` (existing two tests still pass). Each builds a config array and asserts `RuntimeException` (or none) from `RuntimeGuard::verifyConfig(array $config, string $basePath)`.
- [ ] **Step 2: Run** `scripts/test --filter RuntimeGuardTest` → new tests FAIL.
- [ ] **Step 3: Implement** `RuntimeGuard::verifyConfig(array $config, string $basePath): void` branching on `app.env`: `local|testing` = current checks verbatim; `production` = checks listed in Step 1, plus `app.key` set and DB driver `pgsql`; any other env refused. Laptop path, `.runtime/targets.json`, socket and "no git remote" checks move under local/testing only. Config files read values from `env()` with the current local values as defaults so local behaviour is unchanged.
- [ ] **Step 4: Run** `scripts/test` → all pass (189 + new).
- [ ] **Step 5: Commit** `feat(runtime): environment-aware guard with production safety checks`.

### Task 2: Billing schema, models, plans and entitlement

**Files:**
- Create: migration `2026_11_21_000000_create_billing_platform_tables.php`; models under `app/Models/Platform/` (copied from JewelFlow, trimmed); `database/seeders/PlanSeeder.php`
- Modify: `app/Models/Shop.php`, `app/Http/Controllers/ShopAuthController.php` (remove trial insert only), `tests/TestCase.php`, `database/seeders/DemoSeeder.php`, `tests/Feature/PlatformAdminTest.php:35`, `tests/Feature/Dhiran/DhiranOldLoanImportTest.php:149`, `tests/Feature/Dhiran/DhiranBroughtForwardTest.php:196`, `app/Http/Controllers/Admin/PlatformController.php` (read new tables)
- Test: `tests/Feature/Billing/EntitlementTest.php`

**Interfaces:**
- Produces:
  - Tables `plans` (code, name, description, price_monthly, price_yearly, grace_days, is_active, features jsonb), `shop_subscriptions` (shop_id nullable, user_id, plan_id, status `active|grace|expired|cancelled` (a paid subscription with `shop_id` null is "pending business"), billing_cycle `monthly|yearly`, starts_at, ends_at, grace_ends_at, cancelled_at, price_paid, razorpay_order_id unique nullable, razorpay_payment_id unique nullable, updated_by_admin_id, notes), `subscription_events`, `platform_invoices`, `platform_settings`, `platform_announcements` (no realm), `platform_impersonation_sessions`; drop `subscriptions`.
  - `Shop::currentSubscription(): ?ShopSubscription` (latest non-cancelled by ends_at); `Shop::hasEdition('dhiran'): bool` = current subscription status `active|grace` and `grace_ends_at` (or `ends_at`) ≥ now; `Shop::isLapsed(): bool`; `Shop::staffLimit(): int` from plan `features.staff_limit` (-1 if no plan); `Shop::itemLimit(): int` from `features.max_items`.
  - `Plan::priceFor(string $cycle): int` (rupees).
  - Test helper `TestCase::activeSubscription(Shop $shop, string $status='active', ?Carbon $endsAt=null): ShopSubscription` replacing the `subscriptions` insert.

- [ ] **Step 1: Write failing tests** `EntitlementTest`: `test_active_subscription_grants_dhiran`, `test_grace_subscription_grants_dhiran`, `test_expired_subscription_is_lapsed`, `test_no_subscription_is_not_entitled`, `test_staff_and_item_limits_come_from_plan` (monthly → 3 / 1000, yearly → 7 / 3000), `test_plan_seeder_values` (codes, prices 1852 / 19999, grace 7 / 14), `test_registration_creates_no_trial` (no `shop_subscriptions` row after `POST /register`).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** migration + models (drop `platform_product_id`, `grantsEdition()`, realm columns, trial fields) + seeder + `Shop` methods; update helper, seeder and the three tests that wrote `subscriptions`.
- [ ] **Step 4: Run** `scripts/test` → all pass.
- [ ] **Step 5: Commit** `feat(billing): plans and shop subscriptions replace single-row subscriptions`.

### Task 3: Razorpay payments, webhook and reconciliation

**Files:**
- Create: `app/Services/Billing/SubscriptionPaymentService.php`, `app/Services/Billing/SubscriptionWebhookService.php`, `app/Console/Commands/ReconcileCapturedPayments.php`, `app/Http/Controllers/SubscriptionController.php`, views `resources/views/subscription/{plans,_plan-card,payment,status}.blade.php`
- Modify: `composer.json` (`razorpay/razorpay` ^2.9), `routes/web.php`, `bootstrap/app.php` (CSRF-exempt `/subscription/payment/webhook`), `routes/console.php` (reconcile every 10 minutes, `withoutOverlapping`)
- Test: `tests/Feature/Billing/{PaymentFlowTest,WebhookTest,ReconcileTest}.php`

**Interfaces:**
- Consumes: Task 2 models.
- Produces (copied signatures, JewelFlow `SubscriptionPaymentService`): `createRazorpayOrder(Plan $plan, string $billingCycle): object`, `verifyPaymentSignature(string $orderId, string $paymentId, string $signature): void`, `verifyAmount(object $rzpOrder, Plan $plan, string $billingCycle): int`, `verifyCurrency(object $rzpOrder): void`, `verifyPaymentCaptured(string $paymentId): void`, `findExistingSubscription(string $paymentId): ?ShopSubscription`, `createSubscription(...)`, `renewSubscription(...)`, `finalizeCapturedPayment(string $orderId, string $paymentId): ShopSubscription`, `reconcileCapturedPayment(string $orderId, string $paymentId): ?ShopSubscription`, `recordUnresolvedPayment(string $orderId, string $paymentId, string $reason, bool $transient=false): void`. Removed: `trialFamilyFor`, `hasUsedTrialForFamily`, `hasPriorEntitlementForFamily`, `automaticTrialRefusalReason`, `canStartAutomaticTrial`, `startTrial`; `grantEditionForSubscription` replaced by nothing (entitlement is derived from the subscription row).
- Razorpay HTTP access goes through one seam `RazorpayClient` (bound in the container) so tests fake it.

- [ ] **Step 1: Write failing tests** (port and adapt JewelFlow `SubscriptionFlowTest`, `SubscriptionCallbackOwnershipTest`, `SubscriptionPaymentReconciliationTest`):
  - `test_callback_with_valid_signature_creates_pending_subscription_without_shop` (status `active`, `shop_id` null, user_id = payer).
  - `test_callback_for_existing_shop_renews_and_extends_ends_at`.
  - `test_invalid_signature_rejected_no_rows`.
  - `test_amount_mismatch_records_unresolved_and_grants_nothing` (Review Focus 3).
  - `test_callback_then_webhook_then_reconcile_creates_one_subscription_and_one_receipt` (Review Focus 1; also webhook first then callback).
  - `test_webhook_without_callback_finalizes_payment` (Review Focus 2).
  - `test_webhook_bad_signature_401`, `test_refund_webhook_cancels_subscription_and_logs_event`.
  - `test_callback_owned_by_other_user_rejected`.
  - `test_unresolved_payment_emails_alert_to_billing_alert_email` (`config('billing.alert_email')` from `.env`; queued mail, no alert when unset).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** by copying the JewelFlow files, deleting trial/edition/product code paths, and wiring routes `subscription.plans|choose|payment|payment.initiate|payment.callback|status` (auth) and `subscription.webhook` (public).
- [ ] **Step 4: Run** `scripts/test --filter Billing` then `scripts/test` → pass.
- [ ] **Step 5: Commit** `feat(billing): Razorpay checkout, webhook and reconciliation`.

### Task 4: Payment receipts by email

**Files:**
- Create: `app/Services/Billing/PlatformInvoiceService.php`, `app/Jobs/SendPlatformInvoiceEmail.php`, `app/Mail/PlatformInvoiceMail.php`, `resources/views/emails/platform-invoice.blade.php`, `resources/views/subscription/receipt.blade.php`
- Test: `tests/Feature/Billing/ReceiptTest.php`

**Interfaces:**
- Consumes: Task 3 `createSubscription` / `renewSubscription` call `PlatformInvoiceService::issueForSubscription(ShopSubscription $s): PlatformInvoice` inside the same transaction; job dispatched `afterCommit`.
- Produces: receipt number `{billing.receipt_prefix}-{YYYY}-{6-digit sequence}`; owner route `subscription.receipt` (own shop only).

- [ ] **Step 1: Write failing tests**: `test_receipt_without_gst_has_zero_tax_and_total_equals_price` (gst_amount 0, amount_before_tax == price_paid, title "Payment Receipt"), `test_gst_switch_on_back_calculates_18_percent` (19999 → 16948.31 + 3050.69), `test_receipt_email_queued_after_commit_only`, `test_receipt_numbers_are_sequential_and_unique`, `test_owner_cannot_view_other_shops_receipt` (404).
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (copy JewelFlow, add switch). **Step 4: Run** → pass.
- [ ] **Step 5: Commit** `feat(billing): payment receipts emailed after payment`.

### Task 5: Signup, email OTP, password reset and onboarding

**Files:**
- Modify: `app/Http/Controllers/ShopAuthController.php`, `routes/web.php`, `resources/views/auth/*`, `resources/views/dhiran/onboarding/{plans,create}.blade.php`, `resources/views/dhiran/verify-email.blade.php`
- Create: `app/Http/Controllers/DhiranOnboardingController.php`, `app/Http/Controllers/EmailVerificationOtpController.php`, `app/Http/Controllers/PasswordResetController.php`, `app/Services/OtpService.php`, `app/Mail/EmailOtpMail.php`, `resources/views/emails/email-otp.blade.php`, `app/Http/Middleware/{EnsureDhiranEmailVerified,EnsureShopExists}.php`, migration adding `users.terms_accepted_at`, `users.email_verified_at` (if missing), `password_reset_tokens`
- Test: `tests/Feature/Onboarding/{RegistrationTest,EmailOtpTest,OnboardingFlowTest,PasswordResetTest}.php`

**Interfaces:**
- Consumes: Task 3 pending subscription (`shop_id` null, `user_id`).
- Produces: `POST /register` creates **user only** (name, email, mobile (Indian 10-digit, unique), password ≥12 confirmed, `terms` accepted → `terms_accepted_at`); middleware order for shopless users: `auth → EnsureDhiranEmailVerified → EnsureShopExists` redirecting to `dhiran.plans` (no paid pending subscription) or `dhiran.onboarding` (paid pending); `DhiranOnboardingController@store` creates shop + `dhiran_settings` (enabled) + default roles via `TenantRoleService` + links pending subscription (`shop_id`) in one transaction.

- [ ] **Step 1: Write failing tests**: `test_register_requires_terms_consent`, `test_register_creates_user_without_shop_or_subscription`, `test_unverified_user_redirected_to_verify_email`, `test_otp_verifies_and_is_single_use_and_expires`, `test_verified_user_without_payment_sees_plans`, `test_paid_user_without_shop_resumes_create_business` (Review Focus 2), `test_cannot_create_business_without_captured_payment`, `test_create_business_links_subscription_and_owner_role`, `test_second_business_for_same_owner_refused`, `test_password_reset_email_token_single_use`. Port JewelFlow `DhiranOnboardingTest` cases that apply.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (copy JewelFlow controllers/services, drop realm). **Step 4: Run** `scripts/test` → pass (update `StandaloneWorkflowTest` registration expectations to the new flow).
- [ ] **Step 5: Commit** `feat(onboarding): paid signup with email OTP and password reset`.

### Task 6: Subscription lifecycle, lapsed mode, renewal and plan limits

**Files:**
- Create: `app/Console/Commands/CheckSubscriptionExpiry.php`, `app/Http/Middleware/EnsureSubscriptionIsActive.php` (trimmed copy), `app/Services/Billing/SubscriptionGateService.php`, `app/Console/Commands/DhiranOverdueReminder.php` (copy; logs only, scheduled daily — SMS is a non-goal)
- Modify: `app/Http/Middleware/EnsureDhiranEnabled.php`, `routes/console.php` (`subscription:check-expiry` daily, `withoutOverlapping`), `app/Http/Controllers/StaffController.php` (limit), `app/Services/DhiranService.php` / `DhiranController@store` (item limit), `resources/views/subscription/status.blade.php` (renew button)
- Test: `tests/Feature/Billing/LifecycleTest.php`

**Interfaces:**
- Consumes: Task 2 `Shop` methods, Task 3 `renewSubscription`.
- Produces: `subscription:check-expiry` moves `active → grace` at `ends_at`, `grace → expired` at `grace_ends_at` (grace = plan `grace_days`), writing a `subscription_events` row each; renew page `subscription.status` reachable for every shop state; item limit = count of pledged items in non-closed loans (copy JewelFlow semantics; if JewelFlow counts differently, follow JewelFlow and note it in the commit).

- [ ] **Step 1: Write failing tests**: `test_expiry_command_moves_active_to_grace_to_expired` (with events), `test_grace_shop_has_full_access`, `test_expired_shop_collect_only_allows_repay_release_close_blocks_new_loan` (Review Focus 5), `test_expired_shop_can_open_renew_page_and_renewal_restores_access` (Review Focus 5), `test_staff_limit_blocks_extra_staff`, `test_item_limit_blocks_new_loan_at_limit`, `test_expiry_command_runs_per_shop_tenant_context_restored`, `test_overdue_reminder_logs_and_sends_nothing`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** `scripts/test` → pass.
- [ ] **Step 5: Commit** `feat(billing): expiry, grace, collect-only and plan limits`.

### Task 7: Platform admin — shops, users, plans, billing

**Files:**
- Create: admin controllers `ShopManagementController`, `UserManagementController`, `PlanController`, `SubscriptionManagementController`, `PlatformInvoiceAdminController`, `RevenueAnalyticsController`, `UnresolvedPaymentController`, `BillingManagementController`; views under `resources/views/admin/{shops,users,plans,subscriptions,receipts,revenue,unresolved}/`; `routes/admin.php` (required from `routes/web.php` inside the existing `/admin` + `admin.session` group)
- Modify: `app/Http/Controllers/Admin/PlatformController.php` (dashboard counts; remove old `subscription` action), `resources/views/admin/layout.blade.php` (nav), `app/Models/Platform/PlatformAdmin.php` (permissions list: keep existing, add `can_manage_plans`, `can_view_revenue`)
- Test: `tests/Feature/Admin/{ShopAdminTest,PlanAdminTest,BillingAdminTest}.php`

**Interfaces:**
- Consumes: Task 2–4 models/services; `PlatformAuditService` for every write.
- Produces: admin manual grant/extend (`BillingManagementController@updateShopSubscription`: plan, cycle, ends_at, reason required, audited, no payment); plans CRUD + toggle; CSV exports of shops and subscriptions; shop data export (zip of the shop's own rows) and data-delete request copied from JewelFlow `GdprExportController@scheduleDelete` (super admin, reason required, audited, refused while any loan is not closed/forfeited/cancelled; never automatic).

- [ ] **Step 1: Write failing tests**: `test_admin_lists_and_filters_shops`, `test_shop_detail_shows_subscription_history`, `test_plan_crud_and_inactive_plan_hidden_from_signup`, `test_manual_grant_requires_reason_and_is_audited`, `test_unresolved_payments_listed`, `test_data_delete_refused_while_open_loans_and_audited`, `test_admin_without_permission_403` (each controller), `test_shop_owner_session_cannot_reach_admin`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (copy JewelFlow controllers/views, restyle to existing admin layout, drop edition/realm code). **Step 4: Run** → pass.
- [ ] **Step 5: Commit** `feat(admin): shops, users, plans, subscriptions, receipts, revenue`.

### Task 8: Impersonation, announcements, platform settings

**Files:**
- Create: `app/Services/AdminImpersonationService.php`, `app/Http/Controllers/Admin/{ImpersonationController,AnnouncementController,PlatformSettingsController}.php`, banner partial `resources/views/components/impersonation-banner.blade.php`, `resources/views/components/announcement-banner.blade.php`, admin views
- Modify: `components/dhiran-layout.blade.php` (both banners), `routes/admin.php`
- Test: `tests/Feature/Admin/{ImpersonationTest,AnnouncementTest}.php`

**Interfaces:**
- Produces: `AdminImpersonationService::start(PlatformAdmin $admin, Shop $shop, string $reason): PlatformImpersonationSession` (owner user of the shop, `expires_at = now()+30min`), `stop(): void`; middleware check ends expired sessions on every shop request; every shop write during impersonation records `impersonation_session_id` in `platform_audit_logs`.

- [ ] **Step 1: Write failing tests** (Review Focus 4): `test_super_admin_can_impersonate_with_reason`, `test_non_super_admin_cannot_impersonate`, `test_reason_required`, `test_session_ends_after_30_minutes`, `test_banner_visible_while_impersonating`, `test_writes_during_impersonation_are_audited`, `test_stop_returns_to_admin`, `test_announcement_shows_to_shops_between_dates`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → pass.
- [ ] **Step 5: Commit** `feat(admin): audited impersonation, announcements, settings`.

### Task 9: Public website

**Files:**
- Create: `app/Http/Controllers/SiteController.php`, `resources/views/site/{layout,home,pricing,terms,privacy,refund,contact}.blade.php`, `resources/css/site.css`, `config/business.php` (`name`, `legal_name`, `email`, `phone`, `address`, all from `.env`)
- Modify: `routes/web.php` (guest `/` → home; authenticated `/` keeps current redirect), auth and checkout views (footer links)
- Test: `tests/Feature/SiteTest.php`

- [ ] **Step 1: Write failing tests**: `test_public_pages_render_without_login` (home, pricing, terms, privacy, refund, contact → 200), `test_pricing_shows_only_active_plans_with_prices`, `test_footer_links_on_register_and_checkout`, `test_no_jewelflow_text_on_public_pages`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement**; legal pages carry a draft notice in an HTML comment for owner review (not shown to visitors). **Step 4: Run** → pass.
- [ ] **Step 5: Commit** `feat(site): landing, pricing and legal pages`.

### Task 10: Remove JewelFlow leftovers

**Files:**
- Delete: `/comparison/*` routes, `ReferenceDhiranController`, `resources/views/reference/**`, `CheckpointDhiranController` and `/checkpoint/staff` route, dead `erp`/`mobile` realm branches in `CustomerProfilePhoto*`, ERP label maps in `AuditLog`, stale ERP comments/wording (list from inventory: `views/staff/index.blade.php:171`, `views/staff/settings.blade.php:27-28`, `components/dhiran-layout.blade.php`, `config/materials.php` banner)
- Test: `tests/Feature/NoJewelFlowReferencesTest.php`

- [ ] **Step 1: Write failing test** `test_no_jewelflow_or_erp_references_in_app_routes_views` (scans `app/`, `routes/`, `resources/views/`, `config/` for `/jewelflow|jewelflows|\bERP\b/i`, allow-list empty).
- [ ] **Step 2: Run** → FAIL. **Step 3: Remove/rename.** **Step 4: Run** `scripts/test` → pass (delete tests that only covered comparison routes).
- [ ] **Step 5: Commit** `chore: remove JewelFlow comparison and ERP leftovers`.

### Task 11: Deployment guide and launch verification

**Files:**
- Create: `docs/deployment.md` (server packages, nginx + PHP-FPM config, `.env` keys list, `php artisan migrate --force`, `db:seed --class=PlanSeeder`, cron `* * * * * php artisan schedule:run`, supervised `queue:work`, Razorpay webhook URL and events to enable: `payment.captured`, `payment.failed`, `refund.processed`), `docs/launch-checklist.md`
- Modify: `README.md`

- [ ] **Step 1:** Run full suite `scripts/test` → expected all pass; record count.
- [ ] **Step 2:** Local manual run with Razorpay **test** keys: register → OTP (log mailer) → pay with Razorpay test card → create business → loan → payment receipt; webhook via Razorpay test webhook; record results in `docs/launch-checklist.md`.
- [ ] **Step 3:** Whole-branch review (`code-reviewer` + `security-auditor`) with Review Focus list; fix findings in owning areas.
- [ ] **Step 4: Commit** `docs: deployment guide and launch checklist`.
