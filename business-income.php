<?php
/**
 * KhataShata - Business Income Listing
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$page_title = 'Business Income & Revenue';
$active_nav = 'business_income';

$db = get_db_connection();
$business_id = (int)($_GET['business_id'] ?? 0);
$month = $_GET['month'] ?? '';
$category_id = (int)($_GET['category_id'] ?? 0);
$payment_type = trim($_GET['payment_type'] ?? '');
$search = trim($_GET['search'] ?? '');

// Filter data
$sql = "
    SELECT bi.*, b.name as business_name, b.collection_model, ic.name as category_name, a.account_name
    FROM business_income bi
    JOIN businesses b ON bi.business_id = b.id
    LEFT JOIN income_categories ic ON bi.category_id = ic.id
    LEFT JOIN accounts a ON bi.account_id = a.id
    WHERE bi.user_id = :uid AND bi.is_deleted = 0
";
$params = ['uid' => $user['id']];

if ($business_id > 0) {
    $sql .= " AND bi.business_id = :bid";
    $params['bid'] = $business_id;
}
if (!empty($month)) {
    $sql .= " AND bi.financial_month = :month";
    $params['month'] = $month;
}
if ($category_id > 0) {
    $sql .= " AND bi.category_id = :cat";
    $params['cat'] = $category_id;
}
if (!empty($payment_type)) {
    $sql .= " AND bi.payment_type = :ptype";
    $params['ptype'] = $payment_type;
}
if (!empty($search)) {
    $sql .= " AND (bi.title LIKE :s OR bi.client_name LIKE :s OR bi.reference_no LIKE :s OR bi.description LIKE :s)";
    $params['s'] = "%$search%";
}

$sql .= " ORDER BY bi.transaction_date DESC, bi.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$incomes = $stmt->fetchAll();

// Total
$total_revenue = 0.00;
foreach ($incomes as $inc) {
    $total_revenue += (float)$inc['amount'];
}

// Businesses & Categories for filters
$b_stmt = $db->prepare("SELECT id, name FROM businesses WHERE user_id = :uid AND is_deleted = 0 ORDER BY name ASC");
$b_stmt->execute(['uid' => $user['id']]);
$businesses = $b_stmt->fetchAll();

$c_stmt = $db->prepare("SELECT id, name FROM income_categories WHERE user_id = :uid AND is_active = 1 AND type IN ('business','both') ORDER BY name ASC");
$c_stmt->execute(['uid' => $user['id']]);
$categories = $c_stmt->fetchAll();

require_once ROOT_PATH . '/includes/header.php';
?>

<div class="card" style="padding: 16px 20px; margin-bottom: 20px;">
  <form method="GET" action="" class="filter-bar" style="margin-bottom: 0;">
    <div class="filter-group" style="flex: 1; max-width: 220px;">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search client, title..." class="form-control">
    </div>

    <div class="filter-group">
      <select name="business_id" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="0">All Businesses</option>
        <?php foreach ($businesses as $b): ?>
          <option value="<?= $b['id'] ?>" <?= $business_id == $b['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($b['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="payment_type" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="">All Payment Types</option>
        <option value="advance" <?= $payment_type === 'advance' ? 'selected' : '' ?>>⚡ Advance</option>
        <option value="partial" <?= $payment_type === 'partial' ? 'selected' : '' ?>>🔄 Partial / Milestone</option>
        <option value="remaining" <?= $payment_type === 'remaining' ? 'selected' : '' ?>>🏁 Remaining / Final</option>
        <option value="full" <?= $payment_type === 'full' ? 'selected' : '' ?>>💯 Full Payment</option>
        <option value="monthly" <?= $payment_type === 'monthly' ? 'selected' : '' ?>>📅 Monthly / Retainer</option>
      </select>

      <input type="month" name="month" value="<?= htmlspecialchars($month) ?>" class="form-control" style="width: auto;" onchange="this.form.submit()">

      <select name="category_id" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="0">All Categories</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>" <?= $category_id == $cat['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($cat['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <a href="<?= BASE_URL ?>/business-income.php" class="btn btn-secondary btn-sm">Reset</a>
    </div>

    <div>
      <a href="<?= BASE_URL ?>/business-income-create.php" class="btn btn-success">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span>Record Revenue</span>
      </a>
    </div>
  </form>
</div>

<!-- Revenue Summary Bar -->
<div class="card" style="padding: 14px 20px; background: var(--success-light); border-color: var(--success-border); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
  <div>
    <span style="font-weight: 700; color: var(--success-dark); font-size: 14px;">Total Filtered Business Revenue:</span>
    <span style="font-size: 20px; font-weight: 800; color: var(--success-dark); margin-left: 8px;">
      <?= format_money($total_revenue) ?>
    </span>
  </div>
  <div style="font-size: 12.5px; color: var(--text-muted);">
    Total Entries: <strong><?= count($incomes) ?></strong>
  </div>
</div>

<?php if (empty($incomes)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
      </div>
      <div class="empty-state-title">No business income entries found</div>
      <div class="empty-state-text">Record incoming client payments, contracts, advance, or monthly software retainers.</div>
      <a href="<?= BASE_URL ?>/business-income-create.php" class="btn btn-success">Record First Revenue</a>
    </div>
  </div>
<?php else: ?>
  <div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
      <table class="data-table mobile-cards">
        <thead>
          <tr>
            <th>Title & Client</th>
            <th>Type</th>
            <th>Business</th>
            <th>Category</th>
            <th>Month</th>
            <th>Date</th>
            <th>Account</th>
            <th style="text-align: right;">Amount & Split</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($incomes as $inc): 
            $ptype = $inc['payment_type'] ?? 'full';
            $amt = (float)$inc['amount'];
            $owner_est = $amt * 0.60;
            $partner_est = $amt * 0.40;
          ?>
            <tr>
              <td data-label="Title">
                <div style="font-weight: 700; font-size: 14px; color: var(--text-main);">
                  <?= htmlspecialchars($inc['title']) ?>
                </div>
                <?php if (!empty($inc['client_name'])): ?>
                  <div style="font-size: 12px; color: var(--primary); font-weight: 600; margin-top: 1px;">
                    👤 Client: <?= htmlspecialchars($inc['client_name']) ?>
                  </div>
                <?php endif; ?>
                <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                  Ref: <?= htmlspecialchars($inc['reference_no'] ?? '-') ?> &bull; Method: <?= htmlspecialchars($inc['payment_method']) ?>
                </div>
                <?php if (!empty($inc['attachment'])): ?>
                  <div style="font-size: 11px; margin-top: 3px;">
                    <a href="<?= BASE_URL ?>/<?= htmlspecialchars($inc['attachment']) ?>" target="_blank" style="display:inline-flex; align-items:center; gap:3px;">
                      📎 View Receipt
                    </a>
                  </div>
                <?php endif; ?>
              </td>

              <td data-label="Type">
                <?php if ($ptype === 'advance'): ?>
                  <span class="badge badge-warning" style="font-size: 11px;">⚡ Advance</span>
                <?php elseif ($ptype === 'partial'): ?>
                  <span class="badge badge-info" style="font-size: 11px;">🔄 Partial</span>
                <?php elseif ($ptype === 'remaining'): ?>
                  <span class="badge badge-success" style="font-size: 11px;">🏁 Remaining/Final</span>
                <?php elseif ($ptype === 'monthly'): ?>
                  <span class="badge" style="background:#ede9fe; color:#6d28d9; font-size: 11px;">📅 Monthly</span>
                <?php else: ?>
                  <span class="badge badge-primary" style="font-size: 11px;">💯 Full Payment</span>
                <?php endif; ?>
              </td>

              <td data-label="Business">
                <a href="<?= BASE_URL ?>/business-detail.php?id=<?= $inc['business_id'] ?>" style="font-weight: 600;">
                  <?= htmlspecialchars($inc['business_name']) ?>
                </a>
              </td>

              <td data-label="Category">
                <span class="badge badge-neutral"><?= htmlspecialchars($inc['category_name'] ?? 'General') ?></span>
              </td>

              <td data-label="Period">
                <span class="badge badge-primary"><?= htmlspecialchars($inc['financial_month']) ?></span>
              </td>

              <td data-label="Date">
                <?= format_date($inc['transaction_date']) ?>
              </td>

              <td data-label="Account">
                <span style="font-size: 12.5px; font-weight: 500;">
                  <?= htmlspecialchars($inc['account_name'] ?? 'Cash Direct') ?>
                </span>
              </td>

              <td data-label="Amount" style="text-align: right;">
                <div style="font-weight: 800; font-size: 15px;" class="money-positive">
                  +<?= format_money($amt) ?>
                </div>
                <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                  <span style="color: var(--success-dark); font-weight: 600;">You (60%): <?= format_money($owner_est) ?></span><br>
                  <span style="color: #7c3aed; font-weight: 600;">Partner (40%): <?= format_money($partner_est) ?></span>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
