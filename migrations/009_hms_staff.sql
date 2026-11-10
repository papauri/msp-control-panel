-- HMS staff and permissions managed from the panel (owner 2026-11-10). Additive.
-- cp_hms_commands: a change the owner asked for (deactivate, reactivate, role, permissions, add, invitation). It travels
-- in the next SIGNED licence answer, the HMS applies it and confirms it in its staff report. Never deleted.
-- cp_hms_staff: the last staff list each HMS reported (no passwords). Replaced on every report.
-- cp_tenants: when the last report came, when the owner asked for a fresh one, and the HMS's roles and permissions list.

CREATE TABLE IF NOT EXISTS cp_hms_commands (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  username VARCHAR(100) NOT NULL,
  pms_user_id INT UNSIGNED NULL,
  data TEXT NULL,
  summary VARCHAR(255) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  done_at DATETIME NULL,
  result VARCHAR(12) NULL,
  result_message VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_hms_cmd_tenant (tenant_id, done_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cp_hms_staff (
  tenant_id INT UNSIGNED NOT NULL,
  pms_user_id INT UNSIGNED NOT NULL,
  username VARCHAR(100) NOT NULL,
  email VARCHAR(255) NOT NULL DEFAULT '',
  full_name VARCHAR(255) NOT NULL DEFAULT '',
  role VARCHAR(60) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login DATETIME NULL,
  grants TEXT NULL,
  denies TEXT NULL,
  PRIMARY KEY (tenant_id, pms_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE cp_tenants
  ADD COLUMN hms_synced_at DATETIME NULL,
  ADD COLUMN hms_list_requested_at DATETIME NULL,
  ADD COLUMN hms_catalog MEDIUMTEXT NULL;
