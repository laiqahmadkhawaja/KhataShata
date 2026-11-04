<?php
/**
 * KhataShata - Create New Account / Wallet
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$error = '';
$db = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } else {
        $name = trim($_POST['account_name'] ?? '');
        $type = trim($_POST['account_type'] ?? 'bank');
        $provider = trim($_POST['provider_name'] ?? '');
        $number = trim($_POST['account_number'] ?? '');
        $opening_balance = (float)($_POST['opening_balance'] ?? 0);
        $opening_date = !empty($_POST['opening_balance_date']) ? $_POST['opening_balance_date'] : date('Y-m-d');
        $description = trim($_POST['description'] ?? '');
        $status = trim($_POST['status'] ?? 'active');

        if (empty($name)) {
            $error = 'Account name is required.';
        } else {
            try {
                $db->beginTransaction();

                $stmt = $db->prepare("
                    INSERT INTO accounts 
                    (user_id, account_name, account_type, provider_name, account_number, opening_balance, opening_balance_date, current_balance, description, status)
                    VALUES (:uid, :name, :type, :provider, :num, :open, :odate, :cur, :desc, :status)
                ");
                $stmt->execute([
                    'uid' => $user['id'],
                    'name' => $name,
                    'type' => $type,
                    'provider' => $provider,
                    'num' => $number,
                    'open' => $opening_balance,
                    'odate' => $opening_date,
                    'cur' => $opening_balance,
                    'desc' => $description,
                    'status' => $status
                ]);
                $account_id = $db->lastInsertId();

                log_audit($user['id'], 'accounts', $account_id, 'create', null, $_POST, "Created account $name with opening balance $opening_balance");
                $db->commit();

                flash_set('success', "Account '$name' created successfully!");
                redirect('accounts.php');
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Error creating account: " . $e->getMessage();
            }
        }
    }
}

$page_title = 'Create Account or Wallet';
$active_nav = 'accounts';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 700px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">Add Account or Digital Wallet</h2>
      <p style="font-size: 13px; color: var(--text-muted);">Set up bank accounts, cash wallets, JazzCash, or Easypaisa.</p>
    </div>
    <a href="<?= BASE_URL ?>/accounts.php" class="btn btn-secondary btn-sm">Cancel</a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" action="">
      <?= csrf_field() ?>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Account / Wallet Name <span class="req">*</span></label>
          <input type="text" name="account_name" class="form-control" placeholder="e.g. Meezan Bank Checking, Physical Cash" required value="<?= htmlspecialchars($_POST['account_name'] ?? '') ?>" autofocus>
        </div>

        <div class="form-group">
          <label class="form-label">Account Type <span class="req">*</span></label>
          <select name="account_type" class="form-control" required>
            <option value="bank" selected>Bank Account</option>
            <option value="cash">Cash in Hand</option>
            <option value="jazzcash">JazzCash</option>
            <option value="easypaisa">Easypaisa</option>
            <option value="wallet">Other Digital Wallet</option>
            <option value="other">Other Asset Account</option>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Bank / Provider Name</label>
          <input type="text" name="provider_name" class="form-control" placeholder="e.g. Meezan Bank, Habib Bank, Mobilink" value="<?= htmlspecialchars($_POST['provider_name'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Account / IBAN Number (Masked in UI)</label>
          <input type="text" name="account_number" class="form-control" placeholder="PK00MEZN0000000000 or phone number" value="<?= htmlspecialchars($_POST['account_number'] ?? '') ?>">
          <div class="form-text">Stored securely and automatically masked on dashboards.</div>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Opening Balance</label>
          <input type="number" step="0.01" name="opening_balance" class="form-control" placeholder="0.00" value="<?= htmlspecialchars($_POST['opening_balance'] ?? '0.00') ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Opening Balance Date</label>
          <input type="date" name="opening_balance_date" class="form-control" value="<?= htmlspecialchars($_POST['opening_balance_date'] ?? date('Y-m-d')) ?>">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Description / Branch Details</label>
        <textarea name="description" rows="2" class="form-control" placeholder="Branch address, purpose, notes..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" class="form-control">
          <option value="active" selected>Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">Save Account</button>
        <a href="<?= BASE_URL ?>/accounts.php" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
