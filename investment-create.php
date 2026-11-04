<?php
/**
 * KhataShata - Record New Investment
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
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? 'stocks');
        $investment_date = trim($_POST['investment_date'] ?? date('Y-m-d'));
        $invested_amount = (float)($_POST['invested_amount'] ?? 0);
        $current_value = (float)($_POST['current_value'] ?? $invested_amount);
        $expected_return = (float)($_POST['expected_return'] ?? 0);
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            $error = 'Investment name is required.';
        } elseif ($invested_amount <= 0) {
            $error = 'Invested amount must be greater than zero.';
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO investments 
                    (user_id, name, category, investment_date, invested_amount, current_value, expected_return, description, status)
                    VALUES (:uid, :name, :cat, :idate, :iamt, :cval, :eret, :desc, 'active')
                ");
                $stmt->execute([
                    'uid' => $user['id'],
                    'name' => $name,
                    'cat' => $category,
                    'idate' => $investment_date,
                    'iamt' => $invested_amount,
                    'cval' => $current_value,
                    'eret' => $expected_return,
                    'desc' => $description
                ]);
                $inv_id = $db->lastInsertId();

                log_audit($user['id'], 'investments', $inv_id, 'create', null, $_POST, "Created investment $name");
                flash_set('success', "Investment '$name' recorded successfully!");
                redirect('investments.php');
            } catch (Exception $e) {
                $error = "Error saving investment: " . $e->getMessage();
            }
        }
    }
}

$page_title = 'Add Investment';
$active_nav = 'investments';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 700px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">Record Investment</h2>
      <p style="font-size: 13px; color: var(--text-muted);">Track capital invested in stocks, gold, property, crypto, or mutual funds.</p>
    </div>
    <a href="<?= BASE_URL ?>/investments.php" class="btn btn-secondary btn-sm">Cancel</a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" action="">
      <?= csrf_field() ?>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Investment Name <span class="req">*</span></label>
          <input type="text" name="name" class="form-control" placeholder="e.g. Meezan Islamic Fund, PSX OGDC Shares, Gold 24K" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" autofocus>
        </div>

        <div class="form-group">
          <label class="form-label">Category</label>
          <select name="category" class="form-control">
            <option value="stocks">Stocks / Equities (PSX)</option>
            <option value="mutual_funds">Mutual Funds</option>
            <option value="gold">Gold / Precious Metals</option>
            <option value="property">Real Estate / Land Plot</option>
            <option value="business_investment">Business Investment</option>
            <option value="crypto">Cryptocurrency</option>
            <option value="other">Other</option>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Invested Capital Amount <span class="req">*</span></label>
          <input type="number" step="0.01" min="0.01" name="invested_amount" class="form-control" placeholder="0.00" required value="<?= htmlspecialchars($_POST['invested_amount'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Current Market Value <span class="req">*</span></label>
          <input type="number" step="0.01" min="0" name="current_value" class="form-control" placeholder="0.00" required value="<?= htmlspecialchars($_POST['current_value'] ?? '') ?>">
          <div class="form-text">You can update this anytime as market prices fluctuate.</div>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Investment Date</label>
          <input type="date" name="investment_date" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Expected Annual Return (%)</label>
          <input type="number" step="0.01" name="expected_return" class="form-control" placeholder="12.5" value="<?= htmlspecialchars($_POST['expected_return'] ?? '') ?>">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Description / Portfolio Notes</label>
        <textarea name="description" rows="2" class="form-control" placeholder="Broker account, folio number, details..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">Save Investment</button>
        <a href="<?= BASE_URL ?>/investments.php" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
