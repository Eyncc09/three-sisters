# Three Sisters' Olshoppe — Management System

Online Beauty Products Management System with Data Visualization.
Built module-by-module (Rapid Build Stages). Current progress: **Stages 1–3 complete** (Foundation, Core Management, Sales/POS/Orders/Payments/Receipts).

> **If you already ran `database/seed.sql` before Stage 3**, also run
> `database/migrations/001_owner_pos_permission.sql` — it grants the Owner
> role the `pos.use` permission needed to operate the POS terminal
> (a fresh install using the current `seed.sql` already has this).

## Local Setup (XAMPP)

1. Copy this folder into `htdocs/three-sisters/` (or your web root).
2. Create the database and load the schema:
   ```
   mysql -u root -p -e "CREATE DATABASE three_sisters CHARACTER SET utf8mb4"
   mysql -u root -p three_sisters < database/schema.sql
   mysql -u root -p three_sisters < database/seed.sql
   ```
3. Visit `http://localhost/three-sisters/` in your browser.
4. Log in with any seeded account — **password for all demo accounts: `Passw0rd!`**

   | Role | Username |
   |---|---|
   | System Administrator | `admin` |
   | Owner | `owner` |
   | Staff/Cashier | `cashier1` or `cashier2` |
   | Distributor | `manila_beauty` |

If your MySQL uses a different host/user/password, set environment variables
`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` (see `config/database.php`)
instead of editing the file directly.

## What's implemented so far

**Stage 1 — Foundation**
- Secure session-based authentication (bcrypt, CSRF, session regeneration, idle timeout)
- Role-Based Access Control (Admin / Owner / Staff / Distributor) via `config/permissions.php` + `auth/middleware.php`
- Responsive Bootstrap 5 sidebar/topbar layout, beauty-shop design system (`assets/css/style.css`)
- Audit logging (`core/AuditLogger.php`)
- Role-specific dashboards

**Stage 2 — Core Management**
- Categories & Brands (owner-only catalog management)
- Products: add/edit/view, search, filter (category/brand/stock status), pagination, archive instead of delete
- Inventory: stock-in, manual adjustment (owner only), full movement history, transactional stock updates with row locking
- Low-stock monitoring: automatic `stock_alerts` (low/critical) recalculated on every stock movement
- Expiration monitoring: Safe / Expiring Soon / Expired, filterable by category and date range
- Customers: add/edit (owner), view/search (staff), profile page ready for purchase history once POS exists
- Resellers: full CRUD (owner only), profile page ready for order history

**Stage 3 — Sales, POS, Orders, Payments, Receipts**
- POS terminal (`pos/index.php`): product search/category filter, cart with stock-aware quantity controls, retail/reseller customer selection, discount, Cash/GCash/Bank Transfer payment (with change calculation / reference number / optional proof upload)
- Atomic checkout (`core/OrderService.php::completeSale()`): order + order_items + sale + payment + every inventory deduction happen in **one DB transaction** — any failure (insufficient stock, bad input, DB error) rolls back everything, no partial charge/deduction
- `InventoryService::recordMovement()` is now transaction-nesting-safe: it detects an already-open transaction and joins it instead of trying to open its own, so POS checkout and Stage 2's standalone stock-in/adjustment both work correctly through the same method
- Receipt (`pos/receipt.php`): printable (browser print) + downloadable JPEG (via html2canvas)
- Transaction History (`orders/index.php`) with search/date/customer-type/payment/status filters, role-scoped (staff see only their own sales); Order detail + status change (`orders/view.php`)
- Payment Verification queue (`payments/index.php`, owner-only) — verify/reject GCash/Bank Transfer payments; proof files are served only through the permission-gated `payments/proof.php` (never linked directly; `/uploads/.htaccess` blocks direct web access as defense-in-depth)
- Customer/Reseller profile pages now show real purchase history, order counts, and favorite products once sales exist

## Known limitations at this stage

- Discounts are transaction-level only (no per-item/promotion engine yet — that's Stage 4)
- If checkout fails validation server-side, the cart is not restored client-side (cashier re-adds items) — a minor UX gap, not a data-integrity one
- Order status changes (`orders/view.php`) are a simple dropdown with no workflow-transition rules yet (e.g. nothing stops "completed" → "pending")
- Distributor purchase orders, chat, TikTok Shop, Promotions, Analytics, Reports, Backup/Restore are scaffolded in the sidebar but not yet functional (later stages) — those links currently 404, which is expected.
- No automated test suite; testing is manual per the checklist in each stage's summary.
- Could not run a live PHP/MySQL server in this environment — all Stage 3 code was verified by static syntax/balance checks, manual line-by-line trace of the transaction logic, and an isolated simulation of the checkout arithmetic (stock/discount/change rules), not by executing real HTTP requests against MySQL. **Please do a live run-through on your XAMPP setup before considering Stage 3 verified end-to-end.**

## Basket Analysis / Frequent Item Mining (Stage 4D)

**What it is.** A plain statistics pass over real completed orders that finds
product pairs frequently bought together, using standard market-basket
association-rule metrics. It runs on demand from `analytics/basket.php`
("Run Basket Analysis") — nothing is scheduled or automatic.

**Why it is NOT AI.** There is no model, no training, no learned parameters,
and no external API call. The Python script (`python/basket_analysis.py`)
receives the exact list of completed orders' product IDs and computes three
well-known formulas — arithmetic, not inference:

- **Support(A,B)** = (transactions containing both A and B) ÷ (total completed transactions). *"How often the pair occurs together."*
- **Confidence(A→B)** = (transactions containing both) ÷ (transactions containing A). Directional — Confidence(A→B) ≠ Confidence(B→A) in general, so the UI always shows both.
- **Lift(A→B)** = Confidence(A→B) ÷ Support(B). Mathematically symmetric (Lift(A→B) = Lift(B→A)), so only one value is shown per pair. Lift > 1 means the pair occurs together more than random chance would predict; it says nothing about *why*, and the system never claims one product purchase causes the other.

**How bundle candidates are identified.** A fixed, visible rule — not a
trained classifier: `Strong Bundle Candidate` requires Lift > 2 and the
Owner's configured minimum confidence; `Potential Bundle` requires Lift > 1
and that same confidence floor; everything else is `Weak Association`. The
Owner sets Min Support / Min Confidence / Min Lift themselves in the UI —
there are no hidden thresholds.

**Data source and integration.** PHP (`core/BasketAnalysisService.php`)
queries `orders`/`order_items` for `status = 'completed'` orders only —
the exact same "completed" definition used everywhere else in the system,
so cancelled/pending orders are excluded automatically. It builds one
"basket" (a deduplicated list of product IDs) per order and sends that as
JSON over STDIN to the Python script; Python sends JSON results back over
STDOUT. No database driver in Python, no shell arguments carrying user
input (everything travels as JSON, not argv) — this keeps the integration
surface small and auditable.

**How inventory ties in.** Each bundle candidate shows both products'
current stock, fast/slow movement classification, expiration status, and
estimated stock duration — reusing `AnalyticsService::stockAnalysis()`
rather than recalculating any of that separately. A visible warning appears
if either product is critically low on stock or expired.

**Why the Owner decides.** Basket analysis never creates or activates a
promotion. Each bundle candidate has a "Review Bundle in Promotions" link
that pre-fills the product picker on `promotions/add.php` — the Owner still
chooses the discount type/value, dates, and whether to activate it.

**Documented limitation.** Pairwise combinations only (no 3+ item bundles),
capped at 100 returned pairs — a deliberate scope limit for explainability
and performance at capstone/demo catalog sizes, not a hidden constraint.

## Folder Structure

See `/config`, `/auth`, `/core` (business logic services), `/components` (shared UI),
`/products`, `/inventory`, `/customers`, `/resellers` for Stage 1–2 code.
`database/schema.sql` and `database/seed.sql` contain the full planned schema
(including tables for later stages) so future stages won't require restructuring.
