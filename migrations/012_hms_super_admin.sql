-- The hotel's Super Admin (owner 2026-11-11; hotel migration 080, admin_users.is_super_admin, role stays 'admin').
-- The HMS now flags each super admin in its staff report (super_admin 0|1). The panel shows them and refuses every change
-- to them (only another super admin can change them, on the hotel's Users page). Rows stored before this are 0. Additive.
-- Apply BEFORE the panel code that writes this column goes live, or every staff report from the hotels is refused.

ALTER TABLE cp_hms_staff
  ADD COLUMN is_super_admin TINYINT(1) NOT NULL DEFAULT 0;
