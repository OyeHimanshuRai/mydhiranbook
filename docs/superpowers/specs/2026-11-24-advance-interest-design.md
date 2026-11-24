# Advance interest (first installment pre-collected) — design

Status: DRAFT, owner decisions recorded 2026-11-24, not built yet.

## Why
Indian pawnbrokers commonly hand over the loan minus the first month's interest ("advance interest"); the customer owes interest again only after that month. Banks/NBFCs do not do this. The Tamil Nadu Pawn Brokers Act 1943 (as amended) says the pawner must receive the entire principal on the ticket with nothing deducted; other states' acts were unclear in the sources found (Telangana, Karnataka, Puducherry). Market practice itself is not verified by a primary source: owner to confirm it matches the shops.

## Owner decisions
1. Both methods exist; the loan creator chooses per loan: **collected later** (today's behaviour) or **first N months deducted now**.
2. Shop owner switches the feature on in Loan settings (default OFF) and sets a default N (1 to 3). A note warns that some states ban it.
3. The advance is **never refunded**: if the customer closes on day 10 or day 29, the shop keeps all of it.

## Rules
- Advance A = round(principal × rate% × N, 2). One month = 30 days of daily interest (rate/100/30 per day), so month 1 = exactly rate% of principal for flat, daily and compound.
- Ticket principal stays P. Cash handed = P − A − (processing fee if collected at the counter).
- Interest owed starts after the advance is used up: days 1 to 30N cost nothing extra, from day 30N+1 interest accrues normally.
- Unused advance at close is earned income (no refund). Interest on a later close = max(0, accrued interest − A) (plus normal minimum-interest and lock-period rules, which already count A as collected).
- Not offered for brought-forward (old) loans or renewals (A = 0).

## Engine (app/Services/DhiranService.php)
- `dhiran_loans`: `advance_interest_months` (smallint, default 0), `interest_prepaid` (decimal, default 0). `dhiran_settings`: `advance_interest_enabled` (bool, false), `advance_interest_default_months` (smallint, 1).
- `createLoan`: after the disbursement rows, when N > 0 record an `interest` cash-in payment (interest_component = A) with cash and ledger rows (same pattern as the processing fee); `total_interest_collected += A`; `interest_prepaid = A`. Validate N against the shop setting (enabled, 1..3) and A < P.
- `accrueInterest`: after computing the period's interest, offset it with `interest_prepaid` first (`offset = min(prepaid, accrual)`), add only the rest to `outstanding_interest`, reduce `interest_prepaid`, write the offset in the ledger note/meta. Must stay idempotent across repeated accrual and across compound month ends.
- `reversePayout` (cancel pending loan): also reverse A (cash out), clear `interest_prepaid`.
- Close/settle/pre-close/release paths: leftover `interest_prepaid` is simply forfeited to the shop (no cash entry); confirm `assertMaySettle` and `applyMinimumInterest` still hold (A counted in `total_interest_collected`).

## UI
- Settings > Loan settings: switch "Collect first month's interest in advance", default months, warning note.
- New Loan: shown only when the shop enabled it. A choice "Interest collected later / First N months deducted now" plus a live line "Interest taken now ₹A, cash to hand over ₹X".
- Pledge receipt: optional printable fields "Advance interest" and "Cash handed to customer" (owner chooses in Receipt settings, like other fields).
- Loan detail and payment history show the advance as a day-0 interest receipt.

## Tests (RED first)
Day 0 payout and ledger; outstanding interest at days 29, 30, 31; close at day 10 and day 29 (advance kept, nothing refunded); part-payment before the advance is used up; compound; minimum-interest and lock period; cancel-pending reversal; ledger and cash book totals; repeated accrual idempotent; disabled shop cannot send N > 0; N out of range refused; brought-forward and renewals unaffected.

## Risks
Money paths: accrual, settlement, cancel. Needs the Ruthless LoanEngine/LoanMath suites green and an advisor review before commit.
