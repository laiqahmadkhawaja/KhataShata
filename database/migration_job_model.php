<?php
require_once __DIR__ . '/../config/app.php';
$db = get_db_connection();

echo "Running Job Model Migration...\n";

// 1. Expand collection_model ENUM in businesses
try {
    $db->exec("ALTER TABLE `businesses` MODIFY COLUMN `collection_model` ENUM('direct', 'external', 'job') NOT NULL DEFAULT 'direct'");
    echo "[OK] Modified businesses.collection_model to ENUM('direct', 'external', 'job').\n";
} catch (Exception $e) {
    echo "[ERROR] modifying collection_model: " . $e->getMessage() . "\n";
}

// 2. Expand payment_type ENUM in business_income
try {
    $db->exec("ALTER TABLE `business_income` MODIFY COLUMN `payment_type` ENUM('advance', 'partial', 'remaining', 'full', 'monthly', 'salary', 'bonus', 'allowance', 'other') NOT NULL DEFAULT 'full'");
    echo "[OK] Modified business_income.payment_type to include salary, bonus, allowance.\n";
} catch (Exception $e) {
    echo "[ERROR] modifying payment_type: " . $e->getMessage() . "\n";
}

// 3. Insert standard Job Salary Categories into income_categories
$job_cats = [
    ['name' => 'Job Salary (جاب / ملازمت کی تنخواہ)', 'type' => 'business'],
    ['name' => 'Job Bonus & Incentive (ملازمت کا بونس)', 'type' => 'business'],
    ['name' => 'Job Allowance & Perks (الاؤنس / میڈیکل / فیول)', 'type' => 'business'],
];

foreach ($job_cats as $jc) {
    $chk = $db->prepare("SELECT id FROM income_categories WHERE name = :name LIMIT 1");
    $chk->execute(['name' => $jc['name']]);
    if (!$chk->fetchColumn()) {
        $ins = $db->prepare("INSERT INTO income_categories (user_id, name, type, is_active) VALUES (1, :name, :type, 1)");
        $ins->execute(['name' => $jc['name'], 'type' => $jc['type']]);
        echo "[OK] Inserted category: {$jc['name']}\n";
    } else {
        echo "[INFO] Category already exists: {$jc['name']}\n";
    }
}

echo "Job Model Migration finished successfully!\n";
