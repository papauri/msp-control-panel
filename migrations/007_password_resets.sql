-- "Forgot your password?" email links (owner 2026-11-09). Additive.
-- Only a SHA-256 hash of each link's secret is stored. pw_fp = SHA-256 of the account's password hash when the link was
-- made: any password change or reset in the meantime makes the link useless. A link sets a new password only; the
-- person still needs a current 6-digit code from their authenticator app.

CREATE TABLE IF NOT EXISTS cp_password_resets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  pw_fp CHAR(64) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ip VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pwreset_token (token_hash),
  KEY idx_pwreset_admin (admin_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
