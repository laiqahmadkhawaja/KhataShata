<?php
require_once __DIR__ . '/../config/app.php';
$db = get_db_connection();

echo "Running Migration...\n";

// 1. Add collection_model to businesses table if not exists
try {
    $db->exec("ALTER TABLE `businesses` ADD COLUMN `collection_model` ENUM('direct', 'external') NOT NULL DEFAULT 'direct' AFTER `ownership_type`");
    echo "Added collection_model to businesses.\n";
} catch (Exception $e) {
    echo "collection_model already exists or notice: " . $e->getMessage() . "\n";
}

// 2. Add payment_type and client_name to business_income table if not exists
try {
    $db->exec("ALTER TABLE `business_income` ADD COLUMN `payment_type` ENUM('advance', 'partial', 'remaining', 'full', 'monthly', 'other') NOT NULL DEFAULT 'full' AFTER `title`");
    echo "Added payment_type to business_income.\n";
} catch (Exception $e) {
    echo "payment_type already exists or notice: " . $e->getMessage() . "\n";
}

try {
    $db->exec("ALTER TABLE `business_income` ADD COLUMN `client_name` VARCHAR(150) DEFAULT NULL AFTER `title`");
    echo "Added client_name to business_income.\n";
} catch (Exception $e) {
    echo "client_name already exists or notice: " . $e->getMessage() . "\n";
}

// 3. Add is_retained to owner_share_receipts if not exists
try {
    $db->exec("ALTER TABLE `owner_share_receipts` ADD COLUMN `is_retained` TINYINT(1) NOT NULL DEFAULT 0 AFTER `amount`");
    echo "Added is_retained to owner_share_receipts.\n";
} catch (Exception $e) {
    echo "is_retained already exists or notice: " . $e->getMessage() . "\n";
}

// 4. Insert standard SaaS & Software Categories into income_categories
$saas_cats = [
    ['name' => 'SaaS Subscription (ماہانہ سافٹ ویئر فیس)', 'type' => 'business'],
    ['name' => 'Custom Software Development (کسٹم سافٹ ویئر)', 'type' => 'business'],
    ['name' => 'Web & Mobile App Development (ویب و ایپ ڈویلپمنٹ)', 'type' => 'business'],
    ['name' => 'Software Maintenance & Support (مینٹیننس اور سپورٹ)', 'type' => 'business'],
];

foreach ($saas_cats as $sc) {
    $chk = $db->prepare("SELECT id FROM income_categories WHERE name = :name LIMIT 1");
    $chk->execute(['name' => $sc['name']]);
    if (!$chk->fetchColumn()) {
        $ins = $db->prepare("INSERT INTO income_categories (user_id, name, type, is_active) VALUES (1, :name, :type, 1)");
        $ins->execute(['name' => $sc['name'], 'type' => $sc['type']]);
        echo "Inserted category: {$sc['name']}\n";
    }
}

// 5. Update existing business_income entries with appropriate payment_type and client_name based on their titles!
// Titles were:
// ID 1: 'Zafar' -> client: Zafar, type: advance
// ID 2: 'Al Rasheed Traders UMS' -> client: Al Rasheed Traders UMS, type: full
// ID 3: 'Zafar POS Remaining' -> client: Zafar POS, type: remaining
// ID 4: 'Garment Manufacturing Hosery' -> client: Garment Manufacturing Hosery, type: full
// ID 5: 'Zafar Remaining Final' -> client: Zafar, type: remaining

$db->exec("UPDATE business_income SET client_name = 'Zafar', payment_type = 'advance' WHERE id = 1");
$db->exec("UPDATE business_income SET client_name = 'Al Rasheed Traders', payment_type = 'full' WHERE id = 2");
$db->exec("UPDATE business_income SET client_name = 'Zafar (POS)', payment_type = 'partial' WHERE id = 3");
$db->exec("UPDATE business_income SET client_name = 'Garment Manufacturing Hosery', payment_type = 'full' WHERE id = 4");
$db->exec("UPDATE business_income SET client_name = 'Zafar', payment_type = 'remaining' WHERE id = 5");
echo "Updated existing business_income payment types.\n";

// 6. Fix the double-counted owner receipts (ID 1 and 2 in owner_share_receipts)
// ID 1: 2000, ID 2: 3000 -> Total 5000 added into account_transactions ID 22 and 23.
$tx22 = $db->query("SELECT * FROM account_transactions WHERE id = 22 AND related_table = 'owner_share_receipts'")->fetch();
$tx23 = $db->query("SELECT * FROM account_transactions WHERE id = 23 AND related_table = 'owner_share_receipts'")->fetch();

if ($tx22 || $tx23) {
    echo "Found duplicate owner_receipt transactions in account_transactions (IDs 22, 23). Cleaning them up...\n";
    $db->beginTransaction();
    if ($tx22) {
        $db->exec("DELETE FROM account_transactions WHERE id = 22");
    }
    if ($tx23) {
        $db->exec("DELETE FROM account_transactions WHERE id = 23");
    }
    // Update owner_share_receipts to mark is_retained = 1
    $db->exec("UPDATE owner_share_receipts SET is_retained = 1 WHERE id IN (1, 2)");
    // Correct the Cash in Hand account balance (decrease by 5000)
    $db->exec("UPDATE accounts SET current_balance = current_balance - 5000 WHERE id = 1");
    $db->commit();
    echo "Cleaned duplicate transactions and restored Account #1 balance from 12400 to 7400.\n";
}

echo "Migration finished successfully!\n";
