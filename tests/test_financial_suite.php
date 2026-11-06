<?php
/**
 * KhataShata - Comprehensive Financial QA & Test Suite
 * Executes every required test scenario from PRD Section 22
 */

require_once __DIR__ . '/../config/app.php';

echo "========================================================\n";
echo "KhataShata - Section 22 Automated Financial QA Suite\n";
echo "========================================================\n\n";

$db = get_db_connection();
$passed = 0;
$failed = 0;

function assert_test($description, $condition, $details = "") {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $description\n";
        $passed++;
    } else {
        echo "[FAIL] $description : $details\n";
        $failed++;
    }
}

// -------------------------------------------------------------
// Test 1: User authentication verification
// -------------------------------------------------------------
$stmt = $db->query("SELECT * FROM users WHERE email = 'admin@khatashata.com' LIMIT 1");
$admin = $stmt->fetch();
assert_test("Default admin user exists", !empty($admin));
assert_test("Password verify works for default account", password_verify('password123', $admin['password_hash']));

// -------------------------------------------------------------
// Test 2: Create new business
// -------------------------------------------------------------
$test_biz_name = 'Test Alpha Venture ' . time();
$stmt = $db->prepare("INSERT INTO businesses (user_id, category_id, name, start_date, ownership_type, status) VALUES (1, 1, :name, '2026-01-01', 'Partnership', 'Active')");
$stmt->execute(['name' => $test_biz_name]);
$test_biz_id = (int)$db->lastInsertId();
assert_test("Create new business", $test_biz_id > 0);

// -------------------------------------------------------------
// Test 3: Add 3 partners
// -------------------------------------------------------------
$partner_ids = [];
for ($i = 1; $i <= 3; $i++) {
    $pname = "Test Partner $i (" . time() . ")";
    $pstmt = $db->prepare("INSERT INTO partners (user_id, name, phone, status) VALUES (1, :name, '0300123456$i', 'Active')");
    $pstmt->execute(['name' => $pname]);
    $partner_ids[] = (int)$db->lastInsertId();
}
assert_test("Add 3 partners", count($partner_ids) === 3);

// -------------------------------------------------------------
// Test 4: Set ownership to 60/25/15 -> Total validated (100%)
// -------------------------------------------------------------
$valid_ownership = [
    ['partner_id' => null, 'percentage' => 60.0, 'is_owner' => true],
    ['partner_id' => $partner_ids[0], 'percentage' => 25.0, 'is_owner' => false],
    ['partner_id' => $partner_ids[1], 'percentage' => 15.0, 'is_owner' => false]
];
$val_res = FinancialEngine::validateOwnership($valid_ownership);
assert_test("Set ownership to 60/25/15 (Total 100%) validates successfully", $val_res['valid'] === true);

// -------------------------------------------------------------
// Test 5: Set ownership to 60/25/10 -> Total 95% -> Distribution blocked!
// -------------------------------------------------------------
$invalid_ownership = [
    ['partner_id' => null, 'percentage' => 60.0, 'is_owner' => true],
    ['partner_id' => $partner_ids[0], 'percentage' => 25.0, 'is_owner' => false],
    ['partner_id' => $partner_ids[1], 'percentage' => 10.0, 'is_owner' => false]
];
$val_bad = FinancialEngine::validateOwnership($invalid_ownership);
assert_test("Set ownership to 60/25/10 (Total 95%) is blocked", $val_bad['valid'] === false);

// Save valid ownership to database for test business
$eff_date = '2026-10-01';
foreach ($valid_ownership as $vo) {
    $ins = $db->prepare("INSERT INTO business_ownership (business_id, partner_id, percentage, effective_date, notes) VALUES (:bid, :pid, :pct, :eff, 'Initial QA setup')");
    $ins->execute([
        'bid' => $test_biz_id,
        'pid' => $vo['partner_id'],
        'pct' => $vo['percentage'],
        'eff' => $eff_date
    ]);
}

// -------------------------------------------------------------
// Test 6: Record Rs. 150,000 revenue & Rs. 50,000 expense
// -------------------------------------------------------------
$test_month = '2026-10';
$ins_inc = $db->prepare("INSERT INTO business_income (user_id, business_id, title, amount, transaction_date, financial_month) VALUES (1, :bid, 'Client Retainer October', 150000.00, '2026-10-15', :m)");
$ins_inc->execute(['bid' => $test_biz_id, 'm' => $test_month]);

$ins_exp = $db->prepare("INSERT INTO business_expenses (user_id, business_id, title, amount, date, financial_month) VALUES (1, :bid, 'Office Rent & Tools', 50000.00, '2026-10-20', :m)");
$ins_exp->execute(['bid' => $test_biz_id, 'm' => $test_month]);

$rev = FinancialEngine::getBusinessRevenue($test_biz_id, $test_month);
$exp = FinancialEngine::getBusinessExpenses($test_biz_id, $test_month);
assert_test("Record Rs. 150,000 income", abs($rev - 150000.00) < 0.01);
assert_test("Record Rs. 50,000 expense", abs($exp - 50000.00) < 0.01);

// -------------------------------------------------------------
// Test 7: Calculate monthly profit -> Rs. 100,000
// -------------------------------------------------------------
$summary = FinancialEngine::getMonthlySummary($test_biz_id, $test_month);
assert_test("Calculate monthly net profit = Rs. 100,000", abs($summary['net_profit'] - 100000.00) < 0.01);

// -------------------------------------------------------------
// Test 8: Calculate 60% owner share -> Rs. 60,000; 25% -> 25k; 15% -> 15k
// -------------------------------------------------------------
$owner_share = 0; $p1_share = 0; $p2_share = 0;
foreach ($summary['distributions'] as $d) {
    if ($d['is_owner']) $owner_share = $d['share_amount'];
    elseif ($d['partner_id'] == $partner_ids[0]) $p1_share = $d['share_amount'];
    elseif ($d['partner_id'] == $partner_ids[1]) $p2_share = $d['share_amount'];
}
assert_test("Calculate 60% owner share = Rs. 60,000", abs($owner_share - 60000.00) < 0.01);
assert_test("Calculate 25% Partner 1 share = Rs. 25,000", abs($p1_share - 25000.00) < 0.01);
assert_test("Calculate 15% Partner 2 share = Rs. 15,000", abs($p2_share - 15000.00) < 0.01);
assert_test("Total distributions exactly equal distributable profit", abs(($owner_share + $p1_share + $p2_share) - $summary['distributable_profit']) < 0.001);

// -------------------------------------------------------------
// Test 9: Close a month -> Financial records locked
// -------------------------------------------------------------
$close_res = FinancialEngine::finalizeMonthlyClosing($test_biz_id, $test_month, 0.00, 'QA Month Finalization', 1);
assert_test("Close month successfully finalized", $close_res['success'] === true);

$check_summary = FinancialEngine::getMonthlySummary($test_biz_id, $test_month);
assert_test("Month status is finalized", $check_summary['status'] === 'finalized');

// Try to close again -> should be blocked
$close_again = FinancialEngine::finalizeMonthlyClosing($test_biz_id, $test_month, 0.00, '', 1);
assert_test("Duplicate finalization blocked", $close_again['success'] === false);

// -------------------------------------------------------------
// Test 10: Partner partial payment -> Outstanding updated correctly
// -------------------------------------------------------------
// Partner 1 earned 25,000. Pay 10,000.
$pay_res1 = FinancialEngine::recordPartnerPayment([
    'business_id' => $test_biz_id,
    'partner_id' => $partner_ids[0],
    'account_id' => null,
    'financial_month' => $test_month,
    'amount' => 10000.00,
    'payment_date' => '2026-10-25'
], 1);
assert_test("Record partial partner payment 1 (Rs. 10,000)", $pay_res1['success'] === true, $pay_res1['error'] ?? '');

// Check remaining
$stmt_pe = $db->prepare("SELECT closing_balance FROM partner_earnings WHERE business_id = :bid AND partner_id = :pid AND financial_month = :m");
$stmt_pe->execute(['bid' => $test_biz_id, 'pid' => $partner_ids[0], 'm' => $test_month]);
$bal1 = (float)$stmt_pe->fetchColumn();
assert_test("Partner outstanding balance after Rs. 10,000 payment = Rs. 15,000", abs($bal1 - 15000.00) < 0.01);

// Pay another 5,000
$pay_res2 = FinancialEngine::recordPartnerPayment([
    'business_id' => $test_biz_id,
    'partner_id' => $partner_ids[0],
    'account_id' => null,
    'financial_month' => $test_month,
    'amount' => 5000.00,
    'payment_date' => '2026-10-26'
], 1);
$stmt_pe->execute(['bid' => $test_biz_id, 'pid' => $partner_ids[0], 'm' => $test_month]);
$bal2 = (float)$stmt_pe->fetchColumn();
assert_test("Partner outstanding balance after 2nd payment (Rs. 5,000) = Rs. 10,000", abs($bal2 - 10000.00) < 0.01);

// -------------------------------------------------------------
// Test 11: Owner partial share receipt -> Owner receivable updated
// -------------------------------------------------------------
// Owner earned 60,000. Draw 40,000 into personal account.
$acc_stmt = $db->prepare("INSERT INTO accounts (user_id, account_name, account_type, opening_balance, current_balance, status) VALUES (1, 'QA Test Wallet', 'cash', 5000.00, 5000.00, 'active')");
$acc_stmt->execute();
$test_acc_id = (int)$db->lastInsertId();

$rec_res = FinancialEngine::recordOwnerShareReceipt([
    'business_id' => $test_biz_id,
    'account_id' => $test_acc_id,
    'financial_month' => $test_month,
    'amount' => 40000.00,
    'receipt_date' => '2026-10-28'
], 1);
assert_test("Record owner share receipt of Rs. 40,000", $rec_res['success'] === true);

// Check account balance = 5,000 + 40,000 = 45,000
$chk_acc = $db->prepare("SELECT current_balance FROM accounts WHERE id = :id");
$chk_acc->execute(['id' => $test_acc_id]);
$new_acc_bal = (float)$chk_acc->fetchColumn();
assert_test("Owner personal account balance increased to Rs. 45,000", abs($new_acc_bal - 45000.00) < 0.01);

// -------------------------------------------------------------
// Test 12: Change future ownership -> Previous closing unchanged!
// -------------------------------------------------------------
// Effective from Nov 01: Owner becomes 70%, Partner 1 20%, Partner 2 10%
$future_eff_date = '2026-11-01';
$new_future_ownership = [
    ['partner_id' => null, 'percentage' => 70.0, 'eff' => $future_eff_date],
    ['partner_id' => $partner_ids[0], 'percentage' => 20.0, 'eff' => $future_eff_date],
    ['partner_id' => $partner_ids[1], 'percentage' => 10.0, 'eff' => $future_eff_date]
];
foreach ($new_future_ownership as $nfo) {
    $ins = $db->prepare("INSERT INTO business_ownership (business_id, partner_id, percentage, effective_date, notes) VALUES (:bid, :pid, :pct, :eff, 'November restructuring')");
    $ins->execute([
        'bid' => $test_biz_id,
        'pid' => $nfo['partner_id'],
        'pct' => $nfo['percentage'],
        'eff' => $nfo['eff']
    ]);
}

// Check October distribution still reflects snapshot of 60,000!
$oct_summary = FinancialEngine::getMonthlySummary($test_biz_id, '2026-10');
$oct_owner_share = 0;
foreach ($oct_summary['distributions'] as $d) {
    if ($d['is_owner']) $oct_owner_share = $d['share_amount'];
}
assert_test("Historical finalized October closing remains locked at Rs. 60,000 after future ownership revision", abs($oct_owner_share - 60000.00) < 0.01);

// -------------------------------------------------------------
// Test 13: Account to Account Transfer
// -------------------------------------------------------------
$acc_stmt2 = $db->prepare("INSERT INTO accounts (user_id, account_name, account_type, opening_balance, current_balance, status) VALUES (1, 'QA Test Bank', 'bank', 10000.00, 10000.00, 'active')");
$acc_stmt2->execute();
$test_acc_id2 = (int)$db->lastInsertId();

// Transfer 15,000 from Wallet (was 45,000) to Bank (was 10,000)
$trf_res = FinancialEngine::executeAccountTransfer($test_acc_id, $test_acc_id2, 15000.00, '2026-10-29', 'QA Test Transfer', 1);
assert_test("Execute transfer of Rs. 15,000", $trf_res['success'] === true);

$chk_acc->execute(['id' => $test_acc_id]);
$bal_wallet = (float)$chk_acc->fetchColumn();
$chk_acc->execute(['id' => $test_acc_id2]);
$bal_bank = (float)$chk_acc->fetchColumn();

assert_test("Source wallet reduced to Rs. 30,000", abs($bal_wallet - 30000.00) < 0.01);
assert_test("Destination bank increased to Rs. 25,000", abs($bal_bank - 25000.00) < 0.01);
assert_test("Combined total cash unchanged (30k + 25k = 55k)", abs(($bal_wallet + $bal_bank) - 55000.00) < 0.01);

// -------------------------------------------------------------
// Test 14: Soft-deletion preserves audit history
// -------------------------------------------------------------
$del_stmt = $db->prepare("UPDATE businesses SET is_deleted = 1 WHERE id = :id");
$del_stmt->execute(['id' => $test_biz_id]);
$history_check = $db->prepare("SELECT COUNT(*) FROM monthly_closings WHERE business_id = :id");
$history_check->execute(['id' => $test_biz_id]);
$closings_count = (int)$history_check->fetchColumn();
assert_test("Soft-deleting business preserves its historical financial closings in database", $closings_count > 0);

// -------------------------------------------------------------
// Test 15: Job Collection & Revenue Model Integration
// -------------------------------------------------------------
$db->exec("
    INSERT INTO businesses (user_id, name, start_date, ownership_type, collection_model, status)
    VALUES (1, 'QA Tech Corp (Employment Job)', '2026-10-01', 'Sole Proprietorship', 'job', 'Active')
");
$job_biz_id = $db->lastInsertId();
assert_test("Create business with collection_model = 'job'", $job_biz_id > 0);

$db->exec("
    INSERT INTO business_ownership (business_id, partner_id, percentage, effective_date)
    VALUES ($job_biz_id, NULL, 100.00, '2026-10-01')
");

$db->exec("
    INSERT INTO business_income (user_id, business_id, title, client_name, payment_type, amount, transaction_date, financial_month, payment_method)
    VALUES (1, $job_biz_id, 'October 2026 Monthly Salary', 'QA Tech Corp', 'salary', 120000.00, '2026-10-31', '2026-10', 'Bank Transfer')
");
$job_inc_id = $db->lastInsertId();
assert_test("Record Rs. 120,000 job salary with payment_type = 'salary'", $job_inc_id > 0);

$job_summary = FinancialEngine::getMonthlySummary($job_biz_id, '2026-10');
assert_test("Job revenue matches Rs. 120,000", abs($job_summary['total_revenue'] - 120000.00) < 0.01);
assert_test("Job 100% owner share matches Rs. 120,000", abs($job_summary['distributions'][0]['share_amount'] - 120000.00) < 0.01);
assert_test("Job has no external partner distributions", count($job_summary['distributions']) === 1);

// Clean up test job business
$db->exec("UPDATE businesses SET is_deleted = 1 WHERE id = $job_biz_id");

// -------------------------------------------------------------
// Test 16: Client Software Deals & Spreadsheet Math Verification
// -------------------------------------------------------------
// Deal 1: Zafar Brothers Iron (POS) -> Total: 15,000, Adv: 5,000, Paid: 10,000 -> Rem: 0
$d1 = $db->query("SELECT * FROM client_deals WHERE client_name = 'Zafar Brothers Iron' AND is_deleted = 0 LIMIT 1")->fetch();
assert_test("Zafar Brothers deal exists", !empty($d1));
$d1_rem = round((float)$d1['total_amount'] - ((float)$d1['advance_amount'] + (float)$d1['client_paid_amount']), 2);
assert_test("Zafar Brothers client remaining balance is Rs 0.00", abs($d1_rem - 0.00) < 0.01);
assert_test("Zafar Brothers partner (40%) paid is Rs 6,000", abs((float)$d1['partner_paid_amount'] - 6000.00) < 0.01);

// Deal 2: Al Rasheed Traders (UMS) -> Total: 10,000, Adv: 5,000, Paid: 0 -> Rem: 5,000
$d2 = $db->query("SELECT * FROM client_deals WHERE client_name = 'Al Rasheed Traders' AND is_deleted = 0 LIMIT 1")->fetch();
assert_test("Al Rasheed Traders deal exists", !empty($d2));
$d2_rem = round((float)$d2['total_amount'] - ((float)$d2['advance_amount'] + (float)$d2['client_paid_amount']), 2);
assert_test("Al Rasheed Traders client remaining balance is Rs 5,000", abs($d2_rem - 5000.00) < 0.01);
$d2_partner_rem = round(((float)$d2['total_amount'] * 0.40) - (float)$d2['partner_paid_amount'], 2);
assert_test("Al Rasheed Traders partner remaining due is Rs 2,000", abs($d2_partner_rem - 2000.00) < 0.01);

// Deal 3: Smart Fit Hoisery (Manufacturing) -> Total: 25,000, Adv: 5,000, Paid: 0 -> Rem: 20,000
$d3 = $db->query("SELECT * FROM client_deals WHERE client_name = 'Smart Fit Hoisery' AND is_deleted = 0 LIMIT 1")->fetch();
assert_test("Smart Fit Hoisery deal exists", !empty($d3));
$d3_rem = round((float)$d3['total_amount'] - ((float)$d3['advance_amount'] + (float)$d3['client_paid_amount']), 2);
assert_test("Smart Fit Hoisery client remaining balance is Rs 20,000", abs($d3_rem - 20000.00) < 0.01);
$d3_partner_rem = round(((float)$d3['total_amount'] * 0.40) - (float)$d3['partner_paid_amount'], 2);
assert_test("Smart Fit Hoisery partner remaining due is Rs 10,000", abs($d3_partner_rem - 10000.00) < 0.01);

// Test totals across all 3 deals:
$sums = $db->query("
    SELECT 
        SUM(total_amount) as sum_total,
        SUM(advance_amount) as sum_adv,
        SUM(client_paid_amount) as sum_cpaid,
        SUM(monthly_amount) as sum_monthly,
        SUM(partner_paid_amount) as sum_ppaid
    FROM client_deals 
    WHERE is_deleted = 0
")->fetch();
assert_test("Total deals value equals Rs 50,000", abs((float)$sums['sum_total'] - 50000.00) < 0.01);
assert_test("Total client collected (Adv + Paid) equals Rs 25,000", abs(((float)$sums['sum_adv'] + (float)$sums['sum_cpaid']) - 25000.00) < 0.01);
assert_test("Total client remaining equals Rs 25,000", abs(((float)$sums['sum_total'] - ((float)$sums['sum_adv'] + (float)$sums['sum_cpaid'])) - 25000.00) < 0.01);
assert_test("Total monthly retainers equals Rs 4,000", abs((float)$sums['sum_monthly'] - 4000.00) < 0.01);
assert_test("Total partner paid equals Rs 8,000", abs((float)$sums['sum_ppaid'] - 8000.00) < 0.01);
$partner_total_cut = (float)$sums['sum_total'] * 0.40;
assert_test("Total partner remaining due equals Rs 12,000 (20k - 8k)", abs(($partner_total_cut - (float)$sums['sum_ppaid']) - 12000.00) < 0.01);



// Summary
echo "\n========================================================\n";
echo "QA SUITE SUMMARY: $passed PASSED, $failed FAILED\n";
echo "========================================================\n";

if ($failed === 0) {
    echo "SUCCESS: ALL FINANCIAL INTEGRITY TESTS COMPLETED WITH 100% PASS RATE!\n";
} else {
    echo "ATTENTION: SOME TESTS FAILED.\n";
}
