<?php
/**
 * KhataShata - Record Business Revenue
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$error = '';
$db = get_db_connection();

$prefill_business = (int)($_GET['business_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } else {
        $business_id = (int)($_POST['business_id'] ?? 0);
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $title = trim($_POST['title'] ?? '');
        $client_name = trim($_POST['client_name'] ?? '');
        $payment_type = trim($_POST['payment_type'] ?? 'full');
        $amount = (float)($_POST['amount'] ?? 0);
        $transaction_date = trim($_POST['transaction_date'] ?? date('Y-m-d'));
        $financial_month = trim($_POST['financial_month'] ?? date('Y-m', strtotime($transaction_date)));
        $received_date = !empty($_POST['received_date']) ? $_POST['received_date'] : $transaction_date;
        $payment_method = trim($_POST['payment_method'] ?? 'Bank Transfer');
        $reference_no = trim($_POST['reference_no'] ?? generate_reference('REV'));
        $description = trim($_POST['description'] ?? '');

        if ($business_id <= 0) {
            $error = 'Please select a business.';
        } elseif (empty($title)) {
            $error = 'Income title is required.';
        } elseif ($amount <= 0) {
            $error = 'Amount must be greater than zero.';
        } else {
            // Check if month is already locked
            $check_stmt = $db->prepare("SELECT status FROM monthly_closings WHERE business_id = :bid AND financial_month = :month LIMIT 1");
            $check_stmt->execute(['bid' => $business_id, 'month' => $financial_month]);
            $closing_status = $check_stmt->fetchColumn();

            if ($closing_status === 'finalized') {
                $error = "Financial month $financial_month is finalized and locked. Please reopen this month before adding new income.";
            } else {
                $attachment = null;
                if (!empty($_FILES['attachment']['name'])) {
                    $attachment = handle_file_upload($_FILES['attachment'], 'receipts');
                }

                try {
                    $db->beginTransaction();

                    $stmt = $db->prepare("
                        INSERT INTO business_income 
                        (user_id, business_id, category_id, account_id, title, client_name, payment_type, amount, transaction_date, financial_month, received_date, payment_method, reference_no, description, attachment)
                        VALUES (:uid, :bid, :cat, :aid, :title, :cname, :ptype, :amt, :tdate, :month, :rdate, :pmethod, :ref, :desc, :att)
                    ");
                    $stmt->execute([
                        'uid' => $user['id'],
                        'bid' => $business_id,
                        'cat' => $category_id,
                        'aid' => $account_id,
                        'title' => $title,
                        'cname' => $client_name,
                        'ptype' => $payment_type,
                        'amt' => $amount,
                        'tdate' => $transaction_date,
                        'month' => $financial_month,
                        'rdate' => $received_date,
                        'pmethod' => $payment_method,
                        'ref' => $reference_no,
                        'desc' => $description,
                        'att' => $attachment
                    ]);
                    $income_id = $db->lastInsertId();

                    // If account was linked, deposit into account & log account transaction
                    if ($account_id) {
                        FinancialEngine::recordAccountInflow($account_id, $amount, 'business_income', 'business_income', $income_id, $transaction_date, $reference_no, "Business Revenue: $title" . ($client_name ? " ($client_name)" : ""), $user['id']);
                    }

                    // Sync partner earnings ledger automatically
                    $partners_stmt = $db->prepare("SELECT DISTINCT partner_id FROM business_ownership WHERE business_id = :bid AND partner_id IS NOT NULL AND is_active = 1");
                    $partners_stmt->execute(['bid' => $business_id]);
                    $b_partners = $partners_stmt->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($b_partners as $bpid) {
                        FinancialEngine::syncPartnerEarnings($business_id, $bpid);
                    }

                    log_audit($user['id'], 'business_income', $income_id, 'create', null, $_POST, "Recorded business income of $amount");
                    $db->commit();

                    flash_set('success', "Business revenue of " . format_money($amount) . " recorded successfully!");
                    redirect("business-detail.php?id=$business_id&tab=income");
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = "Error saving income: " . $e->getMessage();
                }
            }
        }
    }
}

// Businesses
$stmt = $db->prepare("SELECT id, name, collection_model FROM businesses WHERE user_id = :uid AND is_deleted = 0 ORDER BY name ASC");
$stmt->execute(['uid' => $user['id']]);
$businesses = $stmt->fetchAll();

// Pre-calculate ownership shares for each business to power real-time JS calculator
$business_shares = [];
foreach ($businesses as $b) {
    $parts = FinancialEngine::getEffectiveOwnership($b['id']);
    $owner_pct = 60.0;
    $partner_shares = [];
    foreach ($parts as $p) {
        if ($p['is_owner']) {
            $owner_pct = (float)$p['percentage'];
        } else {
            $partner_shares[] = [
                'name' => $p['name'],
                'percentage' => (float)$p['percentage']
            ];
        }
    }
    $business_shares[$b['id']] = [
        'name' => $b['name'],
        'collection_model' => $b['collection_model'] ?? 'direct',
        'owner_pct' => $owner_pct,
        'partners' => $partner_shares
    ];
}

// Categories
$stmt = $db->prepare("SELECT id, name FROM income_categories WHERE user_id = :uid AND is_active = 1 AND type IN ('business','both') ORDER BY name ASC");
$stmt->execute(['uid' => $user['id']]);
$categories = $stmt->fetchAll();

// Accounts
$stmt = $db->prepare("SELECT id, account_name, current_balance FROM accounts WHERE user_id = :uid AND status = 'active' AND is_deleted = 0 ORDER BY account_name ASC");
$stmt->execute(['uid' => $user['id']]);
$accounts = $stmt->fetchAll();

$page_title = 'Record Business Revenue';
$active_nav = 'business_income';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 780px; margin: 0 auto;">
  <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <div>
      <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main);">Record Business Revenue</h2>
      <p style="font-size: 13px; color: var(--text-muted);">Log customer payments, contracts, advance, and milestone payments.</p>
    </div>
    <a href="<?= BASE_URL ?>/business-income.php" class="btn btn-secondary btn-sm">Cancel</a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="POST" action="" enctype="multipart/form-data">
      <?= csrf_field() ?>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Business <span class="req">*</span></label>
          <select name="business_id" id="business_id" class="form-control" required onchange="calculateSplit()">
            <option value="">-- Select Business --</option>
            <?php foreach ($businesses as $b): ?>
              <option value="<?= $b['id'] ?>" <?= ($prefill_business == $b['id'] || (isset($_POST['business_id']) && $_POST['business_id'] == $b['id'])) ? 'selected' : '' ?>>
                <?= htmlspecialchars($b['name']) ?> (<?= ($b['collection_model'] ?? 'direct') === 'direct' ? 'Direct Collection' : 'External' ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Revenue Category <span class="req">*</span></label>
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

      <!-- Payment Type / Stage & Client Name -->
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Payment Type / Stage <span class="req">*</span></label>
          <select name="payment_type" id="payment_type" class="form-control" required>
            <option value="advance" <?= (isset($_POST['payment_type']) && $_POST['payment_type'] === 'advance') ? 'selected' : '' ?>>⚡ Advance Payment (ایڈوانس پیمنٹ)</option>
            <option value="partial" <?= (isset($_POST['payment_type']) && $_POST['payment_type'] === 'partial') ? 'selected' : '' ?>>🔄 Partial / Milestone Payment (پارشل ادائیگی)</option>
            <option value="remaining" <?= (isset($_POST['payment_type']) && $_POST['payment_type'] === 'remaining') ? 'selected' : '' ?>>🏁 Remaining / Final Settlement (بقایا / فائنل ادائیگی)</option>
            <option value="full" <?= (!isset($_POST['payment_type']) || $_POST['payment_type'] === 'full') ? 'selected' : '' ?>>💯 Full Upfront Payment (مکمل ادائیگی)</option>
            <option value="monthly" <?= (isset($_POST['payment_type']) && $_POST['payment_type'] === 'monthly') ? 'selected' : '' ?>>📅 Monthly Subscription / Retainer (ماہانہ فیس)</option>
            <option value="other" <?= (isset($_POST['payment_type']) && $_POST['payment_type'] === 'other') ? 'selected' : '' ?>>Other (دیگر)</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Client / Customer Name</label>
          <input type="text" name="client_name" class="form-control" placeholder="e.g. Zafar, Al Rasheed Traders, ABC Corp" value="<?= htmlspecialchars($_POST['client_name'] ?? '') ?>">
          <div class="form-text">Client or company making the payment.</div>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Income Title / Description <span class="req">*</span></label>
          <input type="text" name="title" class="form-control" placeholder="e.g. POS Software Advance, UMS Web App Milestone 1" required value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" autofocus>
        </div>

        <div class="form-group">
          <label class="form-label">Total Amount (Rs) <span class="req">*</span></label>
          <input type="number" step="0.01" min="0.01" name="amount" id="amount" class="form-control" placeholder="0.00" required value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" oninput="calculateSplit()" style="font-weight: 700; font-size: 16px;">
        </div>
      </div>

      <!-- Real-Time Share Breakdown Banner -->
      <div id="split-box" style="display: none; background: linear-gradient(135deg, rgba(79, 70, 229, 0.05), rgba(16, 185, 129, 0.08)); border: 1px solid rgba(79, 70, 229, 0.2); border-radius: 12px; padding: 14px 18px; margin-bottom: 20px;">
        <div style="font-weight: 700; font-size: 13.5px; color: var(--primary); margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
          <span>💡 Profit & Share Distribution Preview</span>
          <span id="collection-badge" class="badge badge-info" style="font-size: 11px;">Direct Collection</span>
        </div>
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
          <div style="flex: 1; min-width: 160px; background: #fff; padding: 10px 14px; border-radius: 8px; border: 1px solid var(--border-color);">
            <div style="font-size: 11.5px; color: var(--text-muted);">🟢 Your Retained Share (<span id="owner-pct-display">60</span>%)</div>
            <div id="owner-share-display" style="font-size: 18px; font-weight: 800; color: var(--success-dark); margin-top: 2px;">Rs 0.00</div>
            <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">Remains in your account</div>
          </div>
          <div style="flex: 1; min-width: 160px; background: #fff; padding: 10px 14px; border-radius: 8px; border: 1px solid var(--border-color);">
            <div style="font-size: 11.5px; color: var(--text-muted);">🟣 Partner Share (<span id="partner-pct-display">40</span>%)</div>
            <div id="partner-share-display" style="font-size: 18px; font-weight: 800; color: #7c3aed; margin-top: 2px;">Rs 0.00</div>
            <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">Payable to partner ledger</div>
          </div>
        </div>
        <div id="split-helper-text" style="font-size: 11.5px; color: var(--text-muted); margin-top: 10px;">
          پوری رقم آپ کے منتخب اکاؤنٹ میں جمع ہوگی۔ آپ کا حصہ آپ کے پاس ہی رہے گا، پارٹنر کا حصہ اس کے کھاتے میں قابلِ ادائیگی شامل ہو جائے گا۔
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Transaction Date <span class="req">*</span></label>
          <input type="date" name="transaction_date" id="trx_date" class="form-control" required value="<?= htmlspecialchars($_POST['transaction_date'] ?? date('Y-m-d')) ?>" onchange="updateMonthFromDate(this.value)">
        </div>

        <div class="form-group">
          <label class="form-label">Financial Month (YYYY-MM) <span class="req">*</span></label>
          <input type="month" name="financial_month" id="fin_month" class="form-control" required value="<?= htmlspecialchars($_POST['financial_month'] ?? date('Y-m')) ?>">
          <div class="form-text">The financial accounting period this revenue belongs to.</div>
        </div>
      </div>

      <div class="form-grid">
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
          <div class="form-text">Client payment will be credited directly to this account balance.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Payment Method</label>
          <select name="payment_method" class="form-control">
            <option value="Bank Transfer">Bank Transfer (IBFT)</option>
            <option value="Cash">Cash in Hand</option>
            <option value="JazzCash">JazzCash</option>
            <option value="Easypaisa">Easypaisa</option>
            <option value="Cheque">Cheque</option>
            <option value="Credit Card">Credit Card / POS</option>
            <option value="Other">Other</option>
          </select>
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Invoice / Reference Number</label>
          <input type="text" name="reference_no" class="form-control" placeholder="e.g. INV-2026-001" value="<?= htmlspecialchars($_POST['reference_no'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label class="form-label">Invoice / Receipt Attachment</label>
          <input type="file" name="attachment" class="form-control" accept="image/*,application/pdf">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Description / Scope Details</label>
        <textarea name="description" rows="2" class="form-control" placeholder="e.g. 50% advance for custom inventory SaaS module..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
      </div>

      <div style="display: flex; gap: 12px; margin-top: 24px;">
        <button type="submit" class="btn btn-success btn-lg" style="flex: 1;">
          Save Business Revenue
        </button>
        <a href="<?= BASE_URL ?>/business-income.php" class="btn btn-secondary btn-lg">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script>
const businessShares = <?= json_encode($business_shares) ?>;

function updateMonthFromDate(val) {
  if (val && val.length >= 7) {
    document.getElementById('fin_month').value = val.substring(0, 7);
  }
}

function calculateSplit() {
  const bid = document.getElementById('business_id').value;
  const amtInput = document.getElementById('amount').value;
  const splitBox = document.getElementById('split-box');
  
  if (!bid || !businessShares[bid] || !amtInput || parseFloat(amtInput) <= 0) {
    splitBox.style.display = 'none';
    return;
  }
  
  const bData = businessShares[bid];
  const amt = parseFloat(amtInput);
  const ownerPct = bData.owner_pct || 60;
  
  // Total partner pct
  let partnerPct = 0;
  let partnerName = 'Partner';
  if (bData.partners && bData.partners.length > 0) {
    partnerPct = bData.partners[0].percentage;
    partnerName = bData.partners[0].name;
  } else {
    partnerPct = Math.max(0, 100 - ownerPct);
  }
  
  const ownerShare = (amt * ownerPct) / 100;
  const partnerShare = (amt * partnerPct) / 100;
  
  document.getElementById('owner-pct-display').innerText = ownerPct.toFixed(0);
  document.getElementById('owner-share-display').innerText = 'Rs ' + ownerShare.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  
  document.getElementById('partner-pct-display').innerText = partnerPct.toFixed(0);
  document.getElementById('partner-share-display').innerText = 'Rs ' + partnerShare.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  
  const badge = document.getElementById('collection-badge');
  const helper = document.getElementById('split-helper-text');
  
  if (bData.collection_model === 'external') {
    badge.innerText = 'External Partnership';
    badge.className = 'badge badge-warning';
    helper.innerText = 'اس بزنس میں پارٹنر کلیکشن کرتا ہے۔ جب آپ کو آپ کا شیئر ملے تو وہ آپ کی آمدن ہے۔';
  } else {
    badge.innerText = 'Direct Collection (' + ownerPct.toFixed(0) + '/' + partnerPct.toFixed(0) + ' Split)';
    badge.className = 'badge badge-info';
    helper.innerText = 'پوری رقم آپ کے اکاؤنٹ میں جمع ہوگی۔ آپ کا ' + ownerPct.toFixed(0) + '% شیئر آپ کے پاس ہی رہے گا، ' + partnerName + ' کا ' + partnerPct.toFixed(0) + '% شیئر اس کے کھاتے میں قابلِ ادائیگی شو ہوگا۔';
  }
  
  splitBox.style.display = 'block';
}

document.addEventListener('DOMContentLoaded', function() {
  calculateSplit();
});
</script>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
