-- Deployments (owner 2026-11-23): see every commit pushed to GitHub and ask each live copy to run cPanel "Update from
-- Remote". Additive only. No cPanel credentials are stored (owner's choice: "no credentials: cron"): the panel only
-- RECORDS a request; a cron job on each server picks it up and runs the pull itself (the panel through
-- scripts/deploy-check.php, a business through its signed licence answer). Nothing is ever pushed to GitHub.
-- cp_deploy_targets: one row per live copy (tenant_id NULL = this panel itself; at most one row per business).
--   update_request_id: the newest request (rises only; GREATEST(id + 1, unix time)). picked_up_id / done_id: the newest
--   request the server said it picked up / finished, never above update_request_id. result_*: as reported, shown only.
--   live_sha: the commit the server says it has checked out.
-- cp_deploy_settings: one row (id 1) for the panel-wide GitHub token (fine-grained, read-only), SEALED with AES-256-GCM
--   under keys_derive('cp-deploy-v1'); only its last 4 characters are kept clear.

CREATE TABLE IF NOT EXISTS cp_deploy_targets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  tenant_id INT UNSIGNED NULL,
  github_repo VARCHAR(140) NOT NULL,
  branch VARCHAR(100) NOT NULL DEFAULT 'main',
  update_request_id INT UNSIGNED NOT NULL DEFAULT 0,
  update_requested_at DATETIME NULL,
  requested_by INT UNSIGNED NULL,
  picked_up_id INT UNSIGNED NOT NULL DEFAULT 0,
  picked_up_at DATETIME NULL,
  done_id INT UNSIGNED NOT NULL DEFAULT 0,
  done_at DATETIME NULL,
  result_ok TINYINT(1) NULL,
  result_msg VARCHAR(500) NULL,
  live_sha CHAR(40) NULL,
  live_sha_at DATETIME NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_deploy_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cp_deploy_settings (
  id TINYINT UNSIGNED NOT NULL,
  github_token_sealed TEXT NULL,
  github_token_hint VARCHAR(12) NULL,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
