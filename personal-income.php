<?php
/**
 * KhataShata - Personal Income Listing
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$page_title = 'Personal Income & Salary';
$active_nav = 'personal_income';

$db = get_db_connection();
$source_id = (int)($_GET['source_id'] ?? 0);
$status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT pi.*, pis.name as source_name, a.account_name
    FROM personal_income pi
    LEFT JOIN personal_income_sources pis ON pi.source_id = pis.id
    LEFT JOIN accounts a ON pi.account_id = a.id
    WHERE pi.user_id = :uid AND pi.is_deleted = 0
";
$params = ['uid' => $user['id']];
if ($source_id > 0) {
    $sql .= " AND pi.source_id = :sid";
    $params['sid'] = $source_id;
}
if (!empty($status)) {
    $sql .= " AND pi.payment_status = :status";
    $params['status'] = $status;
}
if (!empty($search)) {
    $sql .= " AND (pi.title LIKE :s OR pi.reference_no LIKE :s OR pi.description LIKE :s)";
    $params['s'] = "%$search%";
}
$sql .= " ORDER BY pi.income_date DESC, pi.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$incomes = $stmt->fetchAll();

// Total
$total_received = 0.00;
$total_expected = 0.00;
foreach ($incomes as $inc) {
    if ($inc['payment_status'] === 'received') {
        $total_received += (float)$inc['amount'];
    } else {
        $total_expected += (float)$inc['amount'];
    }
}

// Income sources for filter
$s_stmt = $db->prepare("SELECT id, name FROM personal_income_sources WHERE user_id = :uid AND is_deleted = 0 ORDER BY name ASC");
$s_stmt->execute(['uid' => $user['id']]);
$sources = $s_stmt->fetchAll();

require_once ROOT_PATH . '/includes/header.php';
?>

<div class="card" style="padding: 16px 20px; margin-bottom: 20px;">
  <form method="GET" action="" class="filter-bar" style="margin-bottom: 0;">
    <div class="filter-group" style="flex: 1; max-width: 260px;">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search personal income..." class="form-control">
    </div>

    <div class="filter-group">
      <select name="source_id" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="0">All Income Sources</option>
        <?php foreach ($sources as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $source_id == $s['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($s['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="status" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <option value="received" <?= $status === 'received' ? 'selected' : '' ?>>Received</option>
        <option value="expected" <?= $status === 'expected' ? 'selected' : '' ?>>Expected</option>
        <option value="partially_received" <?= $status === 'partially_received' ? 'selected' : '' ?>>Partially Received</option>
        <option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Overdue</option>
      </select>

      <a href="<?= BASE_URL ?>/personal-income.php" class="btn btn-secondary btn-sm">Reset</a>
    </div>

    <div style="display:flex; gap:8px;">
      <a href="<?= BASE_URL ?>/income-sources.php" class="btn btn-secondary btn-sm">
        Manage Sources
      </a>
      <a href="<?= BASE_URL ?>/personal-income-create.php" class="btn btn-primary">
        + Record Income
      </a>
    </div>
  </form>
</div>

<!-- Summary cards -->
<div class="metrics-grid">
  <div class="stat-card">
    <span class="stat-label">Total Personal Income Received</span>
    <div class="stat-value money-positive"><?= format_money($total_received) ?></div>
    <div class="stat-sub">Confirmed cash inflow into personal accounts</div>
  </div>

  <div class="stat-card">
    <span class="stat-label">Expected / Pending Inflow</span>
    <div class="stat-value" style="color: var(--warning-dark);"><?= format_money($total_expected) ?></div>
    <div class="stat-sub">Expected salaries or receivables</div>
  </div>
</div>

<?php if (empty($incomes)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/></svg>
      </div>
      <div class="empty-state-title">No personal income entries found</div>
      <div class="empty-state-text">Record your regular monthly salary, freelance projects, consulting gigs, or rental returns.</div>
      <a href="<?= BASE_URL ?>/personal-income-create.php" class="btn btn-primary">Record Personal Income</a>
    </div>
  </div>
<?php else: ?>
  <div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
      <table class="data-table mobile-cards">
        <thead>
          <tr>
            <th>Income Title / Source</th>
            <th>Date</th>
            <th>Account</th>
            <th>Method</th>
            <th>Status</th>
            <th style="text-align: right;">Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($incomes as $inc): ?>
            <tr>
              <td data-label="Title">
                <strong style="font-size: 14px;"><?= htmlspecialchars($inc['title']) ?></strong>
                <div style="font-size: 11px; color: var(--text-muted);">
                  Source: <?= htmlspecialchars($inc['source_name'] ?? 'Direct Income') ?>
                  <?php if (!empty($inc['reference_no'])): ?> &bull; Ref: <?= htmlspecialchars($inc['reference_no']) ?><?php endif; ?>
                </div>
              </td>
              <td data-label="Date"><?= format_date($inc['income_date']) ?></td>
              <td data-label="Account"><?= htmlspecialchars($inc['account_name'] ?? 'Unlinked / Cash Direct') ?></td>
              <td data-label="Method"><?= htmlspecialchars($inc['payment_method']) ?></td>
              <td data-label="Status">
                <span class="badge <?= $inc['payment_status'] === 'received' ? 'badge-success' : ($inc['payment_status'] === 'expected' ? 'badge-warning' : 'badge-danger') ?>">
                  <?= ucfirst(str_replace('_', ' ', $inc['payment_status'])) ?>
                </span>
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
