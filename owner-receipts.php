<?php
/**
 * KhataShata - Owner Share Receipts & Receivables Ledger
 * Prevents double-counting between business profits and personal drawings.
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$error = '';
$db = get_db_connection();

// Handle Record Share Receipt POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_receipt') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } else {
        $business_id = (int)($_POST['business_id'] ?? 0);
        $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $financial_month = trim($_POST['financial_month'] ?? date('Y-m'));
        $amount = (float)($_POST['amount'] ?? 0);
        $receipt_date = trim($_POST['receipt_date'] ?? date('Y-m-d'));
        $payment_method = trim($_POST['payment_method'] ?? 'Bank Transfer');
        $reference_no = trim($_POST['reference_no'] ?? generate_reference('REC'));
        $notes = trim($_POST['notes'] ?? 'Owner share drawing');

        if ($business_id <= 0) {
            $error = 'Please select a business.';
        } elseif ($amount <= 0) {
            $error = 'Receipt amount must be greater than zero.';
        } else {
            $res = FinancialEngine::recordOwnerShareReceipt([
                'business_id' => $business_id,
                'account_id' => $account_id,
                'financial_month' => $financial_month,
                'amount' => $amount,
                'receipt_date' => $receipt_date,
                'payment_method' => $payment_method,
                'reference_no' => $reference_no,
                'notes' => $notes
            ], $user['id']);

            if ($res['success']) {
                flash_set('success', 'Owner share receipt of ' . format_money($amount) . ' recorded successfully into your personal accounts!');
                redirect('owner-receipts.php');
            } else {
                $error = $res['error'];
            }
        }
    }
}

// Filter params
$filter_business = (int)($_GET['business_id'] ?? 0);
$filter_month = $_GET['month'] ?? '';

// Total owner share earned (from finalized monthly closings where partner_id is NULL)
$oe_sql = "
    SELECT COALESCE(SUM(pd.share_amount), 0.00) 
    FROM profit_distributions pd
    JOIN monthly_closings mc ON pd.monthly_closing_id = mc.id
    WHERE mc.user_id = :uid AND pd.partner_id IS NULL
";
$oe_params = ['uid' => $user['id']];
if ($filter_business > 0) {
    $oe_sql .= " AND pd.business_id = :bid";
    $oe_params['bid'] = $filter_business;
}
$stmt = $db->prepare($oe_sql);
$stmt->execute($oe_params);
$total_owner_earned = (float)$stmt->fetchColumn();

// Total owner share receipts drawn
$or_sql = "
    SELECT osr.*, b.name as business_name, a.account_name
    FROM owner_share_receipts osr
    JOIN businesses b ON osr.business_id = b.id
    LEFT JOIN accounts a ON osr.account_id = a.id
    WHERE osr.user_id = :uid AND osr.is_deleted = 0
";
$or_params = ['uid' => $user['id']];
if ($filter_business > 0) {
    $or_sql .= " AND osr.business_id = :bid";
    $or_params['bid'] = $filter_business;
}
if (!empty($filter_month)) {
    $or_sql .= " AND osr.financial_month = :month";
    $or_params['month'] = $filter_month;
}
$or_sql .= " ORDER BY osr.receipt_date DESC, osr.id DESC";

$stmt = $db->prepare($or_sql);
$stmt->execute($or_params);
$receipts = $stmt->fetchAll();

$total_owner_received = 0.00;
foreach ($receipts as $r) {
    $total_owner_received += (float)$r['amount'];
}
$pending_owner_receivable = max(0.00, round($total_owner_earned - $total_owner_received, 2));

// Businesses
$stmt = $db->prepare("SELECT id, name FROM businesses WHERE user_id = :uid AND is_deleted = 0 ORDER BY name ASC");
$stmt->execute(['uid' => $user['id']]);
$businesses = $stmt->fetchAll();

// Active Accounts
$stmt = $db->prepare("SELECT id, account_name, current_balance FROM accounts WHERE user_id = :uid AND status = 'active' AND is_deleted = 0 ORDER BY account_name ASC");
$stmt->execute(['uid' => $user['id']]);
$accounts = $stmt->fetchAll();

$page_title = 'Owner Business Share Receipts';
$active_nav = 'owner_receipts';

require_once ROOT_PATH . '/includes/header.php';
?>

<div class="metrics-grid">
  <div class="stat-card">
    <span class="stat-label">Total Business Share Earned</span>
    <div class="stat-value" style="color: var(--primary);"><?= format_money($total_owner_earned) ?></div>
    <div class="stat-sub">From finalized monthly closings</div>
  </div>

  <div class="stat-card">
    <span class="stat-label">Actual Share Received (Cash Drawn)</span>
    <div class="stat-value money-positive"><?= format_money($total_owner_received) ?></div>
    <div class="stat-sub">Deposited into personal accounts</div>
  </div>

  <div class="stat-card">
    <span class="stat-label">Pending Business Receivables</span>
    <div class="stat-value" style="color: <?= $pending_owner_receivable > 0 ? 'var(--warning-dark)' : 'var(--text-muted)' ?>;">
      <?= format_money($pending_owner_receivable) ?>
    </div>
    <div class="stat-sub">Your earned profit not yet drawn</div>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Record Owner Share Receipt Form (Dedicated inline card, NO MODALS) -->
<div class="card" style="margin-bottom: 24px;">
  <div class="card-header">
    <div>
      <div class="card-title">Draw / Receive Business Share</div>
      <div class="card-subtitle">Transfer your business profits into your personal bank or cash account</div>
    </div>
  </div>

  <form method="POST" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="record_receipt">

    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">Business Source <span class="req">*</span></label>
        <select name="business_id" class="form-control" required>
          <option value="">-- Select Business --</option>
          <?php foreach ($businesses as $b): ?>
            <option value="<?= $b['id'] ?>" <?= (isset($_GET['business_id']) && $_GET['business_id'] == $b['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($b['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Financial Month / Period</label>
        <input type="month" name="financial_month" class="form-control" value="<?= htmlspecialchars($_GET['month'] ?? date('Y-m')) ?>" required>
      </div>
    </div>

    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">Amount Drawn <span class="req">*</span></label>
        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required>
      </div>

      <div class="form-group">
        <label class="form-label">Receipt Date <span class="req">*</span></label>
        <input type="date" name="receipt_date" class="form-control" required value="<?= date('Y-m-d') ?>">
      </div>
    </div>

    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">Deposit Into Personal Account (Inflow)</label>
        <select name="account_id" class="form-control">
          <option value="">-- None (Cash direct or unlinked) --</option>
          <?php foreach ($accounts as $acc): ?>
            <option value="<?= $acc['id'] ?>">
              <?= htmlspecialchars($acc['account_name']) ?> (Current: <?= format_money($acc['current_balance']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Will increase selected account balance and record an account transaction inflow.</div>
      </div>

      <div class="form-group">
        <label class="form-label">Payment Method</label>
        <select name="payment_method" class="form-control">
          <option value="Bank Transfer">Bank Transfer (IBFT)</option>
          <option value="Cash">Cash in Hand</option>
          <option value="JazzCash">JazzCash</option>
          <option value="Easypaisa">Easypaisa</option>
          <option value="Cheque">Cheque</option>
          <option value="Other">Other</option>
        </select>
      </div>
    </div>

    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">Reference Number</label>
        <input type="text" name="reference_no" class="form-control" placeholder="e.g. DRAW-2026-10">
      </div>

      <div class="form-group">
        <label class="form-label">Remarks / Description</label>
        <input type="text" name="notes" class="form-control" placeholder="e.g. October owner drawings">
      </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg">
      Record Owner Profit Share Drawing
    </button>
  </form>
</div>

<!-- Receipts History Table -->
<div class="card">
  <div class="card-header">
    <div class="card-title">Owner Share Receipts History</div>
  </div>

  <?php if (empty($receipts)): ?>
    <div class="empty-state" style="padding: 24px;">
      <div class="empty-state-text">No share receipts recorded yet.</div>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="data-table mobile-cards">
        <thead>
          <tr>
            <th>Date</th>
            <th>Business</th>
            <th>Period</th>
            <th>Reference</th>
            <th>Deposited Account</th>
            <th>Method / Notes</th>
            <th style="text-align: right;">Amount Received</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($receipts as $r): ?>
            <tr>
              <td data-label="Date"><?= format_date($r['receipt_date']) ?></td>
              <td data-label="Business"><strong><?= htmlspecialchars($r['business_name']) ?></strong></td>
              <td data-label="Period"><span class="badge badge-primary"><?= htmlspecialchars($r['financial_month']) ?></span></td>
              <td data-label="Reference"><?= htmlspecialchars($r['reference_no'] ?? '-') ?></td>
              <td data-label="Account"><?= htmlspecialchars($r['account_name'] ?? 'Direct / Unlinked') ?></td>
              <td data-label="Method">
                <div><?= htmlspecialchars($r['payment_method']) ?></div>
                <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($r['notes'] ?? '') ?></div>
              </td>
              <td data-label="Amount" style="text-align: right; font-weight: 700;" class="money-positive">
                +<?= format_money($r['amount']) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
