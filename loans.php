<?php
/**
 * KhataShata - Loans, Receivables & Debts Management
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$page_title = 'Loans, Receivables & Payables';
$active_nav = 'loans';

$db = get_db_connection();
$type = $_GET['type'] ?? '';
$status = $_GET['status'] ?? '';

$sql = "
    SELECT l.*, a.account_name 
    FROM loans l
    LEFT JOIN accounts a ON l.account_id = a.id
    WHERE l.user_id = :uid AND l.is_deleted = 0
";
$params = ['uid' => $user['id']];
if (!empty($type)) {
    $sql .= " AND l.loan_type = :type";
    $params['type'] = $type;
}
if (!empty($status)) {
    $sql .= " AND l.status = :status";
    $params['status'] = $status;
}
$sql .= " ORDER BY l.status ASC, l.loan_date DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$loans = $stmt->fetchAll();

// Totals
$total_given_pending = 0.00;
$total_borrowed_pending = 0.00;
foreach ($loans as $l) {
    if ($l['status'] === 'active') {
        if ($l['loan_type'] === 'given') {
            $total_given_pending += (float)$l['current_balance'];
        } else {
            $total_borrowed_pending += (float)$l['current_balance'];
        }
    }
}

require_once ROOT_PATH . '/includes/header.php';
?>

<!-- Loan Metrics Summary -->
<div class="metrics-grid">
  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label">Money Given (Your Receivables)</span>
      <div class="stat-icon success">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/></svg>
      </div>
    </div>
    <div class="stat-value money-positive"><?= format_money($total_given_pending) ?></div>
    <div class="stat-sub">Outstanding money lent to people (Asset)</div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label">Money Borrowed (Your Payables)</span>
      <div class="stat-icon danger">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/></svg>
      </div>
    </div>
    <div class="stat-value money-negative"><?= format_money($total_borrowed_pending) ?></div>
    <div class="stat-sub">Outstanding debts owed to lenders (Liability)</div>
  </div>
</div>

<div class="card" style="padding: 16px 20px; margin-bottom: 20px;">
  <form method="GET" action="" class="filter-bar" style="margin-bottom: 0;">
    <div class="filter-group">
      <select name="type" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="">All Loan Types</option>
        <option value="given" <?= $type === 'given' ? 'selected' : '' ?>>Money Given (Receivable)</option>
        <option value="borrowed" <?= $type === 'borrowed' ? 'selected' : '' ?>>Money Borrowed (Payable)</option>
      </select>

      <select name="status" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active (Outstanding)</option>
        <option value="settled" <?= $status === 'settled' ? 'selected' : '' ?>>Settled</option>
        <option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Overdue</option>
      </select>

      <a href="<?= BASE_URL ?>/loans.php" class="btn btn-secondary btn-sm">Reset</a>
    </div>

    <div>
      <a href="<?= BASE_URL ?>/loan-create.php" class="btn btn-primary">
        + Record Loan Entry
      </a>
    </div>
  </form>
</div>

<?php if (empty($loans)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-title">No loan or debt records found</div>
      <div class="empty-state-text">Keep track of personal loans given to colleagues, friends, or money borrowed without mixing them with profit/expenses.</div>
      <a href="<?= BASE_URL ?>/loan-create.php" class="btn btn-primary">Add Loan Record</a>
    </div>
  </div>
<?php else: ?>
  <div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
      <table class="data-table mobile-cards">
        <thead>
          <tr>
            <th>Party / Person</th>
            <th>Type</th>
            <th>Date</th>
            <th>Due Date</th>
            <th style="text-align: right;">Original Amount</th>
            <th style="text-align: right;">Current Balance Due</th>
            <th>Status</th>
            <th style="text-align: right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($loans as $l): ?>
            <tr>
              <td data-label="Party">
                <strong><?= htmlspecialchars($l['person_name']) ?></strong>
                <div style="font-size: 11px; color: var(--text-muted);">
                  <?= htmlspecialchars($l['contact_number'] ?? '') ?>
                  <?php if (!empty($l['purpose'])): ?> &bull; <?= htmlspecialchars($l['purpose']) ?><?php endif; ?>
                </div>
              </td>
              <td data-label="Type">
                <span class="badge <?= $l['loan_type'] === 'given' ? 'badge-success' : 'badge-danger' ?>">
                  <?= $l['loan_type'] === 'given' ? 'Given (Receivable)' : 'Borrowed (Liability)' ?>
                </span>
              </td>
              <td data-label="Date"><?= format_date($l['loan_date']) ?></td>
              <td data-label="Due Date"><?= format_date($l['due_date']) ?></td>
              <td data-label="Original" style="text-align: right;">
                <?= format_money($l['amount']) ?>
              </td>
              <td data-label="Current Due" style="text-align: right; font-weight: 700; font-size: 14.5px;" class="<?= $l['loan_type'] === 'given' ? 'money-positive' : 'money-negative' ?>">
                <?= format_money($l['current_balance']) ?>
              </td>
              <td data-label="Status">
                <span class="badge <?= $l['status'] === 'active' ? 'badge-warning' : ($l['status'] === 'settled' ? 'badge-success' : 'badge-danger') ?>">
                  <?= ucfirst($l['status']) ?>
                </span>
              </td>
              <td data-label="Action" style="text-align: right;">
                <a href="<?= BASE_URL ?>/loan-detail.php?id=<?= $l['id'] ?>" class="btn btn-secondary btn-sm">
                  Ledger & Settle
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
