-- ============================================================
-- Three Sisters' Olshoppe — seed.sql
-- Demo/sample data for local development (fictional only, per spec 39)
-- Run AFTER schema.sql
-- ============================================================
--
-- IMPORTANT — demo login credentials:
-- All seeded users share the password: Passw0rd!
-- (bcrypt hash below verifies correctly with PHP's password_verify().)
-- Usernames: admin, owner, cashier1, cashier2, manila_beauty
-- Change these before any real/shared deployment.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------- Roles ----------
INSERT INTO roles (id, name, description) VALUES
(1, 'admin', 'System Administrator — technical/system management'),
(2, 'owner', 'Business Owner'),
(3, 'staff', 'Staff / Cashier — daily POS operations'),
(4, 'distributor', 'Distributor / Supplier portal');

-- ---------- Permissions (representative set — expanded in Phase 3) ----------
INSERT INTO permissions (code, module, description) VALUES
('users.manage', 'admin', 'Create/edit/deactivate user accounts'),
('system.settings', 'admin', 'Modify system configuration'),
('backup.manage', 'admin', 'Run backup and restore'),
('audit.view_all', 'admin', 'View full audit trail'),
('products.manage', 'products', 'Create/edit/archive products'),
('products.view', 'products', 'View product catalog'),
('inventory.adjust', 'inventory', 'Manually adjust stock'),
('inventory.receive', 'inventory', 'Record stock-in from deliveries'),
('pos.use', 'pos', 'Operate the POS terminal'),
('orders.manage', 'orders', 'Full order management'),
('orders.view_assigned', 'orders', 'View own/assigned orders'),
('payments.verify', 'payments', 'Verify or reject GCash/bank payments'),
('customers.manage', 'customers', 'Full customer management'),
('customers.view', 'customers', 'View customer records'),
('resellers.manage', 'resellers', 'Full reseller management'),
('promotions.manage', 'promotions', 'Create/edit promotions'),
('analytics.view', 'analytics', 'View analytics & basket analysis'),
('distributors.manage', 'distributors', 'Manage distributors & purchase orders'),
('distributors.portal', 'distributors', 'Distributor self-service portal access'),
('chat.use', 'chat', 'Use distributor chat'),
('tiktok.manage', 'tiktok', 'Manage TikTok Shop connection & sync'),
('reports.generate', 'reports', 'Generate reports'),
('reports.own_sales', 'reports', 'Generate own sales reports only');

-- ---------- Role → Permission mapping ----------
-- Admin: system/governance permissions only (not business transactions)
INSERT INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions WHERE code IN ('users.manage','system.settings','backup.manage','audit.view_all');

-- Owner: full business permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions WHERE code IN (
    'products.manage','products.view','inventory.adjust','orders.manage','payments.verify','pos.use',
    'customers.manage','resellers.manage','promotions.manage','analytics.view',
    'distributors.manage','chat.use','tiktok.manage','reports.generate','audit.view_all','system.settings'
);

-- Staff: POS-scoped
INSERT INTO role_permissions (role_id, permission_id)
SELECT 3, id FROM permissions WHERE code IN (
    'pos.use','products.view','orders.view_assigned','customers.view','inventory.receive','reports.own_sales'
);

-- Distributor: own portal only
INSERT INTO role_permissions (role_id, permission_id)
SELECT 4, id FROM permissions WHERE code IN ('distributors.portal','chat.use');

-- ---------- Distributors ----------
INSERT INTO distributors (id, name, contact_person, phone, email, address, lead_time_days, status) VALUES
(1, 'Manila Beauty Supply Co.', 'Rosa Fernandez', '0917-100-2001', 'sales@manilabeautysupply.ph', 'Quezon City, Metro Manila', 7, 'active'),
(2, 'GlowChem Trading', 'Bea Santos', '0917-100-2002', 'orders@glowchemtrading.ph', 'Cavite, Calabarzon', 14, 'active'),
(3, 'K-Cosmetics Direct PH', 'Miguel Uy', '0917-100-2003', 'hello@kcosmeticsdirect.ph', 'Makati, Metro Manila', 10, 'active');

-- ---------- Users ----------
-- One user per role for demo login. Replace password_hash before running (see note above).
INSERT INTO users (id, role_id, username, email, password_hash, full_name, phone, distributor_id, status) VALUES
(1, 1, 'admin',       'admin@threesistersolshoppe.ph',       '$2b$10$12QjT1E6Purlnnc.wtn3sep6YrD8c2B5JAE6gSD/tm1qzJHYQQR06', 'System Administrator', '0917-000-0001', NULL, 'active'),
(2, 2, 'owner',        'owner@threesistersolshoppe.ph',        '$2b$10$12QjT1E6Purlnnc.wtn3sep6YrD8c2B5JAE6gSD/tm1qzJHYQQR06', 'Aiza Villanueva (Owner)', '0917-000-0002', NULL, 'active'),
(3, 3, 'cashier1',     'cashier1@threesistersolshoppe.ph',     '$2b$10$12QjT1E6Purlnnc.wtn3sep6YrD8c2B5JAE6gSD/tm1qzJHYQQR06', 'Mika Reyes', '0917-000-0003', NULL, 'active'),
(4, 3, 'cashier2',     'cashier2@threesistersolshoppe.ph',     '$2b$10$12QjT1E6Purlnnc.wtn3sep6YrD8c2B5JAE6gSD/tm1qzJHYQQR06', 'Jhon Dela Cruz', '0917-000-0004', NULL, 'active'),
(5, 4, 'manila_beauty','distributor1@manilabeautysupply.ph',   '$2b$10$12QjT1E6Purlnnc.wtn3sep6YrD8c2B5JAE6gSD/tm1qzJHYQQR06', 'Rosa Fernandez', '0917-100-2001', 1, 'active');

-- ---------- Categories & Brands ----------
INSERT INTO categories (id, name, description) VALUES
(1, 'Skincare', 'Facial and body skincare products'),
(2, 'Makeup', 'Cosmetics and color products'),
(3, 'Hair Care', 'Shampoo, conditioner, treatments'),
(4, 'Fragrance', 'Perfumes and body mists'),
(5, 'Bath & Body', 'Soaps, lotions, body wash');

INSERT INTO brands (id, name) VALUES
(1, 'Belle Rose'), (2, 'Luminous'), (3, 'PureGlow'), (4, 'Cielo'), (5, 'Aroma Haus');

-- ---------- Products ----------
INSERT INTO products (id, sku, name, category_id, brand_id, description, cost_price, selling_price, reorder_level, expiration_date, primary_distributor_id, lead_time_days, status) VALUES
(1, 'SK-BR-001', 'Belle Rose Gentle Facial Wash 100ml', 1, 1, 'Sulfate-free facial cleanser for daily use', 85.00, 149.00, 20, '2027-06-30', 1, 7, 'active'),
(2, 'SK-BR-002', 'Belle Rose Hydrating Moisturizer 50ml', 1, 1, 'Lightweight daily moisturizer with hyaluronic acid', 120.00, 219.00, 15, '2027-04-15', 1, 7, 'active'),
(3, 'SK-LM-003', 'Luminous Vitamin C Serum 30ml', 1, 2, 'Brightening serum with 10% Vitamin C', 180.00, 349.00, 10, '2027-01-20', 2, 14, 'active'),
(4, 'MK-PG-004', 'PureGlow Matte Liquid Lipstick', 2, 3, 'Long-wear matte lipstick, various shades', 60.00, 129.00, 25, NULL, 3, 10, 'active'),
(5, 'MK-PG-005', 'PureGlow Everyday Compact Powder', 2, 3, 'Oil-control pressed powder', 95.00, 189.00, 20, '2026-12-01', 3, 10, 'active'),
(6, 'HC-CI-006', 'Cielo Repair Shampoo 250ml', 3, 4, 'Sulfate-free repair shampoo for damaged hair', 90.00, 169.00, 20, '2027-03-10', 1, 7, 'active'),
(7, 'HC-CI-007', 'Cielo Repair Conditioner 250ml', 3, 4, 'Pairs with Repair Shampoo', 90.00, 169.00, 20, '2027-03-10', 1, 7, 'active'),
(8, 'FR-AH-008', 'Aroma Haus Body Mist - Sakura Bloom 100ml', 4, 5, 'Light floral fragrance mist', 70.00, 139.00, 15, NULL, 2, 14, 'active'),
(9, 'BB-BR-009', 'Belle Rose Whipped Body Lotion 200ml', 5, 1, 'Rich moisturizing body lotion', 75.00, 149.00, 20, '2027-05-05', 1, 7, 'active'),
(10, 'SK-LM-010', 'Luminous Niacinamide + Zinc Serum 30ml', 1, 2, 'Oil-control and pore-refining serum', 150.00, 289.00, 12, '2026-11-20', 2, 14, 'active');

-- ---------- Inventory (current stock snapshot) ----------
INSERT INTO inventory (product_id, quantity_on_hand) VALUES
(1, 48), (2, 32), (3, 9), (4, 60), (5, 41), (6, 27), (7, 25), (8, 18), (9, 35), (10, 6);

-- ---------- Stock movements (initial stock-in, matching inventory above) ----------
INSERT INTO stock_movements (product_id, movement_type, quantity, reason, reference_type, performed_by) VALUES
(1, 'stock_in', 60, 'Initial stock load', 'seed', 2),
(1, 'sale', -12, 'Seed demo sales', 'seed', 3),
(2, 'stock_in', 40, 'Initial stock load', 'seed', 2),
(2, 'sale', -8, 'Seed demo sales', 'seed', 3),
(3, 'stock_in', 20, 'Initial stock load', 'seed', 2),
(3, 'sale', -11, 'Seed demo sales', 'seed', 4),
(10, 'stock_in', 15, 'Initial stock load', 'seed', 2),
(10, 'sale', -9, 'Seed demo sales', 'seed', 4);

-- ---------- Stock alert example (low stock, matches product 10 below reorder level of 12) ----------
INSERT INTO stock_alerts (product_id, alert_type, message, status) VALUES
(10, 'low_stock', 'Luminous Niacinamide + Zinc Serum is below reorder level (6 of 12).', 'active'),
(3, 'low_stock', 'Luminous Vitamin C Serum is below reorder level (9 of 10).', 'active');

-- ---------- Customers ----------
INSERT INTO customers (id, full_name, phone, email) VALUES
(1, 'Grace Panganiban', '0917-200-1001', NULL),
(2, 'Liza Domingo', '0917-200-1002', 'liza.domingo@example.com'),
(3, 'Carla Mendoza', '0917-200-1003', NULL);

-- ---------- Resellers ----------
INSERT INTO resellers (id, full_name, business_name, phone, email, registration_date, status) VALUES
(1, 'Trisha Aquino', 'Trisha Beauty Corner', '0917-300-1001', 'trisha.corner@example.com', '2025-11-05', 'active'),
(2, 'Nico Ramos', 'Glow Up PH Resell', '0917-300-1002', NULL, '2026-02-14', 'active');

-- ---------- Sample Orders / Sales / Payments ----------
-- Order 1: physical retail sale, cash
INSERT INTO orders (id, order_number, channel, customer_id, status, subtotal, discount_amount, total_amount, placed_by) VALUES
(1, 'ORD-2026-00001', 'physical', 1, 'completed', 298.00, 0.00, 298.00, 3);
INSERT INTO order_items (order_id, product_id, quantity, unit_price, discount, line_total) VALUES
(1, 1, 2, 149.00, 0.00, 298.00);
INSERT INTO sales (order_id, sale_number, cashier_id, customer_type, status) VALUES
(1, 'SALE-2026-00001', 3, 'retail', 'completed');
INSERT INTO payments (order_id, payment_method, amount, status, verified_by, verified_at) VALUES
(1, 'cash', 298.00, 'verified', 3, NOW());

-- Order 2: physical retail sale, GCash pending verification
INSERT INTO orders (id, order_number, channel, customer_id, status, subtotal, discount_amount, total_amount, placed_by) VALUES
(2, 'ORD-2026-00002', 'physical', 2, 'completed', 349.00, 0.00, 349.00, 4);
INSERT INTO order_items (order_id, product_id, quantity, unit_price, discount, line_total) VALUES
(2, 3, 1, 349.00, 0.00, 349.00);
INSERT INTO sales (order_id, sale_number, cashier_id, customer_type, status) VALUES
(2, 'SALE-2026-00002', 4, 'retail', 'completed');
INSERT INTO payments (order_id, payment_method, amount, reference_number, status) VALUES
(2, 'gcash', 349.00, 'GC-88213456', 'pending');

-- Order 3: reseller order (bulk)
INSERT INTO orders (id, order_number, channel, reseller_id, status, subtotal, discount_amount, total_amount, placed_by) VALUES
(3, 'ORD-2026-00003', 'reseller', 1, 'completed', 1290.00, 100.00, 1190.00, 2);
INSERT INTO order_items (order_id, product_id, quantity, unit_price, discount, line_total) VALUES
(3, 4, 10, 129.00, 100.00, 1190.00);
INSERT INTO payments (order_id, payment_method, amount, reference_number, status, verified_by, verified_at) VALUES
(3, 'bank_transfer', 1190.00, 'BPI-77621039', 'verified', 2, NOW());

-- ---------- Promotion example ----------
INSERT INTO promotions (id, name, description, discount_type, discount_value, start_date, end_date, status, created_by) VALUES
(1, 'Skincare Duo Bundle Promo', 'Facial Wash + Moisturizer bundle discount', 'percentage', 10.00, '2026-08-01', '2026-08-31', 'active', 2);
INSERT INTO promotion_items (promotion_id, product_id) VALUES (1, 1), (1, 2);

-- ---------- Purchase order example ----------
INSERT INTO purchase_orders (id, po_number, distributor_id, status, expected_delivery_date, total_cost, created_by) VALUES
(1, 'PO-2026-00125', 1, 'shipped', '2026-08-24', 4500.00, 2);
INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_cost) VALUES
(1, 1, 30, 85.00), (1, 2, 20, 120.00);
INSERT INTO delivery_updates (purchase_order_id, status, note, updated_by) VALUES
(1, 'shipped', 'Package left Manila Beauty Supply warehouse.', 5);

-- ---------- System settings ----------
INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES
('business_name', 'Three Sisters\' Olshoppe', 1),
('business_address', 'Dasmariñas, Cavite, Philippines', 1),
('low_stock_default_threshold_days', '3', 1),
('expiration_alert_window_days', '30', 1);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- End of seed.sql
-- ============================================================
