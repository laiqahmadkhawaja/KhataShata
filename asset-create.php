<?php
/**
 * KhataShata - Record New Physical Asset
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
        $category = trim($_POST['category'] ?? 'electronics');
        $purchase_date = trim($_POST['purchase_date'] ?? date('Y-m-d'));
        $purchase_value = (float)($_POST['purchase_value'] ?? 0);
        $current_value = (float)($_POST['current_estimated_value'] ?? $purchase_value);
        $notes = trim($_POST['notes'] ?? '');

        if (empty($name)) {
            $error = 'Asset name is required.';
        } elseif ($purchase_value < 0) {
            $error = 'Purchase value cannot be negative.';
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO assets (user_id, name, category, purchase_date, purchase_value, current_estimated_value, notes)
                    VALUES (:uid, :name, :cat, :pdate, :pval, :cval, :notes)
                ");
                $stmt->execute([
                    'uid' => $user['id'],
                    'name' => $name,
                    'cat' => $category,
                    'pdate' => $purchase_date,
                    'pval' => $purchase_value,
                    'cval' => $current_value,
                    'notes' => $notes
                ]);
                $asset_id = $db->lastInsertId();

                log_audit($user['id'], 'assets', $asset_id, 'create', null, $_POST, "Created physical asset $name");
                flash_set('success', "Asset '$name' saved successfully!");
                redirect('investments.php');
            } catch (Exception $e) {
                $error = "Error saving asset: " . $e->getMessage();
            }
        }
    }
}

$page_title = 'Add Physical Asset';
$active_nav = 'investments';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 700px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">Add Physical Asset</h2>
      <p style="font-size: 13px; color: var(--text-muted);">Record vehicles, real estate, electronics, machinery, and valuables.</p>
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
          <label class="form-label">Asset Title / Name <span class="req">*</span></label>
          <input type="text" name="name" class="form-control" placeholder="e.g. Honda Civic 2022, MacBook Pro M3, Office Furniture" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" autofocus>
        </div>

        <div class="form-group">
          <label class="form-label">Asset Category</label>
          <select name="category" class="form-control">
            <option value="vehicle">Vehicle (Car, Motorcycle)</option>
            <option value="property">Real Estate / House / Plot</option>
            <option value="electronics">Electronics / Laptops / Hardware</option>
            <option value="equipment">Machinery & Equipment</option>
            <option value="furniture">Furniture & Fixtures</option>
            <option value="jewelry">Jewelry & Gold Items</option>
            <option value="other">Other Asset</option>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Purchase Date</label>
          <input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Purchase Price / Cost</label>
          <input type="number" step="0.01" min="0" name="purchase_value" class="form-control" placeholder="0.00" value="<?= htmlspecialchars($_POST['purchase_value'] ?? '') ?>">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Current Estimated Market Value <span class="req">*</span></label>
        <input type="number" step="0.01" min="0" name="current_estimated_value" class="form-control" placeholder="0.00" required value="<?= htmlspecialchars($_POST['current_estimated_value'] ?? '') ?>">
        <div class="form-text">Used for calculating your consolidated balance sheet net worth.</div>
      </div>

      <div class="form-group">
        <label class="form-label">Notes & Serial Numbers</label>
        <textarea name="notes" rows="2" class="form-control" placeholder="Model, registration number, warranty info..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">Save Physical Asset</button>
        <a href="<?= BASE_URL ?>/investments.php" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
