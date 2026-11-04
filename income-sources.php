<?php
/**
 * KhataShata - Personal Income Sources Management
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$error = '';
$db = get_db_connection();

// Handle Add/Edit Income Source POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_source') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $income_type = trim($_POST['income_type'] ?? 'salary');
        $expected_amount = (float)($_POST['expected_amount'] ?? 0);
        $frequency = trim($_POST['frequency'] ?? 'monthly');
        $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
        $description = trim($_POST['description'] ?? '');
        $status = trim($_POST['status'] ?? 'active');

        if (empty($name)) {
            $error = 'Source name is required.';
        } else {
            $stmt = $db->prepare("
                INSERT INTO personal_income_sources 
                (user_id, name, income_type, expected_amount, frequency, is_recurring, description, status)
                VALUES (:uid, :name, :itype, :amt, :freq, :rec, :desc, :status)
            ");
            $stmt->execute([
                'uid' => $user['id'],
                'name' => $name,
                'itype' => $income_type,
                'amt' => $expected_amount,
                'freq' => $frequency,
                'rec' => $is_recurring,
                'desc' => $description,
                'status' => $status
            ]);
            flash_set('success', "Income source '$name' registered successfully!");
            redirect('income-sources.php');
        }
    }
}

// Fetch sources
$stmt = $db->prepare("SELECT * FROM personal_income_sources WHERE user_id = :uid AND is_deleted = 0 ORDER BY status ASC, name ASC");
$stmt->execute(['uid' => $user['id']]);
$sources = $stmt->fetchAll();

$page_title = 'Personal Income Sources';
$active_nav = 'personal_income';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 24px; align-items: start;">
  <!-- Form to Register Source -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Add Income Stream</div>
        <div class="card-subtitle">Define recurring salaries or variable earnings</div>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_source">

      <div class="form-group">
        <label class="form-label">Source Name <span class="req">*</span></label>
        <input type="text" name="name" class="form-control" placeholder="e.g. Primary Job Salary, Upwork Clients, Rental Flat 4B" required autofocus>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Income Type</label>
          <select name="income_type" class="form-control">
            <option value="salary">Regular Salary</option>
            <option value="freelancing">Freelancing / Gigs</option>
            <option value="consultancy">Consultancy</option>
            <option value="rental">Rental Income</option>
            <option value="dividend">Dividend / Returns</option>
            <option value="other">Other</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Expected Amount</label>
          <input type="number" step="0.01" min="0" name="expected_amount" class="form-control" placeholder="0.00">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Frequency</label>
          <select name="frequency" class="form-control">
            <option value="monthly" selected>Monthly</option>
            <option value="weekly">Weekly</option>
            <option value="yearly">Yearly</option>
            <option value="one_time">One-Time</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" class="form-control">
            <option value="active" selected>Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
          <input type="checkbox" name="is_recurring" value="1" checked>
          <span>Recurring Regular Stream</span>
        </label>
      </div>

      <div class="form-group">
        <label class="form-label">Description / Remarks</label>
        <textarea name="description" rows="2" class="form-control" placeholder="Employer name, contract details, notes..."></textarea>
      </div>

      <button type="submit" class="btn btn-primary btn-block">
        Save Income Stream
      </button>
    </form>
  </div>

  <!-- List of Configured Sources -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Registered Income Streams</div>
        <div class="card-subtitle">Active and historical personal earning sources</div>
      </div>
      <a href="<?= BASE_URL ?>/personal-income.php" class="btn btn-secondary btn-sm">Income Entries</a>
    </div>

    <?php if (empty($sources)): ?>
      <div class="empty-state" style="padding: 24px;">
        <div class="empty-state-text">No personal income streams defined yet. Use the form to add your salary or freelance sources.</div>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table mobile-cards">
          <thead>
            <tr>
              <th>Stream Name</th>
              <th>Type</th>
              <th>Expected</th>
              <th>Freq</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sources as $s): ?>
              <tr>
                <td data-label="Name">
                  <strong><?= htmlspecialchars($s['name']) ?></strong>
                  <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($s['description'] ?? '') ?></div>
                </td>
                <td data-label="Type">
                  <span class="badge badge-primary"><?= ucfirst($s['income_type']) ?></span>
                </td>
                <td data-label="Expected" class="money-positive">
                  <?= format_money($s['expected_amount']) ?>
                </td>
                <td data-label="Frequency">
                  <?= ucfirst($s['frequency']) ?>
                </td>
                <td data-label="Status">
                  <span class="badge <?= $s['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>">
                    <?= ucfirst($s['status']) ?>
                  </span>
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
