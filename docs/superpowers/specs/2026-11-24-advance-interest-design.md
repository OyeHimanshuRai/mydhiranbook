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

---

# v2 — two collection methods, installment schedule (owner 2026-11-24, later the same day)

## The two methods (chosen per loan on New Loan)
1. **Paid afterwards (bank way)** — what Dhiran did before: interest builds up per day; the customer pays what has built up when they come. `advance_interest_months = 0`.
2. **Paid in advance, month by month (house-rent way)** — the installment for a month is paid at the START of that month. Installment 1 is taken on the loan day (as the "first N months now", N = 1..3); the next is due when that period ends and pays the following month, and so on. `advance_interest_months > 0` marks the loan as method 2.

## Periods and amounts: per actual day, every interest type
- Period k = [loan date + (k-1) months, loan date + k months), calendar months from the loan day with no overflow (24 Nov, 24 Dec, 24 Jan; 31 Jan + 1 month = 28 Feb). Same arithmetic as maturity and compound month ends.
- Amount = interest for the actual days in the period (rate / 100 / 30 per day, as accrual already does). A 31-day period costs 31 days of interest; a 30-day one costs 30. The earlier flat "rate x N" advance is replaced by this (first advance = days of periods 1..N).
- **flat / daily:** principal still owed x per-day rate x days.
- **compound:** unpaid interest joins the base at each month anniversary. Installments are paid ahead, so nothing is unpaid at an anniversary and the periods are simple interest on principal; only an `interest_capitalised` left by earlier lateness counts, and only until the next anniversary. This is the same base `accrueInterest` uses, so a paid installment is spent to exactly zero at the period end.

## "Amount to cover through period end E" (one function, used by creation and by every installment)
`need = max(0, outstanding_interest + coverInterest(today -> E) - interest_prepaid)`
- On time: outstanding is 0, so need = the period's interest.
- Late (the customer comes after the due date): the already-accrued late days are inside `outstanding_interest`, so need still equals the whole period's interest if the principal did not change.
- After a part-payment the principal is lower, so need shrinks accordingly.
- Paying `need` clears the late interest first, then tops `interest_prepaid` up so accrual is fully covered to E. Accruing to E afterwards leaves interest owed ~0 and prepaid ~0 (the property the tests check for flat, daily and compound).

## Operation: pay the next installment
- Only loans with `advance_interest_months > 0`, status active, and `next_due <= today + 7 days` (a week early is allowed; not further ahead). One period per payment.
- The server computes the amount (no amount field); payment type `advance_interest`, direction in, interest_component = amount; `total_interest_collected += amount`; `interest_periods_paid += 1`. Ledger credit entry and cash entry like the first advance. Submit-once key required.
- `next_due = loan date + interest_periods_paid months`. Late when `next_due < today`.
- Penalty is not part of an installment (use the normal interest payment for penalty).

## Rules kept from v1
The shop keeps whatever advance is left when the loan closes; a cancelled pending loan returns it; renewal starts clean (new loan, method 1 or 2 chosen again). Min-interest and lock-period rules count all collected interest.

## UI
- New Loan: "Interest collection": "Paid afterwards (as it builds up)" | "Paid in advance, month by month" with "Months taken now" (1 to 3); summary shows interest taken now, cash to hand over, and the next installment date.
- Loan page: an "Interest installment" card for method-2 loans: next due date, amount, late flag, and a "Collect installment" button (sheet shows the amount; no editing).
- Settings: wording of the switch updated; the months setting is the default "months taken now".

## Schema
`dhiran_loans.interest_periods_paid` smallint default 0. (`advance_interest_months` and `interest_prepaid` exist.)
