-- Zafar Brothers Iron Merchants - Optional Demo Data
-- Use this script ONLY if you wish to populate the database with sample inventory and demo parties.
USE `zafar_pos_db`;

-- Demo Products Master (Steel Products with sample stock)
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

-- Demo Parties (Sample Customers, Suppliers, Employees)
INSERT INTO `parties` (`id`, `type`, `is_walkin`, `code`, `name`, `phone`, `cnic`, `address`, `company_name`, `salary`, `opening_balance`, `current_balance`, `credit_limit`, `is_active`) VALUES
(2, 'customer', 0, 'CUST-002', 'Haji Munir & Sons Construction', '0301-7654321', '34101-1234567-1', 'G.T. Road, Gujranwala', 'Munir Builders', 0.00, 0.00, 0.00, 500000.00, 1),
(3, 'customer', 0, 'CUST-003', 'Chaudhry Akram Contractor', '0302-8877665', '34101-9876543-3', 'Model Town, Gujranwala', 'Akram & Co.', 0.00, 0.00, 0.00, 300000.00, 1),
(4, 'customer', 0, 'CUST-004', 'Malik Steel Works (Fabricator)', '0300-5544332', '34101-5544332-5', 'Nowshera Road, Gujranwala', 'Malik Workshop', 0.00, 0.00, 0.00, 200000.00, 1),
(5, 'supplier', 0, 'SUP-001', 'Mughal Steel Mills Ltd', '042-35960000', '', '17-KM Sheikhupura Road, Lahore', 'Mughal Iron & Steel Industries', 0.00, 0.00, 0.00, 5000000.00, 1),
(6, 'supplier', 0, 'SUP-002', 'Amreli Steels Distribution', '021-111267354', '', 'Karachi / Lahore Depot', 'Amreli Steels Ltd', 0.00, 0.00, 0.00, 4000000.00, 1),
(7, 'supplier', 0, 'SUP-003', 'Ittehad Steel Re-Rolling Mills', '055-4281190', '', 'Industrial Estate, Gujranwala', 'Ittehad Steel', 0.00, 0.00, 0.00, 2000000.00, 1),
(8, 'employee', 0, 'EMP-001', 'Muhammad Zafar', '0300-9641483', '34101-1111111-1', 'Gujranwala', 'Management', 0.00, 0.00, 0.00, 0.00, 1),
(9, 'employee', 0, 'EMP-002', 'Tariq Mehmood (Head Mistry/Crane)', '0305-6677889', '34101-2222222-2', 'Chungi Gujranwala', 'Yard Operations', 45000.00, 0.00, 0.00, 0.00, 1),
(10, 'employee', 0, 'EMP-003', 'Ali Raza (Yard Weighman)', '0308-3344556', '34101-3333333-3', 'Hafizabad Road', 'Weighing Scale', 35000.00, 0.00, 0.00, 0.00, 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);
