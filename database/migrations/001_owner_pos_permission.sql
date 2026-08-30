-- ============================================================
-- Migration: 001_owner_pos_permission.sql
-- Run this ONLY if you already ran database/seed.sql before Stage 3.
-- (A fresh install using the updated seed.sql already has this.)
--
-- Why: the Owner role matrix always intended POS access (spec section 5 —
-- "POS monitoring"), and in practice an owner running the shop solo also
-- needs to operate checkout, not just view history. Stage 1/2 seed data
-- only granted 'pos.use' to Staff — this adds it for Owner too.
-- ============================================================

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions WHERE code = 'pos.use';
