<?php
require_once __DIR__ . '/../config/app.php';
$db = get_db_connection();

echo "Running Client Deals Migration...\n";

// 1. Create client_deals table
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `client_deals` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `business_id` INT NOT NULL,
          `partner_id` INT DEFAULT NULL,
          `client_name` VARCHAR(150) NOT NULL,
          `software_type` VARCHAR(100) NOT NULL,
          `total_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
          `advance_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
          `client_paid_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
          `monthly_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
          `partner_cut_pct` DECIMAL(5,2) NOT NULL DEFAULT 40.00,
          `partner_paid_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
          `start_date` DATE DEFAULT NULL,
          `status` ENUM('Active', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Active',
          `notes` TEXT DEFAULT NULL,
          `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY `idx_cd_user` (`user_id`),
          KEY `idx_cd_biz` (`business_id`),
          KEY `idx_cd_partner` (`partner_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "[OK] Created client_deals table.\n";
} catch (Exception $e) {
    echo "[ERROR] Creating client_deals table: " . $e->getMessage() . "\n";
}

// 2. Create deal_transactions table (history of payments received from client and payouts to partner)
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `deal_transactions` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `deal_id` INT NOT NULL,
          `user_id` INT NOT NULL,
          `type` ENUM('client_payment', 'partner_payout') NOT NULL,
          `payment_type` ENUM('advance', 'milestone', 'monthly', 'payout') NOT NULL DEFAULT 'milestone',
          `amount` DECIMAL(18,2) NOT NULL,
          `account_id` INT DEFAULT NULL,
          `transaction_date` DATE NOT NULL,
          `payment_method` VARCHAR(50) DEFAULT 'Bank Transfer',
          `reference_no` VARCHAR(100) DEFAULT NULL,
          `notes` TEXT DEFAULT NULL,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY `idx_dt_deal` (`deal_id`),
          KEY `idx_dt_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "[OK] Created deal_transactions table.\n";
} catch (Exception $e) {
    echo "[ERROR] Creating deal_transactions table: " . $e->getMessage() . "\n";
}

// 3. Seed exact deals from user's Google Sheet if not already present
$deals = [
    [
        'client_name' => 'Zafar Brothers Iron',
        'software_type' => 'POS',
        'total_amount' => 15000.00,
        'advance_amount' => 5000.00,
        'client_paid_amount' => 10000.00,
        'monthly_amount' => 2000.00,
        'partner_cut_pct' => 40.00,
        'partner_paid_amount' => 6000.00,
        'start_date' => '2026-10-01',
        'status' => 'Completed',
        'notes' => 'Point of Sale system for retail iron store.'
    ],
    [
        'client_name' => 'Al Rasheed Traders',
        'software_type' => 'UMS',
        'total_amount' => 10000.00,
        'advance_amount' => 5000.00,
        'client_paid_amount' => 0.00,
        'monthly_amount' => 2000.00,
        'partner_cut_pct' => 40.00,
        'partner_paid_amount' => 2000.00,
        'start_date' => '2026-11-01',
        'status' => 'Active',
        'notes' => 'User Management & Billing System.'
    ],
    [
        'client_name' => 'Smart Fit Hoisery',
        'software_type' => 'Manufacturing',
        'total_amount' => 25000.00,
        'advance_amount' => 5000.00,
        'client_paid_amount' => 0.00,
        'monthly_amount' => 0.00,
        'partner_cut_pct' => 40.00,
        'partner_paid_amount' => 0.00,
        'start_date' => '2026-11-01',
        'status' => 'Active',
        'notes' => 'Garment Manufacturing & Inventory System.'
    ]
];

foreach ($deals as $d) {
    $chk = $db->prepare("SELECT id FROM client_deals WHERE client_name = :cname AND is_deleted = 0 LIMIT 1");
    $chk->execute(['cname' => $d['client_name']]);
    if (!$chk->fetchColumn()) {
        $ins = $db->prepare("
            INSERT INTO client_deals 
            (user_id, business_id, partner_id, client_name, software_type, total_amount, advance_amount, client_paid_amount, monthly_amount, partner_cut_pct, partner_paid_amount, start_date, status, notes)
            VALUES (1, 1, 1, :cname, :stype, :tamt, :adv, :cpaid, :mamt, :ppct, :ppaid, :sdate, :status, :notes)
        ");
        $ins->execute([
            'cname' => $d['client_name'],
            'stype' => $d['software_type'],
            'tamt' => $d['total_amount'],
            'adv' => $d['advance_amount'],
            'cpaid' => $d['client_paid_amount'],
            'mamt' => $d['monthly_amount'],
            'ppct' => $d['partner_cut_pct'],
            'ppaid' => $d['partner_paid_amount'],
            'sdate' => $d['start_date'],
            'status' => $d['status'],
            'notes' => $d['notes']
        ]);
        echo "[OK] Inserted deal for {$d['client_name']} ({$d['software_type']})\n";
    } else {
        echo "[INFO] Deal already exists for {$d['client_name']}\n";
    }
}

echo "Client Deals Migration finished successfully!\n";
