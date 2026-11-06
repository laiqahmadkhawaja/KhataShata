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
        $is_retained = isset($_POST['is_retained']) ? (int)$_POST['is_retained'] : 0;

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
                'is_retained' => $is_retained,
                'receipt_date' => $receipt_date,
                'payment_method' => $payment_method,
                'reference_no' => $reference_no,
                'notes' => $notes
            ], $user['id']);

            if ($res['success']) {
                flash_set('success', 'Owner share receipt of ' . format_money($amount) . ' recorded successfully!');
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

// If unfinalized direct businesses exist, calculate real-time earned share
$unfin_sql = "
    SELECT b.id, b.collection_model, bi.financial_month 
    FROM businesses b 
    JOIN business_income bi ON b.id = bi.business_id AND bi.is_deleted = 0
    LEFT JOIN monthly_closings mc ON b.id = mc.business_id AND bi.financial_month = mc.financial_month AND mc.status = 'finalized'
    WHERE b.user_id = :uid AND mc.id IS NULL
";
$unfin_params = ['uid' => $user['id']];
if ($filter_business > 0) {
    $unfin_sql .= " AND b.id = :bid";
    $unfin_params['bid'] = $filter_business;
}
$unfin_sql .= " GROUP BY b.id, bi.financial_month";
$stmt_uf = $db->prepare($unfin_sql);
$stmt_uf->execute($unfin_params);
$unfin_list = $stmt_uf->fetchAll();
foreach ($unfin_list as $uf) {
    $s = FinancialEngine::getMonthlySummary($uf['id'], $uf['financial_month']);
    foreach ($s['distributions'] as $d) {
        if ($d['is_owner']) {
            $total_owner_earned += (float)$d['share_amount'];
            break;
        }
    }
}

// Total owner share receipts drawn
$or_sql = "
    SELECT osr.*, b.name as business_name, b.collection_model, a.account_name
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

// Businesses & Accounts
$b_stmt = $db->prepare("SELECT id, name, collection_model FROM businesses WHERE user_id = :uid AND is_deleted = 0 ORDER BY name ASC");
$b_stmt->execute(['uid' => $user['id']]);
$businesses = $b_stmt->fetchAll();

$business_models = [];
foreach ($businesses as $b) {
    $business_models[$b['id']] = $b['collection_model'] ?? 'direct';
}

$a_stmt = $db->prepare("SELECT id, account_name, current_balance FROM accounts WHERE user_id = :uid AND status = 'active' AND is_deleted = 0 ORDER BY account_name ASC");
$a_stmt->execute(['uid' => $user['id']]);
$accounts = $a_stmt->fetchAll();

$page_title = 'Owner Share Receipts';
$active_nav = 'owner_receipts';

require_once ROOT_PATH . '/includes/header.php';
?>

<div class="card" style="padding: 16px 20px; margin-bottom: 20px;">
  <form method="GET" action="" class="filter-bar" style="margin-bottom: 0;">
    <div class="filter-group">
      <select name="business_id" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="0">All Businesses</option>
        <?php foreach ($businesses as $b): ?>
          <option value="<?= $b['id'] ?>" <?= $filter_business == $b['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($b['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <input type="month" name="month" value="<?= htmlspecialchars($filter_month) ?>" class="form-control" style="width: auto;" onchange="this.form.submit()">
      <a href="<?= BASE_URL ?>/owner-receipts.php" class="btn btn-secondary btn-sm">Reset</a>
    </div>

    <div>
      <button type="button" class="btn btn-primary" onclick="document.getElementById('draw-form-card').scrollIntoView({behavior: 'smooth'})">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        <span>Draw / Record Share</span>
      </button>
    </div>
  </form>
</div>

<!-- Financial Summary Cards -->
<div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); margin-bottom: 24px;">
  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label">Total Share Earned</span>
      <div class="stat-icon primary">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/></svg>
      </div>
    </div>
    <div class="stat-value" style="color: var(--primary);">
      <?= format_money($total_owner_earned) ?>
    </div>
    <div class="stat-sub">From business profit allocations</div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label">Total Share Recorded / Drawn</span>
      <div class="stat-icon success">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
    </div>
    <div class="stat-value" style="color: var(--success-dark);">
      <?= format_money($total_owner_received) ?>
    </div>
    <div class="stat-sub">Retained or transferred to personal</div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label">Pending External Receivables</span>
      <div class="stat-icon warning">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </div>
    </div>
    <div class="stat-value" style="color: var(--warning-dark);">
      <?= format_money($pending_owner_receivable) ?>
    </div>
    <div class="stat-sub">Applicable to external businesses</div>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Record Owner Share Receipt Form -->
<div class="card" id="draw-form-card" style="margin-bottom: 24px;">
  <div class="card-header">
    <div>
      <div class="card-title">Record Owner Profit Share Drawing / Recognition</div>
      <div class="card-subtitle">Document drawn profit into personal accounts or recognize retained funds without double-counting.</div>
    </div>
  </div>

  <form method="POST" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="record_receipt">

    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">Business Source <span class="req">*</span></label>
        <select name="business_id" id="business_source_select" class="form-control" required onchange="handleBusinessChange()">
          <option value="">-- Select Business --</option>
          <?php foreach ($businesses as $b): ?>
            <option value="<?= $b['id'] ?>" <?= (isset($_GET['business_id']) && $_GET['business_id'] == $b['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($b['name']) ?> (<?= ($b['collection_model'] ?? 'direct') === 'job' ? '💼 Job / Salary' : (($b['collection_model'] ?? 'direct') === 'direct' ? 'Direct Collection' : 'External') ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Financial Month / Period <span class="req">*</span></label>
        <input type="month" name="financial_month" class="form-control" value="<?= htmlspecialchars($_GET['month'] ?? date('Y-m')) ?>" required>
      </div>
    </div>

    <!-- Retained Notice Banner -->
    <div id="retained-banner" style="display: none; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px 16px; margin-bottom: 18px;">
      <div style="font-weight: 700; color: #166534; font-size: 13px; display: flex; align-items: center; gap: 6px;">
        <span>🛡️ ڈائریکٹ کلیکشن بزنس (Direct Collection - Double Count Prevention)</span>
      </div>
      <div style="font-size: 12px; color: #15803d; margin-top: 4px; line-height: 1.5;">
        چونکہ اس بزنس میں کلائنٹس کی 100% رقم پہلے ہی آپ کے کیش/بینک اکاؤنٹ میں داخل ہو چکی ہے، آپ کا شیئر پہلے ہی آپ کے ہاتھ میں موجود ہے۔
        یہ انٹری آپ کے منافع میں شمار ہو گی، لیکن کیش ان ہینڈ یا اکاؤنٹ بیلنس میں دوبارہ ڈبل ایڈ نہیں ہو گی تاکہ بیلنس درست رہے۔
      </div>
      <input type="hidden" name="is_retained" id="is_retained_field" value="1">
    </div>

    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">Amount Drawn / Retained (Rs) <span class="req">*</span></label>
        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required style="font-weight:700;">
      </div>

      <div class="form-group">
        <label class="form-label">Receipt Date <span class="req">*</span></label>
        <input type="date" name="receipt_date" class="form-control" required value="<?= date('Y-m-d') ?>">
      </div>
    </div>

    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">Account Used</label>
        <select name="account_id" class="form-control">
          <option value="">-- None (Cash direct or unlinked) --</option>
          <?php foreach ($accounts as $acc): ?>
            <option value="<?= $acc['id'] ?>">
              <?= htmlspecialchars($acc['account_name']) ?> (Current: <?= format_money($acc['current_balance']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <div id="account-help-text" class="form-text">Reference account for this drawing.</div>
      </div>

      <div class="form-group">
        <label class="form-label">Payment Method</label>
        <select name="payment_method" class="form-control">
          <option value="Cash">Cash in Hand</option>
          <option value="Bank Transfer">Bank Transfer (IBFT)</option>
          <option value="JazzCash">JazzCash</option>
          <option value="Easypaisa">Easypaisa</option>
          <option value="Other">Other</option>
        </select>
      </div>
    </div>

    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">Reference Number</label>
        <input type="text" name="reference_no" class="form-control" placeholder="e.g. DRAW-2026-11">
      </div>

      <div class="form-group">
        <label class="form-label">Remarks / Description</label>
        <input type="text" name="notes" class="form-control" placeholder="e.g. November 60% owner profit share retained">
      </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg">
      Save Owner Profit Share Record
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
            <th>Type</th>
            <th>Period</th>
            <th>Reference</th>
            <th>Account</th>
            <th>Method / Notes</th>
            <th style="text-align: right;">Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($receipts as $r): ?>
            <tr>
              <td data-label="Date"><?= format_date($r['receipt_date']) ?></td>
              <td data-label="Business">
                <strong><?= htmlspecialchars($r['business_name']) ?></strong>
              </td>
              <td data-label="Type">
                <?php if (!empty($r['is_retained'])): ?>
                  <span class="badge badge-success" style="font-size: 11px;">🛡️ Retained (Direct/Job)</span>
                <?php else: ?>
                  <span class="badge badge-primary" style="font-size: 11px;">💵 External Inflow</span>
                <?php endif; ?>
              </td>
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

<script>
const businessModels = <?= json_encode($business_models) ?>;

function handleBusinessChange() {
  const bid = document.getElementById('business_source_select').value;
  const banner = document.getElementById('retained-banner');
  const retainedField = document.getElementById('is_retained_field');
  const accHelp = document.getElementById('account-help-text');
  
  if (!bid) {
    banner.style.display = 'none';
    retainedField.value = '0';
    return;
  }
  
  const model = businessModels[bid] || 'direct';
  if (model === 'direct' || model === 'job') {
    banner.style.display = 'block';
    retainedField.value = '1';
    accHelp.innerText = 'For reference: Funds are already in this account from client payments. Will not duplicate cash.';
  } else {
    banner.style.display = 'none';
    retainedField.value = '0';
    accHelp.innerText = 'Will increase selected account balance and record fresh inflow from external partnership.';
  }
}

document.addEventListener('DOMContentLoaded', function() {
  handleBusinessChange();
});
</script>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
