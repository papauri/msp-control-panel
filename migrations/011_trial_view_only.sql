-- View-only free trials (owner 2026-11-10). When on, while the business is on a free trial, every staff member of its HMS
-- can open and read everything but cannot add, change or delete anything (buttons greyed out, changes refused by the HMS).
-- Existing businesses start OFF, so a business already on a trial keeps working as before; new trials default to ON.
-- Additive.

ALTER TABLE cp_tenants
  ADD COLUMN trial_view_only TINYINT(1) NOT NULL DEFAULT 0;
