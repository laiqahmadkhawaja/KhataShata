<?php
/**
 * KhataShata - Recurring Income & Expense Schedules
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$error = '';
$db = get_db_connection();

// Handle Add Recurring POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_recurring') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } else {
        $type = trim($_POST['transaction_type'] ?? 'expense');
        $title = trim($_POST['title'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $frequency = trim($_POST['frequency'] ?? 'monthly');
        $start_date = trim($_POST['start_date'] ?? date('Y-m-d'));
        $remind_days = (int)($_POST['reminder_days_before'] ?? 3);

        if (empty($title)) {
            $error = 'Title is required.';
        } elseif ($amount <= 0) {
            $error = 'Amount must be greater than zero.';
        } else {
            $stmt = $db->prepare("
                INSERT INTO recurring_transactions 
                (user_id, transaction_type, title, amount, frequency, start_date, reminder_days_before, is_active)
                VALUES (:uid, :ttype, :title, :amt, :freq, :sdate, :rem, 1)
            ");
            $stmt->execute([
                'uid' => $user['id'],
                'ttype' => $type,
                'title' => $title,
                'amt' => $amount,
                'freq' => $frequency,
                'sdate' => $start_date,
                'rem' => $remind_days
            ]);
            flash_set('success', "Recurring schedule '$title' created!");
            redirect('recurring.php');
        }
    }
}

// Fetch recurring items
$stmt = $db->prepare("SELECT * FROM recurring_transactions WHERE user_id = :uid AND is_deleted = 0 ORDER BY transaction_type ASC, title ASC");
$stmt->execute(['uid' => $user['id']]);
$recurring_list = $stmt->fetchAll();

$page_title = 'Recurring Schedules & Bills';
$active_nav = 'recurring';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 24px; align-items: start;">
  <!-- Create Recurring Form -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Schedule Recurring Transaction</div>
        <div class="card-subtitle">Bills, subscriptions, monthly rent or salaries</div>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_recurring">

      <div class="form-group">
        <label class="form-label">Type <span class="req">*</span></label>
        <select name="transaction_type" class="form-control" required>
          <option value="expense" selected>Recurring Expense (Bills, Rent, Subscriptions)</option>
          <option value="income">Recurring Income (Salary, Retainers)</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Title / Item <span class="req">*</span></label>
        <input type="text" name="title" class="form-control" placeholder="e.g. Office Electricity Bill, Adobe Cloud, House Rent" required autofocus>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Expected Amount <span class="req">*</span></label>
          <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required>
        </div>

        <div class="form-group">
          <label class="form-label">Frequency</label>
          <select name="frequency" class="form-control">
            <option value="monthly" selected>Monthly</option>
            <option value="weekly">Weekly</option>
            <option value="quarterly">Quarterly</option>
            <option value="yearly">Yearly</option>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Cycle Start Date <span class="req">*</span></label>
          <input type="date" name="start_date" class="form-control" required value="<?= date('Y-m-d') ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Reminder (Days Before)</label>
          <input type="number" name="reminder_days_before" class="form-control" value="3" min="1" max="30">
        </div>
      </div>

      <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top: 10px;">
        Save Recurring Schedule
      </button>
    </form>
  </div>

  <!-- Recurring List -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Active Recurring Schedules</div>
        <div class="card-subtitle">Scheduled bills and expected recurring cash flow</div>
      </div>
    </div>

    <?php if (empty($recurring_list)): ?>
      <div class="empty-state" style="padding: 24px;">
        <div class="empty-state-text">No recurring schedules added yet. Schedule monthly rent, electricity bills, or software subscriptions.</div>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table mobile-cards">
          <thead>
            <tr>
              <th>Title</th>
              <th>Type</th>
              <th>Frequency</th>
              <th style="text-align: right;">Amount</th>
              <th style="text-align: right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recurring_list as $rec): ?>
              <tr>
                <td data-label="Title">
                  <strong><?= htmlspecialchars($rec['title']) ?></strong>
                  <div style="font-size: 11px; color: var(--text-muted);">Starts: <?= format_date($rec['start_date']) ?></div>
                </td>
                <td data-label="Type">
                  <span class="badge <?= $rec['transaction_type'] === 'income' ? 'badge-success' : 'badge-danger' ?>">
                    <?= ucfirst($rec['transaction_type']) ?>
                  </span>
                </td>
                <td data-label="Frequency">
                  <?= ucfirst($rec['frequency']) ?>
                </td>
                <td data-label="Amount" style="text-align: right; font-weight: 700;" class="<?= $rec['transaction_type'] === 'income' ? 'money-positive' : 'money-negative' ?>">
                  <?= format_money($rec['amount']) ?>
                </td>
                <td data-label="Action" style="text-align: right;">
                  <?php if ($rec['transaction_type'] === 'income'): ?>
                    <a href="personal-income-create.php?title=<?= urlencode($rec['title']) ?>&amount=<?= $rec['amount'] ?>" class="btn btn-secondary btn-sm">
                      Post Income
                    </a>
                  <?php else: ?>
                    <a href="personal-expense-create.php?title=<?= urlencode($rec['title']) ?>&amount=<?= $rec['amount'] ?>" class="btn btn-secondary btn-sm">
                      Pay Bill
                    </a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
