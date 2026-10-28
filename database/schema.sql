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
  `sale_date` DATE NOT NULL,
  `sale_time` TIME NOT NULL,
  `subtotal` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
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
