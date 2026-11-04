<?php
/**
 * KhataShata - Record Personal Expense
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
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $title = trim($_POST['title'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $expense_date = trim($_POST['expense_date'] ?? date('Y-m-d'));
        $payment_method = trim($_POST['payment_method'] ?? 'Cash');
        $reference_no = trim($_POST['reference_no'] ?? generate_reference('PEXP'));
        $notes = trim($_POST['notes'] ?? '');

        if (empty($title)) {
            $error = 'Expense title is required.';
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
                    INSERT INTO personal_expenses 
                    (user_id, category_id, account_id, title, amount, expense_date, payment_date, payment_method, reference_no, notes, attachment)
                    VALUES (:uid, :cat, :aid, :title, :amt, :edate, :pdate, :pmethod, :ref, :notes, :att)
                ");
                $stmt->execute([
                    'uid' => $user['id'],
                    'cat' => $category_id,
                    'aid' => $account_id,
                    'title' => $title,
                    'amt' => $amount,
                    'edate' => $expense_date,
                    'pdate' => $expense_date,
                    'pmethod' => $payment_method,
                    'ref' => $reference_no,
                    'notes' => $notes,
                    'att' => $attachment
                ]);
                $expense_id = $db->lastInsertId();

                // If account was linked, record outflow
                if ($account_id) {
                    FinancialEngine::recordAccountOutflow($account_id, $amount, 'personal_expense', 'personal_expenses', $expense_id, $expense_date, $reference_no, "Personal Expense: $title", $user['id']);
                }

                log_audit($user['id'], 'personal_expenses', $expense_id, 'create', null, $_POST, "Recorded personal expense of $amount");
                $db->commit();

                flash_set('success', "Personal expense of " . format_money($amount) . " recorded successfully!");
                redirect('personal-expenses.php');
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Error saving expense: " . $e->getMessage();
            }
        }
    }
}

// Categories
$stmt = $db->prepare("SELECT id, name FROM expense_categories WHERE user_id = :uid AND is_active = 1 AND type IN ('personal','both') ORDER BY name ASC");
$stmt->execute(['uid' => $user['id']]);
$categories = $stmt->fetchAll();

// Accounts
$stmt = $db->prepare("SELECT id, account_name, current_balance FROM accounts WHERE user_id = :uid AND status = 'active' AND is_deleted = 0 ORDER BY account_name ASC");
$stmt->execute(['uid' => $user['id']]);
$accounts = $stmt->fetchAll();

$page_title = 'Record Personal Expense';
$active_nav = 'personal_expenses';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 760px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">Record Personal Expense</h2>
      <p style="font-size: 13px; color: var(--text-muted);">Track everyday personal spending, groceries, fuel, household, and bills.</p>
    </div>
    <a href="<?= BASE_URL ?>/personal-expenses.php" class="btn btn-secondary btn-sm">Cancel</a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" action="" enctype="multipart/form-data">
      <?= csrf_field() ?>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Expense Title / Item <span class="req">*</span></label>
          <input type="text" name="title" class="form-control" placeholder="e.g. Grocery at Metro, Electricity Bill, Petrol" required value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" autofocus>
        </div>

        <div class="form-group">
          <label class="form-label">Category <span class="req">*</span></label>
          <select name="category_id" class="form-control" required>
            <option value="">-- Select Category --</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat['id'] ?>" <?= (isset($_POST['category_id']) && $_POST['category_id'] == $cat['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Amount Spent <span class="req">*</span></label>
          <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Expense Date <span class="req">*</span></label>
          <input type="date" name="expense_date" class="form-control" required value="<?= htmlspecialchars($_POST['expense_date'] ?? date('Y-m-d')) ?>">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Paid Through Account (Outflow)</label>
          <select name="account_id" class="form-control">
            <option value="">-- None (Direct cash / unlinked) --</option>
            <?php foreach ($accounts as $acc): ?>
              <option value="<?= $acc['id'] ?>" <?= (isset($_POST['account_id']) && $_POST['account_id'] == $acc['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($acc['account_name']) ?> (Current: <?= format_money($acc['current_balance']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Will reduce the selected account balance.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Payment Method</label>
          <select name="payment_method" class="form-control">
            <option value="Cash">Cash in Hand</option>
            <option value="Debit Card">Debit / ATM Card</option>
            <option value="Credit Card">Credit Card</option>
            <option value="JazzCash">JazzCash</option>
            <option value="Easypaisa">Easypaisa</option>
            <option value="Bank Transfer">Bank Transfer (IBFT)</option>
            <option value="Other">Other</option>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Receipt / Slip Reference Number</label>
          <input type="text" name="reference_no" class="form-control" placeholder="e.g. REC-10291" value="<?= htmlspecialchars($_POST['reference_no'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Receipt / Bill Attachment</label>
          <input type="file" name="attachment" class="form-control" accept="image/*,application/pdf">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Notes & Remarks</label>
        <textarea name="notes" rows="2" class="form-control" placeholder="Additional details or notes..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-danger btn-lg" style="flex: 1;">
          Save Personal Expense
        </button>
        <a href="<?= BASE_URL ?>/personal-expenses.php" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
