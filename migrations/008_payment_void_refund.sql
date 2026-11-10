-- Void and refund payments, and recurring schedules that keep their day of the month (owner 2026-11-10). Additive.
-- A payment row is never deleted. Void (entered by mistake): voided_at / voided_by / void_reason are set and the row stops
-- counting anywhere. Refund (money given back): a new row with a negative amount and refund_of = the payment it returns.
-- licence_back_at: when the licence time this payment added was taken back (so it is never taken back twice).
-- anchor_day: the day a schedule comes back to (1..31, 31 = the last day of the month), so 30 Jan, 28 Feb, 30 Mar.

ALTER TABLE cp_payments
  ADD COLUMN refund_of INT UNSIGNED NULL AFTER document_id,
  ADD COLUMN voided_at DATETIME NULL AFTER created_at,
  ADD COLUMN voided_by INT UNSIGNED NULL AFTER voided_at,
  ADD COLUMN void_reason VARCHAR(255) NULL AFTER voided_by,
  ADD COLUMN licence_back_at DATETIME NULL AFTER void_reason,
  ADD KEY idx_payments_refund (refund_of);

ALTER TABLE cp_recurring
  ADD COLUMN anchor_day TINYINT UNSIGNED NULL AFTER next_date;
