<?php
/**
 * KhataShata - Unified Master Transactions Stream
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$page_title = 'Master Transaction Journal';
$active_nav = 'transactions';

$db = get_db_connection();
$type = $_GET['type'] ?? '';
$direction = $_GET['dir'] ?? '';
$account_id = (int)($_GET['account_id'] ?? 0);
$date_from = $_GET['from'] ?? '';
$date_to = $_GET['to'] ?? '';
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT at.*, a.account_name
    FROM account_transactions at
    JOIN accounts a ON at.account_id = a.id
    WHERE at.user_id = :uid AND at.is_deleted = 0
";
$params = ['uid' => $user['id']];

if (!empty($type)) {
    $sql .= " AND at.transaction_type = :type";
    $params['type'] = $type;
}
if (!empty($direction)) {
    $sql .= " AND at.direction = :dir";
    $params['dir'] = $direction;
}
if ($account_id > 0) {
    $sql .= " AND at.account_id = :aid";
    $params['aid'] = $account_id;
}
if (!empty($date_from)) {
    $sql .= " AND at.transaction_date >= :dfrom";
    $params['dfrom'] = $date_from;
}
if (!empty($date_to)) {
    $sql .= " AND at.transaction_date <= :dto";
    $params['dto'] = $date_to;
}
if (!empty($search)) {
    $sql .= " AND (at.description LIKE :s OR at.reference_no LIKE :s)";
    $params['s'] = "%$search%";
}

$sql .= " ORDER BY at.transaction_date DESC, at.id DESC LIMIT 100";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Active accounts for filter
$a_stmt = $db->prepare("SELECT id, account_name FROM accounts WHERE user_id = :uid AND is_deleted = 0 ORDER BY account_name ASC");
$a_stmt->execute(['uid' => $user['id']]);
$accounts = $a_stmt->fetchAll();

require_once ROOT_PATH . '/includes/header.php';
?>

<div class="card" style="padding: 16px 20px; margin-bottom: 20px;">
  <form method="GET" action="" class="filter-bar" style="margin-bottom: 0;">
    <div class="filter-group" style="flex: 1; max-width: 240px;">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search transactions..." class="form-control">
    </div>

    <div class="filter-group">
      <select name="type" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="">All Types</option>
        <option value="business_income" <?= $type === 'business_income' ? 'selected' : '' ?>>Business Income</option>
        <option value="business_expense" <?= $type === 'business_expense' ? 'selected' : '' ?>>Business Expense</option>
        <option value="personal_income" <?= $type === 'personal_income' ? 'selected' : '' ?>>Personal Income</option>
        <option value="personal_expense" <?= $type === 'personal_expense' ? 'selected' : '' ?>>Personal Expense</option>
        <option value="partner_payment" <?= $type === 'partner_payment' ? 'selected' : '' ?>>Partner Payment</option>
        <option value="owner_receipt" <?= $type === 'owner_receipt' ? 'selected' : '' ?>>Owner Share Receipt</option>
        <option value="transfer_in" <?= $type === 'transfer_in' ? 'selected' : '' ?>>Transfer In</option>
        <option value="transfer_out" <?= $type === 'transfer_out' ? 'selected' : '' ?>>Transfer Out</option>
      </select>

      <select name="dir" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="">All Directions</option>
        <option value="in" <?= $direction === 'in' ? 'selected' : '' ?>>Inflow (+)</option>
        <option value="out" <?= $direction === 'out' ? 'selected' : '' ?>>Outflow (-)</option>
      </select>

      <select name="account_id" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="0">All Accounts</option>
        <?php foreach ($accounts as $acc): ?>
          <option value="<?= $acc['id'] ?>" <?= $account_id == $acc['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($acc['account_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <a href="<?= BASE_URL ?>/transactions.php" class="btn btn-secondary btn-sm">Reset</a>
    </div>

    <div>
      <a href="<?= BASE_URL ?>/quick-add.php" class="btn btn-primary">
        + Record Entry
      </a>
    </div>
  </form>
</div>

<?php if (empty($transactions)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-title">No transactions recorded matching filters</div>
      <a href="<?= BASE_URL ?>/quick-add.php" class="btn btn-primary btn-sm">Record Transaction</a>
    </div>
  </div>
<?php else: ?>
  <div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
      <table class="data-table mobile-cards">
        <thead>
          <tr>
            <th>Date</th>
            <th>Type</th>
            <th>Account</th>
            <th>Description / Notes</th>
            <th>Reference</th>
            <th style="text-align: right;">Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($transactions as $tx): ?>
            <tr>
              <td data-label="Date"><?= format_date($tx['transaction_date']) ?></td>
              <td data-label="Type">
                <span class="badge <?= $tx['direction'] === 'in' ? 'badge-success' : 'badge-danger' ?>">
                  <?= ucfirst(str_replace('_', ' ', $tx['transaction_type'])) ?>
                </span>
              </td>
              <td data-label="Account">
                <strong><?= htmlspecialchars($tx['account_name']) ?></strong>
              </td>
              <td data-label="Description">
                <?= htmlspecialchars($tx['description']) ?>
              </td>
              <td data-label="Reference" style="font-family: monospace; font-size: 11.5px; color: var(--text-muted);">
                <?= htmlspecialchars($tx['reference_no'] ?? '-') ?>
              </td>
              <td data-label="Amount" style="text-align: right; font-weight: 700; font-size: 14.5px;" class="<?= $tx['direction'] === 'in' ? 'money-positive' : 'money-negative' ?>">
                <?= $tx['direction'] === 'in' ? '+' : '-' ?><?= format_money($tx['amount']) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
