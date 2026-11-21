# Dhiran

Dhiran is a SaaS for gold and silver pledge-loan ("girvi") shops: customers, pledged items, loans, interest accrual, payments, forfeiture, and a platform admin area. Shops subscribe through Razorpay (one-time Orders + Checkout, no autopay); a shop exists only after a captured payment or an admin grant.

## Stack
Laravel 12, PHP 8.4, PostgreSQL 16, Blade, plain CSS/Vite (built assets are committed in `public/build/`), PHPUnit.

## Local development
The local workspace uses a private PostgreSQL cluster inside `.runtime/` and a runtime guard that refuses anything else.

```bash
scripts/setup-local      # creates the private cluster, databases and .env
scripts/dhiran <cmd>     # php artisan, after the runtime guard check
scripts/test [filter]    # PHPUnit against the dedicated test database
```

These scripts are dev-only and hard-coded to this workspace path.

## Docs
- [Deployment guide](docs/deployment.md)
- [Launch checklist](docs/launch-checklist.md)
