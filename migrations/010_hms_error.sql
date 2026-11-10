-- Why the HMS's staff step last stopped (owner 2026-11-10), as the HMS reports it in its check-in (staff_error).
-- Empty when the last run finished. Shown on the HMS staff page so a problem on a hotel's server is visible here. Additive.

ALTER TABLE cp_tenants
  ADD COLUMN hms_error VARCHAR(255) NULL,
  ADD COLUMN hms_error_at DATETIME NULL;
