<?php
/**
 * KhataShata - Edit Partner Profile
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$partner_id = (int)($_GET['id'] ?? 0);
$db = get_db_connection();

$stmt = $db->prepare("SELECT * FROM partners WHERE id = :id AND user_id = :uid AND is_deleted = 0 LIMIT 1");
$stmt->execute(['id' => $partner_id, 'uid' => $user['id']]);
$partner = $stmt->fetch();

if (!$partner) {
    flash_set('danger', 'Partner not found.');
    redirect('partners.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        // Soft delete partner
        $del = $db->prepare("UPDATE partners SET is_deleted = 1, updated_at = NOW() WHERE id = :id AND user_id = :uid");
        $del->execute(['id' => $partner_id, 'uid' => $user['id']]);
        log_audit($user['id'], 'partners', $partner_id, 'soft_delete', $partner, ['is_deleted' => 1], "Soft deleted partner");
        flash_set('warning', "Partner '{$partner['name']}' has been removed.");
        redirect('partners.php');
    } else {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $joining_date = !empty($_POST['joining_date']) ? $_POST['joining_date'] : null;
        $notes = trim($_POST['notes'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');

        if (empty($name)) {
            $error = 'Partner name cannot be empty.';
        } else {
            $pic_path = $partner['profile_pic'];
            if (!empty($_FILES['profile_pic']['name'])) {
                $uploaded = handle_file_upload($_FILES['profile_pic'], 'partners');
                if ($uploaded) {
                    $pic_path = $uploaded;
                }
            }

            $upd = $db->prepare("
                UPDATE partners 
                SET name = :name, phone = :phone, email = :email, address = :addr, 
                    profile_pic = :pic, joining_date = :jdate, notes = :notes, status = :status, updated_at = NOW()
                WHERE id = :id AND user_id = :uid
            ");
            $upd->execute([
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'addr' => $address,
                'pic' => $pic_path,
                'jdate' => $joining_date,
                'notes' => $notes,
                'status' => $status,
                'id' => $partner_id,
                'uid' => $user['id']
            ]);

            log_audit($user['id'], 'partners', $partner_id, 'update', $partner, $_POST, "Updated partner profile");
            flash_set('success', 'Partner profile updated successfully.');
            redirect('partners.php');
        }
    }
}

$page_title = 'Edit Partner: ' . $partner['name'];
$active_nav = 'partners';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 700px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">Edit: <?= htmlspecialchars($partner['name']) ?></h2>
      <p style="font-size: 13px; color: var(--text-muted);">Modify partner contact details or status.</p>
    </div>
    <div style="display:flex; gap:8px;">
      <a href="<?= BASE_URL ?>/partner-ledger.php?partner_id=<?= $partner['id'] ?>" class="btn btn-secondary btn-sm">Ledger</a>
      <a href="<?= BASE_URL ?>/partners.php" class="btn btn-secondary btn-sm">Back</a>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" action="" enctype="multipart/form-data">
      <?= csrf_field() ?>

      <div class="form-group">
        <label class="form-label">Full Name <span class="req">*</span></label>
        <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($_POST['name'] ?? $partner['name']) ?>">
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Contact Number</label>
          <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($_POST['phone'] ?? $partner['phone']) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? $partner['email']) ?>">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Joining Date</label>
          <input type="date" name="joining_date" class="form-control" value="<?= htmlspecialchars($_POST['joining_date'] ?? $partner['joining_date']) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" class="form-control">
            <option value="Active" <?= $partner['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
            <option value="Inactive" <?= $partner['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Residential / Office Address</label>
        <textarea name="address" rows="2" class="form-control"><?= htmlspecialchars($_POST['address'] ?? $partner['address']) ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Profile Photo</label>
        <input type="file" name="profile_pic" class="form-control" accept="image/*">
        <?php if (!empty($partner['profile_pic'])): ?>
          <div style="margin-top: 6px; font-size: 11px;">
            <a href="<?= BASE_URL ?>/<?= htmlspecialchars($partner['profile_pic']) ?>" target="_blank">View current picture</a>
          </div>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label class="form-label">Notes & Bank Account Details</label>
        <textarea name="notes" rows="2" class="form-control"><?= htmlspecialchars($_POST['notes'] ?? $partner['notes']) ?></textarea>
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">Save Changes</button>
        <a href="<?= BASE_URL ?>/partners.php" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>

  <div class="card" style="border-color: var(--danger-border); background: var(--danger-light); margin-top: 24px;">
    <div style="font-weight: 700; color: var(--danger-dark); font-size: 15px; margin-bottom: 6px;">Delete Partner Profile</div>
    <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 14px;">
      Soft-deletes this partner profile. Historical ledger records, finalized distributions, and previous payment transactions remain preserved in database audit trail.
    </div>
    <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this partner profile? Historical ledgers will be preserved.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <button type="submit" class="btn btn-danger btn-sm">Move Partner to Trash</button>
    </form>
  </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
