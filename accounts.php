<?php
/**
 * KhataShata - Accounts & Wallets Management
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$page_title = 'Accounts & Wallets';
$active_nav = 'accounts';

$db = get_db_connection();

$stmt = $db->prepare("SELECT * FROM accounts WHERE user_id = :uid AND is_deleted = 0 ORDER BY status ASC, account_name ASC");
$stmt->execute(['uid' => $user['id']]);
$raw_accounts = $stmt->fetchAll();

$accounts = [];
$total_liquid = 0.00;
foreach ($raw_accounts as $acc) {
    $aid = $acc['id'];

    // Inflows
    $in_stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM account_transactions WHERE account_id = :aid AND direction = 'in' AND is_deleted = 0");
    $in_stmt->execute(['aid' => $aid]);
    $total_in = (float)$in_stmt->fetchColumn();

    // Outflows
    $out_stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM account_transactions WHERE account_id = :aid AND direction = 'out' AND is_deleted = 0");
    $out_stmt->execute(['aid' => $aid]);
    $total_out = (float)$out_stmt->fetchColumn();

    // Calculated derived balance: Opening Balance + Inflows - Outflows
    $derived_balance = round(((float)$acc['opening_balance']) + $total_in - $total_out, 2);

    $acc['total_inflow'] = $total_in;
    $acc['total_outflow'] = $total_out;
    $acc['derived_balance'] = $derived_balance;

    if ($acc['status'] === 'active') {
        $total_liquid += $derived_balance;
    }
    $accounts[] = $acc;
}

require_once ROOT_PATH . '/includes/header.php';
?>

<!-- Liquid Cash Overview Header -->
<div class="card" style="padding: 18px 24px; margin-bottom: 20px; background: linear-gradient(135deg, var(--bg-surface), var(--primary-light)); border-color: var(--primary-border);">
  <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
    <div>
      <span style="font-size: 13px; font-weight: 700; color: var(--primary); text-transform: uppercase; letter-spacing: 0.05em;">Total Consolidated Liquid Funds</span>
      <div style="font-size: 28px; font-weight: 800; color: var(--text-main); margin-top: 4px;">
        <?= format_money($total_liquid) ?>
      </div>
      <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
        Active bank balances, physical cash in hand & mobile wallets
      </div>
    </div>

    <div style="display: flex; gap: 8px;">
      <a href="<?= BASE_URL ?>/account-transfer.php" class="btn btn-secondary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
        <span>Account Transfer</span>
      </a>
      <a href="<?= BASE_URL ?>/account-create.php" class="btn btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span>Add Account / Wallet</span>
      </a>
    </div>
  </div>
</div>

<?php if (empty($accounts)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
      </div>
      <div class="empty-state-title">No accounts configured</div>
      <div class="empty-state-text">Add your cash in hand, bank accounts, or JazzCash/Easypaisa wallets to track money movements.</div>
      <a href="<?= BASE_URL ?>/account-create.php" class="btn btn-primary">Add Account</a>
    </div>
  </div>
<?php else: ?>
  <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <?php foreach ($accounts as $acc): ?>
      <div class="card" style="margin-bottom: 0; display: flex; flex-direction: column; justify-content: space-between;">
        <div>
          <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 12px;">
            <div style="display: flex; align-items: center; gap: 12px;">
              <div class="stat-icon <?= $acc['account_type'] === 'cash' ? 'success' : ($acc['account_type'] === 'jazzcash' || $acc['account_type'] === 'easypaisa' ? 'warning' : 'primary') ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
              </div>
              <div>
                <a href="<?= BASE_URL ?>/account-detail.php?id=<?= $acc['id'] ?>" style="font-weight: 700; font-size: 15px; color: var(--text-main);">
                  <?= htmlspecialchars($acc['account_name']) ?>
                </a>
                <div style="font-size: 11.5px; color: var(--text-muted);">
                  <?= htmlspecialchars($acc['provider_name'] ?? ucfirst($acc['account_type'])) ?>
                </div>
              </div>
            </div>
            <span class="badge <?= $acc['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>">
              <?= ucfirst($acc['status']) ?>
            </span>
          </div>

          <!-- Masked account number (Section 11.2) -->
          <?php if (!empty($acc['account_number'])): ?>
            <div style="font-size: 12px; font-family: monospace; color: var(--text-muted); margin-bottom: 12px;">
              <?= mask_account($acc['account_number']) ?>
            </div>
          <?php endif; ?>

          <div style="margin-bottom: 14px;">
            <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Current Available Balance</div>
            <div style="font-size: 22px; font-weight: 800; color: var(--text-main); margin-top: 2px;">
              <?= format_money($acc['derived_balance']) ?>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 12px; padding: 10px; background: var(--bg-surface-subtle); border-radius: var(--radius-sm); margin-bottom: 14px;">
            <div>
              <span style="color: var(--text-muted);">Inflows:</span><br>
              <strong class="money-positive">+<?= format_money($acc['total_inflow']) ?></strong>
            </div>
            <div>
              <span style="color: var(--text-muted);">Outflows:</span><br>
              <strong class="money-negative">-<?= format_money($acc['total_outflow']) ?></strong>
            </div>
          </div>
        </div>

        <div style="display: flex; gap: 8px; border-top: 1px solid var(--border-subtle); padding-top: 12px;">
          <a href="<?= BASE_URL ?>/account-detail.php?id=<?= $acc['id'] ?>" class="btn btn-secondary btn-sm" style="flex: 1;">
            View Statement
          </a>
          <a href="<?= BASE_URL ?>/account-transfer.php?from_id=<?= $acc['id'] ?>" class="btn btn-secondary btn-sm" title="Transfer From This Account">
            Transfer
          </a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
