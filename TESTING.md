# SmartBils — Final Smoke Test Checklist

Run through this before go-live. Everything that fails blocks release.

## 1. Auth & Access

- [ ] Visiting a protected page while logged out redirects to the right login (shop → `/`, admin → `/admin/`)
- [ ] Admin creds in the shop login box fail; shop creds in the admin login box fail
- [ ] 6 failed logins → "Too many failed attempts" (throttle); clears after 15 min or `TRUNCATE login_attempts`
- [ ] Logging out destroys the session cookie and redirects to the right login
- [ ] Session idle beyond 2h → re-prompted for login
- [ ] Avatar menu → Change password → old password required, new ≥ 8 chars, must differ

## 2. Shop isolation

- [ ] As Shop A owner, open `/client/customers/view.php?id=<ID of Shop B's customer>` → **404**
- [ ] Same for products, suppliers, purchases, sales, payments, returns, expenses — all 404
- [ ] Shop A owner can't reach `/admin/` → **403**

## 3. Super Admin

- [ ] Add shop → owner credentials created in `shop_users` with email mirrored → owner can log in with mobile email **or** username
- [ ] Suspend / Deactivate / Activate changes `shops.status`; data preserved
- [ ] Plans CRUD; usage count updates
- [ ] Licence renewal preserves prepaid time (`max(today, current expiry)`)
- [ ] Reset password → temp password shown once, user logs in with it
- [ ] Dashboard revenue reflects every `license_renewals` row

## 4. Products & stock

- [ ] Add product with opening stock → stock_movements has an `opening` row with the opening qty
- [ ] Adjust stock with reason → stock_movements + stock_adjustments both written
- [ ] Try negative adjustment that would go below 0 → rejected
- [ ] Low-stock / out-of-stock filters work in the Products list and Reports → Stock
- [ ] Delete a product with history → blocked ("mark unavailable instead")

## 5. Purchases

- [ ] Save as draft → no stock change
- [ ] Finalize draft → stock rises exactly once; `products.purchase_cost` updated to the invoiced cost
- [ ] Reverse received → stock drops back; `stock_movements(type=adjustment, ref_type=purchase_reversal)` added
- [ ] Browser Back + resubmit the same purchase form → redirected to existing (idempotency via `txn_token`)
- [ ] Supplier balance = SUM(received totals) − SUM(payments)

## 6. POS billing

- [ ] Mobile first — type → `Enter` jumps to Name
- [ ] Existing customer auto-fills name; new customer requires name entered
- [ ] Product search returns matches for 1 letter, case-insensitive
- [ ] Clicking a suggestion adds to cart and focuses Qty
- [ ] Over-stock qty is highlighted; server also rejects on confirm
- [ ] Confirm saves atomically — invoice + items + stock movement + payment + allocation
- [ ] `invoice_no` is unique per shop, `INV-YYYYMMDD-{id}` format
- [ ] Browser Back + resubmit → redirected to existing invoice (idempotency)
- [ ] A4 / 80 mm / 58 mm receipts render; Print works
- [ ] WhatsApp / SMS buttons open the right scheme with the message pre-filled

## 7. Payments & dues

- [ ] UPI payment at POS = `pending` status; cash = `verified`
- [ ] Admin / owner can Verify → invoice status flips to paid; Fail / Reverse too
- [ ] **Collect Outstanding Payment**:
  - Picks customer by mobile only
  - Lists only invoices with pending amount
  - Rejects over-collection per invoice
  - Idempotency token prevents duplicate collection
  - **Never** creates a sale, **never** touches stock
  - Separate payment receipt references the specific invoice(s)

## 8. Returns & refunds

- [ ] Returnable qty = sold − previously returned; over-return rejected server-side
- [ ] Return amount uses the original line snapshot (`line_total / qty × returned_qty`)
- [ ] `sellable` adds stock; `damaged` / `unavailable` doesn't
- [ ] Credit reduces outstanding; cash refund recorded in `refunds`
- [ ] Invoice status recomputes correctly (`returned` when fully returned)
- [ ] Idempotency on re-submit

## 9. Reports

- [ ] Sales: daily chart + CSV + totals match a hand-count
- [ ] Products: units × line_total aggregates correctly, CSV
- [ ] Dues: totals equal customer billed − verified allocated
- [ ] Profit: Gross − Returns = Net sales; Net − COGS = Gross profit; Gross profit − Expenses = Net profit; costing method shown
- [ ] Stock: value at cost total matches SUM(current_stock × purchase_cost)

## 10. Security

- [ ] `/config/`, `/includes/`, `/storage/`, `/database/` return 403 via `.htaccess`
- [ ] `.php` file dropped in `/uploads/shops/` does not execute
- [ ] Every form submits via POST and carries `_csrf`; bad/missing token → 419
- [ ] Dynamic values in HTML are `e()`-escaped (grep for raw `<?= $var ?>` without `e()`)
- [ ] All DB params use PDO placeholders (no string concat into SQL)
- [ ] Response headers include X-Content-Type-Options / X-Frame-Options / Referrer-Policy
- [ ] Password hashes are bcrypt (`$2y$10$…`) in `admins` and `shop_users`
- [ ] Audit entries exist for login, product/stock, purchase/finalize/cancel/reverse, sale create, payment collect/verify/fail/reverse, return create, backup download/save/restore

## 11. Backup / Restore

- [ ] Admin → Backups → **Download** → .sql opens in a text editor with CREATE TABLE + INSERT lines
- [ ] **Save on server** → file in `storage/backups/` with timestamp
- [ ] Delete a known row (e.g. the latest expense) → **Restore** the saved .sql with `RESTORE` confirm → the row is back
- [ ] Audit log shows `backup.download`, `backup.save`, `backup.restore`

## 12. Concurrency / money / stock

- [ ] Two tabs → both POS Confirm the same `txn_token` → one succeeds, the other redirects to it
- [ ] Two tabs → both try to collect ₹X on an invoice with ₹X outstanding → one succeeds, the other sees "exceeds outstanding"
- [ ] Row locks (`SELECT … FOR UPDATE`) are present on `products` during POS and purchase finalize

## 13. Print layout

- [ ] A4 receipt fits one page with logo + table + totals
- [ ] 80 mm / 58 mm receipt renders at the right width (preview or actual thermal)
- [ ] Share bar hidden on print; `@media print` leaves only the sheet

## 14. Mobile responsiveness

- [ ] Sidebar collapses to drawer < 992px; brand image visible
- [ ] POS, dashboard, settings all usable on 360-wide screens
- [ ] Share buttons visible on mobile; native share works

## 15. Cleanup

- [ ] `setup.php` deleted (or storage/installed.lock in place)
- [ ] `db-test.php` deleted
- [ ] `config/database.php` not in git; placeholder committed instead
- [ ] All default admin passwords changed
