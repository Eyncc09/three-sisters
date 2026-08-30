-- ============================================================
-- Three Sisters' Olshoppe
-- Online Beauty Products Management System with Data Visualization
-- PHASE 1 — Database Schema (MySQL / MariaDB, InnoDB, utf8mb4)
-- ============================================================
-- Design notes (read before implementing Phase 2+):
--
-- 1. `orders` is the central transactional hub. POS sales, reseller
--    orders, and TikTok orders all resolve into one `orders` row
--    (see `channel`), with `order_items` as the single source of
--    truth for line items. This avoids duplicating line-item data
--    across sales/reseller/tiktok tables, per spec section 8
--    ("do not blindly create every table... normalize appropriately").
--
-- 2. `sales` is a thin extension of `orders` for POS-specific
--    receipt data (cashier, payment method label, receipt number).
--    We intentionally did NOT create a separate `sale_items` table —
--    `order_items` already covers it via `sales.order_id`.
--
-- 3. `inventory` stores a maintained running total per product for
--    fast dashboard reads. The source of truth for *how* stock
--    changed is always `stock_movements` — `inventory.quantity_on_hand`
--    must only ever be modified inside the same DB transaction that
--    inserts a `stock_movements` row (enforced in InventoryService,
--    not at the DB level, since MySQL triggers would hide business
--    logic from the PHP layer we need to explain at defense).
--
-- 4. `promo_performance` is a STORED table (per approved decision),
--    snapshotted for the periods before/during/after a promotion,
--    so comparisons remain valid after a promo ends and its live
--    computed numbers would otherwise change.
--
-- 5. `analysis_runs` tracks each manual "Run Analysis" click from
--    the Owner. `frequent_itemsets` and `association_rules` both
--    reference it, so results are grouped by run and old runs are
--    never silently overwritten.
--
-- 6. TikTok integration: `tiktok_orders` stores the raw imported
--    payload (manual import today, official API later) and maps to
--    `orders` once processed — see `docs/tiktok-integration.md`.
--
-- 7. All money columns use DECIMAL(10,2) — never FLOAT — to avoid
--    rounding errors in financial totals.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ============================================================
-- SECTION A: IDENTITY & ACCESS CONTROL
-- ============================================================

CREATE TABLE roles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(50) NOT NULL UNIQUE,   -- admin, owner, staff, distributor
    description     VARCHAR(255) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE permissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(100) NOT NULL UNIQUE,  -- e.g. 'products.create', 'pos.use'
    module          VARCHAR(50) NOT NULL,
    description     VARCHAR(255) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE role_permissions (
    role_id         INT UNSIGNED NOT NULL,
    permission_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id         INT UNSIGNED NOT NULL,
    username        VARCHAR(50) NOT NULL UNIQUE,
    email           VARCHAR(150) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    full_name       VARCHAR(150) NOT NULL,
    phone           VARCHAR(30) NULL,
    distributor_id  INT UNSIGNED NULL,              -- set only when role = distributor
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    last_login_at   DATETIME NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id),
    INDEX idx_users_role (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SECTION B: DISTRIBUTORS (created early — referenced by products)
-- ============================================================

CREATE TABLE distributors (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    contact_person  VARCHAR(150) NULL,
    phone           VARCHAR(30) NULL,
    email           VARCHAR(150) NULL,
    address         VARCHAR(255) NULL,
    lead_time_days  SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE users
    ADD CONSTRAINT fk_users_distributor FOREIGN KEY (distributor_id) REFERENCES distributors(id) ON DELETE SET NULL;

-- ============================================================
-- SECTION C: CATALOG
-- ============================================================

CREATE TABLE categories (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL UNIQUE,
    description     VARCHAR(255) NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE brands (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL UNIQUE,
    description     VARCHAR(255) NULL,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE products (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku                     VARCHAR(50) NOT NULL UNIQUE,
    name                    VARCHAR(150) NOT NULL,
    category_id             INT UNSIGNED NULL,
    brand_id                INT UNSIGNED NULL,
    description             TEXT NULL,
    cost_price              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    selling_price           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    reorder_level           INT UNSIGNED NOT NULL DEFAULT 10,
    expiration_date         DATE NULL,
    primary_distributor_id  INT UNSIGNED NULL,
    lead_time_days          SMALLINT UNSIGNED NULL,      -- overrides distributor default if set
    image_path              VARCHAR(255) NULL,
    status                  ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
    FOREIGN KEY (primary_distributor_id) REFERENCES distributors(id) ON DELETE SET NULL,
    INDEX idx_products_category (category_id),
    INDEX idx_products_brand (brand_id),
    INDEX idx_products_status (status),
    INDEX idx_products_expiration (expiration_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Note: products are never hard-deleted (spec 9) — `status = 'archived'`
-- preserves referential integrity for historical order/sale records.

-- ============================================================
-- SECTION D: INVENTORY
-- ============================================================

CREATE TABLE inventory (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id          INT UNSIGNED NOT NULL UNIQUE,
    quantity_on_hand    INT NOT NULL DEFAULT 0,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_movements (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id      INT UNSIGNED NOT NULL,
    movement_type   ENUM('stock_in','sale','reseller_order','tiktok_order','return','damaged','expired','adjustment') NOT NULL,
    quantity        INT NOT NULL,                 -- positive = increase, negative = decrease
    reason          VARCHAR(255) NULL,
    reference_type  VARCHAR(50) NULL,              -- 'order','purchase_order','return', etc.
    reference_id    BIGINT UNSIGNED NULL,
    performed_by    INT UNSIGNED NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_stock_movements_product (product_id),
    INDEX idx_stock_movements_type (movement_type),
    INDEX idx_stock_movements_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_alerts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id      INT UNSIGNED NOT NULL,
    alert_type      ENUM('low_stock','critical_stock','expiring_soon','expired') NOT NULL,
    message         VARCHAR(255) NOT NULL,
    status          ENUM('active','resolved') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at     DATETIME NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_stock_alerts_status (status),
    INDEX idx_stock_alerts_type (alert_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SECTION E: PEOPLE (CUSTOMERS & RESELLERS)
-- ============================================================

CREATE TABLE customers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(150) NOT NULL,
    phone           VARCHAR(30) NULL,
    email           VARCHAR(150) NULL,
    address         VARCHAR(255) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customers_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE resellers (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name           VARCHAR(150) NOT NULL,
    business_name       VARCHAR(150) NULL,
    phone               VARCHAR(30) NULL,
    email               VARCHAR(150) NULL,
    address             VARCHAR(255) NULL,
    registration_date   DATE NOT NULL,
    status              ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_resellers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SECTION F: ORDERS (central hub) & TRANSACTIONS
-- ============================================================

CREATE TABLE orders (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number    VARCHAR(30) NOT NULL UNIQUE,
    channel         ENUM('physical','reseller','tiktok') NOT NULL,
    customer_id     INT UNSIGNED NULL,
    reseller_id     INT UNSIGNED NULL,
    status          ENUM('pending','confirmed','preparing','ready','shipped','completed','cancelled','returned') NOT NULL DEFAULT 'pending',
    subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    placed_by       INT UNSIGNED NULL,          -- staff/owner who created it (null for raw TikTok import)
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE SET NULL,
    FOREIGN KEY (placed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_orders_channel (channel),
    INDEX idx_orders_status (status),
    INDEX idx_orders_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_items (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        BIGINT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    quantity        INT UNSIGNED NOT NULL,
    unit_price      DECIMAL(10,2) NOT NULL,
    discount        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    line_total      DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_order_items_order (order_id),
    INDEX idx_order_items_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sales (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        BIGINT UNSIGNED NOT NULL UNIQUE,
    sale_number     VARCHAR(30) NOT NULL UNIQUE,       -- printed on receipt
    cashier_id      INT UNSIGNED NOT NULL,
    customer_type   ENUM('retail','reseller') NOT NULL,
    status          ENUM('completed','voided') NOT NULL DEFAULT 'completed',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (cashier_id) REFERENCES users(id),
    INDEX idx_sales_cashier (cashier_id),
    INDEX idx_sales_customer_type (customer_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payments (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id            BIGINT UNSIGNED NOT NULL,
    payment_method      ENUM('cash','gcash','bank_transfer') NOT NULL,
    amount              DECIMAL(10,2) NOT NULL,
    reference_number    VARCHAR(100) NULL,
    status              ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    verified_by         INT UNSIGNED NULL,
    verified_at         DATETIME NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_payments_status (status),
    INDEX idx_payments_method (payment_method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Cash payments are auto-verified at insert time by SalesService;
-- GCash/Bank Transfer default to 'pending' until Owner verifies (spec 15).

CREATE TABLE payment_proofs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id      BIGINT UNSIGNED NOT NULL,
    file_path       VARCHAR(255) NOT NULL,
    mime_type       VARCHAR(100) NOT NULL,
    file_size       INT UNSIGNED NOT NULL,
    uploaded_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE returns (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        BIGINT UNSIGNED NOT NULL,
    processed_by    INT UNSIGNED NOT NULL,
    reason          VARCHAR(255) NOT NULL,
    status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    refund_amount   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (processed_by) REFERENCES users(id),
    INDEX idx_returns_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE return_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_id       INT UNSIGNED NOT NULL,
    order_item_id   BIGINT UNSIGNED NOT NULL,
    quantity        INT UNSIGNED NOT NULL,
    item_condition  ENUM('resellable','damaged','expired') NOT NULL,
    FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
    FOREIGN KEY (order_item_id) REFERENCES order_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SECTION G: PROMOTIONS
-- ============================================================

CREATE TABLE promotions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    description     VARCHAR(255) NULL,
    discount_type   ENUM('percentage','fixed') NOT NULL,
    discount_value  DECIMAL(10,2) NOT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    status          ENUM('scheduled','active','inactive','ended') NOT NULL DEFAULT 'scheduled',
    created_by      INT UNSIGNED NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_promotions_status (status),
    INDEX idx_promotions_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE promotion_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id    INT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    UNIQUE KEY uq_promo_product (promotion_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE promo_performance (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id        INT UNSIGNED NOT NULL,
    period              ENUM('before','during','after') NOT NULL,
    period_start        DATE NOT NULL,
    period_end          DATE NOT NULL,
    transactions_count  INT UNSIGNED NOT NULL DEFAULT 0,
    units_sold          INT UNSIGNED NOT NULL DEFAULT 0,
    revenue             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_given      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    avg_basket_size     DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    recorded_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    UNIQUE KEY uq_promo_period (promotion_id, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SECTION H: BASKET ANALYSIS / FREQUENT ITEM MINING (Python-fed)
-- ============================================================

CREATE TABLE analysis_runs (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_by                  INT UNSIGNED NOT NULL,
    transaction_window_start DATE NOT NULL,
    transaction_window_end   DATE NOT NULL,
    min_support             DECIMAL(5,4) NOT NULL DEFAULT 0.0100,
    min_confidence          DECIMAL(5,4) NOT NULL DEFAULT 0.3000,
    transactions_analyzed   INT UNSIGNED NOT NULL DEFAULT 0,
    status                  ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at            DATETIME NULL,
    FOREIGN KEY (run_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE frequent_itemsets (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    analysis_run_id     INT UNSIGNED NOT NULL,
    product_ids         JSON NOT NULL,          -- e.g. [12, 47]
    support             DECIMAL(6,5) NOT NULL,
    transaction_count   INT UNSIGNED NOT NULL,
    FOREIGN KEY (analysis_run_id) REFERENCES analysis_runs(id) ON DELETE CASCADE,
    INDEX idx_itemsets_run (analysis_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE association_rules (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    analysis_run_id     INT UNSIGNED NOT NULL,
    antecedent          JSON NOT NULL,          -- product_ids
    consequent          JSON NOT NULL,          -- product_ids
    support             DECIMAL(6,5) NOT NULL,
    confidence          DECIMAL(6,5) NOT NULL,
    lift                DECIMAL(8,4) NOT NULL,
    FOREIGN KEY (analysis_run_id) REFERENCES analysis_runs(id) ON DELETE CASCADE,
    INDEX idx_rules_run (analysis_run_id),
    INDEX idx_rules_lift (lift)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE bundle_recommendations (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    association_rule_id     BIGINT UNSIGNED NOT NULL,
    product_ids              JSON NOT NULL,
    suggested_bundle_price   DECIMAL(10,2) NULL,
    status                   ENUM('pending','approved','rejected','converted_to_promo') NOT NULL DEFAULT 'pending',
    resulting_promotion_id   INT UNSIGNED NULL,
    reviewed_by              INT UNSIGNED NULL,
    reviewed_at              DATETIME NULL,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (association_rule_id) REFERENCES association_rules(id) ON DELETE CASCADE,
    FOREIGN KEY (resulting_promotion_id) REFERENCES promotions(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_bundle_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SECTION I: DISTRIBUTORS — PURCHASE ORDERS
-- ============================================================

CREATE TABLE purchase_orders (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    po_number               VARCHAR(30) NOT NULL UNIQUE,
    distributor_id          INT UNSIGNED NOT NULL,
    status                  ENUM('requested','confirmed','preparing','shipped','delivered','cancelled') NOT NULL DEFAULT 'requested',
    expected_delivery_date  DATE NULL,
    total_cost              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_by              INT UNSIGNED NOT NULL,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (distributor_id) REFERENCES distributors(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_po_status (status),
    INDEX idx_po_distributor (distributor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_order_items (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id   INT UNSIGNED NOT NULL,
    product_id          INT UNSIGNED NOT NULL,
    quantity             INT UNSIGNED NOT NULL,
    unit_cost            DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE delivery_updates (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id   INT UNSIGNED NOT NULL,
    status              VARCHAR(50) NOT NULL,
    note                VARCHAR(255) NULL,
    updated_by          INT UNSIGNED NULL,        -- distributor user, or owner
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SECTION J: DISTRIBUTOR CHAT
-- ============================================================

CREATE TABLE chat_conversations (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    distributor_id      INT UNSIGNED NOT NULL,
    owner_id            INT UNSIGNED NOT NULL,       -- the owner-side user
    purchase_order_id   INT UNSIGNED NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (distributor_id) REFERENCES distributors(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id),
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE chat_messages (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id     INT UNSIGNED NOT NULL,
    sender_id           INT UNSIGNED NOT NULL,
    message             TEXT NOT NULL,
    is_read             TINYINT(1) NOT NULL DEFAULT 0,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id),
    INDEX idx_chat_conversation (conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SECTION K: TIKTOK SHOP INTEGRATION
-- ============================================================

CREATE TABLE tiktok_connections (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id             VARCHAR(100) NULL,
    access_token        TEXT NULL,
    refresh_token       TEXT NULL,
    token_expires_at    DATETIME NULL,
    status              ENUM('not_connected','connected','disconnected','expired') NOT NULL DEFAULT 'not_connected',
    connected_by        INT UNSIGNED NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (connected_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Tokens are placeholders in the architecture; encrypted-at-rest handling
-- is added only once real API credentials exist (see docs/tiktok-integration.md).

CREATE TABLE tiktok_orders (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tiktok_order_id     VARCHAR(100) NOT NULL UNIQUE,
    order_id            BIGINT UNSIGNED NULL,          -- set once mapped into `orders`
    raw_payload         JSON NOT NULL,
    sync_status         ENUM('imported','mapped','error') NOT NULL DEFAULT 'imported',
    error_message       VARCHAR(255) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    INDEX idx_tiktok_orders_status (sync_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tiktok_order_items (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tiktok_order_id     BIGINT UNSIGNED NOT NULL,
    sku                 VARCHAR(50) NOT NULL,
    product_id          INT UNSIGNED NULL,             -- resolved via SKU match; null if unmatched
    quantity            INT UNSIGNED NOT NULL,
    price               DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (tiktok_order_id) REFERENCES tiktok_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tiktok_sync_logs (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sync_type           ENUM('manual_import','api') NOT NULL,
    status               ENUM('success','failed','partial') NOT NULL,
    records_imported     INT UNSIGNED NOT NULL DEFAULT 0,
    records_failed       INT UNSIGNED NOT NULL DEFAULT 0,
    error_message        VARCHAR(255) NULL,
    run_by                INT UNSIGNED NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (run_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SECTION L: NOTIFICATIONS
-- ============================================================

CREATE TABLE notifications (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NULL,          -- specific recipient
    role_id         INT UNSIGNED NULL,          -- OR broadcast to a role
    type            ENUM('low_stock','expiration','payment','distributor','promo','system') NOT NULL,
    title           VARCHAR(150) NOT NULL,
    message         VARCHAR(255) NOT NULL,
    related_type    VARCHAR(50) NULL,
    related_id      BIGINT UNSIGNED NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    INDEX idx_notifications_user (user_id, is_read),
    INDEX idx_notifications_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SECTION M: GOVERNANCE — AUDIT, SETTINGS, BACKUP
-- ============================================================

CREATE TABLE audit_logs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NULL,
    action          VARCHAR(100) NOT NULL,      -- e.g. 'product.created', 'sale.completed'
    module          VARCHAR(50) NOT NULL,
    related_type    VARCHAR(50) NULL,
    related_id      BIGINT UNSIGNED NULL,
    details         TEXT NULL,                  -- JSON-encoded before/after or context
    ip_address      VARCHAR(45) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_module (module),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE system_settings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key     VARCHAR(100) NOT NULL UNIQUE,
    setting_value   TEXT NULL,
    updated_by      INT UNSIGNED NULL,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE backup_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename        VARCHAR(150) NOT NULL,
    initiated_by    INT UNSIGNED NOT NULL,
    status          ENUM('success','failed') NOT NULL,
    file_size       BIGINT UNSIGNED NULL,
    error_message   VARCHAR(255) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (initiated_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- End of schema.sql — 30 tables total.
-- Next: seed.sql provides roles, permissions, an admin/owner/staff/
-- distributor user each, sample categories/brands/products,
-- sample orders/sales/payments, and system_settings defaults.
-- ============================================================
