-- Zafar Brothers Iron Merchants Database Schema
-- MySQL 8.x / MariaDB compatible

CREATE DATABASE IF NOT EXISTS `zafar_pos_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `zafar_pos_db`;

-- Users & Authentication
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `role` ENUM('Owner', 'Accountant', 'Cashier', 'Purchase Manager', 'Inventory Manager', 'Employee') NOT NULL DEFAULT 'Employee',
  `pin` VARCHAR(20) DEFAULT NULL,
  `password_hash` VARCHAR(255) DEFAULT NULL,
  `permissions` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Company Settings
CREATE TABLE IF NOT EXISTS `company_settings` (
  `setting_key` VARCHAR(100) PRIMARY KEY,
  `setting_value` TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit Logs
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `action` VARCHAR(50) NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` VARCHAR(50) DEFAULT NULL,
  `details` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_entity` (`entity_type`, `entity_id`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Financial Years
CREATE TABLE IF NOT EXISTS `financial_years` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `year_name` VARCHAR(50) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Chart of Accounts
CREATE TABLE IF NOT EXISTS `chart_of_accounts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(20) NOT NULL UNIQUE,
  `name` VARCHAR(150) NOT NULL,
  `type` ENUM('Asset', 'Liability', 'Equity', 'Revenue', 'Expense') NOT NULL,
  `sub_type` VARCHAR(50) DEFAULT NULL,
  `parent_id` INT DEFAULT NULL,
  `normal_balance` ENUM('debit', 'credit') NOT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `opening_balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_coa_type` (`type`),
  INDEX `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Financial Accounts (Cash Registers, Bank Accounts, Mobile Wallets)
CREATE TABLE IF NOT EXISTS `financial_accounts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `coa_id` INT NOT NULL,
  `account_code` VARCHAR(30) NOT NULL UNIQUE,
  `account_name` VARCHAR(100) NOT NULL,
  `account_type` ENUM('Cash', 'Bank', 'Mobile Wallet') NOT NULL DEFAULT 'Cash',
  `bank_name` VARCHAR(100) DEFAULT NULL,
  `account_number` VARCHAR(100) DEFAULT NULL,
  `opening_balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `current_balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`coa_id`) REFERENCES `chart_of_accounts`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Item Categories
CREATE TABLE IF NOT EXISTS `item_categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Items / Products Master
CREATE TABLE IF NOT EXISTS `items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `item_code` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(150) NOT NULL,
  `category_id` INT DEFAULT NULL,
  `unit` VARCHAR(20) NOT NULL DEFAULT 'kg',
  `cost_price` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `sale_price` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `stock_qty` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `alert_qty` DECIMAL(18,4) NOT NULL DEFAULT 5000.0000,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `item_categories`(`id`) ON DELETE SET NULL,
  INDEX `idx_stock_qty` (`stock_qty`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stock Movements Ledger
CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `item_id` INT NOT NULL,
  `movement_type` ENUM('opening', 'purchase', 'sale', 'adjustment_in', 'adjustment_out', 'purchase_return', 'sale_return') NOT NULL,
  `reference_type` VARCHAR(50) NOT NULL,
  `reference_id` INT DEFAULT NULL,
  `qty_in` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `qty_out` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `unit_cost` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `total_cost` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `balance_qty` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `movement_date` DATE NOT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE RESTRICT,
  INDEX `idx_item_date` (`item_id`, `movement_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Parties (Customers, Suppliers, Employees)
CREATE TABLE IF NOT EXISTS `parties` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `type` ENUM('customer', 'supplier', 'employee') NOT NULL,
  `is_walkin` TINYINT(1) NOT NULL DEFAULT 0,
  `code` VARCHAR(50) DEFAULT NULL UNIQUE,
  `name` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `cnic` VARCHAR(50) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `company_name` VARCHAR(150) DEFAULT NULL,
  `salary` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `opening_balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `current_balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `credit_limit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_party_type` (`type`),
  INDEX `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Purchases Header
CREATE TABLE IF NOT EXISTS `purchases` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `purchase_no` VARCHAR(50) NOT NULL UNIQUE,
  `supplier_id` INT NOT NULL,
  `purchase_date` DATE NOT NULL,
  `supplier_inv_no` VARCHAR(100) DEFAULT NULL,
  `warehouse` VARCHAR(100) DEFAULT 'Main Yard',
  `subtotal` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `additional_charges` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `grand_total` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `due_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `payment_status` ENUM('Paid', 'Partial', 'Unpaid') NOT NULL DEFAULT 'Unpaid',
  `payment_account_id` INT DEFAULT NULL,
  `status` ENUM('draft', 'posted', 'cancelled') NOT NULL DEFAULT 'posted',
  `journal_id` INT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`supplier_id`) REFERENCES `parties`(`id`) ON DELETE RESTRICT,
  INDEX `idx_purchase_date` (`purchase_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Purchase Items
CREATE TABLE IF NOT EXISTS `purchase_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `purchase_id` INT NOT NULL,
  `item_id` INT NOT NULL,
  `qty` DECIMAL(18,4) NOT NULL,
  `unit_rate` DECIMAL(18,4) NOT NULL,
  `line_discount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `base_amount` DECIMAL(18,2) NOT NULL,
  `allocated_charge` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `landed_cost` DECIMAL(18,2) NOT NULL,
  `landed_unit_rate` DECIMAL(18,4) NOT NULL,
  FOREIGN KEY (`purchase_id`) REFERENCES `purchases`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Purchase Additional Charges
CREATE TABLE IF NOT EXISTS `purchase_charges` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `purchase_id` INT NOT NULL,
  `charge_name` VARCHAR(100) NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL,
  `payee_name` VARCHAR(100) DEFAULT NULL,
  `capitalize_inventory` TINYINT(1) NOT NULL DEFAULT 1,
  `allocation_method` ENUM('weight', 'value', 'equal', 'manual') NOT NULL DEFAULT 'weight',
  `expense_account_id` INT DEFAULT NULL,
  `payment_account_id` INT DEFAULT NULL,
  FOREIGN KEY (`purchase_id`) REFERENCES `purchases`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sales Header
CREATE TABLE IF NOT EXISTS `sales` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_no` VARCHAR(50) NOT NULL UNIQUE,
  `customer_id` INT NOT NULL,
  `customer_name_custom` VARCHAR(150) DEFAULT NULL,
  `customer_phone_custom` VARCHAR(50) DEFAULT NULL,
  `sale_date` DATE NOT NULL,
  `sale_time` TIME NOT NULL,
  `subtotal` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `labour_charge` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `delivery_charge` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `additional_charges` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `discount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `grand_total` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `due_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `payment_status` ENUM('Paid', 'Partial', 'Unpaid') NOT NULL DEFAULT 'Paid',
  `payment_method` VARCHAR(50) DEFAULT 'Cash',
  `payment_account_id` INT DEFAULT NULL,
  `total_cogs` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft', 'posted', 'cancelled') NOT NULL DEFAULT 'posted',
  `journal_id` INT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `parties`(`id`) ON DELETE RESTRICT,
  INDEX `idx_sale_date` (`sale_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sale Items
CREATE TABLE IF NOT EXISTS `sale_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sale_id` INT NOT NULL,
  `item_id` INT NOT NULL,
  `qty` DECIMAL(18,4) NOT NULL,
  `unit_rate` DECIMAL(18,4) NOT NULL,
  `line_discount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(18,2) NOT NULL,
  `unit_cogs` DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  `total_cogs` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  FOREIGN KEY (`sale_id`) REFERENCES `sales`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sale Charges
CREATE TABLE IF NOT EXISTS `sale_charges` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sale_id` INT NOT NULL,
  `charge_name` VARCHAR(100) NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL,
  FOREIGN KEY (`sale_id`) REFERENCES `sales`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Double-Entry Journal Entries
CREATE TABLE IF NOT EXISTS `journal_entries` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `entry_no` VARCHAR(50) NOT NULL UNIQUE,
  `entry_date` DATE NOT NULL,
  `source_type` ENUM('sale', 'purchase', 'expense', 'transfer', 'voucher', 'closing', 'opening', 'adjustment', 'reversal') NOT NULL,
  `source_id` INT DEFAULT NULL,
  `narration` TEXT DEFAULT NULL,
  `total_debit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `total_credit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `is_balanced` TINYINT(1) NOT NULL DEFAULT 1,
  `status` ENUM('posted', 'reversed') NOT NULL DEFAULT 'posted',
  `reversed_by_id` INT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_entry_date` (`entry_date`),
  INDEX `idx_source` (`source_type`, `source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Journal Entry Lines
CREATE TABLE IF NOT EXISTS `journal_entry_lines` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `journal_id` INT NOT NULL,
  `account_id` INT NOT NULL,
  `party_id` INT DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `debit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `credit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  FOREIGN KEY (`journal_id`) REFERENCES `journal_entries`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts`(`id`) ON DELETE RESTRICT,
  INDEX `idx_account_id` (`account_id`),
  INDEX `idx_party_id` (`party_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vouchers Master
CREATE TABLE IF NOT EXISTS `vouchers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `voucher_no` VARCHAR(50) NOT NULL UNIQUE,
  `voucher_type` ENUM('RV', 'PV', 'JV', 'CV', 'TV', 'EV') NOT NULL,
  `voucher_date` DATE NOT NULL,
  `party_id` INT DEFAULT NULL,
  `account_id` INT DEFAULT NULL,
  `amount` DECIMAL(18,2) NOT NULL,
  `payment_mode` VARCHAR(50) DEFAULT 'Cash',
  `cheque_no` VARCHAR(50) DEFAULT NULL,
  `narration` TEXT DEFAULT NULL,
  `journal_id` INT DEFAULT NULL,
  `status` ENUM('posted', 'reversed', 'cancelled') NOT NULL DEFAULT 'posted',
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_voucher_type` (`voucher_type`),
  INDEX `idx_voucher_date` (`voucher_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inter-Account Transfers
CREATE TABLE IF NOT EXISTS `inter_account_transfers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `transfer_no` VARCHAR(50) NOT NULL UNIQUE,
  `transfer_date` DATE NOT NULL,
  `from_account_id` INT NOT NULL,
  `to_account_id` INT NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL,
  `fee_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `fee_account_id` INT DEFAULT NULL,
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `narration` TEXT DEFAULT NULL,
  `voucher_id` INT DEFAULT NULL,
  `journal_id` INT DEFAULT NULL,
  `status` ENUM('posted', 'reversed') NOT NULL DEFAULT 'posted',
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`from_account_id`) REFERENCES `financial_accounts`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`to_account_id`) REFERENCES `financial_accounts`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Expense Categories
CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `coa_id` INT NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Expenses
CREATE TABLE IF NOT EXISTS `expenses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `expense_no` VARCHAR(50) NOT NULL UNIQUE,
  `expense_date` DATE NOT NULL,
  `category_id` INT NOT NULL,
  `coa_id` INT NOT NULL,
  `payee` VARCHAR(100) DEFAULT NULL,
  `amount` DECIMAL(18,2) NOT NULL,
  `payment_account_id` INT DEFAULT NULL,
  `payment_method` VARCHAR(50) DEFAULT 'Cash',
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `journal_id` INT DEFAULT NULL,
  `voucher_id` INT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `expense_categories`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`coa_id`) REFERENCES `chart_of_accounts`(`id`) ON DELETE RESTRICT,
  INDEX `idx_expense_date` (`expense_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Daily Closing
CREATE TABLE IF NOT EXISTS `daily_closings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `closing_no` VARCHAR(50) NOT NULL UNIQUE,
  `shift_name` VARCHAR(50) DEFAULT 'General Day Shift',
  `closing_date` DATE NOT NULL,
  `opening_time` TIME DEFAULT NULL,
  `closing_time` TIME DEFAULT NULL,
  `user_id` INT NOT NULL,
  `opened_by` INT DEFAULT NULL,
  `opening_cash` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `opening_bank` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `gross_sales` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `sales_discount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `net_sales` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `cash_sales` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `credit_sales` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `bank_sales` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `customer_cash_collections` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `other_cash_receipts` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `supplier_cash_payments` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `cash_expenses` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `other_cash_payments` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `expected_cash` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `actual_cash_counted` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `cash_difference` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('open', 'draft', 'finalized') NOT NULL DEFAULT 'open',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_closing_date` (`closing_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stock Adjustments
CREATE TABLE IF NOT EXISTS `stock_adjustments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `adjustment_no` VARCHAR(50) NOT NULL UNIQUE,
  `adjustment_date` DATE NOT NULL,
  `item_id` INT NOT NULL,
  `adjustment_type` ENUM('increase', 'decrease') NOT NULL,
  `qty` DECIMAL(18,4) NOT NULL,
  `unit_cost` DECIMAL(18,4) NOT NULL,
  `total_cost` DECIMAL(18,2) NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `journal_id` INT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sales & Purchase Returns Master
CREATE TABLE IF NOT EXISTS `returns` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `return_no` VARCHAR(50) NOT NULL UNIQUE,
  `return_type` ENUM('sale_return', 'purchase_return') NOT NULL,
  `return_date` DATE NOT NULL,
  `party_id` INT NOT NULL,
  `reference_no` VARCHAR(50) NULL,
  `total_qty` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `subtotal` DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
  `deduction_charges` DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
  `net_amount` DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
  `refund_type` ENUM('credit_to_khata', 'cash_refund', 'bank_refund') NOT NULL DEFAULT 'credit_to_khata',
  `account_id` INT NULL,
  `journal_id` INT NULL,
  `status` ENUM('posted', 'cancelled') NOT NULL DEFAULT 'posted',
  `reason` VARCHAR(255) NULL,
  `notes` TEXT NULL,
  `created_by` INT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_party` (`party_id`),
  KEY `idx_date` (`return_date`),
  KEY `idx_type` (`return_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Return Items
CREATE TABLE IF NOT EXISTS `return_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `return_id` INT NOT NULL,
  `item_id` INT NOT NULL,
  `qty` DECIMAL(12, 2) NOT NULL,
  `unit_rate` DECIMAL(12, 2) NOT NULL,
  `line_total` DECIMAL(14, 2) NOT NULL,
  `cost_price` DECIMAL(12, 4) NOT NULL,
  `total_cost` DECIMAL(14, 2) NOT NULL,
  FOREIGN KEY (`return_id`) REFERENCES `returns`(`id`) ON DELETE CASCADE,
  KEY `idx_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Zafar Brothers Iron Merchants Seed Data
USE `zafar_pos_db`;

-- Default Company Settings
INSERT INTO `company_settings` (`setting_key`, `setting_value`) VALUES
('company_name', 'Zafar Brothers Iron Merchants'),
('company_urdu', 'Ø¸ÙØ± Ø¨Ø±Ø§Ø¯Ø±Ø² Ø¢Ø¦Ø±Ù† Ù…Ø±Ú†Ù†Ù¹Ø³'),
('company_tagline', 'Gujranwala Steel Group of Industry'),
('company_motto_urdu', 'Ø¢Ù¾ Ú©Û’ Ø§Ø¹ØªÙ…Ø§Ø¯ Ú©Ø§ Ø´Ú©Ø±ÛŒÛ â€” Ù…Ø¹ÛŒØ§Ø±ÛŒ Ø³Ø±ÛŒØ§ØŒ Ø§ÛŒÙ…Ø§Ù†Ø¯Ø§Ø± ÙˆØ²Ù†ØŒ Ø¨Ú¾Ø±ÙˆØ³Û’ Ú©Ø§ Ù†Ø§Ù…'),
('company_motto_en', 'Thank you for your trust. Quality steel, honest weight.'),
('company_address', 'Old Chungi, Hafizabad Road, Gujranwala'),
('company_phone', '0300-9641483 Â· 0300-7499499'),
('company_landline', '055-4213194 Â· 4215185 Â· 4219151'),
('currency', 'PKR'),
('currency_symbol', 'Rs '),
('timezone', 'Asia/Karachi'),
('receipt_default_size', 'auto'),
('low_stock_alert', '7000')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- Default Users
-- Passwords: Owner (1234 or PIN 1234), Employee 1 (1111), Employee 2 (2222)
INSERT INTO `users` (`id`, `username`, `name`, `role`, `pin`, `password_hash`, `permissions`, `is_active`) VALUES
(1, 'owner', 'Owner (M. Zafar)', 'Owner', '1234', '$2y$10$eO0g5rF7eE4iV1Xo4uVXeOWP5d0W3Qk8y3R4E6M7U8A9C0E1G2I3K', '["dash","pos","bills","purchases","inventory","customers","suppliers","expenses","accounts","vouchers","transfers","closing","reports","backup","settings"]', 1),
(2, 'accountant', 'Muhammad Ahmad', 'Accountant', '5555', '$2y$10$eO0g5rF7eE4iV1Xo4uVXeOWP5d0W3Qk8y3R4E6M7U8A9C0E1G2I3K', '["dash","bills","purchases","inventory","customers","suppliers","expenses","accounts","vouchers","transfers","closing","reports"]', 1),
(3, 'cashier1', 'Hamza Khan', 'Cashier', '1111', '$2y$10$eO0g5rF7eE4iV1Xo4uVXeOWP5d0W3Qk8y3R4E6M7U8A9C0E1G2I3K', '["dash","pos","bills","customers","closing"]', 1),
(4, 'purchaser', 'Bilal Tariq', 'Purchase Manager', '2222', '$2y$10$eO0g5rF7eE4iV1Xo4uVXeOWP5d0W3Qk8y3R4E6M7U8A9C0E1G2I3K', '["dash","purchases","inventory","suppliers"]', 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- Chart of Accounts Hierarchical Base
INSERT INTO `chart_of_accounts` (`id`, `code`, `name`, `type`, `sub_type`, `parent_id`, `normal_balance`, `is_system`, `is_active`) VALUES
-- Assets (1000 - 1999)
(1, '1000', 'Current Assets', 'Asset', 'Current Asset', NULL, 'debit', 1, 1),
(2, '1010', 'Cash in Hand (Main Register)', 'Asset', 'Cash', 1, 'debit', 1, 1),
(3, '1020', 'Petty Cash', 'Asset', 'Cash', 1, 'debit', 1, 1),
(4, '1030', 'Bank Accounts Control', 'Asset', 'Bank', 1, 'debit', 1, 1),
(5, '1031', 'Bank Al Habib Limited', 'Asset', 'Bank', 4, 'debit', 0, 1),
(6, '1032', 'Meezan Bank Limited', 'Asset', 'Bank', 4, 'debit', 0, 1),
(7, '1040', 'JazzCash / EasyPaisa Wallets', 'Asset', 'Mobile Wallet', 1, 'debit', 0, 1),
(8, '1050', 'Accounts Receivable (Customers)', 'Asset', 'Receivable', 1, 'debit', 1, 1),
(9, '1060', 'Inventory (Stock on Hand)', 'Asset', 'Inventory', 1, 'debit', 1, 1),
(10, '1070', 'Advances to Suppliers', 'Asset', 'Advance', 1, 'debit', 1, 1),
(11, '1080', 'Employee Advances / Receivables', 'Asset', 'Advance', 1, 'debit', 1, 1),

-- Liabilities (2000 - 2999)
(12, '2000', 'Current Liabilities', 'Liability', 'Current Liability', NULL, 'credit', 1, 1),
(13, '2010', 'Accounts Payable (Suppliers)', 'Liability', 'Payable', 12, 'credit', 1, 1),
(14, '2020', 'Customer Advances / Unearned', 'Liability', 'Advance', 12, 'credit', 1, 1),
(15, '2030', 'Salaries & Wages Payable', 'Liability', 'Payable', 12, 'credit', 1, 1),
(16, '2040', 'Outstanding Expenses Payable', 'Liability', 'Payable', 12, 'credit', 1, 1),

-- Equity (3000 - 3999)
(17, '3000', 'Owner Equity', 'Equity', 'Equity', NULL, 'credit', 1, 1),
(18, '3010', 'Zafar Brothers Capital', 'Equity', 'Capital', 17, 'credit', 1, 1),
(19, '3020', 'Owner Drawings', 'Equity', 'Drawings', 17, 'debit', 1, 1),
(20, '3030', 'Retained Earnings', 'Equity', 'Retained', 17, 'credit', 1, 1),
(21, '3040', 'Opening Balance Equity', 'Equity', 'Clearing', 17, 'credit', 1, 1),

-- Revenue (4000 - 4999)
(22, '4000', 'Operating Revenue', 'Revenue', 'Sales', NULL, 'credit', 1, 1),
(23, '4010', 'Sales Revenue - Steel & Iron', 'Revenue', 'Sales', 22, 'credit', 1, 1),
(24, '4020', 'Delivery & Freight Income', 'Revenue', 'Service', 22, 'credit', 1, 1),
(25, '4030', 'Cutting & Labour Service Income', 'Revenue', 'Service', 22, 'credit', 1, 1),
(26, '4040', 'Sales Discounts Allowed', 'Revenue', 'Contra Revenue', 22, 'debit', 1, 1),

-- Expenses (5000 - 5999)
(27, '5000', 'Cost of Goods Sold', 'Expense', 'COGS', NULL, 'debit', 1, 1),
(28, '5010', 'COGS - Steel Stock Consumed', 'Expense', 'COGS', 27, 'debit', 1, 1),
(29, '5100', 'Operating Expenses', 'Expense', 'Operating', NULL, 'debit', 1, 1),
(30, '5110', 'Shop / Yard Rent Expense', 'Expense', 'Operating', 29, 'debit', 1, 1),
(31, '5120', 'Electricity & Utility Bills', 'Expense', 'Operating', 29, 'debit', 1, 1),
(32, '5130', 'Staff Salaries & Allowances', 'Expense', 'Operating', 29, 'debit', 1, 1),
(33, '5140', 'Loading, Unloading & Yard Labour', 'Expense', 'Operating', 29, 'debit', 1, 1),
(34, '5150', 'Vehicle Fuel & Transportation', 'Expense', 'Operating', 29, 'debit', 1, 1),
(35, '5160', 'Tea, Entertainment & Refreshments', 'Expense', 'Operating', 29, 'debit', 1, 1),
(36, '5170', 'Repairs & Yard Maintenance', 'Expense', 'Operating', 29, 'debit', 1, 1),
(37, '5180', 'Bank Service Charges', 'Expense', 'Operating', 29, 'debit', 1, 1),
(38, '5190', 'Cash Shortage Expense', 'Expense', 'Operating', 29, 'debit', 1, 1),
(39, '5200', 'General & Miscellaneous Expenses', 'Expense', 'Operating', 29, 'debit', 1, 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- Financial Accounts linked to COA
INSERT INTO `financial_accounts` (`id`, `coa_id`, `account_code`, `account_name`, `account_type`, `bank_name`, `account_number`, `opening_balance`, `current_balance`, `is_active`) VALUES
(1, 2, 'CASH-01', 'Main Cash Register', 'Cash', 'In-Shop Safe', 'REG-01', 0.00, 0.00, 1),
(2, 3, 'PETTY-01', 'Office Petty Cash', 'Cash', 'Yard Drawer', 'PETTY-01', 0.00, 0.00, 1),
(3, 5, 'BANK-BAHL', 'Bank Al Habib Current A/C', 'Bank', 'Bank Al Habib Ltd', '0021-0081-019283-01', 0.00, 0.00, 1),
(4, 6, 'BANK-MEEZAN', 'Meezan Bank Islamic A/C', 'Bank', 'Meezan Bank Ltd', '0201-0102938492', 0.00, 0.00, 1),
(5, 7, 'MOB-JAZZ', 'JazzCash Business Wallet', 'Mobile Wallet', 'Mobilink Microfinance', '0300-9641483', 0.00, 0.00, 1),
(6, 7, 'MOB-EP', 'EasyPaisa Merchant', 'Mobile Wallet', 'Telenor Bank', '0300-7499499', 0.00, 0.00, 1)
ON DUPLICATE KEY UPDATE `account_name`=VALUES(`account_name`);

-- Item Categories
INSERT INTO `item_categories` (`id`, `name`, `description`) VALUES
(1, 'Deformed Saria (Grade 60)', 'High strength deformed rebars for structural construction'),
(2, 'Deformed Saria (Grade 40)', 'Standard construction steel bars'),
(3, 'Girders & Beams (I-Beam)', 'Heavy structural steel girders and joists'),
(4, 'T-Iron & TR Sections', 'Roofing and structural T sections'),
(5, 'Angle Iron & Channels', 'Structural angles, U-channels and C-channels'),
(6, 'Steel Patti & Flat Bars', 'Flat steel strips for gates, grills and fabrication'),
(7, 'Pipes & Square Tubing', 'Round MS pipes and rectangular hollow sections'),
(8, 'Binding Wire & Nails', 'Construction accessories and binding wire')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- Initial Products Master (Steel Products with accurate units and initial stock)
INSERT INTO `items` (`id`, `item_code`, `name`, `category_id`, `unit`, `cost_price`, `sale_price`, `stock_qty`, `alert_qty`, `is_active`) VALUES
(1, 'SAR-12', 'Saria 1/2" (12mm) Grade 60', 1, 'kg', 245.0000, 260.0000, 12500.0000, 6000.0000, 1),
(2, 'SAR-16', 'Saria 5/8" (16mm) Grade 60', 1, 'kg', 245.0000, 260.0000, 9800.0000, 5000.0000, 1),
(3, 'SAR-10', 'Saria 3/8" (10mm) Grade 60', 1, 'kg', 248.0000, 262.0000, 8400.0000, 4000.0000, 1),
(4, 'SAR-20', 'Saria 3/4" (20mm) Grade 60', 1, 'kg', 244.0000, 258.0000, 5600.0000, 3000.0000, 1),
(5, 'SAR-25', 'Saria 1" (25mm) Grade 60', 1, 'kg', 244.0000, 258.0000, 4200.0000, 2500.0000, 1),
(6, 'GRD-7X4', 'Girder 7x4 Heavy (Imported)', 3, 'kg', 255.0000, 275.0000, 7200.0000, 3500.0000, 1),
(7, 'GRD-6X3', 'Girder 6x3 Medium', 3, 'kg', 252.0000, 270.0000, 6100.0000, 3000.0000, 1),
(8, 'TR-T', 'T-Iron (TR 1.5" x 1.5")', 4, 'kg', 240.0000, 255.0000, 4800.0000, 2500.0000, 1),
(9, 'ANG-15', 'Angle Iron 1.5" x 3/16"', 5, 'kg', 238.0000, 252.0000, 3900.0000, 2000.0000, 1),
(10, 'PAT-1', 'Steel Patti 1" x 1/8"', 6, 'kg', 235.0000, 248.0000, 2800.0000, 1500.0000, 1),
(11, 'WIRE-BW', 'Binding Wire (Talaq Tar)', 8, 'kg', 270.0000, 290.0000, 1200.0000, 500.0000, 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- Default Parties
INSERT INTO `parties` (`id`, `type`, `code`, `name`, `phone`, `cnic`, `address`, `company_name`, `salary`, `opening_balance`, `current_balance`, `credit_limit`, `is_active`) VALUES
-- Customers
(1, 'customer', 'CUST-001', 'Walk-in Customer (General Cash)', '0300-0000000', '', 'Counter Sale Yard', 'Cash Customer', 0.00, 0.00, 0.00, 0.00, 1),
(2, 'customer', 'CUST-002', 'Haji Munir & Sons Construction', '0301-7654321', '34101-1234567-1', 'G.T. Road, Gujranwala', 'Munir Builders', 0.00, 0.00, 0.00, 500000.00, 1),
(3, 'customer', 'CUST-003', 'Chaudhry Akram Contractor', '0302-8877665', '34101-9876543-3', 'Model Town, Gujranwala', 'Akram & Co.', 0.00, 0.00, 0.00, 300000.00, 1),
(4, 'customer', 'CUST-004', 'Malik Steel Works (Fabricator)', '0300-5544332', '34101-5544332-5', 'Nowshera Road, Gujranwala', 'Malik Workshop', 0.00, 0.00, 0.00, 200000.00, 1),

-- Suppliers
(5, 'supplier', 'SUP-001', 'Mughal Steel Mills Ltd', '042-35960000', '', '17-KM Sheikhupura Road, Lahore', 'Mughal Iron & Steel Industries', 0.00, 0.00, 0.00, 5000000.00, 1),
(6, 'supplier', 'SUP-002', 'Amreli Steels Distribution', '021-111267354', '', 'Karachi / Lahore Depot', 'Amreli Steels Ltd', 0.00, 0.00, 0.00, 4000000.00, 1),
(7, 'supplier', 'SUP-003', 'Ittehad Steel Re-Rolling Mills', '055-4281190', '', 'Industrial Estate, Gujranwala', 'Ittehad Steel', 0.00, 0.00, 0.00, 2000000.00, 1),

-- Employees
(8, 'employee', 'EMP-001', 'Muhammad Zafar', '0300-9641483', '34101-1111111-1', 'Gujranwala', 'Management', 0.00, 0.00, 0.00, 0.00, 1),
(9, 'employee', 'EMP-002', 'Tariq Mehmood (Head Mistry/Crane)', '0305-6677889', '34101-2222222-2', 'Chungi Gujranwala', 'Yard Operations', 45000.00, 0.00, 0.00, 0.00, 1),
(10, 'employee', 'EMP-003', 'Ali Raza (Yard Weighman)', '0308-3344556', '34101-3333333-3', 'Hafizabad Road', 'Weighing Scale', 35000.00, 0.00, 0.00, 0.00, 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- Expense Categories
INSERT INTO `expense_categories` (`id`, `name`, `coa_id`, `is_active`) VALUES
(1, 'Shop & Yard Rent', 30, 1),
(2, 'Electricity & WAPDA Bills', 31, 1),
(3, 'Labour & Unloading (Yard Expense)', 33, 1),
(4, 'Transportation & Freight (Local)', 34, 1),
(5, 'Staff Tea & Refreshments', 35, 1),
(6, 'Repairs & Crane Maintenance', 36, 1),
(7, 'Bank Charges & Taxes', 37, 1),
(8, 'Stationery & Printing', 39, 1),
(9, 'Staff Salary & Daily Wages', 32, 1),
(10, 'Miscellaneous Office Expense', 39, 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);
