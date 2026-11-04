<?php
/**
 * KhataShata - Record New Loan (Given or Borrowed)
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
        $loan_type = trim($_POST['loan_type'] ?? 'given');
        $person_name = trim($_POST['person_name'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $loan_date = trim($_POST['loan_date'] ?? date('Y-m-d'));
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $purpose = trim($_POST['purpose'] ?? '');
        $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $description = trim($_POST['description'] ?? '');

        if (empty($person_name)) {
            $error = 'Party / Person name is required.';
        } elseif ($amount <= 0) {
            $error = 'Loan amount must be greater than zero.';
        } else {
            try {
                $db->beginTransaction();

                $stmt = $db->prepare("
                    INSERT INTO loans 
                    (user_id, account_id, loan_type, person_name, contact_number, amount, current_balance, loan_date, due_date, purpose, status, description)
                    VALUES (:uid, :aid, :ltype, :pname, :pnum, :amt, :cur, :ldate, :ddate, :purpose, 'active', :desc)
                ");
                $stmt->execute([
                    'uid' => $user['id'],
                    'aid' => $account_id,
                    'ltype' => $loan_type,
                    'pname' => $person_name,
                    'pnum' => $contact_number,
                    'amt' => $amount,
                    'cur' => $amount,
                    'ldate' => $loan_date,
                    'ddate' => $due_date,
                    'purpose' => $purpose,
                    'desc' => $description
                ]);
                $loan_id = $db->lastInsertId();

                // If account linked:
                // Given = Outflow from account (creates receivable)
                // Borrowed = Inflow to account (creates liability)
                if ($account_id) {
                    $ref = generate_reference('LOAN');
                    if ($loan_type === 'given') {
                        FinancialEngine::recordAccountOutflow($account_id, $amount, 'loan_given', 'loans', $loan_id, $loan_date, $ref, "Loan Given to $person_name: $purpose", $user['id']);
                    } else {
                        FinancialEngine::recordAccountInflow($account_id, $amount, 'loan_received', 'loans', $loan_id, $loan_date, $ref, "Loan Borrowed from $person_name: $purpose", $user['id']);
                    }
                }

                log_audit($user['id'], 'loans', $loan_id, 'create', null, $_POST, "Created $loan_type loan of $amount with $person_name");
                $db->commit();

                flash_set('success', "Loan of " . format_money($amount) . " recorded successfully!");
                redirect('loans.php');
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Error saving loan: " . $e->getMessage();
            }
        }
    }
}

// Accounts
$stmt = $db->prepare("SELECT id, account_name, current_balance FROM accounts WHERE user_id = :uid AND status = 'active' AND is_deleted = 0 ORDER BY account_name ASC");
$stmt->execute(['uid' => $user['id']]);
$accounts = $stmt->fetchAll();

$page_title = 'Record Loan / Debt';
$active_nav = 'loans';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 760px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">Record Loan or Debt</h2>
      <p style="font-size: 13px; color: var(--text-muted);">Track money given (receivable asset) or borrowed (liability payable).</p>
    </div>
    <a href="<?= BASE_URL ?>/loans.php" class="btn btn-secondary btn-sm">Cancel</a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" action="">
      <?= csrf_field() ?>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Loan Classification <span class="req">*</span></label>
          <select name="loan_type" class="form-control" required id="loan-type-select">
            <option value="given" selected>Money Given to Someone (Receivable - Asset)</option>
            <option value="borrowed">Money Borrowed (Payable - Liability)</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Party / Person Name <span class="req">*</span></label>
          <input type="text" name="person_name" class="form-control" placeholder="e.g. Asad Ali, Faisal Qureshi" required value="<?= htmlspecialchars($_POST['person_name'] ?? '') ?>" autofocus>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Contact Number</label>
          <input type="text" name="contact_number" class="form-control" placeholder="+92 300 0000000" value="<?= htmlspecialchars($_POST['contact_number'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Principal Amount <span class="req">*</span></label>
          <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Transaction Date <span class="req">*</span></label>
          <input type="date" name="loan_date" class="form-control" required value="<?= htmlspecialchars($_POST['loan_date'] ?? date('Y-m-d')) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Expected Settlement / Due Date</label>
          <input type="date" name="due_date" class="form-control" value="<?= htmlspecialchars($_POST['due_date'] ?? '') ?>">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Disbursement / Receipt Account</label>
          <select name="account_id" class="form-control">
            <option value="">-- None (Cash direct or unlinked) --</option>
            <?php foreach ($accounts as $acc): ?>
              <option value="<?= $acc['id'] ?>" <?= (isset($_POST['account_id']) && $_POST['account_id'] == $acc['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($acc['account_name']) ?> (Avail: <?= format_money($acc['current_balance']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">If Given: deducts funds. If Borrowed: deposits funds.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Purpose / Agreement Note</label>
          <input type="text" name="purpose" class="form-control" placeholder="e.g. Emergency support, machinery loan" value="<?= htmlspecialchars($_POST['purpose'] ?? '') ?>">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Description / Remarks</label>
        <textarea name="description" rows="2" class="form-control" placeholder="Payment terms or guarantees..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
      </div>

      <div class="alert alert-info" style="font-size: 12.5px;">
        💡 <strong>Accounting Guardrail:</strong> Loans are balance sheet assets and liabilities, never operating income or expenses. Giving a loan does not count as spending, and borrowing does not count as income.
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">
          Record Loan Transaction
        </button>
        <a href="<?= BASE_URL ?>/loans.php" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
