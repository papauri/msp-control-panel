-- Billing (owner 2026-11-09): invoices, quotations, payments against invoices, saved items, company settings, email log,
-- and each business's billing contact. Additive only. Text seeds live in code (app/billing.php), not here.

ALTER TABLE cp_tenants
  ADD COLUMN contact_name VARCHAR(120) NULL AFTER notes,
  ADD COLUMN billing_email VARCHAR(190) NULL AFTER contact_name,
  ADD COLUMN cc_emails VARCHAR(500) NULL AFTER billing_email,
  ADD COLUMN contact_phone VARCHAR(60) NULL AFTER cc_emails,
  ADD COLUMN billing_address TEXT NULL AFTER contact_phone;

CREATE TABLE IF NOT EXISTS cp_settings (
  setting_key VARCHAR(64) NOT NULL,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cp_documents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind ENUM('invoice','quote') NOT NULL,
  number VARCHAR(40) NOT NULL,
  tenant_id INT UNSIGNED NULL,
  customer_name VARCHAR(190) NOT NULL,
  customer_contact VARCHAR(120) NULL,
  customer_email VARCHAR(190) NULL,
  customer_address TEXT NULL,
  status ENUM('draft','sent','paid','void','accepted','declined','expired','converted') NOT NULL DEFAULT 'draft',
  issue_date DATE NOT NULL,
  due_date DATE NULL,
  currency CHAR(3) NOT NULL DEFAULT 'MWK',
  salesperson VARCHAR(120) NULL,
  notes TEXT NULL,
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  discount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  tax DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  extend_months SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  source_id INT UNSIGNED NULL,
  sent_at DATETIME NULL,
  last_reminder_at DATETIME NULL,
  reminder_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_documents_number (kind, number),
  KEY idx_documents_tenant (tenant_id, kind, status),
  KEY idx_documents_due (kind, status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cp_document_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id INT UNSIGNED NOT NULL,
  sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  qty DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  item VARCHAR(190) NOT NULL,
  description TEXT NULL,
  unit_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  line_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_items_document (document_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cp_catalog_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item VARCHAR(190) NOT NULL,
  description TEXT NULL,
  unit_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'MWK',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cp_email_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NULL,
  document_id INT UNSIGNED NULL,
  kind VARCHAR(30) NOT NULL,
  to_email VARCHAR(500) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  status VARCHAR(10) NOT NULL,
  error VARCHAR(500) NULL,
  attachment VARCHAR(190) NULL,
  admin_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_email_tenant (tenant_id, created_at),
  KEY idx_email_document (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE cp_payments
  ADD COLUMN document_id INT UNSIGNED NULL AFTER tenant_id,
  ADD COLUMN method VARCHAR(40) NULL AFTER reference,
  ADD KEY idx_payments_document (document_id);
