<?php
/**
 * KhataShata - Monthly Financial Closing Workflow & Reopening
 * Enforces financial closing review, locks, snapshot distributions,
 * and dedicated confirmation for authorized reopening (No modals).
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$error = '';
$db = get_db_connection();

$business_id = (int)($_GET['business_id'] ?? 0);
$financial_month = $_GET['month'] ?? date('Y-m');
$action = $_GET['action'] ?? '';

// Handle Finalization POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'finalize') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } else {
        $b_id = (int)$_POST['business_id'];
        $f_month = trim($_POST['financial_month']);
        $reserve = (float)($_POST['business_reserve'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        $res = FinancialEngine::finalizeMonthlyClosing($b_id, $f_month, $reserve, $notes, $user['id']);
        if ($res['success']) {
            flash_set('success', "Financial month $f_month finalized and locked successfully!");
            redirect("monthly-closing.php?business_id=$b_id&month=$f_month");
        } else {
            $error = $res['error'];
        }
    }
}

// Handle Reopen POST (Dedicated page workflow)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reopen_confirmed') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } else {
        $closing_id = (int)$_POST['closing_id'];
        $reason = trim($_POST['reopen_reason'] ?? '');

        if (empty($reason)) {
            $error = 'You must provide a valid audit reason to reopen a finalized financial month.';
        } else {
            $res = FinancialEngine::reopenMonthlyClosing($closing_id, $reason, $user['id']);
            if ($res['success']) {
                flash_set('warning', 'Financial month has been unlocked for authorized corrections.');
                redirect("monthly-closing.php?business_id=$business_id&month=$financial_month");
            } else {
                $error = $res['error'];
            }
        }
    }
}

// Businesses
$stmt = $db->prepare("SELECT id, name FROM businesses WHERE user_id = :uid AND is_deleted = 0 ORDER BY name ASC");
$stmt->execute(['uid' => $user['id']]);
$businesses = $stmt->fetchAll();

if ($business_id === 0 && !empty($businesses)) {
    $business_id = $businesses[0]['id'];
}

// Current business summary
$summary = null;
$participants = [];
$validation = ['valid' => true];
if ($business_id > 0) {
    $summary = FinancialEngine::getMonthlySummary($business_id, $financial_month);
    $participants = FinancialEngine::getEffectiveOwnership($business_id, $financial_month);
    $validation = FinancialEngine::validateOwnership($participants);
}

// List of all monthly closings for history
$c_stmt = $db->prepare("
    SELECT mc.*, b.name as business_name, u.name as closed_by_name
    FROM monthly_closings mc
    JOIN businesses b ON mc.business_id = b.id
    LEFT JOIN users u ON mc.finalized_by = u.id
    WHERE mc.user_id = :uid
    ORDER BY mc.financial_month DESC, b.name ASC
");
$c_stmt->execute(['uid' => $user['id']]);
$all_closings = $c_stmt->fetchAll();

$page_title = 'Monthly Financial Closing';
$active_nav = 'monthly_closing';

require_once ROOT_PATH . '/includes/header.php';
?>

<!-- DEDICATED REOPEN CONFIRMATION SCREEN (NO MODALS) -->
<?php if ($action === 'reopen_confirm' && $summary && $summary['closing_id']): ?>
  <div style="max-width: 680px; margin: 0 auto;">
    <div class="card" style="border-color: var(--warning-border); background: var(--bg-surface);">
      <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
        <div class="stat-icon warning" style="width:40px; height:40px;">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <div>
          <h3 style="font-size: 18px; font-weight: 800; color: var(--text-main);">Confirm Reopening Financial Month</h3>
          <div style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars($financial_month) ?> for Business ID #<?= $business_id ?></div>
        </div>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <p style="font-size: 13.5px; line-height: 1.6; margin-bottom: 16px;">
        Reopening a finalized financial month is a sensitive audit event. Once reopened, income and expense entries can be amended before re-finalization. Every modification will be permanently logged in the audit trail.
      </p>

      <form method="POST" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reopen_confirmed">
        <input type="hidden" name="closing_id" value="<?= $summary['closing_id'] ?>">

        <div class="form-group">
          <label class="form-label">Reason for Reopening <span class="req">*</span></label>
          <textarea name="reopen_reason" rows="3" class="form-control" placeholder="Specify why this closed month is being reopened (e.g. Correcting misplaced vendor invoice, unrecorded client receipt)..." required autofocus></textarea>
        </div>

        <div style="display: flex; gap: 12px; margin-top: 20px;">
          <button type="submit" class="btn btn-warning btn-lg" style="flex: 1;">
            Authorize & Reopen Month
          </button>
          <a href="monthly-closing.php?business_id=<?= $business_id ?>&month=<?= $financial_month ?>" class="btn btn-secondary btn-lg">
            Cancel
          </a>
        </div>
      </form>
    </div>
  </div>

<?php else: ?>
  <!-- Business & Month Selection Bar -->
  <div class="card" style="padding: 16px 20px; margin-bottom: 20px;">
    <form method="GET" action="" class="filter-bar" style="margin-bottom: 0;">
      <div class="filter-group">
        <span style="font-weight: 700; font-size: 13px;">Business:</span>
        <select name="business_id" class="form-control" style="width: auto; min-width: 220px;" onchange="this.form.submit()">
          <?php foreach ($businesses as $b): ?>
            <option value="<?= $b['id'] ?>" <?= $business_id == $b['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($b['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="filter-group">
        <span style="font-weight: 700; font-size: 13px;">Month:</span>
        <input type="month" name="month" value="<?= htmlspecialchars($financial_month) ?>" class="form-control" style="width: auto;" onchange="this.form.submit()">
      </div>

      <div style="display:flex; gap:8px;">
        <button type="submit" class="btn btn-secondary btn-sm">Load Month</button>
        <?php if ($business_id > 0): ?>
          <a href="<?= BASE_URL ?>/business-detail.php?id=<?= $business_id ?>" class="btn btn-secondary btn-sm">View Business Hub</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($summary): ?>
    <!-- 8-Step Review & Closing Workflow Container -->
    <div class="card" style="margin-bottom: 24px;">
      <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
        <div>
          <h3 style="font-size: 19px; font-weight: 800; color: var(--text-main);">
            Monthly Closing: <?= date('F Y', strtotime($financial_month . '-01')) ?>
          </h3>
          <p style="font-size: 13px; color: var(--text-muted);">
            8-Step audit checklist to calculate net profit, verify 100% ownership, and lock distributions.
          </p>
        </div>
        <div>
          <span class="badge <?= $summary['status'] === 'finalized' ? 'badge-success' : 'badge-warning' ?>" style="font-size: 14px; padding: 6px 14px;">
            Status: <?= strtoupper($summary['status']) ?>
          </span>
        </div>
      </div>

      <!-- Financial Metrics Summary for This Month -->
      <div class="metrics-grid" style="margin-bottom: 20px;">
        <div class="stat-card">
          <span class="stat-label">1. Total Revenue</span>
          <div class="stat-value money-positive"><?= format_money($summary['total_revenue']) ?></div>
          <div class="stat-sub"><a href="business-income.php?business_id=<?= $business_id ?>&month=<?= $financial_month ?>">Review income items &rarr;</a></div>
        </div>

        <div class="stat-card">
          <span class="stat-label">2. Total Expenses</span>
          <div class="stat-value money-negative"><?= format_money($summary['total_expenses']) ?></div>
          <div class="stat-sub"><a href="business-expense.php?business_id=<?= $business_id ?>&month=<?= $financial_month ?>">Review expense items &rarr;</a></div>
        </div>

        <div class="stat-card">
          <span class="stat-label">3. Net Profit</span>
          <div class="stat-value" style="color: <?= $summary['net_profit'] >= 0 ? 'var(--text-main)' : 'var(--danger-dark)' ?>;">
            <?= format_money($summary['net_profit']) ?>
          </div>
          <div class="stat-sub">Revenue minus Expenses</div>
        </div>

        <div class="stat-card">
          <span class="stat-label">4. Distributable Profit</span>
          <div class="stat-value" style="color: var(--primary);">
            <?= format_money($summary['distributable_profit']) ?>
          </div>
          <div class="stat-sub">After business reserve deductions</div>
        </div>
      </div>

      <!-- Ownership Verification Notice -->
      <?php if (!$validation['valid']): ?>
        <div class="alert alert-danger">
          ⚠️ <strong>Ownership Configuration Error:</strong> <?= htmlspecialchars($validation['error']) ?>
          <a href="business-detail.php?id=<?= $business_id ?>&tab=partners" class="btn btn-secondary btn-sm" style="margin-left: 10px;">Fix Ownership</a>
        </div>
      <?php endif; ?>

      <!-- Distribution Table -->
      <div style="margin-bottom: 24px;">
        <div style="font-weight: 700; font-size: 15px; margin-bottom: 10px;">Calculated Participant Profit Shares:</div>
        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Participant</th>
                <th>Role</th>
                <th>Ownership %</th>
                <th style="text-align: right;">Distributable Profit Share</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $dist_sum = 0.00;
              foreach ($summary['distributions'] as $d): 
                  $dist_sum += $d['share_amount'];
              ?>
                <tr>
                  <td><strong><?= htmlspecialchars($d['name']) ?></strong></td>
                  <td>
                    <?php if ($d['is_owner']): ?>
                      <span class="badge badge-primary">Owner</span>
                    <?php else: ?>
                      <span class="badge badge-neutral">Partner</span>
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
                <td colspan="2">Total Reconciled Distribution</td>
                <td>100.00%</td>
                <td style="text-align: right; color: var(--primary); font-size: 15px;"><?= format_money($dist_sum) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Finalization or Locked Actions -->
      <?php if ($summary['status'] === 'finalized'): ?>
        <div class="alert alert-success" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
          <div>
            <strong>🔒 Monthly Closing Locked & Finalized:</strong> Figures and partner ledger postings are protected against modification.
          </div>
          <div>
            <a href="monthly-closing.php?business_id=<?= $business_id ?>&month=<?= $financial_month ?>&action=reopen_confirm" class="btn btn-secondary btn-sm" style="background: #fff;">
              Reopen Month (Authorized Adjustment)
            </a>
          </div>
        </div>
      <?php else: ?>
        <div class="card" style="background: var(--bg-surface-subtle); border-color: var(--border-strong);">
          <div style="font-weight: 700; font-size: 15px; margin-bottom: 8px;">Finalize Financial Closing for <?= $financial_month ?></div>
          <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">
            By finalizing, you lock historical figures, create immutable profit distributions, and post earnings to each partner's ledger.
          </p>

          <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="finalize">
            <input type="hidden" name="business_id" value="<?= $business_id ?>">
            <input type="hidden" name="financial_month" value="<?= $financial_month ?>">

            <div class="form-grid" style="margin-bottom: 14px;">
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Retained Business Reserve (Optional)</label>
                <input type="number" step="0.01" min="0" name="business_reserve" class="form-control" value="<?= htmlspecialchars($summary['business_reserve']) ?>" placeholder="0.00">
                <div class="form-text">Deducted from net profit before calculating distributions.</div>
              </div>

              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Closing Audit Notes</label>
                <input type="text" name="notes" class="form-control" value="<?= htmlspecialchars($summary['notes'] ?? '') ?>" placeholder="e.g. Month reviewed and approved">
              </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg" <?= !$validation['valid'] ? 'disabled' : '' ?>>
              Finalize & Lock Monthly Closing
            </button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Historical Closings Table Across All Businesses -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">All Historical Monthly Closings</div>
        <div class="card-subtitle">Chronological record of closed financial months</div>
      </div>
    </div>

    <?php if (empty($all_closings)): ?>
      <div class="empty-state" style="padding: 24px;">
        <div class="empty-state-text">No finalized monthly closings recorded yet.</div>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table mobile-cards">
          <thead>
            <tr>
              <th>Business</th>
              <th>Month</th>
              <th style="text-align: right;">Revenue</th>
              <th style="text-align: right;">Expenses</th>
              <th style="text-align: right;">Net Profit</th>
              <th style="text-align: right;">Reserve</th>
              <th style="text-align: right;">Distributable</th>
              <th>Status</th>
              <th>Finalized At</th>
              <th style="text-align: right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($all_closings as $c): ?>
              <tr>
                <td data-label="Business"><strong><?= htmlspecialchars($c['business_name']) ?></strong></td>
                <td data-label="Month"><span class="badge badge-primary"><?= htmlspecialchars($c['financial_month']) ?></span></td>
                <td data-label="Revenue" style="text-align: right;" class="money-positive"><?= format_money($c['total_revenue']) ?></td>
                <td data-label="Expenses" style="text-align: right;" class="money-negative"><?= format_money($c['total_expenses']) ?></td>
                <td data-label="Net Profit" style="text-align: right; font-weight: 700;"><?= format_money($c['net_profit']) ?></td>
                <td data-label="Reserve" style="text-align: right;"><?= format_money($c['business_reserve']) ?></td>
                <td data-label="Distributable" style="text-align: right; font-weight: 700; color: var(--primary);"><?= format_money($c['distributable_profit']) ?></td>
                <td data-label="Status">
                  <span class="badge <?= $c['status'] === 'finalized' ? 'badge-success' : 'badge-warning' ?>">
                    <?= htmlspecialchars($c['status']) ?>
                  </span>
                </td>
                <td data-label="Finalized At" style="font-size: 12px; color: var(--text-muted);"><?= format_date($c['finalized_at'], 'M d, Y') ?></td>
                <td data-label="Action" style="text-align: right;">
                  <a href="monthly-closing.php?business_id=<?= $c['business_id'] ?>&month=<?= $c['financial_month'] ?>" class="btn btn-secondary btn-sm">
                    View
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
