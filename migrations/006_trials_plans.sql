-- Free trials and subscription plans (owner 2026-11-09). Additive.

ALTER TABLE cp_tenants
  ADD COLUMN trial_until DATE NULL AFTER paid_until,
  ADD COLUMN trial_started DATE NULL AFTER trial_until,
  ADD COLUMN trial_converted_at DATETIME NULL AFTER trial_started;

ALTER TABLE cp_catalog_items
  ADD COLUMN billing_interval TINYINT UNSIGNED NULL AFTER unit;
