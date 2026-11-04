<?php
/**
 * KhataShata - Loan Ledger & Settlement Tracker
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$loan_id = (int)($_GET['id'] ?? 0);
$error = '';
$db = get_db_connection();

$stmt = $db->prepare("SELECT l.*, a.account_name FROM loans l LEFT JOIN accounts a ON l.account_id = a.id WHERE l.id = :id AND l.user_id = :uid AND l.is_deleted = 0 LIMIT 1");
$stmt->execute(['id' => $loan_id, 'uid' => $user['id']]);
$loan = $stmt->fetch();

if (!$loan) {
    flash_set('danger', 'Loan record not found.');
    redirect('loans.php');
}

// Handle Recovery / Repayment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_installment') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } else {
        $amount = (float)($_POST['amount'] ?? 0);
        $date = trim($_POST['transaction_date'] ?? date('Y-m-d'));
        $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $payment_method = trim($_POST['payment_method'] ?? 'Cash');
        $reference_no = trim($_POST['reference_no'] ?? generate_reference('LREC'));
        $notes = trim($_POST['notes'] ?? '');

        if ($amount <= 0) {
            $error = 'Installment amount must be greater than zero.';
        } elseif ($amount > (float)$loan['current_balance']) {
            $error = 'Installment amount cannot exceed current balance due (' . format_money($loan['current_balance']) . ').';
        } else {
            $trx_type = $loan['loan_type'] === 'given' ? 'recovery' : 'repayment';

            try {
                $db->beginTransaction();

                // 1. Insert into loan_transactions
                $ins = $db->prepare("
                    INSERT INTO loan_transactions 
                    (user_id, loan_id, account_id, transaction_type, amount, transaction_date, payment_method, reference_no, notes)
                    VALUES (:uid, :lid, :aid, :ttype, :amt, :tdate, :pmethod, :ref, :notes)
                ");
                $ins->execute([
                    'uid' => $user['id'],
                    'lid' => $loan_id,
                    'aid' => $account_id,
                    'ttype' => $trx_type,
                    'amt' => $amount,
                    'tdate' => $date,
                    'pmethod' => $payment_method,
                    'ref' => $reference_no,
                    'notes' => $notes
                ]);
                $lt_id = $db->lastInsertId();

                // 2. Reduce loan current_balance
                $new_balance = round((float)$loan['current_balance'] - $amount, 2);
                $new_status = $new_balance <= 0.01 ? 'settled' : 'active';

                $upd_loan = $db->prepare("UPDATE loans SET current_balance = :nb, status = :st, updated_at = NOW() WHERE id = :id");
                $upd_loan->execute(['nb' => $new_balance, 'st' => $new_status, 'id' => $loan_id]);

                // 3. Account balance adjustment:
                // If loan was given: recovery = inflow to account (loan_recovered)
                // If loan was borrowed: repayment = outflow from account (loan_repaid)
                if ($account_id) {
                    if ($loan['loan_type'] === 'given') {
                        FinancialEngine::recordAccountInflow($account_id, $amount, 'loan_recovered', 'loan_transactions', $lt_id, $date, $reference_no, "Loan Recovery from {$loan['person_name']}", $user['id']);
                    } else {
                        FinancialEngine::recordAccountOutflow($account_id, $amount, 'loan_repaid', 'loan_transactions', $lt_id, $date, $reference_no, "Loan Repayment to {$loan['person_name']}", $user['id']);
                    }
                }

                log_audit($user['id'], 'loan_transactions', $lt_id, 'create', null, $_POST, "Recorded $trx_type of $amount for loan #$loan_id");
                $db->commit();

                flash_set('success', "Installment of " . format_money($amount) . " recorded successfully!");
                redirect("loan-detail.php?id=$loan_id");
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Error saving installment: " . $e->getMessage();
            }
        }
    }
}

// Fetch installments history
$lt_stmt = $db->prepare("
    SELECT lt.*, a.account_name 
    FROM loan_transactions lt
    LEFT JOIN accounts a ON lt.account_id = a.id
    WHERE lt.loan_id = :lid AND lt.is_deleted = 0
    ORDER BY lt.transaction_date DESC, lt.id DESC
");
$lt_stmt->execute(['lid' => $loan_id]);
$installments = $lt_stmt->fetchAll();

// Accounts for dropdown
$a_stmt = $db->prepare("SELECT id, account_name, current_balance FROM accounts WHERE user_id = :uid AND status = 'active' AND is_deleted = 0 ORDER BY account_name ASC");
$a_stmt->execute(['uid' => $user['id']]);
$accounts = $a_stmt->fetchAll();

$page_title = 'Loan Ledger: ' . $loan['person_name'];
$active_nav = 'loans';

require_once ROOT_PATH . '/includes/header.php';
?>

<div class="card" style="margin-bottom: 20px;">
  <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
    <div>
      <div style="display: flex; align-items: center; gap: 10px;">
        <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);"><?= htmlspecialchars($loan['person_name']) ?></h2>
        <span class="badge <?= $loan['loan_type'] === 'given' ? 'badge-success' : 'badge-danger' ?>">
          <?= $loan['loan_type'] === 'given' ? 'Given (Receivable)' : 'Borrowed (Payable)' ?>
        </span>
        <span class="badge <?= $loan['status'] === 'active' ? 'badge-warning' : ($loan['status'] === 'settled' ? 'badge-success' : 'badge-danger') ?>">
          <?= ucfirst($loan['status']) ?>
        </span>
      </div>
      <div style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">
        <span>Contact: <?= htmlspecialchars($loan['contact_number'] ?? 'N/A') ?></span> &bull; 
        <span>Issued: <?= format_date($loan['loan_date']) ?></span> &bull; 
        <span>Due: <?= format_date($loan['due_date']) ?></span>
      </div>
    </div>

    <div>
      <a href="<?= BASE_URL ?>/loans.php" class="btn btn-secondary btn-sm">Back to Loans</a>
    </div>
  </div>

  <div class="metrics-grid" style="margin-top: 20px; margin-bottom: 0;">
    <div class="stat-card">
      <span class="stat-label">Original Principal</span>
      <div class="stat-value"><?= format_money($loan['amount']) ?></div>
      <div class="stat-sub">Initial principal recorded</div>
    </div>

    <div class="stat-card">
      <span class="stat-label">Total <?= $loan['loan_type'] === 'given' ? 'Recovered' : 'Repaid' ?></span>
      <div class="stat-value money-positive">
        <?= format_money($loan['amount'] - $loan['current_balance']) ?>
      </div>
      <div class="stat-sub"><?= count($installments) ?> installment payments</div>
    </div>

    <div class="stat-card">
      <span class="stat-label">Outstanding Balance</span>
      <div class="stat-value" style="color: <?= $loan['current_balance'] > 0 ? 'var(--warning-dark)' : 'var(--text-muted)' ?>;">
        <?= format_money($loan['current_balance']) ?>
      </div>
      <div class="stat-sub">Remaining balance due</div>
    </div>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($loan['status'] === 'active'): ?>
  <!-- Record Installment Form (Dedicated inline card, NO MODALS) -->
  <div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
      <div>
        <div class="card-title">Record <?= $loan['loan_type'] === 'given' ? 'Recovery Inflow' : 'Repayment Outflow' ?></div>
        <div class="card-subtitle">Partial or full settlement towards remaining balance</div>
      </div>
    </div>

    <form method="POST" action="">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="record_installment">

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Installment Amount <span class="req">*</span></label>
          <input type="number" step="0.01" min="0.01" max="<?= $loan['current_balance'] ?>" name="amount" class="form-control" placeholder="0.00" value="<?= $loan['current_balance'] ?>" required autofocus>
          <div class="form-text">Max: <?= format_money($loan['current_balance']) ?></div>
        </div>

        <div class="form-group">
          <label class="form-label">Payment Date <span class="req">*</span></label>
          <input type="date" name="transaction_date" class="form-control" required value="<?= date('Y-m-d') ?>">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label"><?= $loan['loan_type'] === 'given' ? 'Deposit Into Account' : 'Paid Through Account' ?></label>
          <select name="account_id" class="form-control">
            <option value="">-- None (Cash direct or unlinked) --</option>
            <?php foreach ($accounts as $acc): ?>
              <option value="<?= $acc['id'] ?>">
                <?= htmlspecialchars($acc['account_name']) ?> (Current: <?= format_money($acc['current_balance']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Payment Method</label>
          <select name="payment_method" class="form-control">
            <option value="Cash">Cash in Hand</option>
            <option value="Bank Transfer">Bank Transfer (IBFT)</option>
            <option value="JazzCash">JazzCash</option>
            <option value="Easypaisa">Easypaisa</option>
            <option value="Cheque">Cheque</option>
            <option value="Other">Other</option>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Reference Number</label>
          <input type="text" name="reference_no" class="form-control" placeholder="e.g. REC-5421">
        </div>

        <div class="form-group">
          <label class="form-label">Notes / Remarks</label>
          <input type="text" name="notes" class="form-control" placeholder="Installment details...">
        </div>
      </div>

      <button type="submit" class="btn btn-primary btn-lg">
        Record <?= $loan['loan_type'] === 'given' ? 'Recovery Settlement' : 'Repayment Settlement' ?>
      </button>
    </form>
  </div>
<?php endif; ?>

<!-- Installment History -->
<div class="card">
  <div class="card-header">
    <div class="card-title">Settlement & Installment Ledger</div>
  </div>

  <?php if (empty($installments)): ?>
    <div class="empty-state" style="padding: 24px;">
      <div class="empty-state-text">No installment settlements recorded yet.</div>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="data-table mobile-cards">
        <thead>
          <tr>
            <th>Date</th>
            <th>Type</th>
            <th>Account</th>
            <th>Reference</th>
            <th>Notes</th>
            <th style="text-align: right;">Amount Settled</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($installments as $inst): ?>
            <tr>
              <td data-label="Date"><?= format_date($inst['transaction_date']) ?></td>
              <td data-label="Type">
                <span class="badge badge-success"><?= ucfirst($inst['transaction_type']) ?></span>
              </td>
              <td data-label="Account"><?= htmlspecialchars($inst['account_name'] ?? 'Direct / Unlinked') ?></td>
              <td data-label="Reference"><?= htmlspecialchars($inst['reference_no'] ?? '-') ?></td>
              <td data-label="Notes"><?= htmlspecialchars($inst['notes'] ?? '-') ?></td>
              <td data-label="Amount" style="text-align: right; font-weight: 700;" class="money-positive">
                <?= format_money($inst['amount']) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
