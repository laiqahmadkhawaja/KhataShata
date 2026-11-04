<?php
/**
 * KhataShata - Partner Directory & Management
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$page_title = 'Business Partners';
$active_nav = 'partners';

$search = trim($_GET['search'] ?? '');
$db = get_db_connection();

$sql = "SELECT * FROM partners WHERE user_id = :uid AND is_deleted = 0";
$params = ['uid' => $user['id']];
if (!empty($search)) {
    $sql .= " AND (name LIKE :search OR phone LIKE :search OR email LIKE :search)";
    $params['search'] = "%$search%";
}
$sql .= " ORDER BY status ASC, name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$raw_partners = $stmt->fetchAll();

$partners = [];
foreach ($raw_partners as $p) {
    $pid = $p['id'];

    // Find businesses this partner participates in
    $b_stmt = $db->prepare("
        SELECT DISTINCT b.id, b.name 
        FROM businesses b
        JOIN business_ownership bo ON b.id = bo.business_id
        WHERE bo.partner_id = :pid AND b.is_deleted = 0
    ");
    $b_stmt->execute(['pid' => $pid]);
    $p['businesses'] = $b_stmt->fetchAll();

    // Lifetime earned share across all businesses
    $e_stmt = $db->prepare("SELECT COALESCE(SUM(share_amount), 0.00) FROM profit_distributions WHERE partner_id = :pid");
    $e_stmt->execute(['pid' => $pid]);
    $p['total_earned'] = (float)$e_stmt->fetchColumn();

    // Lifetime payments received across all businesses
    $pay_stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM partner_payments WHERE partner_id = :pid AND is_deleted = 0");
    $pay_stmt->execute(['pid' => $pid]);
    $p['total_paid'] = (float)$pay_stmt->fetchColumn();

    // Current outstanding balance due to partner
    $p['outstanding_balance'] = max(0.00, round($p['total_earned'] - $p['total_paid'], 2));

    $partners[] = $p;
}

require_once ROOT_PATH . '/includes/header.php';
?>

<div class="card" style="padding: 16px 20px; margin-bottom: 20px;">
  <form method="GET" action="" class="filter-bar" style="margin-bottom: 0;">
    <div class="filter-group" style="flex: 1; max-width: 320px;">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search partners by name, phone, email..." class="form-control">
    </div>

    <div>
      <a href="<?= BASE_URL ?>/partner-create.php" class="btn btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span>Add New Partner Profile</span>
      </a>
    </div>
  </form>
</div>

<?php if (empty($partners)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      </div>
      <div class="empty-state-title">No partners registered yet</div>
      <div class="empty-state-text">Add your business co-founders, shareholders, and investment partners to track their profit entitlements and settlements.</div>
      <a href="<?= BASE_URL ?>/partner-create.php" class="btn btn-primary">Register Partner</a>
    </div>
  </div>
<?php else: ?>
  <div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
      <table class="data-table mobile-cards">
        <thead>
          <tr>
            <th>Partner Name</th>
            <th>Contact</th>
            <th>Businesses</th>
            <th style="text-align: right;">Total Earned</th>
            <th style="text-align: right;">Total Paid</th>
            <th style="text-align: right;">Pending Balance Due</th>
            <th>Status</th>
            <th style="text-align: right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($partners as $p): ?>
            <tr>
              <td data-label="Partner">
                <a href="<?= BASE_URL ?>/partner-ledger.php?partner_id=<?= $p['id'] ?>" style="font-weight: 700; color: var(--text-main); font-size: 14.5px;">
                  <?= htmlspecialchars($p['name']) ?>
                </a>
                <div style="font-size: 11.5px; color: var(--text-muted);">
                  Joined: <?= format_date($p['joining_date'] ?? $p['created_at']) ?>
                </div>
              </td>
              <td data-label="Contact">
                <div><?= htmlspecialchars($p['phone'] ?? '-') ?></div>
                <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($p['email'] ?? '') ?></div>
              </td>
              <td data-label="Businesses">
                <?php if (empty($p['businesses'])): ?>
                  <span style="color: var(--text-muted); font-size: 12px;">Not assigned</span>
                <?php else: ?>
                  <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                    <?php foreach ($p['businesses'] as $b): ?>
                      <a href="<?= BASE_URL ?>/business-detail.php?id=<?= $b['id'] ?>" class="badge badge-primary">
                        <?= htmlspecialchars($b['name']) ?>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td data-label="Earned" style="text-align: right;" class="money-positive">
                <?= format_money($p['total_earned']) ?>
              </td>
              <td data-label="Paid" style="text-align: right;" class="money-negative">
                <?= format_money($p['total_paid']) ?>
              </td>
              <td data-label="Pending Due" style="text-align: right; font-weight: 700; color: <?= $p['outstanding_balance'] > 0 ? 'var(--warning-dark)' : 'var(--text-muted)' ?>;">
                <?= format_money($p['outstanding_balance']) ?>
              </td>
              <td data-label="Status">
                <span class="badge <?= $p['status'] === 'Active' ? 'badge-success' : 'badge-neutral' ?>">
                  <?= htmlspecialchars($p['status']) ?>
                </span>
              </td>
              <td data-label="Actions" style="text-align: right;">
                <div style="display: flex; gap: 6px; justify-content: flex-end;">
                  <a href="<?= BASE_URL ?>/partner-payment.php?partner_id=<?= $p['id'] ?>" class="btn btn-primary btn-sm">
                    Pay Partner
                  </a>
                  <a href="<?= BASE_URL ?>/partner-ledger.php?partner_id=<?= $p['id'] ?>" class="btn btn-secondary btn-sm">
                    Ledger
                  </a>
                  <a href="<?= BASE_URL ?>/partner-edit.php?id=<?= $p['id'] ?>" class="btn btn-secondary btn-sm">
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
