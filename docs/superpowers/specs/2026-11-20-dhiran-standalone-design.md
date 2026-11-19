# Dhiran standalone local milestone

User-authorized goal: extract Dhiran at JewelFlow commit 2b9dfc2c860d2ff570c54ecd278be19cda147577 into this initially empty folder. Preserve the original repository and all worktrees as read-only. Never modify external services, branches, deployments, mobile or OTP. Removing Dhiran from ERP is explicitly out of scope.

## Approach
Keep the existing DhiranService and financial models, tenant context, fail-closed tenant scope, immutable ledger semantics, loan counter algorithm, Dhiran migrations and two scheduled commands. Adapt the small ERP dependencies locally. A full ERP clone would retain unnecessary product coupling; rewriting the loan math would discard the required engine. Use Laravel 12 and PostgreSQL 16 already available locally.

## Boundaries
Fresh independent Git repository, no remotes. Retain the downloaded exact-commit archive privately in .source for provenance, never execute its application. No ERP environment, credentials, vendor symlinks or live records are copied.

Create a workspace-owned PostgreSQL cluster with TCP disabled and a private Unix socket. Separate restricted roles own dhiran_local and dhiran_test; neither can connect to the other database or postgres/template databases. Fresh random passwords and app keys remain in ignored local files. Storage, cache, sessions, logs and queues are local to Dhiran, with separate testing paths and queue names. Runtime checks refuse nonlocal config, unexpected database identities, external disks, Redis, mail transport and unsafe paths before any migrations, seeders, tests, workers or scheduled jobs. Repeat target checks on actual connection establishment.

## Milestone behavior
Password registration creates a Dhiran account and its own shop atomically. Owner, operator and viewer permissions are local roles. Only the authenticated account determines the shop; posted shop IDs never grant access. Add a borrower; issue a gold/silver pledge with private synthetic collateral and ID evidence, retaining pending-evidence activation rules; record repayment with append-only cash and ledger rows; print an immutable payment receipt showing payment-time balances. No OTP, mail delivery, payment gateway, cloud storage or ERP APIs.

## Verification
Use synthetic data only. Tests use the dedicated test role/database after target verification. Prove registration, login, borrower and loan creation, evidence gate, repayment arithmetic, receipt content, cross-shop list/detail/borrower/payment/receipt/file denial, permission denial, validation, duplicate-submit prevention and scheduled-command tenant isolation. Retain existing financial-engine regression tests where their fixtures apply. Run a real local browser workflow and save a printed PDF receipt in private local evidence. No OS scheduler is installed; only Dhiran schedules are registered.

## Platform admin panel (additional user scope)
Separate /admin login, platform_admin guard/provider/table, path-scoped cookie, TOTP MFA with single-use recovery codes, separate password broker/token table, permissions and append-only audit log. Reuse upstream TOTP, admin model recovery/permission helpers, audit model, route-scoped session middleware and admin visual styles. Admin manages only Dhiran shops and subscriptions; no borrower access or owner impersonation. Local password recovery delivers to the private log mailer, no external OTP/mail. Test real password → MFA → dashboard, shop-owner denial, foreign ERP cookie denial, password reset token expiry/single use, MFA recovery code single use and permission checks.
