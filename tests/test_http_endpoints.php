<?php
$cookie_file = __DIR__ . '/cookie.txt';
$base = 'http://localhost/Khata/';

// 1. Get CSRF token from login page
$html = @file_get_contents($base . 'login.php');
if (!$html) {
    echo "Could not reach web server at $base. Checking php syntax of all pages directly:\n";
    $pages = glob(__DIR__ . '/../*.php');
    $all_ok = true;
    foreach ($pages as $f) {
        $out = exec("php -l " . escapeshellarg($f), $lines, $ret);
        if ($ret !== 0) {
            echo "[SYNTAX ERROR] " . basename($f) . ": $out\n";
            $all_ok = false;
        }
    }
    if ($all_ok) echo "All PHP files have valid syntax!\n";
    exit(0);
}

preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches);
$csrf = $matches[1] ?? '';

// 2. Post login
$ch = curl_init($base . 'login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'csrf_token' => $csrf,
    'email' => 'admin@khatashata.com',
    'password' => 'password123'
]));
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "Login HTTP Status: $code\n";

// 3. Test list of pages
$pages = [
    'dashboard.php', 'businesses.php', 'business-create.php', 'partners.php', 'partner-create.php',
    'business-income.php', 'business-income-create.php', 'business-expense.php', 'business-expense-create.php',
    'monthly-closing.php', 'profit-distribution.php', 'owner-receipts.php',
    'personal-income.php', 'personal-income-create.php', 'income-sources.php',
    'personal-expenses.php', 'personal-expense-create.php',
    'accounts.php', 'account-create.php', 'account-transfer.php',
    'transactions.php', 'loans.php', 'loan-create.php',
    'investments.php', 'investment-create.php', 'asset-create.php',
    'reports.php', 'notifications.php', 'settings.php', 'backup.php', 'quick-add.php'
];

$all_ok = true;
foreach ($pages as $p) {
    curl_setopt($ch, CURLOPT_URL, $base . $p);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    $content = curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $has_fatal = stripos($content, 'Fatal error') !== false || stripos($content, 'Parse error') !== false;
    echo "Endpoint: $p -> HTTP $st" . ($has_fatal ? " [FATAL ERROR DETECTED]" : " [OK]") . "\n";
    if ($st !== 200 || $has_fatal) {
        $all_ok = false;
    }
}
curl_close($ch);
if (file_exists($cookie_file)) unlink($cookie_file);

if ($all_ok) {
    echo "\nResult: ALL ENDPOINTS RETURNED HTTP 200 OK WITH NO FATAL ERRORS!\n";
} else {
    echo "\nResult: SOME ENDPOINTS HAD ISSUES\n";
}
