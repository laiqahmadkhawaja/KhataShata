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
$search = trim($_GET['search'] ?? '');

// Filter data
$sql = "
    SELECT bi.*, b.name as business_name, ic.name as category_name, a.account_name
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
if (!empty($search)) {
    $sql .= " AND (bi.title LIKE :s OR bi.reference_no LIKE :s OR bi.description LIKE :s)";
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
    <div class="filter-group" style="flex: 1; max-width: 260px;">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search revenue entries..." class="form-control">
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
        <span>Record Business Revenue</span>
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
      <div class="empty-state-text">Record incoming client payments, contracts, sales, or consultancy revenue.</div>
      <a href="<?= BASE_URL ?>/business-income-create.php" class="btn btn-success">Record First Revenue</a>
    </div>
  </div>
<?php else: ?>
  <div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
      <table class="data-table mobile-cards">
        <thead>
          <tr>
            <th>Income Title / Description</th>
            <th>Business</th>
            <th>Category</th>
            <th>Month</th>
            <th>Date</th>
            <th>Account Inflow</th>
            <th style="text-align: right;">Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($incomes as $inc): ?>
            <tr>
              <td data-label="Title">
                <strong style="font-size: 14px;"><?= htmlspecialchars($inc['title']) ?></strong>
                <div style="font-size: 11px; color: var(--text-muted);">
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
                <?= htmlspecialchars($inc['account_name'] ?? 'Unlinked / Cash Direct') ?>
              </td>
              <td data-label="Amount" style="text-align: right; font-weight: 700; font-size: 14.5px;" class="money-positive">
                +<?= format_money($inc['amount']) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
