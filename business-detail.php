<?php
/**
 * KhataShata - Dedicated Business Dashboard & Multi-Tab Management
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$business_id = (int)($_GET['id'] ?? 0);
$db = get_db_connection();

$stmt = $db->prepare("
    SELECT b.*, bc.name as category_name 
    FROM businesses b 
    LEFT JOIN business_categories bc ON b.category_id = bc.id 
    WHERE b.id = :id AND b.user_id = :uid AND b.is_deleted = 0 
    LIMIT 1
");
$stmt->execute(['id' => $business_id, 'uid' => $user['id']]);
$business = $stmt->fetch();

if (!$business) {
    flash_set('danger', 'Business not found.');
    redirect('businesses.php');
}

$active_tab = $_GET['tab'] ?? 'overview';
$selected_month = $_GET['month'] ?? date('Y-m');

// Handle Ownership Update POST
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_ownership') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } else {
        $effective_date = trim($_POST['effective_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? 'Ownership adjustment');
        $owners = $_POST['ownership'] ?? []; // array of ['partner_id' => ..., 'percentage' => ...]

        $shares_to_validate = [];
        foreach ($owners as $item) {
            $pid = ($item['partner_id'] === 'owner' || empty($item['partner_id'])) ? null : (int)$item['partner_id'];
            $pct = (float)($item['percentage'] ?? 0);
            $shares_to_validate[] = [
                'partner_id' => $pid,
                'percentage' => $pct
            ];
        }

        $val = FinancialEngine::validateOwnership($shares_to_validate);
        if (!$val['valid']) {
            $error = $val['error'];
        } else {
            try {
                $db->beginTransaction();

                // Check previous configuration for audit log
                $prev_config = FinancialEngine::getEffectiveOwnership($business_id, $effective_date);

                // Insert new effective-dated ownership batch
                foreach ($shares_to_validate as $s) {
                    $ins = $db->prepare("
                        INSERT INTO business_ownership (business_id, partner_id, percentage, effective_date, notes)
                        VALUES (:bid, :pid, :pct, :eff, :notes)
                    ");
                    $ins->execute([
                        'bid' => $business_id,
                        'pid' => $s['partner_id'],
                        'pct' => $s['percentage'],
                        'eff' => $effective_date,
                        'notes' => $notes
                    ]);
                }

                log_audit($user['id'], 'business_ownership', $business_id, 'update_ownership', $prev_config, $shares_to_validate, "Updated ownership effective $effective_date: $notes");
                $db->commit();

                flash_set('success', 'Ownership structure updated successfully with effective date ' . format_date($effective_date));
                redirect("business-detail.php?id=$business_id&tab=partners");
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Error saving ownership: " . $e->getMessage();
            }
        }
    }
}

// 1. Current effective ownership
$participants = FinancialEngine::getEffectiveOwnership($business_id, $selected_month);

// 2. All registered partners for user (to select when adding to business)
$p_stmt = $db->prepare("SELECT * FROM partners WHERE user_id = :uid AND is_deleted = 0 AND status = 'Active' ORDER BY name ASC");
$p_stmt->execute(['uid' => $user['id']]);
$all_partners = $p_stmt->fetchAll();

// 3. Monthly Financial Summary
$summary = FinancialEngine::getMonthlySummary($business_id, $selected_month);

// 4. Lifetime totals for this business
$rev_stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM business_income WHERE business_id = :bid AND is_deleted = 0");
$rev_stmt->execute(['bid' => $business_id]);
$lifetime_rev = (float)$rev_stmt->fetchColumn();

$exp_stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM business_expenses WHERE business_id = :bid AND is_deleted = 0");
$exp_stmt->execute(['bid' => $business_id]);
$lifetime_exp = (float)$exp_stmt->fetchColumn();
$lifetime_profit = round($lifetime_rev - $lifetime_exp, 2);

// Lifetime Owner Share
if (($business['collection_model'] ?? 'direct') === 'direct' || ($business['collection_model'] ?? '') === 'job') {
    $owner_pct = 60.0;
    foreach ($participants as $p) {
        if ($p['is_owner']) { $owner_pct = (float)$p['percentage']; break; }
    }
    $lifetime_owner_earned = round($lifetime_profit * ($owner_pct / 100), 2);
    $lifetime_owner_received = $lifetime_owner_earned;
    $pending_owner_receivable = 0.00;
} else {
    $oe_stmt = $db->prepare("SELECT COALESCE(SUM(share_amount), 0.00) FROM profit_distributions WHERE business_id = :bid AND partner_id IS NULL");
    $oe_stmt->execute(['bid' => $business_id]);
    $lifetime_owner_earned = (float)$oe_stmt->fetchColumn();

    $or_stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM owner_share_receipts WHERE business_id = :bid AND is_deleted = 0");
    $or_stmt->execute(['bid' => $business_id]);
    $lifetime_owner_received = (float)$or_stmt->fetchColumn();
    $pending_owner_receivable = max(0.00, round($lifetime_owner_earned - $lifetime_owner_received, 2));
}

// 5. Recent Business Income
$inc_stmt = $db->prepare("
    SELECT bi.*, ic.name as category_name, a.account_name 
    FROM business_income bi
    LEFT JOIN income_categories ic ON bi.category_id = ic.id
    LEFT JOIN accounts a ON bi.account_id = a.id
    WHERE bi.business_id = :bid AND bi.is_deleted = 0 
    ORDER BY bi.transaction_date DESC, bi.id DESC 
    LIMIT 20
");
$inc_stmt->execute(['bid' => $business_id]);
$recent_incomes = $inc_stmt->fetchAll();

// 6. Recent Business Expenses
$exp_q = $db->prepare("
    SELECT be.*, ec.name as category_name, a.account_name 
    FROM business_expenses be
    LEFT JOIN expense_categories ec ON be.category_id = ec.id
    LEFT JOIN accounts a ON be.account_id = a.id
    WHERE be.business_id = :bid AND be.is_deleted = 0 
    ORDER BY be.date DESC, be.id DESC 
    LIMIT 20
");
$exp_q->execute(['bid' => $business_id]);
$recent_expenses = $exp_q->fetchAll();

// 7. Partner Earnings Ledger
$pe_stmt = $db->prepare("
    SELECT pe.*, p.name as partner_name, p.phone as partner_phone
    FROM partner_earnings pe
    JOIN partners p ON pe.partner_id = p.id
    WHERE pe.business_id = :bid
    ORDER BY pe.financial_month DESC, p.name ASC
");
$pe_stmt->execute(['bid' => $business_id]);
$partner_ledger_rows = $pe_stmt->fetchAll();

// 8. Partner Payments
$pp_stmt = $db->prepare("
    SELECT pp.*, p.name as partner_name, a.account_name
    FROM partner_payments pp
    JOIN partners p ON pp.partner_id = p.id
    LEFT JOIN accounts a ON pp.account_id = a.id
    WHERE pp.business_id = :bid AND pp.is_deleted = 0
    ORDER BY pp.payment_date DESC, pp.id DESC
");
$pp_stmt->execute(['bid' => $business_id]);
$partner_payments = $pp_stmt->fetchAll();

// 9. Ownership History
$hist_stmt = $db->prepare("
    SELECT bo.*, p.name as partner_name
    FROM business_ownership bo
    LEFT JOIN partners p ON bo.partner_id = p.id
    WHERE bo.business_id = :bid
    ORDER BY bo.effective_date DESC, bo.id DESC
");
$hist_stmt->execute(['bid' => $business_id]);
$ownership_history = $hist_stmt->fetchAll();

// 10. Client Deals (SaaS / Software Breakdown)
$deals_q = $db->prepare("
    SELECT cd.*, p.name as partner_name
    FROM client_deals cd
    LEFT JOIN partners p ON cd.partner_id = p.id
    WHERE cd.business_id = :bid AND cd.user_id = :uid AND cd.is_deleted = 0
    ORDER BY cd.id ASC
");
$deals_q->execute(['bid' => $business_id, 'uid' => $user['id']]);
$client_deals = $deals_q->fetchAll();

$page_title = $business['name'] . ' - Business Hub';
$active_nav = 'businesses';

require_once ROOT_PATH . '/includes/header.php';
?>

<!-- Business Header Banner -->
<div class="card" style="margin-bottom: 20px; padding: 22px 24px;">
  <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
    <div style="display: flex; align-items: center; gap: 18px;">
      <?php if (!empty($business['logo'])): ?>
        <img src="<?= BASE_URL ?>/<?= htmlspecialchars($business['logo']) ?>" alt="Logo" style="width: 58px; height: 58px; border-radius: 12px; object-fit: cover; border: 1px solid var(--border-subtle);">
      <?php else: ?>
        <div style="width: 58px; height: 58px; border-radius: 12px; background: linear-gradient(135deg, var(--primary), #60a5fa); color:#fff; display:flex; align-items:center; justify-content:center; font-size:24px; font-weight:800;">
          <?= strtoupper(substr($business['name'], 0, 1)) ?>
        </div>
      <?php endif; ?>

      <div>
        <div style="display: flex; align-items: center; gap: 10px;">
          <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main); letter-spacing: -0.02em;"><?= htmlspecialchars($business['name']) ?></h2>
          <span class="badge <?= ($business['collection_model'] ?? '') === 'job' ? 'badge-success' : (($business['collection_model'] ?? 'direct') === 'direct' ? 'badge-info' : 'badge-warning') ?>" style="font-size: 11px;"><?= ($business['collection_model'] ?? '') === 'job' ? '💼 Job / Salary' : (($business['collection_model'] ?? 'direct') === 'direct' ? '🏢 Direct Collection' : '🤝 External Partnership') ?></span>
          <span class="badge <?= $business['status'] === 'Active' ? 'badge-success' : ($business['status'] === 'Inactive' ? 'badge-warning' : 'badge-neutral') ?>">
            <?= htmlspecialchars($business['status']) ?>
          </span>
        </div>
        <div style="font-size: 13px; color: var(--text-muted); margin-top: 3px;">
          <span><?= htmlspecialchars($business['category_name'] ?? 'General Business') ?></span> &bull; 
          <span>Type: <strong><?= htmlspecialchars($business['ownership_type']) ?></strong></span> &bull; 
          <span>Started: <?= format_date($business['start_date']) ?></span>
        </div>
      </div>
    </div>

    <!-- Quick Action Buttons -->
    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
      <a href="<?= BASE_URL ?>/saas-deals.php?business_id=<?= $business['id'] ?>" class="btn btn-primary btn-sm" style="background:#4f46e5; border-color:#4338ca;">
        📊 Software Deals
      </a>
      <a href="<?= BASE_URL ?>/business-income-create.php?business_id=<?= $business['id'] ?>" class="btn btn-success btn-sm">
        + Add <?= ($business['collection_model'] ?? '') === 'job' ? 'Salary' : 'Revenue' ?>
      </a>
      <a href="<?= BASE_URL ?>/business-expense-create.php?business_id=<?= $business['id'] ?>" class="btn btn-danger btn-sm">
        + Add Expense
      </a>
      <a href="<?= BASE_URL ?>/partner-payment.php?business_id=<?= $business['id'] ?>" class="btn btn-secondary btn-sm">
        Pay Partner
      </a>
      <a href="<?= BASE_URL ?>/business-edit.php?id=<?= $business['id'] ?>" class="btn btn-secondary btn-sm">
        Edit Settings
      </a>
    </div>
  </div>
</div>

<!-- Navigation Sub-Tabs (Section 5.3) -->
<div class="nav-tabs">
  <a href="?id=<?= $business_id ?>&tab=overview" class="nav-tab-item <?= $active_tab === 'overview' ? 'active' : '' ?>">
    Overview
  </a>
  <a href="<?= BASE_URL ?>/saas-deals.php?business_id=<?= $business_id ?>" class="nav-tab-item">
    📊 Software Deals (<?= count($client_deals) ?>)
  </a>
  <a href="?id=<?= $business_id ?>&tab=partners" class="nav-tab-item <?= $active_tab === 'partners' ? 'active' : '' ?>">
    Partners & Ownership
  </a>
  <a href="?id=<?= $business_id ?>&tab=income" class="nav-tab-item <?= $active_tab === 'income' ? 'active' : '' ?>">
    Revenue & Income
  </a>
  <a href="?id=<?= $business_id ?>&tab=expenses" class="nav-tab-item <?= $active_tab === 'expenses' ? 'active' : '' ?>">
    Expenses
  </a>
  <a href="?id=<?= $business_id ?>&tab=closing" class="nav-tab-item <?= $active_tab === 'closing' ? 'active' : '' ?>">
    Monthly Profit & Closing
  </a>
  <a href="?id=<?= $business_id ?>&tab=ledger" class="nav-tab-item <?= $active_tab === 'ledger' ? 'active' : '' ?>">
    Partner Ledger
  </a>
  <a href="?id=<?= $business_id ?>&tab=payments" class="nav-tab-item <?= $active_tab === 'payments' ? 'active' : '' ?>">
    Partner Payments
  </a>
  <a href="?id=<?= $business_id ?>&tab=history" class="nav-tab-item <?= $active_tab === 'history' ? 'active' : '' ?>">
    Ownership History
  </a>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- ==========================================
     TAB 1: OVERVIEW
     ========================================== -->
<?php if ($active_tab === 'overview'): ?>
  <!-- Financial Metric Cards -->
  <div class="metrics-grid">
    <div class="stat-card">
      <div class="stat-header">
        <span class="stat-label">Lifetime Revenue</span>
        <div class="stat-icon success">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/></svg>
        </div>
      </div>
      <div class="stat-value money-positive"><?= format_money($lifetime_rev) ?></div>
      <div class="stat-sub"><?= count($recent_incomes) ?> total income entries</div>
    </div>

    <div class="stat-card">
      <div class="stat-header">
        <span class="stat-label">Lifetime Expenses</span>
        <div class="stat-icon danger">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/></svg>
        </div>
      </div>
      <div class="stat-value money-negative"><?= format_money($lifetime_exp) ?></div>
      <div class="stat-sub"><?= count($recent_expenses) ?> total expense entries</div>
    </div>

    <div class="stat-card">
      <div class="stat-header">
        <span class="stat-label">Cumulative Net Profit</span>
        <div class="stat-icon <?= $lifetime_profit >= 0 ? 'success' : 'danger' ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
      </div>
      <div class="stat-value" style="color: <?= $lifetime_profit >= 0 ? 'var(--success-dark)' : 'var(--danger-dark)' ?>;">
        <?= format_money($lifetime_profit) ?>
      </div>
      <div class="stat-sub">Revenue minus all expenses</div>
    </div>

    <div class="stat-card">
      <div class="stat-header">
        <span class="stat-label">Your Share Receivable</span>
        <div class="stat-icon warning">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
      </div>
      <div class="stat-value" style="color: var(--warning-dark);">
        <?= format_money($pending_owner_receivable) ?>
      </div>
      <div class="stat-sub">Earned: <?= format_money($lifetime_owner_earned) ?> &bull; Drawn: <?= format_money($lifetime_owner_received) ?></div>
    </div>
  </div>

  <!-- Current Month Spotlight -->
  <div class="card" style="background: linear-gradient(135deg, var(--bg-surface), var(--primary-light)); border-color: var(--primary-border);">
    <div class="card-header">
      <div>
        <div class="card-title"><?= date('F Y', strtotime($selected_month . '-01')) ?> Financial Spotlight</div>
        <div class="card-subtitle">Current month performance and profit breakdown</div>
      </div>
      <a href="?id=<?= $business_id ?>&tab=closing&month=<?= $selected_month ?>" class="btn btn-primary btn-sm">
        Review Closing Workflow
      </a>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: 10px;">
      <div>
        <div style="font-size: 12px; color: var(--text-muted);">Current Month Revenue</div>
        <div style="font-size: 20px; font-weight: 800; color: var(--success-dark);"><?= format_money($summary['total_revenue']) ?></div>
      </div>
      <div>
        <div style="font-size: 12px; color: var(--text-muted);">Current Month Expenses</div>
        <div style="font-size: 20px; font-weight: 800; color: var(--danger-dark);"><?= format_money($summary['total_expenses']) ?></div>
      </div>
      <div>
        <div style="font-size: 12px; color: var(--text-muted);">Net Profit</div>
        <div style="font-size: 20px; font-weight: 800; color: <?= $summary['net_profit'] >= 0 ? 'var(--text-main)' : 'var(--danger-dark)' ?>;">
          <?= format_money($summary['net_profit']) ?>
        </div>
      </div>
      <div>
        <div style="font-size: 12px; color: var(--text-muted);">Closing Status</div>
        <div style="margin-top: 4px;">
          <span class="badge <?= $summary['status'] === 'finalized' ? 'badge-success' : 'badge-warning' ?>" style="font-size: 13px;">
            <?= ucfirst($summary['status']) ?>
          </span>
        </div>
      </div>
    </div>
  </div>

  <?php if (!empty($client_deals)): ?>
    <!-- Software Deals Quick Spotlight -->
    <div class="card" style="border-left: 4px solid var(--primary); padding: 18px 20px;">
      <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
        <div>
          <div style="font-weight: 800; font-size: 15px; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
            <span>📊 Software & Client Deals Breakdown</span>
            <span class="badge badge-primary"><?= count($client_deals) ?> active deals</span>
          </div>
          <div style="font-size: 12.5px; color: var(--text-muted); margin-top: 2px;">
            Client-by-client contract values, collected advance, monthly retainers, and partner splits.
          </div>
        </div>
        <a href="<?= BASE_URL ?>/saas-deals.php?business_id=<?= $business_id ?>" class="btn btn-primary btn-sm">
          Open Full Spreadsheet View &rarr;
        </a>
      </div>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
        <?php foreach (array_slice($client_deals, 0, 3) as $cd): 
          $c_rem = max(0.00, (float)$cd['total_amount'] - ((float)$cd['advance_amount'] + (float)$cd['client_paid_amount']));
        ?>
          <div style="background: var(--bg-hover); padding: 10px 14px; border-radius: 8px; border: 1px solid var(--border-color);">
            <div style="font-weight: 700; font-size: 13px; color: var(--text-main);"><?= htmlspecialchars($cd['client_name']) ?></div>
            <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($cd['software_type']) ?> &bull; Total: <?= format_money($cd['total_amount']) ?></div>
            <div style="font-size: 11.5px; margin-top: 4px; display:flex; justify-content:space-between;">
              <span style="color:var(--success-dark); font-weight:600;">Paid: <?= format_money($cd['advance_amount'] + $cd['client_paid_amount']) ?></span>
              <span style="color:#b45309; font-weight:600;">Rem: <?= format_money($c_rem) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Ownership Composition Bar -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">Current Ownership Distribution</div>
      <a href="?id=<?= $business_id ?>&tab=partners" class="btn btn-secondary btn-sm">Manage Shares</a>
    </div>
    <div style="display: flex; height: 18px; border-radius: 999px; overflow: hidden; margin-bottom: 14px; background: var(--bg-surface-subtle);">
      <?php 
      $colors = ['#2563eb', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4'];
      foreach ($participants as $idx => $p): 
        $c = $colors[$idx % count($colors)];
      ?>
        <div style="width: <?= $p['percentage'] ?>%; background-color: <?= $c ?>;" title="<?= htmlspecialchars($p['name']) ?>: <?= $p['percentage'] ?>%"></div>
      <?php endforeach; ?>
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: 16px;">
      <?php foreach ($participants as $idx => $p): 
        $c = $colors[$idx % count($colors)];
      ?>
        <div style="display: flex; align-items: center; gap: 8px; font-size: 13px;">
          <span style="width: 10px; height: 10px; border-radius: 50%; background: <?= $c ?>;"></span>
          <strong><?= htmlspecialchars($p['name']) ?>:</strong>
          <span><?= format_percentage($p['percentage']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

<!-- ==========================================
     TAB 2: PARTNERS & OWNERSHIP CONFIGURATION
     ========================================== -->
<?php elseif ($active_tab === 'partners'): ?>
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Configure Ownership Shares</div>
        <div class="card-subtitle">Every business can have different partners and ownership percentages. Total must equal 100.00%.</div>
      </div>
      <div>
        <a href="<?= BASE_URL ?>/partner-create.php" class="btn btn-secondary btn-sm">+ Register New Partner Profile</a>
      </div>
    </div>

    <form method="POST" action="">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_ownership">

      <div style="margin-bottom: 20px;">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Effective Date of This Ownership Batch <span class="req">*</span></label>
            <input type="date" name="effective_date" class="form-control" required value="<?= date('Y-m-d') ?>">
            <div class="form-text">Ownership changes are effective-dated. Historical finalized closings will never be altered.</div>
          </div>
          <div class="form-group">
            <label class="form-label">Reason / Notes for Modification</label>
            <input type="text" name="notes" class="form-control" placeholder="e.g. New partner joined, buy-out of equity, annual restructuring">
          </div>
        </div>
      </div>

      <div style="margin-bottom: 16px; font-weight: 700; font-size: 14px;">Participants & Share Percentages:</div>

      <div id="ownership-rows-container">
        <!-- 1. Owner Row (Always mandatory) -->
        <?php 
        $owner_pct = 100.0;
        foreach ($participants as $p) {
            if ($p['is_owner']) { $owner_pct = $p['percentage']; break; }
        }
        ?>
        <div class="card" style="padding: 14px; background: var(--primary-light); border-color: var(--primary-border); margin-bottom: 12px;">
          <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 10px;">
              <div class="stat-icon primary" style="width: 32px; height: 32px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </div>
              <div>
                <strong style="color: var(--primary); font-size: 14.5px;">You (Business Owner)</strong>
                <div style="font-size: 11.5px; color: var(--text-muted);">Required participant</div>
              </div>
            </div>
            <input type="hidden" name="ownership[0][partner_id]" value="owner">
            <div style="display: flex; align-items: center; gap: 8px;">
              <label style="font-size: 13px; font-weight: 600;">Share %:</label>
              <input type="number" step="0.01" min="0" max="100" name="ownership[0][percentage]" value="<?= number_format($owner_pct, 2, '.', '') ?>" class="form-control ownership-pct-input" style="width: 110px; font-weight: 700; text-align: right;" required>
            </div>
          </div>
        </div>

        <!-- 2. Existing Partner Rows -->
        <?php 
        $row_index = 1;
        foreach ($participants as $p): 
            if ($p['is_owner']) continue;
        ?>
          <div class="card partner-share-row" style="padding: 14px; margin-bottom: 12px;">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
              <div style="display: flex; align-items: center; gap: 10px;">
                <select name="ownership[<?= $row_index ?>][partner_id]" class="form-control" style="width: auto; min-width: 220px;" required>
                  <?php foreach ($all_partners as $part): ?>
                    <option value="<?= $part['id'] ?>" <?= $p['partner_id'] == $part['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($part['name']) ?> (<?= htmlspecialchars($part['phone'] ?? 'No phone') ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="display: flex; align-items: center; gap: 8px;">
                <label style="font-size: 13px; font-weight: 600;">Share %:</label>
                <input type="number" step="0.01" min="0" max="100" name="ownership[<?= $row_index ?>][percentage]" value="<?= number_format($p['percentage'], 2, '.', '') ?>" class="form-control ownership-pct-input" style="width: 110px; font-weight: 700; text-align: right;" required>
                <button type="button" class="btn btn-secondary btn-sm" onclick="this.closest('.partner-share-row').remove(); calculateTotalOwnership();" title="Remove partner">
                  &times;
                </button>
              </div>
            </div>
          </div>
        <?php 
        $row_index++;
        endforeach; 
        ?>
      </div>

      <!-- Add Partner to Business Slot -->
      <div style="margin-bottom: 20px;">
        <button type="button" class="btn btn-secondary btn-sm" id="add-partner-row-btn">
          + Add Another Partner to this Business
        </button>
      </div>

      <!-- Live Total Validation Bar (Section 6.3) -->
      <div class="card" style="background: var(--bg-surface-subtle); padding: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
        <div>
          <span style="font-weight: 700; font-size: 14px;">Total Ownership Sum:</span>
          <span id="ownership-total-pct" style="font-weight: 800; font-size: 18px; margin-left: 8px;">0.00%</span>
          <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;">
            Must equal exactly 100.00% to save.
          </div>
        </div>
        <button type="submit" id="ownership-submit-btn" class="btn btn-primary btn-lg">
          Save Ownership Structure
        </button>
      </div>
    </form>
  </div>

  <script>
  let partnerRowIndex = <?= $row_index ?>;
  const partnersOptionsHtml = `<?php foreach ($all_partners as $part): ?>
    <option value="<?= $part['id'] ?>"><?= htmlspecialchars(addslashes($part['name'])) ?> (<?= htmlspecialchars(addslashes($part['phone'] ?? 'No phone')) ?>)</option>
  <?php endforeach; ?>`;

  function calculateTotalOwnership() {
    let total = 0;
    document.querySelectorAll('.ownership-pct-input').forEach(input => {
      total += parseFloat(input.value) || 0;
    });
    const totalDisplay = document.getElementById('ownership-total-pct');
    const submitBtn = document.getElementById('ownership-submit-btn');
    if (totalDisplay) {
      totalDisplay.textContent = total.toFixed(2) + '%';
      if (Math.abs(total - 100.0) < 0.001) {
        totalDisplay.style.color = 'var(--success-dark)';
        if (submitBtn) submitBtn.disabled = false;
      } else {
        totalDisplay.style.color = 'var(--danger-dark)';
        if (submitBtn) submitBtn.disabled = true;
      }
    }
  }

  document.getElementById('add-partner-row-btn')?.addEventListener('click', () => {
    partnerRowIndex++;
    const container = document.getElementById('ownership-rows-container');
    const row = document.createElement('div');
    row.className = 'card partner-share-row';
    row.style.cssText = 'padding: 14px; margin-bottom: 12px;';
    row.innerHTML = `
      <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
        <div style="display: flex; align-items: center; gap: 10px;">
          <select name="ownership[${partnerRowIndex}][partner_id]" class="form-control" style="width: auto; min-width: 220px;" required>
            ${partnersOptionsHtml}
          </select>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
          <label style="font-size: 13px; font-weight: 600;">Share %:</label>
          <input type="number" step="0.01" min="0" max="100" name="ownership[${partnerRowIndex}][percentage]" value="0.00" class="form-control ownership-pct-input" style="width: 110px; font-weight: 700; text-align: right;" required>
          <button type="button" class="btn btn-secondary btn-sm" onclick="this.closest('.partner-share-row').remove(); calculateTotalOwnership();">&times;</button>
        </div>
      </div>
    `;
    container.appendChild(row);
    row.querySelector('.ownership-pct-input').addEventListener('input', calculateTotalOwnership);
    calculateTotalOwnership();
  });

  document.querySelectorAll('.ownership-pct-input').forEach(inp => inp.addEventListener('input', calculateTotalOwnership));
  calculateTotalOwnership();
  </script>

<!-- ==========================================
     TAB 3: REVENUE & INCOME
     ========================================== -->
<?php elseif ($active_tab === 'income'): ?>
  <div class="card" style="padding: 16px 20px;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
      <h3 style="font-size: 17px; font-weight: 700;">Business Revenue Transactions</h3>
      <a href="<?= BASE_URL ?>/business-income-create.php?business_id=<?= $business['id'] ?>" class="btn btn-primary btn-sm">
        + Record Revenue
      </a>
    </div>

    <?php if (empty($recent_incomes)): ?>
      <div class="empty-state">
        <div class="empty-state-title">No revenue entries recorded yet</div>
        <a href="<?= BASE_URL ?>/business-income-create.php?business_id=<?= $business['id'] ?>" class="btn btn-primary btn-sm">Add First Revenue</a>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table mobile-cards">
          <thead>
            <tr>
              <th>Title / Ref</th>
              <th>Category</th>
              <th>Month</th>
              <th>Date</th>
              <th>Account</th>
              <th style="text-align: right;">Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent_incomes as $inc): ?>
              <tr>
                <td data-label="Title">
                  <div style="font-weight: 700;"><?= htmlspecialchars($inc['title']) ?></div>
                  <?php if (!empty($inc['client_name'])): ?>
                    <div style="font-size: 11.5px; color: var(--primary); font-weight: 600;"><?= ($business['collection_model'] ?? '') === 'job' ? '🏢 Employer: ' : '👤 ' ?><?= htmlspecialchars($inc['client_name']) ?></div>
                  <?php endif; ?>
                  <div style="font-size: 11px; color: var(--text-muted);">
                    Ref: <?= htmlspecialchars($inc['reference_no'] ?? '-') ?>
                    <?php if (!empty($inc['payment_type'])): ?>
                      &bull; <span class="badge badge-neutral" style="font-size: 10px;"><?= ucfirst($inc['payment_type']) ?></span>
                    <?php endif; ?>
                  </div>
                </td>
                <td data-label="Category"><?= htmlspecialchars($inc['category_name'] ?? 'General') ?></td>
                <td data-label="Month"><span class="badge badge-neutral"><?= htmlspecialchars($inc['financial_month']) ?></span></td>
                <td data-label="Date"><?= format_date($inc['transaction_date']) ?></td>
                <td data-label="Account"><?= htmlspecialchars($inc['account_name'] ?? 'Unlinked') ?></td>
                <td data-label="Amount" style="text-align: right;" class="money-positive">+<?= format_money($inc['amount']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<!-- ==========================================
     TAB 4: EXPENSES
     ========================================== -->
<?php elseif ($active_tab === 'expenses'): ?>
  <div class="card" style="padding: 16px 20px;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
      <h3 style="font-size: 17px; font-weight: 700;">Business Expenses</h3>
      <a href="<?= BASE_URL ?>/business-expense-create.php?business_id=<?= $business['id'] ?>" class="btn btn-danger btn-sm">
        + Record Expense
      </a>
    </div>

    <?php if (empty($recent_expenses)): ?>
      <div class="empty-state">
        <div class="empty-state-title">No expense entries recorded yet</div>
        <a href="<?= BASE_URL ?>/business-expense-create.php?business_id=<?= $business['id'] ?>" class="btn btn-danger btn-sm">Add First Expense</a>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table mobile-cards">
          <thead>
            <tr>
              <th>Title / Ref</th>
              <th>Category</th>
              <th>Month</th>
              <th>Date</th>
              <th>Account</th>
              <th style="text-align: right;">Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent_expenses as $exp): ?>
              <tr>
                <td data-label="Title">
                  <strong><?= htmlspecialchars($exp['title']) ?></strong>
                  <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($exp['reference_no'] ?? '-') ?></div>
                </td>
                <td data-label="Category"><?= htmlspecialchars($exp['category_name'] ?? 'General') ?></td>
                <td data-label="Month"><span class="badge badge-neutral"><?= htmlspecialchars($exp['financial_month']) ?></span></td>
                <td data-label="Date"><?= format_date($exp['date']) ?></td>
                <td data-label="Account"><?= htmlspecialchars($exp['account_name'] ?? 'Unlinked') ?></td>
                <td data-label="Amount" style="text-align: right;" class="money-negative">-<?= format_money($exp['amount']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<!-- ==========================================
     TAB 5: MONTHLY PROFIT & CLOSING (Section 7.5)
     ========================================== -->
<?php elseif ($active_tab === 'closing'): ?>
  <div class="card">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
      <div>
        <h3 style="font-size: 18px; font-weight: 800;">Monthly Financial Closing Workflow</h3>
        <p style="font-size: 13px; color: var(--text-muted);">Review income, expenses, set reserves, verify shares, and finalize monthly lock.</p>
      </div>

      <!-- Month Selector -->
      <form method="GET" action="" style="display: flex; align-items: center; gap: 8px;">
        <input type="hidden" name="id" value="<?= $business_id ?>">
        <input type="hidden" name="tab" value="closing">
        <input type="month" name="month" value="<?= htmlspecialchars($selected_month) ?>" class="form-control" style="width: auto;" onchange="this.form.submit()">
      </form>
    </div>

    <!-- Financial Metrics for Month -->
    <div class="metrics-grid" style="margin-bottom: 20px;">
      <div class="stat-card">
        <span class="stat-label">Total Revenue</span>
        <div class="stat-value money-positive"><?= format_money($summary['total_revenue']) ?></div>
      </div>
      <div class="stat-card">
        <span class="stat-label">Total Expenses</span>
        <div class="stat-value money-negative"><?= format_money($summary['total_expenses']) ?></div>
      </div>
      <div class="stat-card">
        <span class="stat-label">Net Profit</span>
        <div class="stat-value" style="color: <?= $summary['net_profit'] >= 0 ? 'var(--text-main)' : 'var(--danger-dark)' ?>;">
          <?= format_money($summary['net_profit']) ?>
        </div>
      </div>
      <div class="stat-card">
        <span class="stat-label">Distributable Profit</span>
        <div class="stat-value" style="color: var(--primary);">
          <?= format_money($summary['distributable_profit']) ?>
        </div>
      </div>
    </div>

    <!-- Profit Distribution Breakdown Table -->
    <div style="margin-bottom: 24px;">
      <div style="font-weight: 700; font-size: 15px; margin-bottom: 10px;">Participant Share Distributions (Month: <?= $selected_month ?>)</div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Participant</th>
              <th>Ownership %</th>
              <th style="text-align: right;">Calculated Distributable Share</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $dist_sum = 0.00;
            foreach ($summary['distributions'] as $d): 
                $dist_sum += $d['share_amount'];
            ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($d['name']) ?></strong>
                  <?php if ($d['is_owner']): ?>
                    <span class="badge badge-primary" style="margin-left: 6px;">Owner</span>
                  <?php endif; ?>
                </td>
                <td><span class="badge badge-neutral"><?= format_percentage($d['percentage']) ?></span></td>
                <td style="text-align: right; font-weight: 700;" class="money-positive">
                  <?= format_money($d['share_amount']) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="background: var(--bg-surface-subtle); font-weight: 800;">
              <td>Total Distributed</td>
              <td>100.00%</td>
              <td style="text-align: right; color: var(--primary); font-size: 15px;"><?= format_money($dist_sum) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- Finalization or Status Banner -->
    <?php if ($summary['status'] === 'finalized'): ?>
      <div class="alert alert-success" style="align-items: flex-start; flex-direction: column; gap: 8px;">
        <div style="font-weight: 700; font-size: 15px;">🔒 Financial Month Finalized & Locked</div>
        <div style="font-size: 13px;">
          This month was finalized on <?= format_date($summary['finalized_at'], 'M d, Y H:i') ?>. Figures and historical distributions are locked against accidental changes.
        </div>
        <div style="margin-top: 8px;">
          <a href="<?= BASE_URL ?>/monthly-closing.php?business_id=<?= $business_id ?>&month=<?= $selected_month ?>&action=reopen_confirm" class="btn btn-secondary btn-sm" style="background:#fff;">
            Reopen Month Workflow (Authorized Adjustment)
          </a>
        </div>
      </div>
    <?php else: ?>
      <div class="card" style="background: var(--bg-surface-subtle); border-color: var(--border-strong);">
        <div style="font-weight: 700; font-size: 15px; margin-bottom: 8px;">Ready to finalize this month?</div>
        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">
          Finalizing locks revenue, expenses, and posts profit shares to partner ledgers and your owner receivable account.
        </p>
        <form method="POST" action="<?= BASE_URL ?>/monthly-closing.php" onsubmit="return confirm('Finalize month <?= $selected_month ?>? This will lock calculations and credit partner earnings.');">
          <?= csrf_field() ?>
          <input type="hidden" name="business_id" value="<?= $business_id ?>">
          <input type="hidden" name="financial_month" value="<?= $selected_month ?>">
          <input type="hidden" name="action" value="finalize">

          <div class="form-grid" style="margin-bottom: 14px;">
            <div class="form-group" style="margin-bottom: 0;">
              <label class="form-label">Retained Business Reserve (Optional)</label>
              <input type="number" step="0.01" min="0" name="business_reserve" class="form-control" value="0.00" placeholder="0.00">
              <div class="form-text">Amount held back in business bank account before profit distribution.</div>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
              <label class="form-label">Closing Notes / Summary</label>
              <input type="text" name="notes" class="form-control" placeholder="e.g. October standard closing finalized">
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-lg">
            Finalize & Lock Monthly Closing
          </button>
        </form>
      </div>
    <?php endif; ?>
  </div>

<!-- ==========================================
     TAB 6: PARTNER EARNINGS LEDGER (Section 8.1)
     ========================================== -->
<?php elseif ($active_tab === 'ledger'): ?>
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Partner Earnings & Outstanding Balances</div>
        <div class="card-subtitle">Month-by-month opening, earned, paid, and closing balances for each partner</div>
      </div>
      <a href="<?= BASE_URL ?>/partner-payment.php?business_id=<?= $business_id ?>" class="btn btn-primary btn-sm">
        Record Partner Payment
      </a>
    </div>

    <?php if (empty($partner_ledger_rows)): ?>
      <div class="empty-state">
        <div class="empty-state-title">No partner earnings posted yet</div>
        <div class="empty-state-text">Partner earnings are created automatically when you finalize a monthly financial closing.</div>
        <a href="?id=<?= $business_id ?>&tab=closing" class="btn btn-secondary btn-sm">Go to Monthly Closing</a>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table mobile-cards">
          <thead>
            <tr>
              <th>Partner</th>
              <th>Month</th>
              <th style="text-align: right;">Opening Balance</th>
              <th style="text-align: right;">Earned Share</th>
              <th style="text-align: right;">Paid Amount</th>
              <th style="text-align: right;">Closing Balance Due</th>
              <th style="text-align: right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($partner_ledger_rows as $pl): ?>
              <tr>
                <td data-label="Partner">
                  <strong><?= htmlspecialchars($pl['partner_name']) ?></strong>
                  <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($pl['partner_phone'] ?? '') ?></div>
                </td>
                <td data-label="Month"><span class="badge badge-neutral"><?= htmlspecialchars($pl['financial_month']) ?></span></td>
                <td data-label="Opening" style="text-align: right;"><?= format_money($pl['opening_balance']) ?></td>
                <td data-label="Earned" style="text-align: right;" class="money-positive">+<?= format_money($pl['earned_share']) ?></td>
                <td data-label="Paid" style="text-align: right;" class="money-negative">-<?= format_money($pl['paid_amount']) ?></td>
                <td data-label="Closing Due" style="text-align: right; font-weight: 700; color: <?= $pl['closing_balance'] > 0 ? 'var(--warning-dark)' : 'var(--text-muted)' ?>;">
                  <?= format_money($pl['closing_balance']) ?>
                </td>
                <td data-label="Action" style="text-align: right;">
                  <a href="<?= BASE_URL ?>/partner-payment.php?business_id=<?= $business_id ?>&partner_id=<?= $pl['partner_id'] ?>&month=<?= $pl['financial_month'] ?>" class="btn btn-secondary btn-sm">
                    Settle / Pay
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<!-- ==========================================
     TAB 7: PARTNER PAYMENTS
     ========================================== -->
<?php elseif ($active_tab === 'payments'): ?>
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Settlement Payments to Partners</div>
        <div class="card-subtitle">Historical records of partner payouts from business accounts</div>
      </div>
      <a href="<?= BASE_URL ?>/partner-payment.php?business_id=<?= $business_id ?>" class="btn btn-primary btn-sm">
        + Record Payment
      </a>
    </div>

    <?php if (empty($partner_payments)): ?>
      <div class="empty-state">
        <div class="empty-state-title">No partner payments recorded yet</div>
        <a href="<?= BASE_URL ?>/partner-payment.php?business_id=<?= $business_id ?>" class="btn btn-primary btn-sm">Record First Payment</a>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table mobile-cards">
          <thead>
            <tr>
              <th>Partner</th>
              <th>Ref / Method</th>
              <th>Date</th>
              <th>Period</th>
              <th>Account</th>
              <th style="text-align: right;">Amount Paid</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($partner_payments as $pay): ?>
              <tr>
                <td data-label="Partner"><strong><?= htmlspecialchars($pay['partner_name']) ?></strong></td>
                <td data-label="Ref">
                  <div><?= htmlspecialchars($pay['reference_no'] ?? '-') ?></div>
                  <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($pay['payment_method']) ?></div>
                </td>
                <td data-label="Date"><?= format_date($pay['payment_date']) ?></td>
                <td data-label="Period"><span class="badge badge-neutral"><?= htmlspecialchars($pay['financial_month']) ?></span></td>
                <td data-label="Account"><?= htmlspecialchars($pay['account_name'] ?? 'Unlinked') ?></td>
                <td data-label="Amount" style="text-align: right; font-weight: 700;" class="money-negative">-<?= format_money($pay['amount']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<!-- ==========================================
     TAB 8: OWNERSHIP HISTORY (Section 6.4)
     ========================================== -->
<?php elseif ($active_tab === 'history'): ?>
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Ownership Revision History</div>
        <div class="card-subtitle">Complete chronological record of all equity and share percentage modifications</div>
      </div>
    </div>

    <?php if (empty($ownership_history)): ?>
      <div class="empty-state">
        <div class="empty-state-title">No ownership revisions recorded</div>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Effective Date</th>
              <th>Participant</th>
              <th>Percentage</th>
              <th>Notes / Reason</th>
              <th>Timestamp</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ownership_history as $h): ?>
              <tr>
                <td><strong><?= format_date($h['effective_date']) ?></strong></td>
                <td>
                  <?php if (is_null($h['partner_id'])): ?>
                    <strong style="color: var(--primary);">You (Owner)</strong>
                  <?php else: ?>
                    <?= htmlspecialchars($h['partner_name'] ?? 'Partner #' . $h['partner_id']) ?>
                  <?php endif; ?>
                </td>
                <td><span class="badge badge-primary"><?= format_percentage($h['percentage']) ?></span></td>
                <td><?= htmlspecialchars($h['notes'] ?? '-') ?></td>
                <td style="color: var(--text-muted); font-size: 12px;"><?= format_date($h['created_at'], 'M d, Y H:i') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
