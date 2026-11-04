<?php
/**
 * KhataShata - Account Detail & Bank Statement
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$account_id = (int)($_GET['id'] ?? 0);
$db = get_db_connection();

$stmt = $db->prepare("SELECT * FROM accounts WHERE id = :id AND user_id = :uid AND is_deleted = 0 LIMIT 1");
$stmt->execute(['id' => $account_id, 'uid' => $user['id']]);
$account = $stmt->fetch();

if (!$account) {
    flash_set('danger', 'Account not found.');
    redirect('accounts.php');
}

// Fetch transactions for statement
$tx_stmt = $db->prepare("
    SELECT * FROM account_transactions 
    WHERE account_id = :aid AND is_deleted = 0 
    ORDER BY transaction_date ASC, id ASC
");
$tx_stmt->execute(['aid' => $account_id]);
$all_txs = $tx_stmt->fetchAll();

// Calculate running balances
$running_balance = (float)$account['opening_balance'];
$statement_rows = [];
$total_inflows = 0.00;
$total_outflows = 0.00;

foreach ($all_txs as $tx) {
    $amt = (float)$tx['amount'];
    if ($tx['direction'] === 'in') {
        $running_balance += $amt;
        $total_inflows += $amt;
    } else {
        $running_balance -= $amt;
        $total_outflows += $amt;
    }
    $tx['running_balance'] = $running_balance;
    $statement_rows[] = $tx;
}

// Reverse to show latest first on UI
$statement_display = array_reverse($statement_rows);

$page_title = $account['account_name'] . ' Statement';
$active_nav = 'accounts';

require_once ROOT_PATH . '/includes/header.php';
?>

<!-- Account Overview Card -->
<div class="card" style="margin-bottom: 20px;">
  <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
    <div>
      <div style="display: flex; align-items: center; gap: 10px;">
        <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);"><?= htmlspecialchars($account['account_name']) ?></h2>
        <span class="badge <?= $account['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>">
          <?= ucfirst($account['status']) ?>
        </span>
      </div>
      <div style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">
        <span><?= htmlspecialchars($account['provider_name'] ?? ucfirst($account['account_type'])) ?></span>
        <?php if (!empty($account['account_number'])): ?>
          &bull; <code><?= mask_account($account['account_number']) ?></code>
        <?php endif; ?>
      </div>
    </div>

    <div style="display: flex; gap: 8px;">
      <a href="<?= BASE_URL ?>/account-transfer.php?from_id=<?= $account['id'] ?>" class="btn btn-primary btn-sm">
        Transfer Money
      </a>
      <a href="<?= BASE_URL ?>/accounts.php" class="btn btn-secondary btn-sm">
        Back to Accounts
      </a>
    </div>
  </div>

  <div class="metrics-grid" style="margin-top: 20px; margin-bottom: 0;">
    <div class="stat-card">
      <span class="stat-label">Opening Balance</span>
      <div class="stat-value"><?= format_money($account['opening_balance']) ?></div>
      <div class="stat-sub">As of <?= format_date($account['opening_balance_date'] ?? $account['created_at']) ?></div>
    </div>

    <div class="stat-card">
      <span class="stat-label">Total Inflows</span>
      <div class="stat-value money-positive">+<?= format_money($total_inflows) ?></div>
      <div class="stat-sub">Deposits, revenue & transfers in</div>
    </div>

    <div class="stat-card">
      <span class="stat-label">Total Outflows</span>
      <div class="stat-value money-negative">-<?= format_money($total_outflows) ?></div>
      <div class="stat-sub">Expenses, payments & transfers out</div>
    </div>

    <div class="stat-card" style="background: var(--bg-surface-subtle);">
      <span class="stat-label">Current Closing Balance</span>
      <div class="stat-value" style="color: var(--primary);"><?= format_money($running_balance) ?></div>
      <div class="stat-sub">Reconciled running balance</div>
    </div>
  </div>
</div>

<!-- Statement Transactions Table -->
<div class="card">
  <div class="card-header">
    <div class="card-title">Transaction Ledger & Account Statement</div>
    <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm">Print Statement</button>
  </div>

  <?php if (empty($statement_display)): ?>
    <div class="empty-state" style="padding: 24px;">
      <div class="empty-state-text">No transactions recorded for this account yet. Opening balance is <?= format_money($account['opening_balance']) ?>.</div>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="data-table mobile-cards">
        <thead>
          <tr>
            <th>Date</th>
            <th>Type</th>
            <th>Description / Ref</th>
            <th style="text-align: right;">Inflow (+)</th>
            <th style="text-align: right;">Outflow (-)</th>
            <th style="text-align: right;">Running Balance</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($statement_display as $row): ?>
            <tr>
              <td data-label="Date"><?= format_date($row['transaction_date']) ?></td>
              <td data-label="Type">
                <span class="badge <?= $row['direction'] === 'in' ? 'badge-success' : 'badge-danger' ?>">
                  <?= ucfirst(str_replace('_', ' ', $row['transaction_type'])) ?>
                </span>
              </td>
              <td data-label="Description">
                <strong><?= htmlspecialchars($row['description']) ?></strong>
                <?php if (!empty($row['reference_no'])): ?>
                  <div style="font-size: 11px; color: var(--text-muted); font-family: monospace;">
                    Ref: <?= htmlspecialchars($row['reference_no']) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td data-label="Inflow" style="text-align: right;" class="money-positive">
                <?= $row['direction'] === 'in' ? '+' . format_money($row['amount']) : '-' ?>
              </td>
              <td data-label="Outflow" style="text-align: right;" class="money-negative">
                <?= $row['direction'] === 'out' ? '-' . format_money($row['amount']) : '-' ?>
              </td>
              <td data-label="Balance" style="text-align: right; font-weight: 700;">
                <?= format_money($row['running_balance']) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
