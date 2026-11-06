<?php
/**
 * KhataShata - Edit Client Software Deal
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$error = '';
$db = get_db_connection();

$deal_id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM client_deals WHERE id = :id AND user_id = :uid AND is_deleted = 0 LIMIT 1");
$stmt->execute(['id' => $deal_id, 'uid' => $user['id']]);
$deal = $stmt->fetch();

if (!$deal) {
    flash_set('danger', 'Deal not found.');
    redirect('saas-deals.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $del = $db->prepare("UPDATE client_deals SET is_deleted = 1, updated_at = NOW() WHERE id = :id AND user_id = :uid");
        $del->execute(['id' => $deal_id, 'uid' => $user['id']]);
        log_audit($user['id'], 'client_deals', $deal_id, 'delete', $deal, ['is_deleted' => 1], "Archived deal for {$deal['client_name']}");
        flash_set('warning', "Deal for '{$deal['client_name']}' has been removed.");
        redirect('saas-deals.php?business_id=' . $deal['business_id']);
    } else {
        $client_name = trim($_POST['client_name'] ?? '');
        $software_type = trim($_POST['software_type'] ?? 'POS');
        $total_amount = (float)($_POST['total_amount'] ?? 0);
        $advance_amount = (float)($_POST['advance_amount'] ?? 0);
        $client_paid_amount = (float)($_POST['client_paid_amount'] ?? 0);
        $monthly_amount = (float)($_POST['monthly_amount'] ?? 0);
        $partner_cut_pct = (float)($_POST['partner_cut_pct'] ?? 40.0);
        $partner_paid_amount = (float)($_POST['partner_paid_amount'] ?? 0);
        $status = trim($_POST['status'] ?? 'Active');
        $notes = trim($_POST['notes'] ?? '');

        if (empty($client_name)) {
            $error = 'Client name is required.';
        } elseif (empty($software_type)) {
            $error = 'Software type is required.';
        } else {
            try {
                $upd = $db->prepare("
                    UPDATE client_deals 
                    SET client_name = :cname, software_type = :stype, total_amount = :tamt, 
                        advance_amount = :adv, client_paid_amount = :cpaid, monthly_amount = :mamt, 
                        partner_cut_pct = :ppct, partner_paid_amount = :ppaid, status = :status, 
                        notes = :notes, updated_at = NOW()
                    WHERE id = :id AND user_id = :uid
                ");
                $upd->execute([
                    'cname' => $client_name,
                    'stype' => $software_type,
                    'tamt' => $total_amount,
                    'adv' => $advance_amount,
                    'cpaid' => $client_paid_amount,
                    'mamt' => $monthly_amount,
                    'ppct' => $partner_cut_pct,
                    'ppaid' => $partner_paid_amount,
                    'status' => $status,
                    'notes' => $notes,
                    'id' => $deal_id,
                    'uid' => $user['id']
                ]);

                log_audit($user['id'], 'client_deals', $deal_id, 'update', $deal, $_POST, "Updated deal details for $client_name");
                flash_set('success', "Deal details for '$client_name' updated successfully!");
                redirect('saas-deals.php?business_id=' . $deal['business_id']);
            } catch (Exception $e) {
                $error = "Error updating deal: " . $e->getMessage();
            }
        }
    }
}

$page_title = 'Edit Client Deal: ' . $deal['client_name'];
$active_nav = 'saas_deals';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 760px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">Edit Deal: <?= htmlspecialchars($deal['client_name']) ?></h2>
      <p style="font-size: 13px; color: var(--text-muted);">Adjust contract figures, payments received, partner payouts, or status.</p>
    </div>
    <a href="<?= BASE_URL ?>/saas-deals.php" class="btn btn-secondary btn-sm">Back</a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" action="">
      <?= csrf_field() ?>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Client Name <span class="req">*</span></label>
          <input type="text" name="client_name" class="form-control" required value="<?= htmlspecialchars($_POST['client_name'] ?? $deal['client_name']) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Software Type <span class="req">*</span></label>
          <input type="text" name="software_type" class="form-control" required value="<?= htmlspecialchars($_POST['software_type'] ?? $deal['software_type']) ?>">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Total Contract Amount (Rs)</label>
          <input type="number" step="0.01" min="0" name="total_amount" class="form-control" required value="<?= htmlspecialchars($_POST['total_amount'] ?? $deal['total_amount']) ?>" style="font-weight: 700;">
        </div>

        <div class="form-group">
          <label class="form-label">Monthly Retainer (Rs / mo)</label>
          <input type="number" step="0.01" min="0" name="monthly_amount" class="form-control" value="<?= htmlspecialchars($_POST['monthly_amount'] ?? $deal['monthly_amount']) ?>">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Advance Received (Rs)</label>
          <input type="number" step="0.01" min="0" name="advance_amount" class="form-control" value="<?= htmlspecialchars($_POST['advance_amount'] ?? $deal['advance_amount']) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Subsequent Installments Paid (Rs)</label>
          <input type="number" step="0.01" min="0" name="client_paid_amount" class="form-control" value="<?= htmlspecialchars($_POST['client_paid_amount'] ?? $deal['client_paid_amount']) ?>">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Partner Cut Percentage (%)</label>
          <input type="number" step="0.01" min="0" max="100" name="partner_cut_pct" class="form-control" value="<?= htmlspecialchars($_POST['partner_cut_pct'] ?? $deal['partner_cut_pct']) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Total Paid to Partner (Rs)</label>
          <input type="number" step="0.01" min="0" name="partner_paid_amount" class="form-control" value="<?= htmlspecialchars($_POST['partner_paid_amount'] ?? $deal['partner_paid_amount']) ?>" style="color: #7c3aed; font-weight: 600;">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Deal Status</label>
          <select name="status" class="form-control">
            <option value="Active" <?= $deal['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
            <option value="Completed" <?= $deal['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
            <option value="Cancelled" <?= $deal['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Internal Notes</label>
          <input type="text" name="notes" class="form-control" value="<?= htmlspecialchars($_POST['notes'] ?? $deal['notes']) ?>">
        </div>
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">Save Changes</button>
        <a href="<?= BASE_URL ?>/saas-deals.php" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>

  <!-- Delete Deal Card -->
  <div class="card" style="margin-top: 20px; border-color: var(--danger-border); background: var(--danger-light);">
    <div style="font-weight: 700; color: var(--danger-dark); margin-bottom: 6px;">Delete Deal</div>
    <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px;">Archiving this deal removes it from the active breakdown.</div>
    <form method="POST" action="" onsubmit="return confirm('Are you sure you want to remove this client deal?');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <button type="submit" class="btn btn-danger btn-sm">Move Deal to Trash</button>
    </form>
  </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
