-- Business type and licensed modules per customer. Run once; scripts/migrate.php records it.
-- Existing rows get business_type 'full_hotel' and modules NULL (NULL means "the preset's modules").

ALTER TABLE cp_tenants
  ADD COLUMN business_type VARCHAR(40) NOT NULL DEFAULT 'full_hotel' AFTER name,
  ADD COLUMN modules TEXT NULL AFTER business_type;
