<?php
/**
 * KhataShata - Record Client Deal Payment
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
        $payment_type = trim($_POST['payment_type'] ?? 'milestone');
        $amount = (float)($_POST['amount'] ?? 0);
        $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $transaction_date = trim($_POST['transaction_date'] ?? date('Y-m-d'));
        $payment_method = trim($_POST['payment_method'] ?? 'Bank Transfer');
        $reference_no = trim($_POST['reference_no'] ?? generate_reference('CLT'));
        $notes = trim($_POST['notes'] ?? '');

        if ($deal_id <= 0) {
            $error = 'Please select a valid client deal.';
        } elseif ($amount <= 0) {
            $error = 'Payment amount must be greater than zero.';
        } else {
            // Fetch deal
            $d_stmt = $db->prepare("SELECT * FROM client_deals WHERE id = :id AND user_id = :uid AND is_deleted = 0 LIMIT 1");
            $d_stmt->execute(['id' => $deal_id, 'uid' => $user['id']]);
            $deal = $d_stmt->fetch();

            if (!$deal) {
                $error = 'Deal not found.';
            } else {
                try {
                    $db->beginTransaction();

                    // 1. Update deal balances
                    if ($payment_type === 'advance') {
                        $upd = $db->prepare("UPDATE client_deals SET advance_amount = advance_amount + :amt, updated_at = NOW() WHERE id = :id");
                        $upd->execute(['amt' => $amount, 'id' => $deal_id]);
                    } elseif ($payment_type === 'monthly') {
                        // Monthly retainer does not reduce one-time total, but records as transaction
                    } else {
                        // Milestone / regular client payment
                        $upd = $db->prepare("UPDATE client_deals SET client_paid_amount = client_paid_amount + :amt, updated_at = NOW() WHERE id = :id");
                        $upd->execute(['amt' => $amount, 'id' => $deal_id]);
                    }

                    // Check if total collected equals or exceeds total amount -> mark Completed
                    $check_deal = $db->prepare("SELECT total_amount, advance_amount, client_paid_amount FROM client_deals WHERE id = :id");
                    $check_deal->execute(['id' => $deal_id]);
                    $cd = $check_deal->fetch();
                    if (($cd['advance_amount'] + $cd['client_paid_amount']) >= $cd['total_amount'] && $cd['total_amount'] > 0) {
                        $db->exec("UPDATE client_deals SET status = 'Completed' WHERE id = $deal_id");
                    }

                    // 2. Insert into deal_transactions
                    $t_stmt = $db->prepare("
                        INSERT INTO deal_transactions (deal_id, user_id, type, payment_type, amount, account_id, transaction_date, payment_method, reference_no, notes)
                        VALUES (:did, :uid, 'client_payment', :ptype, :amt, :aid, :tdate, :pmethod, :ref, :notes)
                    ");
                    $t_stmt->execute([
                        'did' => $deal_id,
                        'uid' => $user['id'],
                        'ptype' => $payment_type,
                        'amt' => $amount,
                        'aid' => $account_id,
                        'tdate' => $transaction_date,
                        'pmethod' => $payment_method,
                        'ref' => $reference_no,
                        'notes' => $notes
                    ]);
                    $trx_id = $db->lastInsertId();

                    // 3. Deposit into financial accounts
                    if ($account_id) {
                        FinancialEngine::recordAccountInflow(
                            $account_id, 
                            $amount, 
                            'deal_transactions', 
                            'client_deals', 
                            $deal_id, 
                            $transaction_date, 
                            $reference_no, 
                            "Client Payment ({$deal['client_name']} - " . ucfirst($payment_type) . ")", 
                            $user['id']
                        );
                    }

                    // 4. Log in business_income for general portfolio reporting
                    $fin_month = date('Y-m', strtotime($transaction_date));
                    $bi_stmt = $db->prepare("
                        INSERT INTO business_income 
                        (user_id, business_id, account_id, title, client_name, payment_type, amount, transaction_date, financial_month, received_date, payment_method, reference_no, description)
                        VALUES (:uid, :bid, :aid, :title, :cname, :ptype, :amt, :tdate, :fmonth, :tdate, :pmethod, :ref, :desc)
                    ");
                    $bi_stmt->execute([
                        'uid' => $user['id'],
                        'bid' => $deal['business_id'],
                        'aid' => $account_id,
                        'title' => "Payment for {$deal['software_type']} (" . ucfirst($payment_type) . ")",
                        'cname' => $deal['client_name'],
                        'ptype' => ($payment_type === 'advance') ? 'advance' : (($payment_type === 'monthly') ? 'monthly' : 'partial'),
                        'amt' => $amount,
                        'tdate' => $transaction_date,
                        'fmonth' => $fin_month,
                        'pmethod' => $payment_method,
                        'ref' => $reference_no,
                        'desc' => "Client payment against deal ID #{$deal_id}. " . $notes
                    ]);

                    log_audit($user['id'], 'client_deals', $deal_id, 'receive_payment', null, $_POST, "Received $amount from {$deal['client_name']}");
                    $db->commit();

                    flash_set('success', "Payment of " . format_money($amount) . " from {$deal['client_name']} recorded successfully!");
                    redirect('saas-deals.php?business_id=' . $deal['business_id']);
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = "Error saving payment: " . $e->getMessage();
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
    $c_rem = max(0.00, round((float)$d['total_amount'] - ((float)$d['advance_amount'] + (float)$d['client_paid_amount']), 2));
    $d['remaining_to_collect'] = $c_rem;
    $deals_map[$d['id']] = $d;
}

// Accounts
$a_stmt = $db->prepare("SELECT id, account_name, current_balance FROM accounts WHERE user_id = :uid AND status = 'active' AND is_deleted = 0 ORDER BY account_name ASC");
$a_stmt->execute(['uid' => $user['id']]);
$accounts = $a_stmt->fetchAll();

$page_title = 'Receive Client Payment';
$active_nav = 'saas_deals';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 700px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">📥 Receive Client Payment</h2>
      <p style="font-size: 13px; color: var(--text-muted);">Record advance, milestone installments, or monthly subscription fees.</p>
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
        <label class="form-label">Client Deal <span class="req">*</span></label>
        <select name="deal_id" id="deal_select" class="form-control" required onchange="updateDealInfo()">
          <option value="">-- Select Client Deal --</option>
          <?php foreach ($all_deals as $d): ?>
            <option value="<?= $d['id'] ?>" <?= ($prefill_deal_id == $d['id'] || (isset($_POST['deal_id']) && $_POST['deal_id'] == $d['id'])) ? 'selected' : '' ?>>
              <?= htmlspecialchars($d['client_name']) ?> (<?= htmlspecialchars($d['software_type']) ?> - Total: <?= format_money($d['total_amount']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Live Deal Summary Banner -->
      <div id="deal-summary-box" style="display: none; background: linear-gradient(135deg, rgba(59, 130, 246, 0.05), rgba(16, 185, 129, 0.08)); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 10px; padding: 14px 18px; margin-bottom: 18px;">
        <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
          <div>
            <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Total Deal Value</div>
            <div id="summary-total" style="font-size: 16px; font-weight: 800; color: var(--primary);">Rs 0.00</div>
          </div>
          <div>
            <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Collected So Far</div>
            <div id="summary-collected" style="font-size: 16px; font-weight: 800; color: var(--success-dark);">Rs 0.00</div>
          </div>
          <div>
            <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Client Remaining</div>
            <div id="summary-remaining" style="font-size: 16px; font-weight: 800; color: #b45309;">Rs 0.00</div>
          </div>
          <div>
            <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Monthly Retainer</div>
            <div id="summary-monthly" style="font-size: 16px; font-weight: 800; color: #4f46e5;">Rs 0.00</div>
          </div>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Payment Stage / Type <span class="req">*</span></label>
          <select name="payment_type" id="payment_type" class="form-control" required>
            <option value="milestone" selected>🔄 Milestone / Part Payment (قسط / بقایا سے ادائیگی)</option>
            <option value="advance">⚡ Advance Payment (ایڈوانس پیمنٹ)</option>
            <option value="monthly">📅 Monthly Subscription Fee (ماہانہ فیس)</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Amount Received (Rs) <span class="req">*</span></label>
          <input type="number" step="0.01" min="0.01" name="amount" id="amount" class="form-control" placeholder="0.00" required value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" style="font-weight: 700; font-size: 16px;">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Transaction Date <span class="req">*</span></label>
          <input type="date" name="transaction_date" class="form-control" required value="<?= htmlspecialchars($_POST['transaction_date'] ?? date('Y-m-d')) ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Deposit Into Account <span class="req">*</span></label>
          <select name="account_id" class="form-control" required>
            <option value="">-- Select Receiving Account --</option>
            <?php foreach ($accounts as $acc): ?>
              <option value="<?= $acc['id'] ?>" <?= (isset($_POST['account_id']) && $_POST['account_id'] == $acc['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($acc['account_name']) ?> (Current: <?= format_money($acc['current_balance']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Received cash will be deposited into this account.</div>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Payment Method</label>
          <select name="payment_method" class="form-control">
            <option value="Bank Transfer">Bank Transfer (IBFT)</option>
            <option value="Cash">Cash in Hand</option>
            <option value="JazzCash">JazzCash</option>
            <option value="Easypaisa">Easypaisa</option>
            <option value="Cheque">Cheque</option>
            <option value="Other">Other</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Reference / Receipt Number</label>
          <input type="text" name="reference_no" class="form-control" placeholder="e.g. REC-2026-001" value="<?= htmlspecialchars($_POST['reference_no'] ?? '') ?>">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Notes / Remarks</label>
        <textarea name="notes" rows="2" class="form-control" placeholder="e.g. Received via Meezan bank for POS milestone 2..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-success btn-lg" style="flex: 1;">
          Confirm & Save Payment
        </button>
        <a href="<?= BASE_URL ?>/saas-deals.php" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script>
const dealsMap = <?= json_encode($deals_map) ?>;

function updateDealInfo() {
  const did = document.getElementById('deal_select').value;
  const box = document.getElementById('deal-summary-box');
  const amtInput = document.getElementById('amount');
  
  if (!did || !dealsMap[did]) {
    box.style.display = 'none';
    return;
  }
  
  const d = dealsMap[did];
  const adv = parseFloat(d.advance_amount || 0);
  const paid = parseFloat(d.client_paid_amount || 0);
  const total = parseFloat(d.total_amount || 0);
  const rem = parseFloat(d.remaining_to_collect || 0);
  const monthly = parseFloat(d.monthly_amount || 0);
  
  document.getElementById('summary-total').innerText = 'Rs ' + total.toLocaleString(undefined, {minimumFractionDigits: 2});
  document.getElementById('summary-collected').innerText = 'Rs ' + (adv + paid).toLocaleString(undefined, {minimumFractionDigits: 2});
  document.getElementById('summary-remaining').innerText = 'Rs ' + rem.toLocaleString(undefined, {minimumFractionDigits: 2});
  document.getElementById('summary-monthly').innerText = 'Rs ' + monthly.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' / mo';
  
  box.style.display = 'block';
  
  // Suggest amount if empty
  if (!amtInput.value && rem > 0) {
    amtInput.placeholder = 'Suggested: ' + rem.toFixed(2);
  }
}

document.addEventListener('DOMContentLoaded', updateDealInfo);
</script>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
