<?php
/**
 * KhataShata - Account to Account Transfer
 * Moves funds between cash, bank, and digital wallets without affecting income/expenses.
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$error = '';
$db = get_db_connection();

$from_id = (int)($_GET['from_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } else {
        $from_account = (int)($_POST['from_account_id'] ?? 0);
        $to_account = (int)($_POST['to_account_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $date = trim($_POST['transfer_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? 'Account fund transfer');

        if ($from_account <= 0 || $to_account <= 0) {
            $error = 'Please select both source and destination accounts.';
        } elseif ($from_account === $to_account) {
            $error = 'Source and destination accounts must be different.';
        } elseif ($amount <= 0) {
            $error = 'Transfer amount must be greater than zero.';
        } else {
            $res = FinancialEngine::executeAccountTransfer($from_account, $to_account, $amount, $date, $notes, $user['id']);
            if ($res['success']) {
                flash_set('success', 'Successfully transferred ' . format_money($amount) . ' between accounts!');
                redirect('accounts.php');
            } else {
                $error = $res['error'];
            }
        }
    }
}

// Fetch active accounts
$stmt = $db->prepare("SELECT id, account_name, account_type, current_balance FROM accounts WHERE user_id = :uid AND status = 'active' AND is_deleted = 0 ORDER BY account_name ASC");
$stmt->execute(['uid' => $user['id']]);
$accounts = $stmt->fetchAll();

// Recent transfers
$t_stmt = $db->prepare("
    SELECT at.*, a1.account_name as from_name, a2.account_name as to_name
    FROM account_transfers at
    JOIN accounts a1 ON at.from_account_id = a1.id
    JOIN accounts a2 ON at.to_account_id = a2.id
    WHERE at.user_id = :uid AND at.is_deleted = 0
    ORDER BY at.transfer_date DESC, at.id DESC
    LIMIT 10
");
$t_stmt->execute(['uid' => $user['id']]);
$recent_transfers = $t_stmt->fetchAll();

$page_title = 'Account Fund Transfer';
$active_nav = 'accounts';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 760px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">Transfer Between Accounts</h2>
      <p style="font-size: 13px; color: var(--text-muted);">Move money between your cash, bank accounts, and mobile wallets without affecting profit or expenses.</p>
    </div>
    <a href="<?= BASE_URL ?>/accounts.php" class="btn btn-secondary btn-sm">Cancel</a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card" style="margin-bottom: 24px;">
    <form method="POST" action="">
      <?= csrf_field() ?>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Transfer From (Source) <span class="req">*</span></label>
          <select name="from_account_id" class="form-control" required>
            <option value="">-- Select Source Account --</option>
            <?php foreach ($accounts as $acc): ?>
              <option value="<?= $acc['id'] ?>" <?= ($from_id == $acc['id'] || (isset($_POST['from_account_id']) && $_POST['from_account_id'] == $acc['id'])) ? 'selected' : '' ?>>
                <?= htmlspecialchars($acc['account_name']) ?> (Avail: <?= format_money($acc['current_balance']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Transfer To (Destination) <span class="req">*</span></label>
          <select name="to_account_id" class="form-control" required>
            <option value="">-- Select Destination Account --</option>
            <?php foreach ($accounts as $acc): ?>
              <option value="<?= $acc['id'] ?>" <?= (isset($_POST['to_account_id']) && $_POST['to_account_id'] == $acc['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($acc['account_name']) ?> (Avail: <?= format_money($acc['current_balance']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Transfer Amount <span class="req">*</span></label>
          <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" autofocus>
        </div>

        <div class="form-group">
          <label class="form-label">Transfer Date <span class="req">*</span></label>
          <input type="date" name="transfer_date" class="form-control" required value="<?= htmlspecialchars($_POST['transfer_date'] ?? date('Y-m-d')) ?>">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Notes / Purpose of Transfer</label>
        <textarea name="notes" rows="2" class="form-control" placeholder="e.g. Bank cash withdrawal, moving funds to JazzCash for quick expenses..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
      </div>

      <div class="alert alert-info" style="font-size: 12.5px;">
        ℹ️ <strong>Neutral Cash Movement:</strong> Account transfers adjust both account balances simultaneously and will <em>not</em> be counted as income or expense in your savings reports.
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">
          Execute Account Transfer
        </button>
        <a href="<?= BASE_URL ?>/accounts.php" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>

  <!-- Recent Transfers -->
  <?php if (!empty($recent_transfers)): ?>
    <div class="card">
      <div class="card-header">
        <div class="card-title">Recent Internal Transfers</div>
      </div>
      <div class="table-responsive">
        <table class="data-table mobile-cards">
          <thead>
            <tr>
              <th>Date</th>
              <th>From Account</th>
              <th>To Account</th>
              <th>Reference / Notes</th>
              <th style="text-align: right;">Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent_transfers as $rt): ?>
              <tr>
                <td data-label="Date"><?= format_date($rt['transfer_date']) ?></td>
                <td data-label="From"><strong><?= htmlspecialchars($rt['from_name']) ?></strong></td>
                <td data-label="To"><strong><?= htmlspecialchars($rt['to_name']) ?></strong></td>
                <td data-label="Notes">
                  <div><?= htmlspecialchars($rt['reference_no'] ?? '-') ?></div>
                  <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($rt['notes'] ?? '') ?></div>
                </td>
                <td data-label="Amount" style="text-align: right; font-weight: 700;">
                  <?= format_money($rt['amount']) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
