<?php
/**
 * KhataShata - Partner Payment Settlement (Dedicated Entry Screen)
 * Not a modal! Direct settlement recording with account balance integration.
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$error = '';
$db = get_db_connection();

$prefill_partner = (int)($_GET['partner_id'] ?? 0);
$prefill_business = (int)($_GET['business_id'] ?? 0);
$prefill_month = $_GET['month'] ?? date('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } else {
        $business_id = (int)($_POST['business_id'] ?? 0);
        $partner_id = (int)($_POST['partner_id'] ?? 0);
        $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $financial_month = trim($_POST['financial_month'] ?? date('Y-m'));
        $amount = (float)($_POST['amount'] ?? 0);
        $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
        $payment_method = trim($_POST['payment_method'] ?? 'Bank Transfer');
        $reference_no = trim($_POST['reference_no'] ?? generate_reference('PAY'));
        $description = trim($_POST['description'] ?? 'Profit share settlement');

        if ($business_id <= 0) {
            $error = 'Please select a business.';
        } elseif ($partner_id <= 0) {
            $error = 'Please select a partner.';
        } elseif ($amount <= 0) {
            $error = 'Payment amount must be greater than zero.';
        } else {
            $attachment = null;
            if (!empty($_FILES['attachment']['name'])) {
                $attachment = handle_file_upload($_FILES['attachment'], 'receipts');
            }

            $res = FinancialEngine::recordPartnerPayment([
                'business_id' => $business_id,
                'partner_id' => $partner_id,
                'account_id' => $account_id,
                'financial_month' => $financial_month,
                'amount' => $amount,
                'payment_date' => $payment_date,
                'payment_method' => $payment_method,
                'reference_no' => $reference_no,
                'description' => $description,
                'attachment' => $attachment
            ], $user['id']);

            if ($res['success']) {
                flash_set('success', 'Partner payment of ' . format_money($amount) . ' recorded successfully.');
                redirect("partner-ledger.php?partner_id=$partner_id&business_id=$business_id");
            } else {
                $error = $res['error'];
            }
        }
    }
}

// Fetch businesses
$stmt = $db->prepare("SELECT id, name FROM businesses WHERE user_id = :uid AND is_deleted = 0 ORDER BY name ASC");
$stmt->execute(['uid' => $user['id']]);
$businesses = $stmt->fetchAll();

// Fetch partners
$stmt = $db->prepare("SELECT id, name, phone FROM partners WHERE user_id = :uid AND is_deleted = 0 ORDER BY name ASC");
$stmt->execute(['uid' => $user['id']]);
$partners = $stmt->fetchAll();

// Fetch active accounts for payment outflow
$stmt = $db->prepare("SELECT id, account_name, account_type, current_balance FROM accounts WHERE user_id = :uid AND status = 'active' AND is_deleted = 0 ORDER BY account_name ASC");
$stmt->execute(['uid' => $user['id']]);
$accounts = $stmt->fetchAll();

$page_title = 'Record Partner Payment Settlement';
$active_nav = 'profit_distribution';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 760px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">Record Partner Payment</h2>
      <p style="font-size: 13px; color: var(--text-muted);">Pay out profit share settlements. Partial payments are supported.</p>
    </div>
    <a href="<?= BASE_URL ?>/profit-distribution.php" class="btn btn-secondary btn-sm">Cancel</a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" action="" enctype="multipart/form-data">
      <?= csrf_field() ?>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Business <span class="req">*</span></label>
          <select name="business_id" class="form-control" required id="payment-business-select">
            <option value="">-- Select Business --</option>
            <?php foreach ($businesses as $b): ?>
              <option value="<?= $b['id'] ?>" <?= ($prefill_business == $b['id'] || (isset($_POST['business_id']) && $_POST['business_id'] == $b['id'])) ? 'selected' : '' ?>>
                <?= htmlspecialchars($b['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Partner <span class="req">*</span></label>
          <select name="partner_id" class="form-control" required id="payment-partner-select">
            <option value="">-- Select Partner --</option>
            <?php foreach ($partners as $p): ?>
              <option value="<?= $p['id'] ?>" <?= ($prefill_partner == $p['id'] || (isset($_POST['partner_id']) && $_POST['partner_id'] == $p['id'])) ? 'selected' : '' ?>>
                <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['phone'] ?? 'No phone') ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Payment Amount <span class="req">*</span></label>
          <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" autofocus>
          <div class="form-text">Partial payments automatically reduce the remaining payable balance.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Financial Month / Period</label>
          <input type="month" name="financial_month" class="form-control" value="<?= htmlspecialchars($_POST['financial_month'] ?? $prefill_month) ?>" required>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Payment Date <span class="req">*</span></label>
          <input type="date" name="payment_date" class="form-control" required value="<?= htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d')) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Paid Through Account (Optional Outflow)</label>
          <select name="account_id" class="form-control">
            <option value="">-- No account deduction (External or cash direct) --</option>
            <?php foreach ($accounts as $acc): ?>
              <option value="<?= $acc['id'] ?>" <?= (isset($_POST['account_id']) && $_POST['account_id'] == $acc['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($acc['account_name']) ?> (Avail: <?= format_money($acc['current_balance']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Payment Method</label>
          <select name="payment_method" class="form-control">
            <option value="Bank Transfer">Bank Transfer (IBFT)</option>
            <option value="Cash">Cash Settlement</option>
            <option value="Cheque">Cheque</option>
            <option value="JazzCash">JazzCash</option>
            <option value="Easypaisa">Easypaisa</option>
            <option value="Other">Other</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Reference / Cheque Number</label>
          <input type="text" name="reference_no" class="form-control" placeholder="e.g. TR-982183 or Cheque #1029" value="<?= htmlspecialchars($_POST['reference_no'] ?? '') ?>">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Description / Remarks</label>
        <textarea name="description" rows="2" class="form-control" placeholder="e.g. October 50% profit share disbursement..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Payment Receipt / Proof Attachment (Optional)</label>
        <input type="file" name="attachment" class="form-control" accept="image/*,application/pdf">
      </div>

      <div class="alert alert-info" style="font-size: 12.5px;">
        💡 <strong>Financial Integrity Notice:</strong> Partner profit disbursements are settlements of equity earnings, NOT operating expenses of the business. Recording this will not double-reduce the business net profit.
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">
          Record Settlement Payment
        </button>
        <a href="<?= BASE_URL ?>/profit-distribution.php" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
