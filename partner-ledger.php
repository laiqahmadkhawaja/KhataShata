<?php
/**
 * KhataShata - Partner Detailed Financial Ledger
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$partner_id = (int)($_GET['partner_id'] ?? 0);
$business_id = (int)($_GET['business_id'] ?? 0);

$db = get_db_connection();

// Partner list for dropdown
$p_stmt = $db->prepare("SELECT id, name FROM partners WHERE user_id = :uid AND is_deleted = 0 ORDER BY name ASC");
$p_stmt->execute(['uid' => $user['id']]);
$all_partners = $p_stmt->fetchAll();

if ($partner_id === 0 && !empty($all_partners)) {
    $partner_id = $all_partners[0]['id'];
}

// Fetch active partner details
$stmt = $db->prepare("SELECT * FROM partners WHERE id = :id AND user_id = :uid AND is_deleted = 0 LIMIT 1");
$stmt->execute(['id' => $partner_id, 'uid' => $user['id']]);
$partner = $stmt->fetch();

// Fetch businesses for dropdown filter
$b_stmt = $db->prepare("SELECT id, name FROM businesses WHERE user_id = :uid AND is_deleted = 0 ORDER BY name ASC");
$b_stmt->execute(['uid' => $user['id']]);
$all_businesses = $b_stmt->fetchAll();

// Partner earnings query
$pe_sql = "
    SELECT pe.*, b.name as business_name
    FROM partner_earnings pe
    JOIN businesses b ON pe.business_id = b.id
    WHERE pe.partner_id = :pid
";
$pe_params = ['pid' => $partner_id];
if ($business_id > 0) {
    $pe_sql .= " AND pe.business_id = :bid";
    $pe_params['bid'] = $business_id;
}
$pe_sql .= " ORDER BY pe.financial_month DESC, b.name ASC";

$stmt = $db->prepare($pe_sql);
$stmt->execute($pe_params);
$earnings_ledger = $stmt->fetchAll();

// Partner payments query
$pay_sql = "
    SELECT pp.*, b.name as business_name, a.account_name
    FROM partner_payments pp
    JOIN businesses b ON pp.business_id = b.id
    LEFT JOIN accounts a ON pp.account_id = a.id
    WHERE pp.partner_id = :pid AND pp.is_deleted = 0
";
$pay_params = ['pid' => $partner_id];
if ($business_id > 0) {
    $pay_sql .= " AND pp.business_id = :bid";
    $pay_params['bid'] = $business_id;
}
$pay_sql .= " ORDER BY pp.payment_date DESC, pp.id DESC";

$stmt = $db->prepare($pay_sql);
$stmt->execute($pay_params);
$payment_history = $stmt->fetchAll();

// Summary calculations
$total_earned = 0.00;
$total_paid = 0.00;
foreach ($earnings_ledger as $row) {
    $total_earned += (float)$row['earned_share'];
}
foreach ($payment_history as $row) {
    $total_paid += (float)$row['amount'];
}
$outstanding_balance = max(0.00, round($total_earned - $total_paid, 2));

$page_title = 'Partner Financial Ledger';
$active_nav = 'partners';

require_once ROOT_PATH . '/includes/header.php';
?>

<!-- Partner Selector Bar -->
<div class="card" style="padding: 16px 20px; margin-bottom: 20px;">
  <form method="GET" action="" class="filter-bar" style="margin-bottom: 0;">
    <div class="filter-group">
      <span style="font-weight: 700; font-size: 13px;">Select Partner:</span>
      <select name="partner_id" class="form-control" style="width: auto; min-width: 220px;" onchange="this.form.submit()">
        <?php foreach ($all_partners as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $partner_id == $p['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($p['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="filter-group">
      <span style="font-weight: 700; font-size: 13px;">Filter Business:</span>
      <select name="business_id" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="0">All Businesses</option>
        <?php foreach ($all_businesses as $b): ?>
          <option value="<?= $b['id'] ?>" <?= $business_id == $b['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($b['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <a href="<?= BASE_URL ?>/partner-payment.php?partner_id=<?= $partner_id ?>&business_id=<?= $business_id ?>" class="btn btn-primary btn-sm">
        + Make Settlement Payment
      </a>
    </div>
  </form>
</div>

<?php if (!$partner): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-title">No partner selected or found</div>
      <a href="<?= BASE_URL ?>/partner-create.php" class="btn btn-primary">Create Partner Profile</a>
    </div>
  </div>
<?php else: ?>
  <!-- Partner Overview Cards -->
  <div class="metrics-grid">
    <div class="stat-card">
      <span class="stat-label">Total Profit Share Earned</span>
      <div class="stat-value money-positive"><?= format_money($total_earned) ?></div>
      <div class="stat-sub">From finalized monthly closings</div>
    </div>

    <div class="stat-card">
      <span class="stat-label">Total Settlements Paid</span>
      <div class="stat-value money-negative"><?= format_money($total_paid) ?></div>
      <div class="stat-sub">Total payouts recorded</div>
    </div>

    <div class="stat-card">
      <span class="stat-label">Current Outstanding Payable</span>
      <div class="stat-value" style="color: <?= $outstanding_balance > 0 ? 'var(--warning-dark)' : 'var(--text-muted)' ?>;">
        <?= format_money($outstanding_balance) ?>
      </div>
      <div class="stat-sub">Remaining balance due to <?= htmlspecialchars($partner['name']) ?></div>
    </div>
  </div>

  <!-- Section 1: Monthly Earnings Ledger -->
  <div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
      <div>
        <div class="card-title">Earnings Ledger by Financial Month</div>
        <div class="card-subtitle">Opening balance, earned share, settlements paid, and closing balance</div>
      </div>
    </div>

    <?php if (empty($earnings_ledger)): ?>
      <div class="empty-state" style="padding: 24px;">
        <div class="empty-state-text">No finalized monthly earnings recorded for this partner yet.</div>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table mobile-cards">
          <thead>
            <tr>
              <th>Business</th>
              <th>Period</th>
              <th style="text-align: right;">Opening Balance</th>
              <th style="text-align: right;">Earned Share</th>
              <th style="text-align: right;">Paid Amount</th>
              <th style="text-align: right;">Closing Balance</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($earnings_ledger as $row): ?>
              <tr>
                <td data-label="Business"><strong><?= htmlspecialchars($row['business_name']) ?></strong></td>
                <td data-label="Period"><span class="badge badge-neutral"><?= htmlspecialchars($row['financial_month']) ?></span></td>
                <td data-label="Opening" style="text-align: right;"><?= format_money($row['opening_balance']) ?></td>
                <td data-label="Earned" style="text-align: right;" class="money-positive">+<?= format_money($row['earned_share']) ?></td>
                <td data-label="Paid" style="text-align: right;" class="money-negative">-<?= format_money($row['paid_amount']) ?></td>
                <td data-label="Closing" style="text-align: right; font-weight: 700; color: <?= $row['closing_balance'] > 0 ? 'var(--warning-dark)' : 'var(--text-muted)' ?>;">
                  <?= format_money($row['closing_balance']) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Section 2: Payment Disbursements History -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Disbursement & Settlement History</div>
        <div class="card-subtitle">All actual payments made to <?= htmlspecialchars($partner['name']) ?></div>
      </div>
      <a href="<?= BASE_URL ?>/partner-payment.php?partner_id=<?= $partner['id'] ?>" class="btn btn-primary btn-sm">+ New Payment</a>
    </div>

    <?php if (empty($payment_history)): ?>
      <div class="empty-state" style="padding: 24px;">
        <div class="empty-state-text">No payment records found.</div>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table mobile-cards">
          <thead>
            <tr>
              <th>Date</th>
              <th>Business</th>
              <th>Reference / Method</th>
              <th>Paid Through Account</th>
              <th>Description</th>
              <th style="text-align: right;">Amount Paid</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($payment_history as $pay): ?>
              <tr>
                <td data-label="Date"><?= format_date($pay['payment_date']) ?></td>
                <td data-label="Business"><strong><?= htmlspecialchars($pay['business_name']) ?></strong></td>
                <td data-label="Reference">
                  <div><?= htmlspecialchars($pay['reference_no'] ?? '-') ?></div>
                  <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($pay['payment_method']) ?></div>
                </td>
                <td data-label="Account"><?= htmlspecialchars($pay['account_name'] ?? 'Direct / Unlinked') ?></td>
                <td data-label="Description"><?= htmlspecialchars($pay['description'] ?? '-') ?></td>
                <td data-label="Amount" style="text-align: right; font-weight: 700;" class="money-negative">
                  -<?= format_money($pay['amount']) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
