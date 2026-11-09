-- Sales and billing department (owner 2026-11-09): any client (a company or an individual, hosting with us or not),
-- hardware and other products, recurring invoices, and an outbox for automated emails. Additive, plus cp_payments.tenant_id
-- made optional so a client who is not a hosted business can pay.

CREATE TABLE IF NOT EXISTS cp_clients (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind ENUM('company','individual') NOT NULL DEFAULT 'company',
  name VARCHAR(190) NOT NULL,
  contact_name VARCHAR(120) NULL,
  email VARCHAR(190) NULL,
  cc_emails VARCHAR(500) NULL,
  phone VARCHAR(60) NULL,
  address TEXT NULL,
  tax_number VARCHAR(60) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'MWK',
  tenant_id INT UNSIGNED NULL,
  source VARCHAR(40) NULL,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_clients_tenant (tenant_id),
  KEY idx_clients_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE cp_documents
  ADD COLUMN client_id INT UNSIGNED NULL AFTER tenant_id,
  ADD KEY idx_documents_client (client_id, kind, status);

ALTER TABLE cp_payments
  MODIFY COLUMN tenant_id INT UNSIGNED NULL,
  ADD COLUMN client_id INT UNSIGNED NULL AFTER tenant_id,
  ADD KEY idx_payments_client (client_id);

ALTER TABLE cp_catalog_items
  ADD COLUMN category VARCHAR(40) NOT NULL DEFAULT 'service' AFTER id,
  ADD COLUMN sku VARCHAR(60) NULL AFTER category,
  ADD COLUMN unit VARCHAR(20) NULL AFTER unit_price,
  ADD COLUMN cost_price DECIMAL(14,2) NULL AFTER unit;

ALTER TABLE cp_document_items
  ADD COLUMN catalog_item_id INT UNSIGNED NULL AFTER document_id,
  ADD COLUMN cost_price DECIMAL(14,2) NULL AFTER unit_price;

CREATE TABLE IF NOT EXISTS cp_recurring (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT UNSIGNED NOT NULL,
  tenant_id INT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL,
  interval_months TINYINT UNSIGNED NOT NULL DEFAULT 1,
  next_date DATE NOT NULL,
  end_date DATE NULL,
  currency CHAR(3) NOT NULL DEFAULT 'MWK',
  due_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  extend_months SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  items_json TEXT NOT NULL,
  notes TEXT NULL,
  send_mode ENUM('auto','approve','draft') NOT NULL DEFAULT 'approve',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_document_id INT UNSIGNED NULL,
  last_run_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_recurring_due (is_active, next_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cp_outbox (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind VARCHAR(30) NOT NULL,
  ref_id INT UNSIGNED NOT NULL,
  client_id INT UNSIGNED NULL,
  document_id INT UNSIGNED NULL,
  reason VARCHAR(190) NOT NULL,
  opts_json TEXT NULL,
  status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  dedupe_key VARCHAR(120) NOT NULL,
  error VARCHAR(500) NULL,
  decided_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_outbox_dedupe (dedupe_key),
  KEY idx_outbox_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE cp_email_log
  ADD COLUMN client_id INT UNSIGNED NULL AFTER tenant_id,
  ADD KEY idx_email_client (client_id, created_at);
