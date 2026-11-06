<?php
/**
 * KhataShata - Give Payout to Partner from Client Deal
 * "Once client paid then I've decided how much amount I've given to partner"
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$error = '';
$db = get_db_connection();

$prefill_deal_id = (int)($_GET['deal_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } else {
        $deal_id = (int)($_POST['deal_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $transaction_date = trim($_POST['transaction_date'] ?? date('Y-m-d'));
        $payment_method = trim($_POST['payment_method'] ?? 'Bank Transfer');
        $reference_no = trim($_POST['reference_no'] ?? generate_reference('PAY'));
        $notes = trim($_POST['notes'] ?? '');

        if ($deal_id <= 0) {
            $error = 'Please select a client deal.';
        } elseif ($amount <= 0) {
            $error = 'Payout amount must be greater than zero.';
        } else {
            // Fetch deal
            $d_stmt = $db->prepare("
                SELECT cd.*, b.name as business_name, p.id as p_id, p.name as partner_name 
                FROM client_deals cd
                JOIN businesses b ON cd.business_id = b.id
                LEFT JOIN partners p ON cd.partner_id = p.id
                WHERE cd.id = :id AND cd.user_id = :uid AND cd.is_deleted = 0 
                LIMIT 1
            ");
            $d_stmt->execute(['id' => $deal_id, 'uid' => $user['id']]);
            $deal = $d_stmt->fetch();

            if (!$deal) {
                $error = 'Deal not found.';
            } else {
                try {
                    $db->beginTransaction();

                    // 1. Update partner_paid_amount in client_deals
                    $upd = $db->prepare("UPDATE client_deals SET partner_paid_amount = partner_paid_amount + :amt, updated_at = NOW() WHERE id = :id");
                    $upd->execute(['amt' => $amount, 'id' => $deal_id]);

                    // 2. Insert into deal_transactions
                    $t_stmt = $db->prepare("
                        INSERT INTO deal_transactions (deal_id, user_id, type, payment_type, amount, account_id, transaction_date, payment_method, reference_no, notes)
                        VALUES (:did, :uid, 'partner_payout', 'payout', :amt, :aid, :tdate, :pmethod, :ref, :notes)
                    ");
                    $t_stmt->execute([
                        'did' => $deal_id,
                        'uid' => $user['id'],
                        'amt' => $amount,
                        'aid' => $account_id,
                        'tdate' => $transaction_date,
                        'pmethod' => $payment_method,
                        'ref' => $reference_no,
                        'notes' => $notes
                    ]);

                    // 3. Deduct from account balance if account selected
                    if ($account_id) {
                        FinancialEngine::recordAccountOutflow(
                            $account_id, 
                            $amount, 
                            'deal_transactions', 
                            'client_deals', 
                            $deal_id, 
                            $transaction_date, 
                            $reference_no, 
                            "Partner Payout ({$deal['partner_name']} for {$deal['client_name']})", 
                            $user['id']
                        );
                    }

                    // 4. Sync into partner_payments table for system-wide consistency
                    $partner_id = $deal['partner_id'] ?? 1;
                    $fin_month = date('Y-m', strtotime($transaction_date));
                    $pp_stmt = $db->prepare("
                        INSERT INTO partner_payments 
                        (user_id, business_id, partner_id, account_id, financial_month, amount, payment_date, payment_method, reference_no, description)
                        VALUES (:uid, :bid, :pid, :aid, :month, :amt, :pdate, :pmethod, :ref, :desc)
                    ");
                    $pp_stmt->execute([
                        'uid' => $user['id'],
                        'bid' => $deal['business_id'],
                        'pid' => $partner_id,
                        'aid' => $account_id,
                        'month' => $fin_month,
                        'amt' => $amount,
                        'pdate' => $transaction_date,
                        'pmethod' => $payment_method,
                        'ref' => $reference_no,
                        'desc' => "Payout for deal: {$deal['client_name']} ({$deal['software_type']}). " . $notes
                    ]);

                    log_audit($user['id'], 'client_deals', $deal_id, 'partner_payout', null, $_POST, "Paid $amount to partner {$deal['partner_name']} for {$deal['client_name']}");
                    $db->commit();

                    flash_set('success', "Payout of " . format_money($amount) . " successfully given to {$deal['partner_name']} for {$deal['client_name']}!");
                    redirect('saas-deals.php?business_id=' . $deal['business_id']);
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = "Error recording partner payout: " . $e->getMessage();
                }
            }
        }
    }
}

// Fetch active deals
$deals_stmt = $db->prepare("
    SELECT cd.*, b.name as business_name, p.name as partner_name 
    FROM client_deals cd
    JOIN businesses b ON cd.business_id = b.id
    LEFT JOIN partners p ON cd.partner_id = p.id
    WHERE cd.user_id = :uid AND cd.is_deleted = 0 
    ORDER BY cd.id ASC
");
$deals_stmt->execute(['uid' => $user['id']]);
$all_deals = $deals_stmt->fetchAll();

$deals_map = [];
foreach ($all_deals as $d) {
    $t_amt = (float)$d['total_amount'];
    $adv = (float)$d['advance_amount'];
    $c_paid = (float)$d['client_paid_amount'];
    $c_collected = $adv + $c_paid;

    $p_pct = (float)$d['partner_cut_pct'];
    $p_cut = round($t_amt * ($p_pct / 100), 2);
    $p_paid = (float)$d['partner_paid_amount'];
    $p_rem = max(0.00, round($p_cut - $p_paid, 2));

    $d['collected_from_client'] = $c_collected;
    $d['partner_cut_amount'] = $p_cut;
    $d['partner_remaining'] = $p_rem;
    $deals_map[$d['id']] = $d;
}

// Accounts
$a_stmt = $db->prepare("SELECT id, account_name, current_balance FROM accounts WHERE user_id = :uid AND status = 'active' AND is_deleted = 0 ORDER BY account_name ASC");
$a_stmt->execute(['uid' => $user['id']]);
$accounts = $a_stmt->fetchAll();

$page_title = 'Pay Partner from Deal';
$active_nav = 'saas_deals';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 700px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">🤝 Pay Partner from Deal</h2>
      <p style="font-size: 13px; color: var(--text-muted);">Decide how much collected money to disburse to your partner for a client deal.</p>
    </div>
    <a href="<?= BASE_URL ?>/saas-deals.php" class="btn btn-secondary btn-sm">Back to Deals</a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" action="">
      <?= csrf_field() ?>

      <div class="form-group" style="margin-bottom: 16px;">
        <label class="form-label">Client Deal Source <span class="req">*</span></label>
        <select name="deal_id" id="deal_select" class="form-control" required onchange="updatePayoutInfo()">
          <option value="">-- Select Client Deal --</option>
          <?php foreach ($all_deals as $d): ?>
            <option value="<?= $d['id'] ?>" <?= ($prefill_deal_id == $d['id'] || (isset($_POST['deal_id']) && $_POST['deal_id'] == $d['id'])) ? 'selected' : '' ?>>
              <?= htmlspecialchars($d['client_name']) ?> (<?= htmlspecialchars($d['software_type']) ?> - Partner: <?= htmlspecialchars($d['partner_name'] ?? 'Partner') ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Live Partner Share Summary Banner -->
      <div id="payout-summary-box" style="display: none; background: linear-gradient(135deg, rgba(139, 92, 246, 0.08), rgba(79, 70, 229, 0.05)); border: 1px solid rgba(139, 92, 246, 0.25); border-radius: 10px; padding: 14px 18px; margin-bottom: 18px;">
        <div style="font-weight: 700; color: #6d28d9; font-size: 13.5px; margin-bottom: 10px;">
          <span>💼 Partner Entitlement for this Deal: <strong id="partner-name-display">Partner</strong></span>
        </div>
        <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
          <div>
            <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Client Total Paid</div>
            <div id="payout-collected" style="font-size: 16px; font-weight: 800; color: var(--success-dark);">Rs 0.00</div>
          </div>
          <div>
            <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Partner's Total Cut</div>
            <div id="payout-cut" style="font-size: 16px; font-weight: 800; color: #6d28d9;">Rs 0.00</div>
          </div>
          <div>
            <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Already Given to Partner</div>
            <div id="payout-given" style="font-size: 16px; font-weight: 800; color: #7c3aed;">Rs 0.00</div>
          </div>
          <div>
            <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Remaining Due to Partner</div>
            <div id="payout-remaining" style="font-size: 16px; font-weight: 800; color: #dc2626;">Rs 0.00</div>
          </div>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Amount Given to Partner (Rs) <span class="req">*</span></label>
          <input type="number" step="0.01" min="0.01" name="amount" id="amount" class="form-control" placeholder="0.00" required value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" style="font-weight: 700; font-size: 16px; color: #7c3aed;">
        </div>

        <div class="form-group">
          <label class="form-label">Payment Date <span class="req">*</span></label>
          <input type="date" name="transaction_date" class="form-control" required value="<?= htmlspecialchars($_POST['transaction_date'] ?? date('Y-m-d')) ?>">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Paid From Account <span class="req">*</span></label>
          <select name="account_id" class="form-control" required>
            <option value="">-- Select Disbursing Account --</option>
            <?php foreach ($accounts as $acc): ?>
              <option value="<?= $acc['id'] ?>" <?= (isset($_POST['account_id']) && $_POST['account_id'] == $acc['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($acc['account_name']) ?> (Current: <?= format_money($acc['current_balance']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Funds will be deducted from this account balance.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Payment Method</label>
          <select name="payment_method" class="form-control">
            <option value="Cash">Cash in Hand</option>
            <option value="Bank Transfer">Bank Transfer (IBFT)</option>
            <option value="JazzCash">JazzCash</option>
            <option value="Easypaisa">Easypaisa</option>
            <option value="Other">Other</option>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Reference / Slip Number</label>
          <input type="text" name="reference_no" class="form-control" placeholder="e.g. PAY-2026-001" value="<?= htmlspecialchars($_POST['reference_no'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Notes / Remarks</label>
          <input type="text" name="notes" class="form-control" placeholder="e.g. Paid Rs 2,000 cash from advance received..." value="<?= htmlspecialchars($_POST['notes'] ?? '') ?>">
        </div>
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-primary btn-lg" style="flex: 1; background: #7c3aed; border-color: #6d28d9;">
          Confirm & Record Partner Payout
        </button>
        <a href="<?= BASE_URL ?>/saas-deals.php" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script>
const dealsMap = <?= json_encode($deals_map) ?>;

function updatePayoutInfo() {
  const did = document.getElementById('deal_select').value;
  const box = document.getElementById('payout-summary-box');
  const amtInput = document.getElementById('amount');
  
  if (!did || !dealsMap[did]) {
    box.style.display = 'none';
    return;
  }
  
  const d = dealsMap[did];
  const pName = d.partner_name || 'Partner';
  const collected = parseFloat(d.collected_from_client || 0);
  const cut = parseFloat(d.partner_cut_amount || 0);
  const given = parseFloat(d.partner_paid_amount || 0);
  const rem = parseFloat(d.partner_remaining || 0);
  
  document.getElementById('partner-name-display').innerText = pName + ' (' + (d.partner_cut_pct || 40) + '%)';
  document.getElementById('payout-collected').innerText = 'Rs ' + collected.toLocaleString(undefined, {minimumFractionDigits: 2});
  document.getElementById('payout-cut').innerText = 'Rs ' + cut.toLocaleString(undefined, {minimumFractionDigits: 2});
  document.getElementById('payout-given').innerText = 'Rs ' + given.toLocaleString(undefined, {minimumFractionDigits: 2});
  document.getElementById('payout-remaining').innerText = 'Rs ' + rem.toLocaleString(undefined, {minimumFractionDigits: 2});
  
  box.style.display = 'block';
  
  // Suggest amount if empty
  if (!amtInput.value && rem > 0) {
    amtInput.placeholder = 'Suggested Remaining: ' + rem.toFixed(2);
  }
}

document.addEventListener('DOMContentLoaded', updatePayoutInfo);
</script>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
