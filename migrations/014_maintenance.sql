-- Scheduled maintenance windows for the businesses' systems (owner 2026-11-23). Additive.
-- cp_maintenance: one row per business per window. Every time is unix seconds (INT), like the licence answer itself.
-- scope: admin (the staff system, front desk, POS and kitchen screens), site (the guest website) or both.
-- message NULL = the generic message (cp_settings maintenance_default_message, or the text built into app/maintenance.php).
-- state: planned, ended (End now) or cancelled. A planned window whose ends_at has passed is over by itself.
-- sent_at: when the window first went out in a signed licence answer. hotel_seen_at: when the business first said it holds it.
-- Nothing is ever deleted.

CREATE TABLE IF NOT EXISTS cp_maintenance (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  scope ENUM('admin','site','both') NOT NULL,
  announce_from INT UNSIGNED NOT NULL,
  starts_at INT UNSIGNED NOT NULL,
  ends_at INT UNSIGNED NOT NULL,
  message TEXT NULL,
  state ENUM('planned','ended','cancelled') NOT NULL DEFAULT 'planned',
  ended_at INT UNSIGNED NULL,
  sent_at INT UNSIGNED NULL,
  hotel_seen_at INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_maint_tenant (tenant_id, state, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
