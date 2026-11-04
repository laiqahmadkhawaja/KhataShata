<?php
/**
 * KhataShata - Create New Business
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$error = '';
$db = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $description = trim($_POST['description'] ?? '');
        $start_date = trim($_POST['start_date'] ?? date('Y-m-d'));
        $ownership_type = trim($_POST['ownership_type'] ?? 'Partnership');
        $collection_model = trim($_POST['collection_model'] ?? 'direct');
        $status = trim($_POST['status'] ?? 'Active');
        $notes = trim($_POST['notes'] ?? '');
        $initial_owner_pct = (float)($_POST['owner_percentage'] ?? 100.0);

        if (empty($name)) {
            $error = 'Business name is required.';
        } elseif (empty($start_date)) {
            $error = 'Start date is required.';
        } elseif ($initial_owner_pct < 0 || $initial_owner_pct > 100) {
            $error = 'Owner ownership percentage must be between 0 and 100.';
        } else {
            // Handle logo upload
            $logo_path = null;
            if (!empty($_FILES['logo']['name'])) {
                $logo_path = handle_file_upload($_FILES['logo'], 'businesses');
            }

            try {
                $db->beginTransaction();

                $stmt = $db->prepare("
                    INSERT INTO businesses 
                    (user_id, category_id, name, description, logo, start_date, ownership_type, collection_model, status, notes)
                    VALUES (:uid, :cat, :name, :desc, :logo, :sdate, :otype, :cmodel, :status, :notes)
                ");
                $stmt->execute([
                    'uid' => $user['id'],
                    'cat' => $category_id,
                    'name' => $name,
                    'desc' => $description,
                    'logo' => $logo_path,
                    'sdate' => $start_date,
                    'otype' => $ownership_type,
                    'cmodel' => $collection_model,
                    'status' => $status,
                    'notes' => $notes
                ]);
                $business_id = $db->lastInsertId();

                // Initialize Owner Ownership Record
                $own_stmt = $db->prepare("
                    INSERT INTO business_ownership (business_id, partner_id, percentage, effective_date, notes)
                    VALUES (:bid, NULL, :pct, :eff, 'Initial business setup')
                ");
                $own_stmt->execute([
                    'bid' => $business_id,
                    'pct' => $initial_owner_pct,
                    'eff' => $start_date
                ]);

                log_audit($user['id'], 'businesses', $business_id, 'create', null, ['name' => $name, 'category_id' => $category_id], "Created business $name");
                $db->commit();

                flash_set('success', "Business '$name' created successfully! You can now add partners or record income.");
                redirect("business-detail.php?id=$business_id&tab=partners");
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to create business: " . $e->getMessage();
            }
        }
    }
}

// Fetch categories
$stmt = $db->prepare("SELECT id, name FROM business_categories WHERE user_id = :uid AND is_active = 1 ORDER BY name ASC");
$stmt->execute(['uid' => $user['id']]);
$categories = $stmt->fetchAll();

$page_title = 'Register New Business';
$active_nav = 'businesses';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 800px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">Create Business Profile</h2>
      <p style="font-size: 13px; color: var(--text-muted);">Define business details, starting date, and ownership structure.</p>
    </div>
    <a href="<?= BASE_URL ?>/businesses.php" class="btn btn-secondary btn-sm">Cancel</a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" action="" enctype="multipart/form-data">
      <?= csrf_field() ?>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Business Name <span class="req">*</span></label>
          <input type="text" name="name" class="form-control" placeholder="e.g. Acme Digital Solutions" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" autofocus>
        </div>

        <div class="form-group">
          <label class="form-label">Business Category <span class="req">*</span></label>
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

      <div class="form-group" style="margin-bottom: 16px;">
          <label class="form-label">Collection & Revenue Model <span class="req">*</span></label>
          <select name="collection_model" class="form-control" required>
            <option value="direct">🏢 Direct Collection (آپ خود 100% رقم وصول کرتے ہیں - 60/40 تقسیم)</option>
            <option value="external">🤝 External Partnership (پارٹنر یا بزنس رقم وصول کرتا ہے - آپ کو صرف آپ کا منافع ملتا ہے)</option>
          </select>
          <div class="form-text">Direct Collection: Client payments arrive directly in your account (e.g. SaaS). External: Partner collects, you receive profit distributions.</div>
        </div>

        <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Business Start Date <span class="req">*</span></label>
          <input type="date" name="start_date" class="form-control" required value="<?= htmlspecialchars($_POST['start_date'] ?? date('Y-m-d')) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Ownership Type <span class="req">*</span></label>
          <select name="ownership_type" class="form-control" required>
            <option value="Partnership" selected>Partnership</option>
            <option value="Sole Proprietorship">Sole Proprietorship (100% You)</option>
            <option value="Joint Venture">Joint Venture</option>
            <option value="Private Limited / LLC">Private Limited / LLC</option>
            <option value="Other">Other</option>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Your Initial Ownership Percentage (%) <span class="req">*</span></label>
          <input type="number" step="0.01" min="0" max="100" name="owner_percentage" class="form-control" value="<?= htmlspecialchars($_POST['owner_percentage'] ?? '100.00') ?>" required>
          <div class="form-text">You can add partners and configure individual share allocations anytime.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Status <span class="req">*</span></label>
          <select name="status" class="form-control" required>
            <option value="Active" selected>Active</option>
            <option value="Inactive">Inactive</option>
            <option value="Closed">Closed</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Business Description</label>
        <textarea name="description" rows="3" class="form-control" placeholder="Brief outline of business scope, offerings, or clients..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Business Logo / Branding Image (Optional)</label>
        <input type="file" name="logo" class="form-control" accept="image/*">
      </div>

      <div class="form-group">
        <label class="form-label">Internal Notes / Registration Details</label>
        <textarea name="notes" rows="2" class="form-control" placeholder="Tax numbers, contract notes, or internal remarks..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">
          Create & Proceed to Partner Configuration
        </button>
        <a href="<?= BASE_URL ?>/businesses.php" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
