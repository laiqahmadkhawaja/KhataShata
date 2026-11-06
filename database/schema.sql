-- ==============================================================
-- Business & Income Manager (KhataShata) Database Schema
-- MySQL / InnoDB with Decimal Monetary & Percentage Precision
-- ==============================================================

CREATE DATABASE IF NOT EXISTS `khatashata_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `khatashata_db`;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `avatar` VARCHAR(255) DEFAULT NULL,
  `role` VARCHAR(50) DEFAULT 'owner',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Application Settings
CREATE TABLE IF NOT EXISTS `app_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_user_setting` (`user_id`, `setting_key`),
  CONSTRAINT `fk_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Business Categories
CREATE TABLE IF NOT EXISTS `business_categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_buscat_user` (`user_id`),
  CONSTRAINT `fk_buscat_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Businesses
CREATE TABLE IF NOT EXISTS `businesses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `category_id` INT DEFAULT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `logo` VARCHAR(255) DEFAULT NULL,
  `start_date` DATE NOT NULL,
  `ownership_type` VARCHAR(50) DEFAULT 'Partnership',
  `collection_model` ENUM('direct', 'external', 'job') DEFAULT 'direct', -- direct: user collects 100% payments; external: partner collects, user gets share; job: salary/employment income
  `status` ENUM('Active', 'Inactive', 'Closed') DEFAULT 'Active',
  `notes` TEXT DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_bus_user` (`user_id`),
  KEY `idx_bus_cat` (`category_id`),
  CONSTRAINT `fk_bus_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bus_cat` FOREIGN KEY (`category_id`) REFERENCES `business_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Partners
CREATE TABLE IF NOT EXISTS `partners` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `email` VARCHAR(191) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `profile_pic` VARCHAR(255) DEFAULT NULL,
  `joining_date` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('Active', 'Inactive') DEFAULT 'Active',
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_partner_user` (`user_id`),
  CONSTRAINT `fk_partner_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Business Ownership (Effective dated)
CREATE TABLE IF NOT EXISTS `business_ownership` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT NOT NULL,
  `partner_id` INT DEFAULT NULL, -- NULL indicates the OWNER
  `percentage` DECIMAL(7,4) NOT NULL, -- e.g. 60.0000%
  `effective_date` DATE NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_own_business` (`business_id`),
  KEY `idx_own_partner` (`partner_id`),
  KEY `idx_own_effective` (`business_id`, `effective_date`),
  CONSTRAINT `fk_own_bus` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_own_partner` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Accounts & Wallets
CREATE TABLE IF NOT EXISTS `accounts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `account_name` VARCHAR(100) NOT NULL,
  `account_type` ENUM('cash', 'bank', 'jazzcash', 'easypaisa', 'wallet', 'other') DEFAULT 'bank',
  `provider_name` VARCHAR(100) DEFAULT NULL,
  `account_number` VARCHAR(100) DEFAULT NULL,
  `opening_balance` DECIMAL(18,2) DEFAULT 0.00,
  `opening_balance_date` DATE DEFAULT NULL,
  `current_balance` DECIMAL(18,2) DEFAULT 0.00,
  `description` TEXT DEFAULT NULL,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_acc_user` (`user_id`),
  CONSTRAINT `fk_acc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Income Categories
CREATE TABLE IF NOT EXISTS `income_categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `type` ENUM('business', 'personal', 'both') DEFAULT 'both',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_inccat_user` (`user_id`),
  CONSTRAINT `fk_inccat_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Expense Categories
CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `type` ENUM('business', 'personal', 'both') DEFAULT 'both',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_expcat_user` (`user_id`),
  CONSTRAINT `fk_expcat_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Business Income
CREATE TABLE IF NOT EXISTS `business_income` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `business_id` INT NOT NULL,
  `category_id` INT DEFAULT NULL,
  `account_id` INT DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `client_name` VARCHAR(150) DEFAULT NULL,
  `payment_type` ENUM('advance', 'partial', 'remaining', 'full', 'monthly', 'salary', 'bonus', 'allowance', 'other') DEFAULT 'full',
  `amount` DECIMAL(18,2) NOT NULL,
  `transaction_date` DATE NOT NULL,
  `financial_month` VARCHAR(7) NOT NULL, -- Format: YYYY-MM
  `received_date` DATE DEFAULT NULL,
  `payment_method` VARCHAR(50) DEFAULT 'Bank Transfer',
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `attachment` VARCHAR(255) DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_binc_user` (`user_id`),
  KEY `idx_binc_bus` (`business_id`),
  KEY `idx_binc_month` (`business_id`, `financial_month`),
  KEY `idx_binc_acc` (`account_id`),
  CONSTRAINT `fk_binc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_binc_bus` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_binc_cat` FOREIGN KEY (`category_id`) REFERENCES `income_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_binc_acc` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Business Expenses
CREATE TABLE IF NOT EXISTS `business_expenses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `business_id` INT NOT NULL,
  `category_id` INT DEFAULT NULL,
  `account_id` INT DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `client_name` VARCHAR(150) DEFAULT NULL,
  `payment_type` ENUM('advance', 'partial', 'remaining', 'full', 'monthly', 'salary', 'bonus', 'allowance', 'other') DEFAULT 'full',
  `amount` DECIMAL(18,2) NOT NULL,
  `date` DATE NOT NULL,
  `financial_month` VARCHAR(7) NOT NULL, -- Format: YYYY-MM
  `paid_through_account` VARCHAR(100) DEFAULT NULL,
  `payment_method` VARCHAR(50) DEFAULT 'Bank Transfer',
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `receipt_attachment` VARCHAR(255) DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_bexp_user` (`user_id`),
  KEY `idx_bexp_bus` (`business_id`),
  KEY `idx_bexp_month` (`business_id`, `financial_month`),
  KEY `idx_bexp_acc` (`account_id`),
  CONSTRAINT `fk_bexp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bexp_bus` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bexp_cat` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bexp_acc` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Monthly Closings
CREATE TABLE IF NOT EXISTS `monthly_closings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `business_id` INT NOT NULL,
  `financial_month` VARCHAR(7) NOT NULL, -- YYYY-MM
  `total_revenue` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `total_expenses` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `net_profit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `business_reserve` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `distributable_profit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft', 'finalized', 'reopened') DEFAULT 'draft',
  `finalized_at` DATETIME DEFAULT NULL,
  `finalized_by` INT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_bus_closing_month` (`business_id`, `financial_month`),
  KEY `idx_closing_user` (`user_id`),
  CONSTRAINT `fk_closing_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_closing_bus` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Profit Distributions (Snapshot per monthly closing)
CREATE TABLE IF NOT EXISTS `profit_distributions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `monthly_closing_id` INT NOT NULL,
  `business_id` INT NOT NULL,
  `partner_id` INT DEFAULT NULL, -- NULL indicates the Owner
  `financial_month` VARCHAR(7) NOT NULL,
  `ownership_percentage` DECIMAL(7,4) NOT NULL,
  `share_amount` DECIMAL(18,2) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_dist_closing` (`monthly_closing_id`),
  KEY `idx_dist_partner` (`partner_id`),
  CONSTRAINT `fk_dist_closing` FOREIGN KEY (`monthly_closing_id`) REFERENCES `monthly_closings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dist_bus` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dist_partner` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Partner Earnings (Ledger per business, partner, month)
CREATE TABLE IF NOT EXISTS `partner_earnings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `business_id` INT NOT NULL,
  `partner_id` INT NOT NULL,
  `financial_month` VARCHAR(7) NOT NULL,
  `opening_balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `earned_share` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `adjustments` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `closing_balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_partner_earning_month` (`business_id`, `partner_id`, `financial_month`),
  KEY `idx_pe_user` (`user_id`),
  CONSTRAINT `fk_pe_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pe_bus` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pe_partner` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Partner Payments
CREATE TABLE IF NOT EXISTS `partner_payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `business_id` INT NOT NULL,
  `partner_id` INT NOT NULL,
  `account_id` INT DEFAULT NULL,
  `financial_month` VARCHAR(7) DEFAULT NULL,
  `amount` DECIMAL(18,2) NOT NULL,
  `payment_date` DATE NOT NULL,
  `payment_method` VARCHAR(50) DEFAULT 'Bank Transfer',
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `attachment` VARCHAR(255) DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_ppay_bus` (`business_id`),
  KEY `idx_ppay_partner` (`partner_id`),
  KEY `idx_ppay_acc` (`account_id`),
  CONSTRAINT `fk_ppay_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ppay_bus` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ppay_partner` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ppay_acc` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Owner Share Receipts
CREATE TABLE IF NOT EXISTS `owner_share_receipts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `business_id` INT NOT NULL,
  `account_id` INT DEFAULT NULL,
  `financial_month` VARCHAR(7) DEFAULT NULL,
  `amount` DECIMAL(18,2) NOT NULL,
  `is_retained` TINYINT(1) DEFAULT 0,
  `receipt_date` DATE NOT NULL,
  `payment_method` VARCHAR(50) DEFAULT 'Bank Transfer',
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_oreceipt_user` (`user_id`),
  KEY `idx_oreceipt_bus` (`business_id`),
  KEY `idx_oreceipt_acc` (`account_id`),
  CONSTRAINT `fk_oreceipt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oreceipt_bus` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oreceipt_acc` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Personal Income Sources
CREATE TABLE IF NOT EXISTS `personal_income_sources` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `category_id` INT DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `income_type` ENUM('salary', 'freelancing', 'consultancy', 'rental', 'dividend', 'other') DEFAULT 'salary',
  `is_recurring` TINYINT(1) DEFAULT 1,
  `expected_amount` DECIMAL(18,2) DEFAULT 0.00,
  `frequency` ENUM('weekly', 'monthly', 'yearly', 'one_time') DEFAULT 'monthly',
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_pis_user` (`user_id`),
  CONSTRAINT `fk_pis_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pis_cat` FOREIGN KEY (`category_id`) REFERENCES `income_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Personal Income Transactions
CREATE TABLE IF NOT EXISTS `personal_income` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `source_id` INT DEFAULT NULL,
  `account_id` INT DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `client_name` VARCHAR(150) DEFAULT NULL,
  `payment_type` ENUM('advance', 'partial', 'remaining', 'full', 'monthly', 'salary', 'bonus', 'allowance', 'other') DEFAULT 'full',
  `amount` DECIMAL(18,2) NOT NULL,
  `income_date` DATE NOT NULL,
  `received_date` DATE DEFAULT NULL,
  `payment_status` ENUM('expected', 'partially_received', 'received', 'overdue') DEFAULT 'received',
  `payment_method` VARCHAR(50) DEFAULT 'Bank Transfer',
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `attachment` VARCHAR(255) DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_pinc_user` (`user_id`),
  KEY `idx_pinc_source` (`source_id`),
  KEY `idx_pinc_acc` (`account_id`),
  CONSTRAINT `fk_pinc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pinc_source` FOREIGN KEY (`source_id`) REFERENCES `personal_income_sources` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pinc_acc` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Personal Expenses
CREATE TABLE IF NOT EXISTS `personal_expenses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `category_id` INT DEFAULT NULL,
  `account_id` INT DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `client_name` VARCHAR(150) DEFAULT NULL,
  `payment_type` ENUM('advance', 'partial', 'remaining', 'full', 'monthly', 'salary', 'bonus', 'allowance', 'other') DEFAULT 'full',
  `amount` DECIMAL(18,2) NOT NULL,
  `expense_date` DATE NOT NULL,
  `payment_date` DATE DEFAULT NULL,
  `payment_method` VARCHAR(50) DEFAULT 'Cash',
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `attachment` VARCHAR(255) DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_pexp_user` (`user_id`),
  KEY `idx_pexp_cat` (`category_id`),
  KEY `idx_pexp_acc` (`account_id`),
  CONSTRAINT `fk_pexp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pexp_cat` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pexp_acc` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. Account Transactions (Consolidated inflows & outflows for accurate running balances)
CREATE TABLE IF NOT EXISTS `account_transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `account_id` INT NOT NULL,
  `transaction_type` ENUM(
    'deposit', 'withdrawal', 'transfer_in', 'transfer_out', 
    'business_income', 'business_expense', 
    'partner_payment', 'owner_receipt', 
    'personal_income', 'personal_expense', 
    'loan_given', 'loan_received', 'loan_repaid', 'loan_recovered'
  ) NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL, -- positive for inflow, positive for outflow (type defines sign)
  `direction` ENUM('in', 'out') NOT NULL,
  `transaction_date` DATE NOT NULL,
  `related_table` VARCHAR(50) DEFAULT NULL,
  `related_id` INT DEFAULT NULL,
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_at_user` (`user_id`),
  KEY `idx_at_acc` (`account_id`),
  KEY `idx_at_date` (`transaction_date`),
  CONSTRAINT `fk_at_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_at_acc` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Account Transfers (Fund movements between user accounts)
CREATE TABLE IF NOT EXISTS `account_transfers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `from_account_id` INT NOT NULL,
  `to_account_id` INT NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL,
  `transfer_date` DATE NOT NULL,
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_trans_user` (`user_id`),
  CONSTRAINT `fk_trans_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_trans_from` FOREIGN KEY (`from_account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_trans_to` FOREIGN KEY (`to_account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. Loans (Given or Borrowed)
CREATE TABLE IF NOT EXISTS `loans` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `account_id` INT DEFAULT NULL,
  `loan_type` ENUM('given', 'borrowed') NOT NULL, -- given = receivable, borrowed = payable
  `person_name` VARCHAR(150) NOT NULL,
  `contact_number` VARCHAR(50) DEFAULT NULL,
  `amount` DECIMAL(18,2) NOT NULL,
  `current_balance` DECIMAL(18,2) NOT NULL,
  `loan_date` DATE NOT NULL,
  `due_date` DATE DEFAULT NULL,
  `purpose` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('active', 'settled', 'overdue') DEFAULT 'active',
  `description` TEXT DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_loan_user` (`user_id`),
  CONSTRAINT `fk_loan_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_loan_acc` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 23. Loan Transactions (Repayments or Recoveries)
CREATE TABLE IF NOT EXISTS `loan_transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `loan_id` INT NOT NULL,
  `account_id` INT DEFAULT NULL,
  `transaction_type` ENUM('repayment', 'recovery') NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL,
  `transaction_date` DATE NOT NULL,
  `payment_method` VARCHAR(50) DEFAULT 'Cash',
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_lt_loan` (`loan_id`),
  CONSTRAINT `fk_lt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lt_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lt_acc` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 24. Investments
CREATE TABLE IF NOT EXISTS `investments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `business_id` INT DEFAULT NULL,
  `name` VARCHAR(150) NOT NULL,
  `category` ENUM('property', 'stocks', 'mutual_funds', 'business_investment', 'gold', 'crypto', 'other') DEFAULT 'stocks',
  `investment_date` DATE NOT NULL,
  `invested_amount` DECIMAL(18,2) NOT NULL,
  `current_value` DECIMAL(18,2) NOT NULL,
  `expected_return` DECIMAL(18,2) DEFAULT 0.00,
  `description` TEXT DEFAULT NULL,
  `status` ENUM('active', 'liquidated') DEFAULT 'active',
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_inv_user` (`user_id`),
  CONSTRAINT `fk_inv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inv_bus` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 25. Assets
CREATE TABLE IF NOT EXISTS `assets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `category` ENUM('vehicle', 'property', 'electronics', 'furniture', 'jewelry', 'equipment', 'other') DEFAULT 'electronics',
  `purchase_date` DATE NOT NULL,
  `purchase_value` DECIMAL(18,2) NOT NULL,
  `current_estimated_value` DECIMAL(18,2) NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_asset_user` (`user_id`),
  CONSTRAINT `fk_asset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 26. Recurring Transactions
CREATE TABLE IF NOT EXISTS `recurring_transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `transaction_type` ENUM('income', 'expense') NOT NULL,
  `category_id` INT DEFAULT NULL,
  `account_id` INT DEFAULT NULL,
  `title` VARCHAR(150) NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL,
  `frequency` ENUM('weekly', 'monthly', 'quarterly', 'yearly') DEFAULT 'monthly',
  `start_date` DATE NOT NULL,
  `end_date` DATE DEFAULT NULL,
  `reminder_days_before` INT DEFAULT 3,
  `is_active` TINYINT(1) DEFAULT 1,
  `last_generated_date` DATE DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_rec_user` (`user_id`),
  CONSTRAINT `fk_rec_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 27. In-App Notifications
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `notification_type` ENUM('closing_reminder', 'partner_payment', 'share_outstanding', 'recurring_due', 'loan_due', 'general') DEFAULT 'general',
  `link_url` VARCHAR(255) DEFAULT NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_notif_user` (`user_id`),
  KEY `idx_notif_read` (`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 28. Audit Logs
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `record_type` VARCHAR(50) NOT NULL,
  `record_id` INT DEFAULT NULL,
  `action` VARCHAR(50) NOT NULL,
  `previous_values` LONGTEXT DEFAULT NULL,
  `new_values` LONGTEXT DEFAULT NULL,
  `reason` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(50) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_type_id` (`record_type`, `record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
