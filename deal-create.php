<?php
/**
 * KhataShata - Create New Software Client Deal
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$error = '';
$db = get_db_connection();

$prefill_biz = (int)($_GET['business_id'] ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } else {
        $business_id = (int)($_POST['business_id'] ?? 1);
        $partner_id = !empty($_POST['partner_id']) ? (int)$_POST['partner_id'] : null;
        $client_name = trim($_POST['client_name'] ?? '');
        $software_type = trim($_POST['software_type'] ?? 'POS');
        $total_amount = (float)($_POST['total_amount'] ?? 0);
        $advance_amount = (float)($_POST['advance_amount'] ?? 0);
        $monthly_amount = (float)($_POST['monthly_amount'] ?? 0);
        $partner_cut_pct = (float)($_POST['partner_cut_pct'] ?? 40.0);
        $start_date = trim($_POST['start_date'] ?? date('Y-m-d'));
        $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $notes = trim($_POST['notes'] ?? '');

        if (empty($client_name)) {
            $error = 'Client name is required.';
        } elseif (empty($software_type)) {
            $error = 'Software type is required.';
        } elseif ($total_amount <= 0 && $monthly_amount <= 0) {
            $error = 'Please enter either total contract amount or monthly retainer.';
        } else {
            try {
                $db->beginTransaction();

                $stmt = $db->prepare("
                    INSERT INTO client_deals 
                    (user_id, business_id, partner_id, client_name, software_type, total_amount, advance_amount, client_paid_amount, monthly_amount, partner_cut_pct, partner_paid_amount, start_date, status, notes)
                    VALUES (:uid, :bid, :pid, :cname, :stype, :tamt, :adv, 0.00, :mamt, :ppct, 0.00, :sdate, 'Active', :notes)
                ");
                $stmt->execute([
                    'uid' => $user['id'],
                    'bid' => $business_id,
                    'pid' => $partner_id,
                    'cname' => $client_name,
                    'stype' => $software_type,
                    'tamt' => $total_amount,
                    'adv' => $advance_amount,
                    'mamt' => $monthly_amount,
                    'ppct' => $partner_cut_pct,
                    'sdate' => $start_date,
                    'notes' => $notes
                ]);
                $deal_id = $db->lastInsertId();

                // If initial advance received and account selected, record inflow
                if ($advance_amount > 0 && $account_id) {
                    $ref = generate_reference('ADV');
                    // Record in deal_transactions
                    $t_stmt = $db->prepare("
                        INSERT INTO deal_transactions (deal_id, user_id, type, payment_type, amount, account_id, transaction_date, payment_method, reference_no, notes)
                        VALUES (:did, :uid, 'client_payment', 'advance', :amt, :aid, :tdate, 'Bank Transfer', :ref, 'Initial Advance')
                    ");
                    $t_stmt->execute([
                        'did' => $deal_id,
                        'uid' => $user['id'],
                        'amt' => $advance_amount,
                        'aid' => $account_id,
                        'tdate' => $start_date,
                        'ref' => $ref
                    ]);

                    FinancialEngine::recordAccountInflow(
                        $account_id, 
                        $advance_amount, 
                        'deal_transactions', 
                        'client_deals', 
                        $deal_id, 
                        $start_date, 
                        $ref, 
                        "Advance Payment ($client_name - $software_type)", 
                        $user['id']
                    );

                    // Also in business_income
                    $fin_month = date('Y-m', strtotime($start_date));
                    $bi_stmt = $db->prepare("
                        INSERT INTO business_income 
                        (user_id, business_id, account_id, title, client_name, payment_type, amount, transaction_date, financial_month, received_date, payment_method, reference_no, description)
                        VALUES (:uid, :bid, :aid, :title, :cname, 'advance', :amt, :tdate, :fmonth, :tdate, 'Bank Transfer', :ref, :desc)
                    ");
                    $bi_stmt->execute([
                        'uid' => $user['id'],
                        'bid' => $business_id,
                        'aid' => $account_id,
                        'title' => "$software_type Software Advance",
                        'cname' => $client_name,
                        'amt' => $advance_amount,
                        'tdate' => $start_date,
                        'fmonth' => $fin_month,
                        'ref' => $ref,
                        'desc' => "Initial advance for $client_name against deal #$deal_id"
                    ]);
                }

                log_audit($user['id'], 'client_deals', $deal_id, 'create', null, $_POST, "Created deal for $client_name ($software_type)");
                $db->commit();

                flash_set('success', "Client deal for '$client_name' registered successfully!");
                redirect("saas-deals.php?business_id=$business_id");
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Error creating deal: " . $e->getMessage();
            }
        }
    }
}

// Businesses & Partners
$businesses = $db->query("SELECT id, name FROM businesses WHERE user_id = {$user['id']} AND is_deleted = 0 ORDER BY id ASC")->fetchAll();
$partners = $db->query("SELECT id, name FROM partners WHERE user_id = {$user['id']} AND is_deleted = 0 ORDER BY name ASC")->fetchAll();
$accounts = $db->query("SELECT id, account_name, current_balance FROM accounts WHERE user_id = {$user['id']} AND status = 'active' AND is_deleted = 0 ORDER BY account_name ASC")->fetchAll();

$page_title = 'Add New Client Deal';
$active_nav = 'saas_deals';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 760px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">+ Add New Client Software Deal</h2>
      <p style="font-size: 13px; color: var(--text-muted);">Configure client project, one-time contract value, monthly subscription, and partner split.</p>
    </div>
    <a href="<?= BASE_URL ?>/saas-deals.php" class="btn btn-secondary btn-sm">Cancel</a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" action="">
      <?= csrf_field() ?>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Client / Customer Name <span class="req">*</span></label>
          <input type="text" name="client_name" class="form-control" placeholder="e.g. Zafar Brothers Iron, Al Rasheed Traders" required value="<?= htmlspecialchars($_POST['client_name'] ?? '') ?>" autofocus>
        </div>

        <div class="form-group">
          <label class="form-label">Software / Project Type <span class="req">*</span></label>
          <input type="text" name="software_type" class="form-control" placeholder="e.g. POS, UMS, Manufacturing, ERP" required value="<?= htmlspecialchars($_POST['software_type'] ?? '') ?>">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Business Hub</label>
          <select name="business_id" class="form-control" required>
            <?php foreach ($businesses as $b): ?>
              <option value="<?= $b['id'] ?>" <?= $prefill_biz == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Project Start Date</label>
          <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($_POST['start_date'] ?? date('Y-m-d')) ?>">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Total One-Time Amount (Rs) <span class="req">*</span></label>
          <input type="number" step="0.01" min="0" name="total_amount" id="total_amount" class="form-control" placeholder="15000.00" required value="<?= htmlspecialchars($_POST['total_amount'] ?? '') ?>" oninput="updateDealMath()" style="font-weight: 700; font-size: 16px;">
          <div class="form-text">Full contract price for software development/setup.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Advance Received Upfront (Rs)</label>
          <input type="number" step="0.01" min="0" name="advance_amount" id="advance_amount" class="form-control" placeholder="5000.00" value="<?= htmlspecialchars($_POST['advance_amount'] ?? '0.00') ?>" oninput="updateDealMath()" style="font-weight: 600;">
          <div class="form-text">Initial deposit paid by client at start.</div>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Monthly Retainer / Subscription (Rs / mo)</label>
          <input type="number" step="0.01" min="0" name="monthly_amount" class="form-control" placeholder="2000.00" value="<?= htmlspecialchars($_POST['monthly_amount'] ?? '0.00') ?>">
          <div class="form-text">Ongoing monthly support or cloud maintenance fee.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Partner & Profit Split (%)</label>
          <div style="display: flex; gap: 8px;">
            <select name="partner_id" class="form-control" style="flex: 2;">
              <option value="">-- No Partner (100% You) --</option>
              <?php foreach ($partners as $p): ?>
                <option value="<?= $p['id'] ?>" selected><?= htmlspecialchars($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="number" step="0.01" min="0" max="100" name="partner_cut_pct" id="partner_cut_pct" class="form-control" placeholder="40" value="<?= htmlspecialchars($_POST['partner_cut_pct'] ?? '40.00') ?>" oninput="updateDealMath()" style="flex: 1; font-weight: 600;">
          </div>
          <div class="form-text">Partner's cut percentage (e.g. 40% Partner / 60% You).</div>
        </div>
      </div>

      <!-- Live Split Preview Box -->
      <div id="split-preview" style="background: linear-gradient(135deg, rgba(79, 70, 229, 0.05), rgba(16, 185, 129, 0.08)); border: 1px solid rgba(79, 70, 229, 0.2); border-radius: 10px; padding: 14px 18px; margin-bottom: 18px;">
        <div style="font-weight: 700; color: var(--primary); font-size: 13.5px; margin-bottom: 6px;">
          💡 Projected Split on Full Deal
        </div>
        <div style="display: flex; gap: 16px; flex-wrap: wrap;">
          <div>Partner Cut (<span id="preview-partner-pct">40</span>%): <strong id="preview-partner-amt" style="color:#7c3aed;">Rs 0.00</strong></div>
          <div>Your Cut (<span id="preview-mine-pct">60</span>%): <strong id="preview-mine-amt" style="color:var(--success-dark);">Rs 0.00</strong></div>
          <div>Client Remaining after Advance: <strong id="preview-client-rem" style="color:#b45309;">Rs 0.00</strong></div>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Deposit Advance Into Account (If Advance > 0)</label>
        <select name="account_id" class="form-control">
          <option value="">-- Select Receiving Account --</option>
          <?php foreach ($accounts as $acc): ?>
            <option value="<?= $acc['id'] ?>">
              <?= htmlspecialchars($acc['account_name']) ?> (Current: <?= format_money($acc['current_balance']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">If you collected an advance right now, deposit it into this account.</div>
      </div>

      <div class="form-group">
        <label class="form-label">Notes & Specifications</label>
        <textarea name="notes" rows="2" class="form-control" placeholder="Project deliverables, custom modules, client contact details..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">
          Create Deal & Open Breakdown
        </button>
        <a href="<?= BASE_URL ?>/saas-deals.php" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script>
function updateDealMath() {
  const total = parseFloat(document.getElementById('total_amount').value) || 0;
  const adv = parseFloat(document.getElementById('advance_amount').value) || 0;
  const pPct = parseFloat(document.getElementById('partner_cut_pct').value) || 0;
  const mPct = Math.max(0, 100 - pPct);
  
  const pCut = (total * pPct) / 100;
  const mCut = (total * mPct) / 100;
  const rem = Math.max(0, total - adv);
  
  document.getElementById('preview-partner-pct').innerText = pPct.toFixed(0);
  document.getElementById('preview-mine-pct').innerText = mPct.toFixed(0);
  document.getElementById('preview-partner-amt').innerText = 'Rs ' + pCut.toLocaleString(undefined, {minimumFractionDigits: 2});
  document.getElementById('preview-mine-amt').innerText = 'Rs ' + mCut.toLocaleString(undefined, {minimumFractionDigits: 2});
  document.getElementById('preview-client-rem').innerText = 'Rs ' + rem.toLocaleString(undefined, {minimumFractionDigits: 2});
}
document.addEventListener('DOMContentLoaded', updateDealMath);
</script>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
