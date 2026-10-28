-- Zafar Brothers Iron Merchants Seed Data
USE `zafar_pos_db`;

-- Default Company Settings
INSERT INTO `company_settings` (`setting_key`, `setting_value`) VALUES
('company_name', 'Zafar Brothers Iron Merchants'),
('company_urdu', 'ظفر برادرز آئرن مرچنٹس'),
('company_tagline', 'Gujranwala Steel Group of Industry'),
('company_motto_urdu', 'آپ کے اعتماد کا شکریہ — معیاری سریا، ایماندار وزن، بھروسے کا نام'),
('company_motto_en', 'Thank you for your trust. Quality steel, honest weight.'),
('company_address', 'Old Chungi, Hafizabad Road, Gujranwala'),
('company_phone', '0300-9641483 · 0300-7499499'),
('company_landline', '055-4213194 · 4215185 · 4219151'),
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
