-- ============================================================
-- Migration: 003_owner_audit_settings_permissions.sql
-- Run this ONLY if you already ran database/seed.sql before this audit pass.
-- (A fresh install using the current seed.sql already has this.)
--
-- Why: components/sidebar.php has always linked Owner to Audit Trail and
-- Settings, but seed.sql never granted Owner either permission — those
-- links 404/403'd. Owner's audit VIEW is scoped away from system-only
-- actions (admin/backup module) at the UI query level in audit/index.php,
-- not via a separate permission, since the underlying audit_logs table
-- doesn't structurally separate "business" vs "system" rows.
-- ============================================================

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions WHERE code IN ('audit.view_all', 'system.settings');
