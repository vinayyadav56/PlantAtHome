# Accounting & Financial Management System — Implementation Report

**For:** PlantAtHome owner + chartered accountant · **Date:** 2026-09-14 · **Status:** complete on staging, accounting switch OFF in production (owner flips it after the cutover gate).

## 1. What was built (one paragraph)

A double-entry general ledger sits underneath the existing order, GST, payment, refund and settlement engines. Every financial event — payment capture, order delivery, refund, vendor settlement, vendor payment, adjustment, courier cost, inventory movement, opening balance — posts one balanced, immutable, idempotent journal entry in the same database transaction as the business change. Vendor, customer, tax and inventory sub-ledgers are projections of those journal lines; settlements, payments, reports, the trial balance, P&L and balance sheet are all aggregates of them. Nothing in the system ever does `balance += amount`.

## 2. Architecture (what exists, where)

| Layer | Path (API `packages/marvel/src`) |
|---|---|
| Journal core | `Services/Accounting/JournalService` (draft/post/reverse/correct; Σdr = Σcr to the paisa; UNIQUE `source_key` = idempotency; closed periods refused, late system events redirected + flagged), models `Database/Models/Accounting/*` (posted entries/lines refuse update/delete) |
| Posting façade | `Services/Accounting/AccountingPostingService` — the only place business events become journals: `onOrderStatusChanged`, `recognizeOrder`, `derecognizeOrder`, `cancelBeforeRecognition`, `recordPaymentCaptured`, `onShipmentDelivered`; strict mode = a posting failure rolls the business change back |
| Money | integer paise via `App\Shared\Domain\ValueObject\Money` + `MoneyBridge` (largest-remainder allocation); DECIMAL(14,2) columns; no float arithmetic |
| Vendor payable | `VendorPayableCalculator` + `VendorCommissionRuleResolver` (product › category › vendor rule › shop mode; cost-sheet default; percentage / fixed per item / fixed per order); result frozen on `order_items.*_snapshot` |
| Vendor sub-ledger | existing `vendor_ledger_entries`, per LINE, every row linked to its journal (`VendorLedgerService`) |
| Settlements & payments | `SettlementService` (weekly, delivered + 7-day hold, pending_approval → approved → partially_paid → paid), `VendorPaymentService` (partial payments, `withdraws` mirror), `VendorAdjustmentService` (threshold-gated second approver) |
| Refunds & returns | `RefundService` (full / partial / item slices from the immutable snapshot, credit notes CN-FY-000001, payout wallet / Razorpay / manual), `ReturnService` (requested → approved → received → refunded) |
| GST | order/line snapshot (existing `GstService`) → tax dims on journal lines; `FinancialReports::taxLedger`; credit notes as negative rows in the GSTR export |
| Delivery | `SHIPMENT_COST` on delivered shipments (courier vs delivery-partner payable) + configurable vendor share |
| Inventory | `InventoryLedgerService` (platform-owned stock only; weighted average; COGS inside the order's recognition journal; return restock at sale cost); purchase-order / goods-receipt / vendor-bill tables migrated (no UI yet) |
| Reconciliation | `ReconciliationEngine` (journal, vendor, payment, GST, legacy, inventory checks → persisted findings, explain/resolve with a note), `PeriodService`, `OpeningBalanceService`; `accounting:reconcile [--gate]` nightly 03:30 |
| Reports | `FinancialReports`: trial balance, balance sheet, general ledger (running balance), P&L, accounts payable, cash & bank, vendor statement (JSON/CSV/PDF), tax ledger, dashboard |
| RBAC | module `accounting` (view/create/edit/approve/export; settlements.view/approve/pay; adjustments.view/create/approve; periods.view/close) → every `api/accounting/*` route carries `permission:accounting.*` (verified by `SecurityTest`) |
| Admin UI | `admin/rest/src/pages/accounting/*` (17 screens), Settings → Accounting, vendor commission dialog (payable basis + recognition mode), product form (stock ownership), refund detail (payout method / retry) |
| Vendor portal | `admin/rest/src/pages/[shop]/finance` — balance, ledger, settlements, payments, statement (CSV/PDF); read-only |

Tables added (all additive, `hasTable/hasColumn`-guarded): `acc_accounts`, `acc_accounting_periods`, `acc_sequences`, `acc_journal_entries`, `acc_journal_lines`, `acc_audit_log`, `payment_events`, `vendor_payments`, `vendor_adjustments`, `vendor_commission_rules`, `refund_items`, `credit_notes`, `return_requests`, `acc_reconciliation_runs`, `acc_reconciliation_findings`, `inventory_transactions`, `inventory_valuations`, `purchase_orders(+items)`, `goods_receipts(+items)`, `vendor_bills(+items)`; columns on `orders`, `order_items`, `products`, `shops`, `refunds`, `vendor_ledger_entries`, `vendor_settlements`, `settlement_runs`.

## 3. Business decisions applied (owner-confirmed)

| # | Decision | Where it lives |
|---|---|---|
| D1 | Vendor payable = **cost sheet** (vendor rate × qty) by default; commission modes per vendor / category / product | `shops.commission_mode`, `vendor_commission_rules`, Settings → Accounting |
| D2 | Revenue recognition = **principal** (gross sales are our revenue, vendor share is a cost); per-vendor **agent** override flagged for CA review | `shops.recognition_mode`, `settings.accounting.recognition_mode` |
| D3 | Settlement eligibility = **delivered + return window (7 days)**, **weekly** runs | `settings.settlement.hold_days / cadence` |
| D4 | History = **cutover + opening balances**; past orders never re-posted; each opening balance flagged until confirmed | Settings → Accounting (cutover date), Reconciliation → Opening balances |

## 4. Posting rules (principal mode)

| Event | Entry |
|---|---|
| Payment captured (Razorpay) | DR 1020 Gateway Receivable (+ DR 2080 Wallet for the wallet part) / CR 2070 Customer Advances; fee (when reported): DR 5050 / CR 1020 |
| Order delivered (parent completed) | DR 2070 (prepaid) or DR 1030 COD Receivable · CR 4010 Product Sales (Σ taxable) · CR 2020/2030 or 2040 GST · CR 4030 Delivery Revenue + its GST · DR 4015 Discounts Given; per vendor line: DR 5020 Vendor Settlement Cost / CR 2010 Vendor Payables[shop]; platform-owned line: DR 5010 COGS / CR 1040 Inventory; paise residual ≤ 5p → 6070, larger → balanced DRAFT + flag |
| Cancel after delivery | exact reversal of the recognition (+ vendor sub-ledger reversal / clawback); after a partial refund it is flagged for a person instead |
| Cancel before delivery (paid) | DR 2070 / CR 2050 Customer Refund Payable |
| Refund posted | per slice: DR 4010, DR GST payables, (DR 4030 delivery on full), CR 4015 discount share, CR 2050; vendor: DR 2010[shop] / CR 5020; credit note issued |
| Refund paid | DR 2050 / CR 2080 (wallet) · CR 1020 (Razorpay, after the approval commits) · CR 1010 (manual) |
| Vendor payment | DR 2010[shop] / CR 1010 |
| Vendor adjustment | credit: DR 6060 / CR 2010 · debit: DR 2010 / CR 4040 |
| Shipment delivered | DR 5030 Delivery Cost / CR 2060 Courier Payable (2090 for a delivery partner); vendor share DR 2010 / CR 5030 |
| Opening balance | DR 3030 Opening Balance Equity / CR 2010[shop] (negative swapped), flagged until confirmed |
| Inventory receipt / adjustment / return | DR 1040 / CR 2060|2010[supplier] · ± 1040 vs 6060 · DR 1040 / CR 5010 |

## 5. §68 acceptance simulation — numbers (automated, `tests/Feature/Accounting`)

Cart: Plant ₹300 @0% · Pot ₹500 @18% incl. (taxable 423.73, CGST 38.14, SGST 38.13) · Fertilizer ₹200 @5% incl. (190.48 / 4.76 / 4.76) · Delivery ₹60 (taxable 54.85, CGST 2.58, SGST 2.57) · paid ₹1060 · Vendor A rates 240 + 400, Vendor B 160.

| Step | Journal | Checked by |
|---|---|---|
| Capture | DR 1020 1060.00 / CR 2070 1060.00 | `PaymentEventsTest` |
| Delivery | DR 2070 1060.00 · CR 4010 914.21 · CR 2020 45.48 · CR 2030 45.46 · CR 4030 54.85; DR 5020 800.00 / CR 2010[A] 640.00, CR 2010[B] 160.00 | `OrderRecognitionTest`, `VendorLedgerTest` |
| Settlement (weekly, hold elapsed) | A 640.00, B 160.00 pending approval → approved | `SettlementPaymentTest` |
| Partial payment A ₹400 | DR 2010[A] 400.00 / CR 1010 400.00 → partially_paid, 240.00 remaining | `SettlementPaymentTest` |
| Refund the pot | DR 4010 423.73 · DR 2020 38.14 · DR 2030 38.13 / CR 2050 500.00; DR 2010[A] 400.00 / CR 5020 400.00; credit note CN-2026-27-000001; payout DR 2050 / CR 1020 500.00 (Razorpay) | `RefundReturnTest`, `GstLedgerTest` |
| End state | 2010[A] −160.00 (carried forward), 2010[B] 160.00, 2020 7.34, 2030 7.33, 4010 490.48, 1020 560.00; P&L revenue 545.33, direct costs 400.00, net income 145.33; trial balance balanced; balance sheet balanced; reconciliation: 0 findings, gate PASSED | `ReconciliationTest`, `ReportsTest` |

## 6. Tests

`tests/Feature/Accounting` — 87 tests / 673 assertions (sqlite `:memory:` running the REAL migrations + seeded chart of accounts): JournalService (11), OrderRecognition (8), PaymentEvents (6), VendorLedger (7), SettlementPayment (9), RefundReturn (15), VendorPayable (3), GstLedger (3), DeliveryAccounting (4), Inventory (4), Reconciliation (9), Reports (4), Security (3), OrderPivotRegression (1); plus `tests/Feature/Tax/GstConfigResolutionTest` (2). Full API Feature suite: **1060 tests / 5470 assertions green**. Admin `tsc --noEmit`: clean. `CodGatewayTest` (real MySQL) exercises every migration.

Coverage of the 50-case list: 1-21, 22-29 (GST), 30-34 (delivery), 35-39 (inventory), 40-44 (payments/reconciliation), 45-47 (security), 48-50 (reconciliation) — all present; 42 (refund paid), 43 (duplicate webhook), 44 (payment reconciliation) live in `PaymentEventsTest` / `RefundReturnTest` / `ReconciliationTest`.

## 7. Staging verification (P16)

_See §7 addendum below — filled from the live run._

## 8. Cutover plan (owner action)

1. Staging is migrated and the switch is ON there (`settings.options.accounting.enabled = true`, cutover 2026-09-14).
2. Production: deploy (migrations are additive; nothing is posted while the switch is OFF).
3. Settings → Accounting: set the **cutover date**; Reconciliation → Opening balances: post + confirm each vendor's balance (prefilled from the legacy `balances` table). Production currently has vendors with legacy balances only if `balances.current_balance ≠ 0`.
4. Flip **Accounting enabled** (strict ON). From then on the legacy `balances` credit stops, manual withdrawals are blocked, settlements run weekly.
5. Run `php artisan accounting:reconcile --gate` (also the Reconciliation screen banner). Production goes live for finance only when it prints **GATE PASSED**.

## 9. CA review checklist (all configurable, none is a code change)

- Principal vs agent recognition per vendor; GST liability under agent mode.
- Vendor payable basis (cost sheet) and commission rules.
- `tax.enforce_tax_verified` (default OFF): unverified products post at their configured rate; ON treats them as 0%.
- Delivery GST treatment (Settings → GST & Tax) and the vendor share of courier cost.
- Discounts of any funder are contra-revenue (4015); vendor-funded ones lower the payable.
- Opening balances go to 3030 Opening Balance Equity; gateway fees only when Razorpay reports them; Input GST (1060), TDS/TCS designed but not booked; inventory at weighted average; credit-note numbering per financial year.

## 10. Known limits / follow-ups

- Agent-mode vendors post as principal today with a note in the journal (rule variant designed, not switched on until the CA confirms the GST treatment).
- Razorpay settlement-to-bank and COD remittance are manual journals (DR 1010 / CR 1020 or 1030) until a settlement-file import exists.
- Purchase orders / goods receipts / vendor bills: tables + postings exist, no admin UI.
- Legacy `balances` readers on the vendor dashboard still show the legacy figure next to the new Finance page; retire after the cutover gate passes in production.
- The adversarial review workflow could verify only 1 of ~22 claims before the session limit; the remaining claims were triaged and fixed by hand (see commit `bf20787`). DB-portability of `ReconciliationEngine::checkGst` (driver-specific string concat) is exercised on staging MySQL by `accounting:reconcile`.
