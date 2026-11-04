<?php
/**
 * KhataShata - Business Portfolio Listing
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$page_title = 'My Businesses';
$active_nav = 'businesses';

$current_month = date('Y-m');
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';

$db = get_db_connection();

// Categories for filter
$c_stmt = $db->prepare("SELECT id, name FROM business_categories WHERE user_id = :uid ORDER BY name ASC");
$c_stmt->execute(['uid' => $user['id']]);
$categories = $c_stmt->fetchAll();

// Build query
$sql = "
    SELECT b.*, bc.name as category_name
    FROM businesses b
    LEFT JOIN business_categories bc ON b.category_id = bc.id
    WHERE b.user_id = :uid AND b.is_deleted = 0
";
$params = ['uid' => $user['id']];

if (!empty($search)) {
    $sql .= " AND (b.name LIKE :search OR b.description LIKE :search)";
    $params['search'] = "%$search%";
}
if (!empty($status_filter)) {
    $sql .= " AND b.status = :status";
    $params['status'] = $status_filter;
}
if (!empty($category_filter)) {
    $sql .= " AND b.category_id = :cat";
    $params['cat'] = $category_filter;
}

$sql .= " ORDER BY b.status ASC, b.name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$raw_businesses = $stmt->fetchAll();

// Enrich each business with current month financial statistics & ownership
$businesses = [];
foreach ($raw_businesses as $b) {
    $bid = $b['id'];

    // Ownership & Partners count
    $participants = FinancialEngine::getEffectiveOwnership($bid, $current_month);
    $owner_pct = 0.0;
    $partner_count = 0;
    foreach ($participants as $p) {
        if ($p['is_owner']) {
            $owner_pct = $p['percentage'];
        } else {
            $partner_count++;
        }
    }

    // Monthly Financials
    $summary = FinancialEngine::getMonthlySummary($bid, $current_month);
    $rev = $summary['total_revenue'];
    $exp = $summary['total_expenses'];
    $net_profit = $summary['net_profit'];

    // Owner current month share
    $owner_month_share = 0.00;
    foreach ($summary['distributions'] as $d) {
        if ($d['is_owner']) {
            $owner_month_share = $d['share_amount'];
            break;
        }
    }

    // Total outstanding receivables from this business for owner
    $stmt_rec = $db->prepare("
        SELECT 
          (SELECT COALESCE(SUM(share_amount), 0.00) FROM profit_distributions pd WHERE pd.business_id = :bid AND pd.partner_id IS NULL) -
          (SELECT COALESCE(SUM(amount), 0.00) FROM owner_share_receipts osr WHERE osr.business_id = :bid2 AND osr.is_deleted = 0)
    ");
    $stmt_rec->execute(['bid' => $bid, 'bid2' => $bid]);
    $outstanding_rec = max(0.00, (float)$stmt_rec->fetchColumn());

    $b['owner_pct'] = $owner_pct;
    $b['partner_count'] = $partner_count;
    $b['month_revenue'] = $rev;
    $b['month_expenses'] = $exp;
    $b['month_net_profit'] = $net_profit;
    $b['owner_month_share'] = $owner_month_share;
    $b['outstanding_receivable'] = $outstanding_rec;

    $businesses[] = $b;
}

require_once ROOT_PATH . '/includes/header.php';
?>

<div class="card" style="padding: 16px 20px; margin-bottom: 20px;">
  <form method="GET" action="" class="filter-bar" style="margin-bottom: 0;">
    <div class="filter-group" style="flex: 1; max-width: 320px;">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search businesses..." class="form-control">
    </div>
    
    <div class="filter-group">
      <select name="status" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <option value="Active" <?= $status_filter === 'Active' ? 'selected' : '' ?>>Active</option>
        <option value="Inactive" <?= $status_filter === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
        <option value="Closed" <?= $status_filter === 'Closed' ? 'selected' : '' ?>>Closed</option>
      </select>

      <select name="category" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>" <?= $category_filter == $cat['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($cat['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <a href="<?= BASE_URL ?>/businesses.php" class="btn btn-secondary btn-sm">Reset</a>
    </div>

    <div>
      <a href="<?= BASE_URL ?>/business-create.php" class="btn btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span>Register New Business</span>
      </a>
    </div>
  </form>
</div>

<?php if (empty($businesses)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg>
      </div>
      <div class="empty-state-title">No businesses found</div>
      <div class="empty-state-text">Start building your business portfolio by registering your first enterprise, startup, or partnership.</div>
      <a href="<?= BASE_URL ?>/business-create.php" class="btn btn-primary">Create Your First Business</a>
    </div>
  </div>
<?php else: ?>
  <div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
      <table class="data-table mobile-cards">
        <thead>
          <tr>
            <th>Business</th>
            <th>Your Share</th>
            <th>Partners</th>
            <th style="text-align: right;"><?= date('M') ?> Revenue</th>
            <th style="text-align: right;"><?= date('M') ?> Expense</th>
            <th style="text-align: right;"><?= date('M') ?> Net Profit</th>
            <th style="text-align: right;">Your Share</th>
            <th style="text-align: right;">Outstanding Due</th>
            <th>Status</th>
            <th style="text-align: right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($businesses as $b): ?>
            <tr>
              <td data-label="Business">
                <a href="<?= BASE_URL ?>/business-detail.php?id=<?= $b['id'] ?>" style="font-weight: 700; color: var(--text-main); font-size: 14.5px;">
                  <?= htmlspecialchars($b['name']) ?>
                </a>
                <div style="font-size: 11.5px; color: var(--text-muted);">
                  <?= htmlspecialchars($b['category_name'] ?? 'General') ?> &bull; Started <?= format_date($b['start_date']) ?>
                </div>
              </td>
              <td data-label="Your Share">
                <span class="badge badge-primary"><?= format_percentage($b['owner_pct']) ?></span>
              </td>
              <td data-label="Partners">
                <span style="font-weight: 600;"><?= $b['partner_count'] ?></span>
              </td>
              <td data-label="Revenue" style="text-align: right;" class="money-positive">
                <?= format_money($b['month_revenue']) ?>
              </td>
              <td data-label="Expense" style="text-align: right;" class="money-negative">
                <?= format_money($b['month_expenses']) ?>
              </td>
              <td data-label="Net Profit" style="text-align: right; font-weight: 700;" class="<?= $b['month_net_profit'] >= 0 ? 'money-positive' : 'money-negative' ?>">
                <?= format_money($b['month_net_profit']) ?>
              </td>
              <td data-label="Your Share Amount" style="text-align: right; font-weight: 700; color: var(--primary);">
                <?= format_money($b['owner_month_share']) ?>
              </td>
              <td data-label="Outstanding Due" style="text-align: right; font-weight: 700; color: <?= $b['outstanding_receivable'] > 0 ? 'var(--warning-dark)' : 'var(--text-muted)' ?>;">
                <?= format_money($b['outstanding_receivable']) ?>
              </td>
              <td data-label="Status">
                <span class="badge <?= $b['status'] === 'Active' ? 'badge-success' : ($b['status'] === 'Inactive' ? 'badge-warning' : 'badge-neutral') ?>">
                  <?= htmlspecialchars($b['status']) ?>
                </span>
              </td>
              <td data-label="Actions" style="text-align: right;">
                <div style="display: flex; gap: 6px; justify-content: flex-end;">
                  <a href="<?= BASE_URL ?>/business-detail.php?id=<?= $b['id'] ?>" class="btn btn-secondary btn-sm" title="View Full Dashboard">
                    Dashboard
                  </a>
                  <a href="<?= BASE_URL ?>/business-edit.php?id=<?= $b['id'] ?>" class="btn btn-secondary btn-sm" title="Edit Information">
                    Edit
                  </a>
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
