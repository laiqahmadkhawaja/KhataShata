<?php
/**
 * KhataShata - Record Personal Income
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
        $source_id = !empty($_POST['source_id']) ? (int)$_POST['source_id'] : null;
        $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $title = trim($_POST['title'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $income_date = trim($_POST['income_date'] ?? date('Y-m-d'));
        $received_date = !empty($_POST['received_date']) ? $_POST['received_date'] : $income_date;
        $payment_status = trim($_POST['payment_status'] ?? 'received');
        $payment_method = trim($_POST['payment_method'] ?? 'Bank Transfer');
        $reference_no = trim($_POST['reference_no'] ?? generate_reference('PINC'));
        $description = trim($_POST['description'] ?? '');

        if (empty($title)) {
            $error = 'Income title is required.';
        } elseif ($amount <= 0) {
            $error = 'Amount must be greater than zero.';
        } else {
            $attachment = null;
            if (!empty($_FILES['attachment']['name'])) {
                $attachment = handle_file_upload($_FILES['attachment'], 'receipts');
            }

            try {
                $db->beginTransaction();

                $stmt = $db->prepare("
                    INSERT INTO personal_income 
                    (user_id, source_id, account_id, title, amount, income_date, received_date, payment_status, payment_method, reference_no, description, attachment)
                    VALUES (:uid, :sid, :aid, :title, :amt, :idate, :rdate, :pstatus, :pmethod, :ref, :desc, :att)
                ");
                $stmt->execute([
                    'uid' => $user['id'],
                    'sid' => $source_id,
                    'aid' => $account_id,
                    'title' => $title,
                    'amt' => $amount,
                    'idate' => $income_date,
                    'rdate' => $received_date,
                    'pstatus' => $payment_status,
                    'pmethod' => $payment_method,
                    'ref' => $reference_no,
                    'desc' => $description,
                    'att' => $attachment
                ]);
                $income_id = $db->lastInsertId();

                // If received and account linked, deposit into account & log transaction
                if ($payment_status === 'received' && $account_id) {
                    FinancialEngine::recordAccountInflow($account_id, $amount, 'personal_income', 'personal_income', $income_id, $income_date, $reference_no, "Personal Income: $title", $user['id']);
                }

                log_audit($user['id'], 'personal_income', $income_id, 'create', null, $_POST, "Recorded personal income of $amount ($payment_status)");
                $db->commit();

                flash_set('success', "Personal income of " . format_money($amount) . " recorded successfully!");
                redirect('personal-income.php');
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Error saving income: " . $e->getMessage();
            }
        }
    }
}

// Income Sources
$s_stmt = $db->prepare("SELECT id, name, expected_amount FROM personal_income_sources WHERE user_id = :uid AND is_deleted = 0 AND status = 'active' ORDER BY name ASC");
$s_stmt->execute(['uid' => $user['id']]);
$sources = $s_stmt->fetchAll();

// Accounts
$stmt = $db->prepare("SELECT id, account_name, current_balance FROM accounts WHERE user_id = :uid AND status = 'active' AND is_deleted = 0 ORDER BY account_name ASC");
$stmt->execute(['uid' => $user['id']]);
$accounts = $stmt->fetchAll();

$page_title = 'Record Personal Income';
$active_nav = 'personal_income';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 760px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">Record Personal Income</h2>
      <p style="font-size: 13px; color: var(--text-muted);">Log your monthly salary, freelancing earnings, consultancy, or other personal returns.</p>
    </div>
    <a href="<?= BASE_URL ?>/personal-income.php" class="btn btn-secondary btn-sm">Cancel</a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" action="" enctype="multipart/form-data">
      <?= csrf_field() ?>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Income Source</label>
          <select name="source_id" class="form-control" id="income-source-select">
            <option value="">-- General / Direct Personal Income --</option>
            <?php foreach ($sources as $s): ?>
              <option value="<?= $s['id'] ?>" data-amount="<?= $s['expected_amount'] ?>" <?= (isset($_POST['source_id']) && $_POST['source_id'] == $s['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['name']) ?> (Expected: <?= format_money($s['expected_amount']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Title / Note <span class="req">*</span></label>
          <input type="text" name="title" id="income-title" class="form-control" placeholder="e.g. October Monthly Salary, Upwork Project" required value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" autofocus>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Amount <span class="req">*</span></label>
          <input type="number" step="0.01" min="0.01" name="amount" id="income-amount" class="form-control" placeholder="0.00" required value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Payment Status <span class="req">*</span></label>
          <select name="payment_status" class="form-control" required>
            <option value="received" selected>Received (Confirmed in Account)</option>
            <option value="expected">Expected (Scheduled / Pending)</option>
            <option value="partially_received">Partially Received</option>
            <option value="overdue">Overdue</option>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Income Date <span class="req">*</span></label>
          <input type="date" name="income_date" class="form-control" required value="<?= htmlspecialchars($_POST['income_date'] ?? date('Y-m-d')) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Deposit Into Account</label>
          <select name="account_id" class="form-control">
            <option value="">-- None (Cash direct or unlinked) --</option>
            <?php foreach ($accounts as $acc): ?>
              <option value="<?= $acc['id'] ?>" <?= (isset($_POST['account_id']) && $_POST['account_id'] == $acc['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($acc['account_name']) ?> (Avail: <?= format_money($acc['current_balance']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">If marked 'Received', account balance will increase automatically.</div>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Payment Method</label>
          <select name="payment_method" class="form-control">
            <option value="Bank Transfer">Bank Transfer (IBFT)</option>
            <option value="Cash">Cash</option>
            <option value="Cheque">Cheque</option>
            <option value="JazzCash">JazzCash</option>
            <option value="Easypaisa">Easypaisa</option>
            <option value="Other">Other</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Pay Slip / Transaction Reference</label>
          <input type="text" name="reference_no" class="form-control" placeholder="e.g. SLIP-2026-10" value="<?= htmlspecialchars($_POST['reference_no'] ?? '') ?>">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Receipt / Payslip Attachment</label>
        <input type="file" name="attachment" class="form-control" accept="image/*,application/pdf">
      </div>

      <div class="form-group">
        <label class="form-label">Description / Remarks</label>
        <textarea name="description" rows="2" class="form-control" placeholder="Notes or details about this personal income..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">
          Save Personal Income
        </button>
        <a href="<?= BASE_URL ?>/personal-income.php" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('income-source-select')?.addEventListener('change', function() {
  const opt = this.options[this.selectedIndex];
  const amt = opt.getAttribute('data-amount');
  if (amt && parseFloat(amt) > 0 && !document.getElementById('income-amount').value) {
    document.getElementById('income-amount').value = amt;
  }
  if (!document.getElementById('income-title').value && opt.value) {
    document.getElementById('income-title').value = opt.text.split('(')[0].trim() + ' (' + new Date().toLocaleString('default', { month: 'short' }) + ')';
  }
});
</script>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
