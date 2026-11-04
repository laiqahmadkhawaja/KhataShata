<?php
/**
 * KhataShata - Business Expenses Listing
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$page_title = 'Business Operating Expenses';
$active_nav = 'business_expense';

$db = get_db_connection();
$business_id = (int)($_GET['business_id'] ?? 0);
$month = $_GET['month'] ?? '';
$category_id = (int)($_GET['category_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

// Filter data
$sql = "
    SELECT be.*, b.name as business_name, ec.name as category_name, a.account_name
    FROM business_expenses be
    JOIN businesses b ON be.business_id = b.id
    LEFT JOIN expense_categories ec ON be.category_id = ec.id
    LEFT JOIN accounts a ON be.account_id = a.id
    WHERE be.user_id = :uid AND be.is_deleted = 0
";
$params = ['uid' => $user['id']];

if ($business_id > 0) {
    $sql .= " AND be.business_id = :bid";
    $params['bid'] = $business_id;
}
if (!empty($month)) {
    $sql .= " AND be.financial_month = :month";
    $params['month'] = $month;
}
if ($category_id > 0) {
    $sql .= " AND be.category_id = :cat";
    $params['cat'] = $category_id;
}
if (!empty($search)) {
    $sql .= " AND (be.title LIKE :s OR be.reference_no LIKE :s OR be.description LIKE :s)";
    $params['s'] = "%$search%";
}

$sql .= " ORDER BY be.date DESC, be.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

// Total
$total_expense = 0.00;
foreach ($expenses as $exp) {
    $total_expense += (float)$exp['amount'];
}

// Businesses & Categories
$b_stmt = $db->prepare("SELECT id, name FROM businesses WHERE user_id = :uid AND is_deleted = 0 ORDER BY name ASC");
$b_stmt->execute(['uid' => $user['id']]);
$businesses = $b_stmt->fetchAll();

$c_stmt = $db->prepare("SELECT id, name FROM expense_categories WHERE user_id = :uid AND is_active = 1 AND type IN ('business','both') ORDER BY name ASC");
$c_stmt->execute(['uid' => $user['id']]);
$categories = $c_stmt->fetchAll();

require_once ROOT_PATH . '/includes/header.php';
?>

<div class="card" style="padding: 16px 20px; margin-bottom: 20px;">
  <form method="GET" action="" class="filter-bar" style="margin-bottom: 0;">
    <div class="filter-group" style="flex: 1; max-width: 260px;">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search expense entries..." class="form-control">
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

      <a href="<?= BASE_URL ?>/business-expense.php" class="btn btn-secondary btn-sm">Reset</a>
    </div>

    <div>
      <a href="<?= BASE_URL ?>/business-expense-create.php" class="btn btn-danger">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span>Record Business Expense</span>
      </a>
    </div>
  </form>
</div>

<!-- Expense Summary Bar -->
<div class="card" style="padding: 14px 20px; background: var(--danger-light); border-color: var(--danger-border); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
  <div>
    <span style="font-weight: 700; color: var(--danger-dark); font-size: 14px;">Total Filtered Business Expenses:</span>
    <span style="font-size: 20px; font-weight: 800; color: var(--danger-dark); margin-left: 8px;">
      <?= format_money($total_expense) ?>
    </span>
  </div>
  <div style="font-size: 12.5px; color: var(--text-muted);">
    Total Entries: <strong><?= count($expenses) ?></strong>
  </div>
</div>

<?php if (empty($expenses)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
      </div>
      <div class="empty-state-title">No business expenses found</div>
      <div class="empty-state-text">Record operational costs, employee salaries, office rent, utilities, and software subscriptions.</div>
      <a href="<?= BASE_URL ?>/business-expense-create.php" class="btn btn-danger">Record First Expense</a>
    </div>
  </div>
<?php else: ?>
  <div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
      <table class="data-table mobile-cards">
        <thead>
          <tr>
            <th>Expense Title / Details</th>
            <th>Business</th>
            <th>Category</th>
            <th>Month</th>
            <th>Date</th>
            <th>Account Deducted</th>
            <th style="text-align: right;">Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($expenses as $exp): ?>
            <tr>
              <td data-label="Title">
                <strong style="font-size: 14px;"><?= htmlspecialchars($exp['title']) ?></strong>
                <div style="font-size: 11px; color: var(--text-muted);">
                  Ref: <?= htmlspecialchars($exp['reference_no'] ?? '-') ?> &bull; Method: <?= htmlspecialchars($exp['payment_method']) ?>
                </div>
                <?php if (!empty($exp['receipt_attachment'])): ?>
                  <div style="font-size: 11px; margin-top: 3px;">
                    <a href="<?= BASE_URL ?>/<?= htmlspecialchars($exp['receipt_attachment']) ?>" target="_blank">
                      📎 View Bill / Receipt
                    </a>
                  </div>
                <?php endif; ?>
              </td>
              <td data-label="Business">
                <a href="<?= BASE_URL ?>/business-detail.php?id=<?= $exp['business_id'] ?>" style="font-weight: 600;">
                  <?= htmlspecialchars($exp['business_name']) ?>
                </a>
              </td>
              <td data-label="Category">
                <span class="badge badge-neutral"><?= htmlspecialchars($exp['category_name'] ?? 'General') ?></span>
              </td>
              <td data-label="Period">
                <span class="badge badge-primary"><?= htmlspecialchars($exp['financial_month']) ?></span>
              </td>
              <td data-label="Date">
                <?= format_date($exp['date']) ?>
              </td>
              <td data-label="Account">
                <?= htmlspecialchars($exp['account_name'] ?? 'Direct / Unlinked') ?>
              </td>
              <td data-label="Amount" style="text-align: right; font-weight: 700; font-size: 14.5px;" class="money-negative">
                -<?= format_money($exp['amount']) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
