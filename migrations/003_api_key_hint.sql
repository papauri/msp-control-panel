-- Last 4 characters of each business's licence key (API key), so the owner can match it with the key in that
-- business's settings file. Four characters are useless on their own; the full key is still only stored as a hash.
ALTER TABLE cp_tenants
  ADD COLUMN api_key_hint VARCHAR(8) NULL AFTER api_key_hash;
