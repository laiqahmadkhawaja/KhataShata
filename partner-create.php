<?php
/**
 * KhataShata - Register Partner Profile
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
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $joining_date = !empty($_POST['joining_date']) ? $_POST['joining_date'] : date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');

        if (empty($name)) {
            $error = 'Partner name is required.';
        } else {
            $pic_path = null;
            if (!empty($_FILES['profile_pic']['name'])) {
                $pic_path = handle_file_upload($_FILES['profile_pic'], 'partners');
            }

            try {
                $stmt = $db->prepare("
                    INSERT INTO partners (user_id, name, phone, email, address, profile_pic, joining_date, notes, status)
                    VALUES (:uid, :name, :phone, :email, :addr, :pic, :jdate, :notes, :status)
                ");
                $stmt->execute([
                    'uid' => $user['id'],
                    'name' => $name,
                    'phone' => $phone,
                    'email' => $email,
                    'addr' => $address,
                    'pic' => $pic_path,
                    'jdate' => $joining_date,
                    'notes' => $notes,
                    'status' => $status
                ]);
                $partner_id = $db->lastInsertId();

                log_audit($user['id'], 'partners', $partner_id, 'create', null, ['name' => $name, 'phone' => $phone], "Created partner profile for $name");
                flash_set('success', "Partner profile '$name' created successfully!");
                redirect('partners.php');
            } catch (Exception $e) {
                $error = "Error saving partner: " . $e->getMessage();
            }
        }
    }
}

$page_title = 'Add New Partner';
$active_nav = 'partners';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 700px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">Add Partner Profile</h2>
      <p style="font-size: 13px; color: var(--text-muted);">Define partner contact info and identity to assign to businesses.</p>
    </div>
    <a href="<?= BASE_URL ?>/partners.php" class="btn btn-secondary btn-sm">Cancel</a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" action="" enctype="multipart/form-data">
      <?= csrf_field() ?>

      <div class="form-group">
        <label class="form-label">Full Name <span class="req">*</span></label>
        <input type="text" name="name" class="form-control" placeholder="e.g. Usman Tariq" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" autofocus>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Contact Number</label>
          <input type="text" name="phone" class="form-control" placeholder="+92 300 1234567" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="usman@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Joining Date</label>
          <input type="date" name="joining_date" class="form-control" value="<?= htmlspecialchars($_POST['joining_date'] ?? date('Y-m-d')) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" class="form-control">
            <option value="Active" selected>Active</option>
            <option value="Inactive">Inactive</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Residential / Office Address</label>
        <textarea name="address" rows="2" class="form-control" placeholder="Street, city, country..."><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Profile Photo (Optional)</label>
        <input type="file" name="profile_pic" class="form-control" accept="image/*">
      </div>

      <div class="form-group">
        <label class="form-label">Notes & Agreement References</label>
        <textarea name="notes" rows="2" class="form-control" placeholder="Agreement terms, bank routing or personal remarks..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">Save Partner Profile</button>
        <a href="<?= BASE_URL ?>/partners.php" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
