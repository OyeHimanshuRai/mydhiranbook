# Dhiran Standalone Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans inline. This folder is the user-authorized isolated workspace. Steps use checkbox syntax for tracking.

**Goal:** A locally usable independent Dhiran shop → borrower → pledge loan → repayment → printed receipt workflow with verified shop isolation.

**Architecture:** Extract only the existing loan engine and required ERP primitives from the exact source snapshot. Fresh Laravel bootstrap, local accounts and shop permissions, private storage, a guarded PostgreSQL cluster, and Dhiran-only schedules.

**Tech Stack:** PHP 8.4, Laravel 12, PostgreSQL 16, Blade, plain CSS, PHPUnit.

**Spec:** docs/superpowers/specs/2026-11-20-dhiran-standalone-design.md

## Global Constraints
- Every write stays inside /home/himanshu/Desktop/Dhiran ERP; independent .git and no push remote.
- Original JewelFlows repository and all worktrees are read-only; no external writes or services.
- Fresh .env, app key, dedicated restricted PostgreSQL role/database; synthetic data only.
- Verify runtime and actual database identity before migrations, seeders, tests, workers and scheduled jobs.

## Review Focus
- A forged borrower/loan/payment/file ID must not reveal or change another shop's data.
- Retry or double-submit must not collect payment twice; invalid amounts must not write cash or ledger rows.
- Missing evidence must preserve the loan engine's activation gate.
- Reprinting a receipt must show payment-time balances even after later repayments.
- Wrong database/session/storage/queue targets must stop before application work.

### Task 1: Isolated runtime and source extraction
Files: composer.json, bootstrap/*, config/*, scripts/*, app/Support/RuntimeGuard.php, app/Providers/AppServiceProvider.php, tests/Unit/RuntimeGuardTest.php.
Interfaces: RuntimeGuard::verify(): void; scripts/dhiran verifies and executes artisan; scripts/test verifies and runs PHPUnit on dhiran_test.
- [ ] Verify Git boundary, preserve source provenance, create cluster and fresh environments.
- [ ] Write target-refusal tests, observe failures, implement guard and connection identity checks.
- [ ] Install a minimal Laravel dependency set; prove role privileges and runtime paths.

### Task 2: Engine, schema, accounts and shop workflow
Files: app/Services/DhiranService.php, app/Models/Dhiran/*, local Shop/User/Customer, migrations, controllers, middleware, routes, views, tests/Feature/StandaloneWorkflowTest.php.
Interfaces: owner-scoped HTTP registration, borrower creation, loan issuance, evidence upload/activation, payment creation and receipt printing; TenantContext::runFor(int, callable).
- [ ] Write workflow/tenant/permission/receipt regression tests and run red.
- [ ] Extract engine and financial schema; adapt counters, metal registry and period lock locally.
- [ ] Implement password auth, owner/operator/viewer permissions, tenant middleware and forms.
- [ ] Implement private evidence and idempotent repayments with immutable receipt snapshots.
- [ ] Run HTTP and retained financial-engine tests green.

### Task 3: Local demo, schedules and verification
Files: database/seeders/DemoSeeder.php, routes/console.php, app/Console/Commands/Dhiran*, README.md, docs/verification.md.
Interfaces: scripts/dhiran db:seed --class=DemoSeeder; scripts/dhiran serve --host=127.0.0.1 --port=8787.
- [ ] Verify targets, seed synthetic shops, borrower, evidenced loan and payment.
- [ ] Run Dhiran-only interest and forfeiture commands and confirm tenant context is restored.
- [ ] Exercise a real browser flow including second-shop denial, save receipt PDF and screenshots.
- [ ] Run the complete suite and review isolation and code; document exact results and startup instructions.
