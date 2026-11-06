<?php
/**
 * KhataShata - Edit Business
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$business_id = (int)($_GET['id'] ?? 0);
$db = get_db_connection();

$stmt = $db->prepare("SELECT * FROM businesses WHERE id = :id AND user_id = :uid AND is_deleted = 0 LIMIT 1");
$stmt->execute(['id' => $business_id, 'uid' => $user['id']]);
$business = $stmt->fetch();

if (!$business) {
    flash_set('danger', 'Business not found.');
    redirect('businesses.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        // Soft delete business
        $del = $db->prepare("UPDATE businesses SET is_deleted = 1, updated_at = NOW() WHERE id = :id AND user_id = :uid");
        $del->execute(['id' => $business_id, 'uid' => $user['id']]);
        log_audit($user['id'], 'businesses', $business_id, 'soft_delete', $business, ['is_deleted' => 1], 'Soft deleted business');
        flash_set('warning', "Business '{$business['name']}' has been moved to trash.");
        redirect('businesses.php');
    } else {
        $name = trim($_POST['name'] ?? '');
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $description = trim($_POST['description'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $ownership_type = trim($_POST['ownership_type'] ?? 'Partnership');
        $collection_model = in_array(trim($_POST['collection_model'] ?? 'direct'), ['direct', 'external', 'job']) ? trim($_POST['collection_model']) : 'direct';
        $status = trim($_POST['status'] ?? 'Active');
        $notes = trim($_POST['notes'] ?? '');

        if (empty($name)) {
            $error = 'Business name cannot be empty.';
        } else {
            $logo_path = $business['logo'];
            if (!empty($_FILES['logo']['name'])) {
                $uploaded = handle_file_upload($_FILES['logo'], 'businesses');
                if ($uploaded) {
                    $logo_path = $uploaded;
                }
            }

            $upd = $db->prepare("
                UPDATE businesses 
                SET name = :name, category_id = :cat, description = :desc, logo = :logo, 
                    start_date = :sdate, ownership_type = :otype, collection_model = :cmodel, status = :status, notes = :notes, updated_at = NOW()
                WHERE id = :id AND user_id = :uid
            ");
            $upd->execute([
                'name' => $name,
                'cat' => $category_id,
                'desc' => $description,
                'logo' => $logo_path,
                'sdate' => $start_date,
                'otype' => $ownership_type,
                'cmodel' => $collection_model,
                'status' => $status,
                'notes' => $notes,
                'id' => $business_id,
                'uid' => $user['id']
            ]);

            log_audit($user['id'], 'businesses', $business_id, 'update', $business, $_POST, "Updated business details");
            flash_set('success', 'Business details updated successfully.');
            redirect("business-detail.php?id=$business_id");
        }
    }
}

// Categories
$stmt = $db->prepare("SELECT id, name FROM business_categories WHERE user_id = :uid AND is_active = 1 ORDER BY name ASC");
$stmt->execute(['uid' => $user['id']]);
$categories = $stmt->fetchAll();

$page_title = 'Edit Business: ' . $business['name'];
$active_nav = 'businesses';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 800px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">Edit: <?= htmlspecialchars($business['name']) ?></h2>
      <p style="font-size: 13px; color: var(--text-muted);">Update business profile, operational status, or categories.</p>
    </div>
    <div style="display:flex; gap:8px;">
      <a href="<?= BASE_URL ?>/business-detail.php?id=<?= $business['id'] ?>" class="btn btn-secondary btn-sm">View Business</a>
      <a href="<?= BASE_URL ?>/businesses.php" class="btn btn-secondary btn-sm">Back</a>
    </div>
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
          <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($_POST['name'] ?? $business['name']) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Business Category</label>
          <select name="category_id" class="form-control">
            <option value="">-- Select Category --</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat['id'] ?>" <?= (isset($_POST['category_id']) ? $_POST['category_id'] == $cat['id'] : $business['category_id'] == $cat['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-group" style="margin-bottom: 16px;">
        <label class="form-label">Collection & Revenue Model <span class="req">*</span></label>
        <select name="collection_model" id="collection_model" class="form-control" required>
          <option value="direct" <?= ($business['collection_model'] ?? 'direct') === 'direct' ? 'selected' : '' ?>>🏢 Direct Collection (آپ خود 100% رقم وصول کرتے ہیں، پارٹنر کو اس کا حصہ دیتے ہیں)</option>
          <option value="external" <?= ($business['collection_model'] ?? '') === 'external' ? 'selected' : '' ?>>🤝 External Partnership (پارٹنر یا بیرونی ادارہ رقم وصول کرتا ہے، آپ کو صرف آپ کا شیئر ملتا ہے)</option>
          <option value="job" <?= ($business['collection_model'] ?? '') === 'job' ? 'selected' : '' ?>>💼 Job / Employment Salary (ملازمت / نوکری - کمپنی سے ماہانہ تنخواہ اور الاؤنسز)</option>
        </select>
        <div class="form-text">
          Direct Collection: Client payments arrive directly in your accounts (e.g. SaaS). External: Partner collects, you receive profit draws. Job: Employment salary credited 100% directly to you.
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Business Start Date <span class="req">*</span></label>
          <input type="date" name="start_date" class="form-control" required value="<?= htmlspecialchars($_POST['start_date'] ?? $business['start_date']) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Ownership Type</label>
          <select name="ownership_type" class="form-control">
            <?php foreach (['Partnership', 'Sole Proprietorship', 'Joint Venture', 'Private Limited / LLC', 'Other'] as $ot): ?>
              <option value="<?= $ot ?>" <?= $business['ownership_type'] === $ot ? 'selected' : '' ?>><?= $ot ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Business Status</label>
          <select name="status" class="form-control">
            <option value="Active" <?= $business['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
            <option value="Inactive" <?= $business['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
            <option value="Closed" <?= $business['status'] === 'Closed' ? 'selected' : '' ?>>Closed</option>
          </select>
          <div class="form-text">Closed/Inactive businesses preserve all historical financial records.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Logo / Brand Image</label>
          <input type="file" name="logo" class="form-control" accept="image/*">
          <?php if (!empty($business['logo'])): ?>
            <div style="margin-top: 6px; font-size: 11px; color: var(--text-muted);">
              Current: <a href="<?= BASE_URL ?>/<?= htmlspecialchars($business['logo']) ?>" target="_blank">View existing logo</a>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" rows="3" class="form-control"><?= htmlspecialchars($_POST['description'] ?? $business['description']) ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Internal Notes</label>
        <textarea name="notes" rows="2" class="form-control"><?= htmlspecialchars($_POST['notes'] ?? $business['notes']) ?></textarea>
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">Save Changes</button>
        <a href="<?= BASE_URL ?>/business-detail.php?id=<?= $business['id'] ?>" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>

  <!-- Danger Zone for Soft Delete -->
  <div class="card" style="border-color: var(--danger-border); background: var(--danger-light); margin-top: 24px;">
    <div style="font-weight: 700; color: var(--danger-dark); font-size: 15px; margin-bottom: 6px;">Delete Business</div>
    <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 14px;">
      Soft-deleting this business will remove it from active listings while preserving historical transactions and financial ledgers.
    </div>
    <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this business? All historical records will be archived.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <button type="submit" class="btn btn-danger btn-sm">Move Business to Trash</button>
    </form>
  </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
