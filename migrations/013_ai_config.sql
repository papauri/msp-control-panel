-- The Assistant (AI) package per business, managed from the panel (owner 2026-11-11). Additive.
-- cp_ai_config: one row per business. The provider key is SEALED (AES-256-GCM under keys_derive('cp-ai-v1'), so a copy of
-- the database alone cannot read it); only its last 4 characters are kept in clear. The panel tests the key and every chosen
-- model itself before anything can be sent to the hotel (test_hash = what was tested). Deploying queues one cp_hms_commands
-- row of kind 'ai_setup' (deploy_command_id); the hotel applies it once, and its own admins may change it afterwards.
-- hms_state: what the hotel last reported about its Assistant (provider, last 4, models, on/off, this month's usage). Never a key.

CREATE TABLE IF NOT EXISTS cp_ai_config (
  tenant_id INT UNSIGNED NOT NULL,
  provider VARCHAR(20) NOT NULL DEFAULT '',
  key_sealed TEXT NULL,
  key_last4 VARCHAR(8) NULL,
  key_payer VARCHAR(10) NOT NULL DEFAULT 'msp',
  model_primary VARCHAR(120) NOT NULL DEFAULT '',
  model_fast VARCHAR(120) NOT NULL DEFAULT '',
  model_fallback VARCHAR(120) NOT NULL DEFAULT '',
  monthly_budget_usd DECIMAL(10,2) NOT NULL DEFAULT 5.00,
  plan_item_id INT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  models_json MEDIUMTEXT NULL,
  models_at DATETIME NULL,
  test_ok TINYINT(1) NULL,
  test_hash CHAR(64) NULL,
  test_at DATETIME NULL,
  test_json TEXT NULL,
  deploy_command_id INT UNSIGNED NULL,
  deployed_json TEXT NULL,
  hms_state MEDIUMTEXT NULL,
  hms_state_at DATETIME NULL,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
