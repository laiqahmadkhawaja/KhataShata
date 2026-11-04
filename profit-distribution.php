<?php
/**
 * KhataShata - Profit Distribution Hub & Participant Ledgers
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$page_title = 'Profit Distribution & Ledgers';
$active_nav = 'profit_distribution';

$db = get_db_connection();
$business_id = (int)($_GET['business_id'] ?? 0);
$month = $_GET['month'] ?? '';

// Build query for profit distributions
$sql = "
    SELECT pd.*, b.name as business_name, p.name as partner_name, mc.status as closing_status
    FROM profit_distributions pd
    JOIN businesses b ON pd.business_id = b.id
    JOIN monthly_closings mc ON pd.monthly_closing_id = mc.id
    LEFT JOIN partners p ON pd.partner_id = p.id
    WHERE mc.user_id = :uid
";
$params = ['uid' => $user['id']];
if ($business_id > 0) {
    $sql .= " AND pd.business_id = :bid";
    $params['bid'] = $business_id;
}
if (!empty($month)) {
    $sql .= " AND pd.financial_month = :month";
    $params['month'] = $month;
}
$sql .= " ORDER BY pd.financial_month DESC, b.name ASC, pd.partner_id IS NULL DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$distributions = $stmt->fetchAll();

// Businesses for filter
$b_stmt = $db->prepare("SELECT id, name FROM businesses WHERE user_id = :uid AND is_deleted = 0 ORDER BY name ASC");
$b_stmt->execute(['uid' => $user['id']]);
$businesses = $b_stmt->fetchAll();

// Summary stats
$total_distributed = 0.00;
$owner_distributed = 0.00;
$partner_distributed = 0.00;
foreach ($distributions as $d) {
    $total_distributed += (float)$d['share_amount'];
    if (is_null($d['partner_id'])) {
        $owner_distributed += (float)$d['share_amount'];
    } else {
        $partner_distributed += (float)$d['share_amount'];
    }
}

require_once ROOT_PATH . '/includes/header.php';
?>

<div class="card" style="padding: 16px 20px; margin-bottom: 20px;">
  <form method="GET" action="" class="filter-bar" style="margin-bottom: 0;">
    <div class="filter-group">
      <span style="font-weight: 700; font-size: 13px;">Business:</span>
      <select name="business_id" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="0">All Businesses</option>
        <?php foreach ($businesses as $b): ?>
          <option value="<?= $b['id'] ?>" <?= $business_id == $b['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($b['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="filter-group">
      <span style="font-weight: 700; font-size: 13px;">Month:</span>
      <input type="month" name="month" value="<?= htmlspecialchars($month) ?>" class="form-control" style="width: auto;" onchange="this.form.submit()">
      <a href="<?= BASE_URL ?>/profit-distribution.php" class="btn btn-secondary btn-sm">Reset</a>
    </div>

    <div style="display: flex; gap: 8px;">
      <a href="<?= BASE_URL ?>/partner-payment.php" class="btn btn-primary btn-sm">
        + Pay Business Partner
      </a>
      <a href="<?= BASE_URL ?>/owner-receipts.php" class="btn btn-secondary btn-sm">
        Owner Share Receipts
      </a>
    </div>
  </form>
</div>

<!-- Metrics summary -->
<div class="metrics-grid">
  <div class="stat-card">
    <span class="stat-label">Total Distributed Profit</span>
    <div class="stat-value" style="color: var(--primary);"><?= format_money($total_distributed) ?></div>
    <div class="stat-sub">Across filtered finalized closings</div>
  </div>

  <div class="stat-card">
    <span class="stat-label">Your Share Allocated</span>
    <div class="stat-value money-positive"><?= format_money($owner_distributed) ?></div>
    <div class="stat-sub">Your personal profit entitlements</div>
  </div>

  <div class="stat-card">
    <span class="stat-label">Partner Shares Allocated</span>
    <div class="stat-value" style="color: var(--purple);"><?= format_money($partner_distributed) ?></div>
    <div class="stat-sub">Allocated to external partners</div>
  </div>
</div>

<?php if (empty($distributions)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-title">No finalized profit distributions found</div>
      <div class="empty-state-text">Profit distributions are automatically created and locked when you finalize a monthly financial closing.</div>
      <a href="<?= BASE_URL ?>/monthly-closing.php" class="btn btn-primary">Go to Monthly Closings</a>
    </div>
  </div>
<?php else: ?>
  <div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
      <table class="data-table mobile-cards">
        <thead>
          <tr>
            <th>Participant</th>
            <th>Business</th>
            <th>Month</th>
            <th>Ownership %</th>
            <th style="text-align: right;">Calculated Distributable Share</th>
            <th>Closing Status</th>
            <th style="text-align: right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($distributions as $d): ?>
            <tr>
              <td data-label="Participant">
                <?php if (is_null($d['partner_id'])): ?>
                  <strong style="color: var(--primary);">You (Owner)</strong>
                <?php else: ?>
                  <a href="<?= BASE_URL ?>/partner-ledger.php?partner_id=<?= $d['partner_id'] ?>" style="font-weight: 700; color: var(--text-main);">
                    <?= htmlspecialchars($d['partner_name']) ?>
                  </a>
                <?php endif; ?>
              </td>
              <td data-label="Business">
                <a href="<?= BASE_URL ?>/business-detail.php?id=<?= $d['business_id'] ?>">
                  <?= htmlspecialchars($d['business_name']) ?>
                </a>
              </td>
              <td data-label="Period">
                <span class="badge badge-primary"><?= htmlspecialchars($d['financial_month']) ?></span>
              </td>
              <td data-label="Ownership">
                <span class="badge badge-neutral"><?= format_percentage($d['ownership_percentage']) ?></span>
              </td>
              <td data-label="Share Amount" style="text-align: right; font-weight: 700;" class="money-positive">
                <?= format_money($d['share_amount']) ?>
              </td>
              <td data-label="Status">
                <span class="badge badge-success"><?= htmlspecialchars($d['closing_status']) ?></span>
              </td>
              <td data-label="Action" style="text-align: right;">
                <?php if (!is_null($d['partner_id'])): ?>
                  <a href="<?= BASE_URL ?>/partner-payment.php?business_id=<?= $d['business_id'] ?>&partner_id=<?= $d['partner_id'] ?>&month=<?= $d['financial_month'] ?>" class="btn btn-secondary btn-sm">
                    Pay Partner
                  </a>
                <?php else: ?>
                  <a href="<?= BASE_URL ?>/owner-receipts.php?business_id=<?= $d['business_id'] ?>&month=<?= $d['financial_month'] ?>" class="btn btn-secondary btn-sm">
                    Draw Share
                  </a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
