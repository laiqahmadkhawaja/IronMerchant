-- Migration Patch for daily_closings table & UTF-8 Urdu Localization
-- Resolves MySQL Error 1364: Field 'closing_time' doesn't have a default value
-- Repairs any corrupted question-mark strings in company_settings

ALTER TABLE `daily_closings` 
  MODIFY `closing_time` TIME NULL DEFAULT NULL,
  MODIFY `opening_time` TIME NULL DEFAULT NULL,
  MODIFY `opened_by` INT NULL DEFAULT NULL,
  MODIFY `opening_cash` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  MODIFY `opening_bank` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  MODIFY `gross_sales` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  MODIFY `sales_discount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  MODIFY `net_sales` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  MODIFY `cash_sales` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  MODIFY `credit_sales` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  MODIFY `bank_sales` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  MODIFY `customer_cash_collections` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  MODIFY `other_cash_receipts` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  MODIFY `supplier_cash_payments` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  MODIFY `cash_expenses` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  MODIFY `other_cash_payments` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  MODIFY `expected_cash` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  MODIFY `actual_cash_counted` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  MODIFY `cash_difference` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  MODIFY `status` ENUM('open', 'draft', 'finalized') NOT NULL DEFAULT 'open',
  MODIFY `notes` TEXT NULL DEFAULT NULL;

-- Repair UTF-8 Urdu company settings
UPDATE `company_settings` SET `setting_value` = 'ظفر برادرز آئرن مرچنٹس' WHERE `setting_key` = 'company_urdu';
UPDATE `company_settings` SET `setting_value` = 'آپ کے اعتماد کا شکریہ — معیاری سریا، ایماندار وزن، بھروسے کا نام' WHERE `setting_key` = 'company_motto_urdu';
UPDATE `company_settings` SET `setting_value` = '0300-9641483 · 0300-7499499' WHERE `setting_key` = 'company_phone';
UPDATE `company_settings` SET `setting_value` = '055-4213194 · 4215185 · 4219151' WHERE `setting_key` = 'company_landline';
