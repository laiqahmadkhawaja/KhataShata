-- ==============================================================
-- KhataShata Seed Data
-- ==============================================================
USE `khatashata_db`;

-- Seed default user (email: admin@khatashata.com, password: password123)
INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `phone`, `role`) 
VALUES (1, 'Laiq Ahmad', 'admin@khatashata.com', '$2y$10$zk4ml6okMllNqdhGHjAOOORUGHM.nXs3otDU/TZaVjGhYhZ0G9oyy', '+92 300 1234567', 'owner')
ON DUPLICATE KEY UPDATE `id`=VALUES(`id`);

-- Default Settings
INSERT INTO `app_settings` (`user_id`, `setting_key`, `setting_value`) VALUES
(1, 'app_name', 'KhataShata - Business & Income Manager'),
(1, 'currency_symbol', 'Rs.'),
(1, 'currency_code', 'PKR'),
(1, 'currency_position', 'before'),
(1, 'thousands_sep', ','),
(1, 'decimal_point', '.'),
(1, 'decimals', '2'),
(1, 'theme_mode', 'light'),
(1, 'date_format', 'Y-m-d'),
(1, 'fiscal_year_start', '07-01')
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`);

-- Business Categories
INSERT INTO `business_categories` (`id`, `user_id`, `name`, `description`, `is_active`) VALUES
(1, 1, 'Software Development', 'Custom software, web apps, mobile apps, SaaS', 1),
(2, 1, 'Marketing Agency', 'Digital marketing, advertising, branding', 1),
(3, 1, 'Retail & Wholesale', 'Physical store, retail inventory, trading', 1),
(4, 1, 'Real Estate', 'Property development, renting, leasing', 1),
(5, 1, 'Consultancy & Advisory', 'Professional consulting services', 1),
(6, 1, 'E-Commerce', 'Online retail, dropshipping, D2C store', 1),
(7, 1, 'Other', 'General business activities', 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- Income Categories
INSERT INTO `income_categories` (`id`, `user_id`, `name`, `type`, `is_active`) VALUES
(1, 1, 'Sales Revenue', 'business', 1),
(2, 1, 'Service Revenue', 'business', 1),
(3, 1, 'Consultancy Fee', 'business', 1),
(4, 1, 'Contract Payment', 'business', 1),
(5, 1, 'Commission', 'business', 1),
(6, 1, 'Monthly Salary', 'personal', 1),
(7, 1, 'Freelance Income', 'personal', 1),
(8, 1, 'Rental Income', 'personal', 1),
(9, 1, 'Dividend / Investment Returns', 'personal', 1),
(10, 1, 'Other Business Income', 'business', 1),
(11, 1, 'Other Personal Income', 'personal', 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- Expense Categories
INSERT INTO `expense_categories` (`id`, `user_id`, `name`, `type`, `is_active`) VALUES
(1, 1, 'Salaries & Staff Wages', 'business', 1),
(2, 1, 'Office Rent', 'business', 1),
(3, 1, 'Electricity & Utilities', 'both', 1),
(4, 1, 'Software & Subscriptions', 'business', 1),
(5, 1, 'Marketing & Advertising', 'business', 1),
(6, 1, 'Transport & Fuel', 'both', 1),
(7, 1, 'Maintenance & Repairs', 'both', 1),
(8, 1, 'Supplies & Equipment', 'business', 1),
(9, 1, 'Household & Groceries', 'personal', 1),
(10, 1, 'Food & Dining', 'personal', 1),
(11, 1, 'Education & Courses', 'personal', 1),
(12, 1, 'Health & Medical', 'personal', 1),
(13, 1, 'Shopping & Clothing', 'personal', 1),
(14, 1, 'Entertainment & Travel', 'personal', 1),
(15, 1, 'Other Business Expense', 'business', 1),
(16, 1, 'Other Personal Expense', 'personal', 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- Default Accounts
INSERT INTO `accounts` (`id`, `user_id`, `account_name`, `account_type`, `provider_name`, `account_number`, `opening_balance`, `opening_balance_date`, `current_balance`, `description`, `status`) VALUES
(1, 1, 'Cash in Hand', 'cash', 'Physical Cash', 'CASH-01', 50000.00, CURDATE(), 50000.00, 'Physical cash wallet for daily cash transactions', 'active'),
(2, 1, 'Main Business Bank Account', 'bank', 'Meezan Bank Ltd', 'PK88MEZN0001234567890123', 250000.00, CURDATE(), 250000.00, 'Primary corporate banking account for business operations', 'active'),
(3, 1, 'JazzCash Wallet', 'jazzcash', 'Mobilink Microfinance Bank', '03001234567', 25000.00, CURDATE(), 25000.00, 'Digital mobile wallet for rapid transfers', 'active')
ON DUPLICATE KEY UPDATE `account_name`=VALUES(`account_name`);
