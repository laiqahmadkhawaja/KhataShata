<?php
/**
 * KhataShata - Record Business Income
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$error = '';
$db = get_db_connection();

$prefill_business = (int)($_GET['business_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } else {
        $business_id = (int)($_POST['business_id'] ?? 0);
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $title = trim($_POST['title'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $transaction_date = trim($_POST['transaction_date'] ?? date('Y-m-d'));
        $financial_month = trim($_POST['financial_month'] ?? date('Y-m', strtotime($transaction_date)));
        $received_date = !empty($_POST['received_date']) ? $_POST['received_date'] : $transaction_date;
        $payment_method = trim($_POST['payment_method'] ?? 'Bank Transfer');
        $reference_no = trim($_POST['reference_no'] ?? generate_reference('REV'));
        $description = trim($_POST['description'] ?? '');

        if ($business_id <= 0) {
            $error = 'Please select a business.';
        } elseif (empty($title)) {
            $error = 'Income title is required.';
        } elseif ($amount <= 0) {
            $error = 'Amount must be greater than zero.';
        } else {
            // Check if month is already locked
            $check_stmt = $db->prepare("SELECT status FROM monthly_closings WHERE business_id = :bid AND financial_month = :month LIMIT 1");
            $check_stmt->execute(['bid' => $business_id, 'month' => $financial_month]);
            $closing_status = $check_stmt->fetchColumn();

            if ($closing_status === 'finalized') {
                $error = "Financial month $financial_month is finalized and locked. Please reopen this month before adding new income.";
            } else {
                $attachment = null;
                if (!empty($_FILES['attachment']['name'])) {
                    $attachment = handle_file_upload($_FILES['attachment'], 'receipts');
                }

                try {
                    $db->beginTransaction();

                    $stmt = $db->prepare("
                        INSERT INTO business_income 
                        (user_id, business_id, category_id, account_id, title, amount, transaction_date, financial_month, received_date, payment_method, reference_no, description, attachment)
                        VALUES (:uid, :bid, :cat, :aid, :title, :amt, :tdate, :month, :rdate, :pmethod, :ref, :desc, :att)
                    ");
                    $stmt->execute([
                        'uid' => $user['id'],
                        'bid' => $business_id,
                        'cat' => $category_id,
                        'aid' => $account_id,
                        'title' => $title,
                        'amt' => $amount,
                        'tdate' => $transaction_date,
                        'month' => $financial_month,
                        'rdate' => $received_date,
                        'pmethod' => $payment_method,
                        'ref' => $reference_no,
                        'desc' => $description,
                        'att' => $attachment
                    ]);
                    $income_id = $db->lastInsertId();

                    // If account was linked, deposit into account & log account transaction
                    if ($account_id) {
                        FinancialEngine::recordAccountInflow($account_id, $amount, 'business_income', 'business_income', $income_id, $transaction_date, $reference_no, "Business Revenue: $title", $user['id']);
                    }

                    log_audit($user['id'], 'business_income', $income_id, 'create', null, $_POST, "Recorded business income of $amount");
                    $db->commit();

                    flash_set('success', "Business revenue of " . format_money($amount) . " recorded successfully!");
                    redirect("business-detail.php?id=$business_id&tab=income");
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = "Error saving income: " . $e->getMessage();
                }
            }
        }
    }
}

// Businesses
$stmt = $db->prepare("SELECT id, name FROM businesses WHERE user_id = :uid AND is_deleted = 0 ORDER BY name ASC");
$stmt->execute(['uid' => $user['id']]);
$businesses = $stmt->fetchAll();

// Categories
$stmt = $db->prepare("SELECT id, name FROM income_categories WHERE user_id = :uid AND is_active = 1 AND type IN ('business','both') ORDER BY name ASC");
$stmt->execute(['uid' => $user['id']]);
$categories = $stmt->fetchAll();

// Accounts
$stmt = $db->prepare("SELECT id, account_name, current_balance FROM accounts WHERE user_id = :uid AND status = 'active' AND is_deleted = 0 ORDER BY account_name ASC");
$stmt->execute(['uid' => $user['id']]);
$accounts = $stmt->fetchAll();

$page_title = 'Record Business Revenue';
$active_nav = 'business_income';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 760px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">Record Business Revenue</h2>
      <p style="font-size: 13px; color: var(--text-muted);">Log customer payments, contracts, sales, and client retainers.</p>
    </div>
    <a href="<?= BASE_URL ?>/business-income.php" class="btn btn-secondary btn-sm">Cancel</a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" action="" enctype="multipart/form-data">
      <?= csrf_field() ?>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Business <span class="req">*</span></label>
          <select name="business_id" class="form-control" required>
            <option value="">-- Select Business --</option>
            <?php foreach ($businesses as $b): ?>
              <option value="<?= $b['id'] ?>" <?= ($prefill_business == $b['id'] || (isset($_POST['business_id']) && $_POST['business_id'] == $b['id'])) ? 'selected' : '' ?>>
                <?= htmlspecialchars($b['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Revenue Category <span class="req">*</span></label>
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
          <label class="form-label">Income Title / Item <span class="req">*</span></label>
          <input type="text" name="title" class="form-control" placeholder="e.g. Website design milestone 1, Monthly retainer" required value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" autofocus>
        </div>

        <div class="form-group">
          <label class="form-label">Amount (Revenue) <span class="req">*</span></label>
          <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Transaction Date <span class="req">*</span></label>
          <input type="date" name="transaction_date" id="trx_date" class="form-control" required value="<?= htmlspecialchars($_POST['transaction_date'] ?? date('Y-m-d')) ?>" onchange="updateMonthFromDate(this.value)">
        </div>

        <div class="form-group">
          <label class="form-label">Financial Month (YYYY-MM) <span class="req">*</span></label>
          <input type="month" name="financial_month" id="fin_month" class="form-control" required value="<?= htmlspecialchars($_POST['financial_month'] ?? date('Y-m')) ?>">
          <div class="form-text">The financial accounting period this revenue belongs to.</div>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Deposit Into Account (Optional)</label>
          <select name="account_id" class="form-control">
            <option value="">-- None (Cash direct or unlinked) --</option>
            <?php foreach ($accounts as $acc): ?>
              <option value="<?= $acc['id'] ?>" <?= (isset($_POST['account_id']) && $_POST['account_id'] == $acc['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($acc['account_name']) ?> (Current: <?= format_money($acc['current_balance']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Payment Method</label>
          <select name="payment_method" class="form-control">
            <option value="Bank Transfer">Bank Transfer (IBFT)</option>
            <option value="Cash">Cash in Hand</option>
            <option value="Cheque">Cheque</option>
            <option value="JazzCash">JazzCash</option>
            <option value="Easypaisa">Easypaisa</option>
            <option value="Credit Card">Credit Card / POS</option>
            <option value="Other">Other</option>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Invoice / Reference Number</label>
          <input type="text" name="reference_no" class="form-control" placeholder="e.g. INV-2026-001" value="<?= htmlspecialchars($_POST['reference_no'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Invoice / Receipt Attachment</label>
          <input type="file" name="attachment" class="form-control" accept="image/*,application/pdf">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Description / Scope Details</label>
        <textarea name="description" rows="2" class="form-control" placeholder="Details about this revenue transaction..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-success btn-lg" style="flex: 1;">
          Save Business Revenue
        </button>
        <a href="<?= BASE_URL ?>/business-income.php" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script>
function updateMonthFromDate(val) {
  if (val && val.length >= 7) {
    document.getElementById('fin_month').value = val.substring(0, 7);
  }
}
</script>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
