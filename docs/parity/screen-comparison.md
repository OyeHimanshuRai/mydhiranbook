# Local JewelFlow → standalone Dhiran comparison

Source of truth: actual on-disk files in `/home/himanshu/Desktop/JewelFlow`, HEAD `018b3d810e37d534f498033ab582ee41f3197c27`. Relevant tracked/untracked source changes: none at snapshot time. Source was read only; all rendering and checks use copied files inside standalone and synthetic Dhiran data. The requested `2b9dfc2c` snapshot remains preserved in `.source/jewelflow` but is superseded as baseline.

Standalone before restoration: checkpoint `4710ced`, branch `checkpoint/initial-standalone`. Database, fresh environments and private uploads backed up in ignored `.runtime/checkpoints/initial-standalone`. Screenshots and DOM observations captured before replacing runtime screens.

## Screen-by-screen findings before restoration

| Screen/workflow | Identical | Changed | Missing in initial standalone |
| --- | --- | --- | --- |
| Dashboard | Loan data uses same Dhiran tables and tenant scope | Entire shell, navigation, cards, typography, counts and labels; principal-only amount replaces original total outstanding | Original overdue and interest metrics, recent-loan presentation |
| Borrowers | Shop-bound borrower identities | Simplified add form and list | Original searchable directory, borrower profile, loan/payment/item history and KYC indicators; inline borrower dialog |
| New Loan | Engine computes net/fine weight, valuation and LTV; loan starts pending evidence | Redesigned form and labels; defaults 2%/12 months rather than actual local UI 1.5%/6 months | Multiple items; other/appraised collateral; HUID; interest type; penalty; fee display; notes; borrower search/profile/modal; live calculation summary; original actions |
| Pledged items | Pledged rows exist | Embedded small table | Original item detail page, private item evidence and linked borrower/loan navigation |
| Old-loan entry | **Absent in selected local original and initial standalone** | `2b9dfc2c` has this module, but selected local source does not | No old-loan screen is added during parity restoration; new module work is paused |
| Payments | Penalty → interest → principal allocation, cash and ledger rows | Simplified repayment form and receipt design | Original separate interest payment, repayment and pre-close dialogs; source receipt format |
| Renewals | Service exists in previously extracted engine | Earlier extraction used different engine revision | Original renewal dialog/action and renewed-loan navigation |
| Closure | Service exists | Automatic payoff summary presentation | Original close/pre-close workflow, item release and closure certificate |
| Forfeiture | Service and command exist | Earlier extraction used newer state/deadline behavior | Original notice/forfeit dialogs and print notice |
| Reports | Underlying financial rows exist | No dedicated report UI | Original active, overdue, interest, forfeiture, cashbook and profitability reports |
| Settings | Per-shop Dhiran settings table exists | No original settings UI | Original settings sections and form controls |
| Printed documents | Principal/repayment totals from engine | Custom receipt layout and `DH-R...` reference | Original pledge receipt/Girvi Parchi, payment receipt (`DPR-...`), closure certificate and forfeiture notice |
| Staff | Local accounts exist | New owner/operator/viewer screen replaced original workflow | Original add/edit staff forms, role permissions, termination/reactivation and staff audit records |
| Login / platform admin | Intentionally independent | Separate local shop login, admin guard, MFA, recovery, cookie and audit table | These differences remain intentional; no ERP sessions or accounts copied |

## New Loan — observed defaults and fields

Both pages rendered at 1440×1000. PNG dimensions verified independently. Original render uses actual copied local Blade, original assets and the original `create()` method. It does not execute the ERP application or access its database.

- Local original: search borrower by name/mobile/PAN/ID; inline Add New Borrower; profile card includes mobile, PAN, ID number, state and address. Multiple pledged items; gold, silver, other; metal or appraised valuation; supported purity choices; item rate; HUID; live net/fine weight, market value and lending value. Loan parameters include principal, today's gold rate, monthly rate, flat/daily/compound, tenure, penalty and fee; summary and notes; Cancel/Create Loan actions.
- Observed original defaults: gold, auto metal valuation, internal purity 22 but visible select initially says `Select`; weights/rates/principal zero; 1.5% monthly; flat; 6 months; 2% penalty; zero fee. Its local controller does not pass `$defaults`, so template fallbacks win even when settings store 2%/12 months. This is a source behavior, not an assumption.
- Initial standalone: single item; gold/silver only; purity numeric; ₹6,000/g; 2% monthly; 12 months; visible issue date; no live summary or inline borrower creation. It uses the settings' implicit penalty, not an editable source penalty.

## Shared synthetic calculation and resulting documents

Borrower: Synthetic Borrower, synthetic mobile 9000000001. Item: Synthetic gold chain, gross 20g, stones 0g, gold 22K, pure-metal rate ₹6,000/g. Principal ₹50,000; monthly interest 1.5%; flat; 6 months; date 20 Nov 2026.

Hand-checked expected values: net 20g; fine 18.333333g; market ₹110,000; 75% maximum loan ₹82,500; approximate monthly interest ₹750. Current standalone created DH-000002 with ₹50,000 principal and 20 May 2027 maturity; original print template renders these same synthetic records with full collateral and LTV details.

Initial standalone produced only a Disbursement receipt (DH-R000003), with cash method and balance snapshots. Original Pledge Receipt / Girvi Parchi includes loan number/date, borrower/contact, rate/type, tenure/maturity, collateral weights/purity/value, totals, 75% LTV and pledger/lender signatures. Its separate payment receipt uses `DPR-` plus the payment ID. Receipt parity must be verified after the original controller and service are restored, not inferred from screenshots alone.

## Intentional isolation adaptations

All code, routes, assets and tests are physical copies. Fresh Dhiran database roles, session cookies, password accounts, admin MFA, private storage, cache and queues remain independent. ERP cross-promotion, OTP/onboarding dependencies, external subdomain redirects and platform-integrated provisioning are not runtime requirements. No external mail, cloud storage, payment gateway or OTP service is enabled. Only Dhiran schedules will be registered.
