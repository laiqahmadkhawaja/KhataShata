<?php
/**
 * KhataShata - Personal Expenses Listing
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$page_title = 'Personal Expenses';
$active_nav = 'personal_expenses';

$db = get_db_connection();
$category_id = (int)($_GET['category_id'] ?? 0);
$account_id = (int)($_GET['account_id'] ?? 0);
$month = $_GET['month'] ?? '';
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT pe.*, ec.name as category_name, a.account_name
    FROM personal_expenses pe
    LEFT JOIN expense_categories ec ON pe.category_id = ec.id
    LEFT JOIN accounts a ON pe.account_id = a.id
    WHERE pe.user_id = :uid AND pe.is_deleted = 0
";
$params = ['uid' => $user['id']];
if ($category_id > 0) {
    $sql .= " AND pe.category_id = :cat";
    $params['cat'] = $category_id;
}
if ($account_id > 0) {
    $sql .= " AND pe.account_id = :aid";
    $params['aid'] = $account_id;
}
if (!empty($month)) {
    $sql .= " AND pe.expense_date LIKE :month";
    $params['month'] = "$month%";
}
if (!empty($search)) {
    $sql .= " AND (pe.title LIKE :s OR pe.notes LIKE :s OR pe.reference_no LIKE :s)";
    $params['s'] = "%$search%";
}
$sql .= " ORDER BY pe.expense_date DESC, pe.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

$total_expenses = 0.00;
foreach ($expenses as $exp) {
    $total_expenses += (float)$exp['amount'];
}

// Categories & accounts
$c_stmt = $db->prepare("SELECT id, name FROM expense_categories WHERE user_id = :uid AND is_active = 1 AND type IN ('personal','both') ORDER BY name ASC");
$c_stmt->execute(['uid' => $user['id']]);
$categories = $c_stmt->fetchAll();

$a_stmt = $db->prepare("SELECT id, account_name FROM accounts WHERE user_id = :uid AND is_deleted = 0 ORDER BY account_name ASC");
$a_stmt->execute(['uid' => $user['id']]);
$accounts = $a_stmt->fetchAll();

require_once ROOT_PATH . '/includes/header.php';
?>

<div class="card" style="padding: 16px 20px; margin-bottom: 20px;">
  <form method="GET" action="" class="filter-bar" style="margin-bottom: 0;">
    <div class="filter-group" style="flex: 1; max-width: 260px;">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search personal expenses..." class="form-control">
    </div>

    <div class="filter-group">
      <select name="category_id" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="0">All Categories</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>" <?= $category_id == $cat['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($cat['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <input type="month" name="month" value="<?= htmlspecialchars($month) ?>" class="form-control" style="width: auto;" onchange="this.form.submit()">

      <select name="account_id" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="0">All Accounts</option>
        <?php foreach ($accounts as $acc): ?>
          <option value="<?= $acc['id'] ?>" <?= $account_id == $acc['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($acc['account_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <a href="<?= BASE_URL ?>/personal-expenses.php" class="btn btn-secondary btn-sm">Reset</a>
    </div>

    <div>
      <a href="<?= BASE_URL ?>/personal-expense-create.php" class="btn btn-danger">
        + Record Expense
      </a>
    </div>
  </form>
</div>

<!-- Summary Bar -->
<div class="card" style="padding: 14px 20px; background: var(--danger-light); border-color: var(--danger-border); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
  <div>
    <span style="font-weight: 700; color: var(--danger-dark); font-size: 14px;">Total Filtered Personal Expenses:</span>
    <span style="font-size: 20px; font-weight: 800; color: var(--danger-dark); margin-left: 8px;">
      <?= format_money($total_expenses) ?>
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
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      </div>
      <div class="empty-state-title">No personal expenses recorded yet</div>
      <div class="empty-state-text">Track your household groceries, utility bills, fuel, healthcare, dining, and shopping.</div>
      <a href="<?= BASE_URL ?>/personal-expense-create.php" class="btn btn-danger">Record First Expense</a>
    </div>
  </div>
<?php else: ?>
  <div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
      <table class="data-table mobile-cards">
        <thead>
          <tr>
            <th>Expense Title / Notes</th>
            <th>Category</th>
            <th>Date</th>
            <th>Deducted Account</th>
            <th>Method</th>
            <th style="text-align: right;">Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($expenses as $exp): ?>
            <tr>
              <td data-label="Title">
                <strong style="font-size: 14px;"><?= htmlspecialchars($exp['title']) ?></strong>
                <div style="font-size: 11px; color: var(--text-muted);">
                  <?= htmlspecialchars($exp['notes'] ?? '') ?>
                  <?php if (!empty($exp['reference_no'])): ?> &bull; Ref: <?= htmlspecialchars($exp['reference_no']) ?><?php endif; ?>
                </div>
                <?php if (!empty($exp['attachment'])): ?>
                  <div style="font-size: 11px; margin-top: 3px;">
                    <a href="<?= BASE_URL ?>/<?= htmlspecialchars($exp['attachment']) ?>" target="_blank">
                      📎 View Receipt
                    </a>
                  </div>
                <?php endif; ?>
              </td>
              <td data-label="Category">
                <span class="badge badge-neutral"><?= htmlspecialchars($exp['category_name'] ?? 'General') ?></span>
              </td>
              <td data-label="Date"><?= format_date($exp['expense_date']) ?></td>
              <td data-label="Account"><?= htmlspecialchars($exp['account_name'] ?? 'Direct / Cash in Hand') ?></td>
              <td data-label="Method"><?= htmlspecialchars($exp['payment_method']) ?></td>
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
