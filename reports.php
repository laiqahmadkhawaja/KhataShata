<?php
/**
 * KhataShata - Comprehensive Financial Reports & Analytics
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$page_title = 'Financial Reports & Statements';
$active_nav = 'reports';

$db = get_db_connection();
$report_type = $_GET['report'] ?? 'business_pl';
$business_id = (int)($_GET['business_id'] ?? 0);
$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? '';
$is_export_csv = isset($_GET['export']) && $_GET['export'] === 'csv';

// Businesses for filters
$stmt = $db->prepare("SELECT id, name FROM businesses WHERE user_id = :uid AND is_deleted = 0 ORDER BY name ASC");
$stmt->execute(['uid' => $user['id']]);
$businesses = $stmt->fetchAll();

// -------------------------------------------------------------
// REPORT 1: BUSINESS PROFIT & LOSS STATEMENT
// -------------------------------------------------------------
$pl_data = [];
if ($report_type === 'business_pl') {
    $sql_rev = "
        SELECT ic.name as cat_name, COALESCE(SUM(bi.amount), 0.00) as total
        FROM business_income bi
        LEFT JOIN income_categories ic ON bi.category_id = ic.id
        WHERE bi.user_id = :uid AND bi.is_deleted = 0
    ";
    $params_rev = ['uid' => $user['id']];
    if ($business_id > 0) {
        $sql_rev .= " AND bi.business_id = :bid";
        $params_rev['bid'] = $business_id;
    }
    if (!empty($month)) {
        $sql_rev .= " AND bi.financial_month = :month";
        $params_rev['month'] = $month;
    } else {
        $sql_rev .= " AND bi.financial_month LIKE :yr";
        $params_rev['yr'] = "$year-%";
    }
    $sql_rev .= " GROUP BY ic.name ORDER BY total DESC";
    $stmt = $db->prepare($sql_rev);
    $stmt->execute($params_rev);
    $pl_data['revenue_items'] = $stmt->fetchAll();

    $sql_exp = "
        SELECT ec.name as cat_name, COALESCE(SUM(be.amount), 0.00) as total
        FROM business_expenses be
        LEFT JOIN expense_categories ec ON be.category_id = ec.id
        WHERE be.user_id = :uid AND be.is_deleted = 0
    ";
    $params_exp = ['uid' => $user['id']];
    if ($business_id > 0) {
        $sql_exp .= " AND be.business_id = :bid";
        $params_exp['bid'] = $business_id;
    }
    if (!empty($month)) {
        $sql_exp .= " AND be.financial_month = :month";
        $params_exp['month'] = $month;
    } else {
        $sql_exp .= " AND be.financial_month LIKE :yr";
        $params_exp['yr'] = "$year-%";
    }
    $sql_exp .= " GROUP BY ec.name ORDER BY total DESC";
    $stmt = $db->prepare($sql_exp);
    $stmt->execute($params_exp);
    $pl_data['expense_items'] = $stmt->fetchAll();

    $pl_data['total_rev'] = array_sum(array_column($pl_data['revenue_items'], 'total'));
    $pl_data['total_exp'] = array_sum(array_column($pl_data['expense_items'], 'total'));
    $pl_data['net_profit'] = round($pl_data['total_rev'] - $pl_data['total_exp'], 2);
}

// -------------------------------------------------------------
// REPORT 2: BUSINESS COMPARISON
// -------------------------------------------------------------
$comparison_data = [];
if ($report_type === 'business_comparison') {
    foreach ($businesses as $b) {
        $bid = $b['id'];
        $rev_stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM business_income WHERE business_id = :bid AND financial_month LIKE :yr AND is_deleted = 0");
        $rev_stmt->execute(['bid' => $bid, 'yr' => "$year-%"]);
        $b_rev = (float)$rev_stmt->fetchColumn();

        $exp_stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM business_expenses WHERE business_id = :bid AND financial_month LIKE :yr AND is_deleted = 0");
        $exp_stmt->execute(['bid' => $bid, 'yr' => "$year-%"]);
        $b_exp = (float)$exp_stmt->fetchColumn();

        $oe_stmt = $db->prepare("SELECT COALESCE(SUM(share_amount), 0.00) FROM profit_distributions WHERE business_id = :bid AND financial_month LIKE :yr AND partner_id IS NULL");
        $oe_stmt->execute(['bid' => $bid, 'yr' => "$year-%"]);
        $b_owner_earned = (float)$oe_stmt->fetchColumn();

        $comparison_data[] = [
            'id' => $bid,
            'name' => $b['name'],
            'revenue' => $b_rev,
            'expenses' => $b_exp,
            'net_profit' => round($b_rev - $b_exp, 2),
            'owner_earned' => $b_owner_earned,
            'margin' => $b_rev > 0 ? round((($b_rev - $b_exp) / $b_rev) * 100, 1) : 0.0
        ];
    }
}

// -------------------------------------------------------------
// REPORT 3: MONTHLY SAVINGS & CASH FLOW REPORT
// -------------------------------------------------------------
$savings_data = [];
if ($report_type === 'monthly_savings') {
    for ($m = 1; $m <= 12; $m++) {
        $m_str = sprintf("%s-%02d", $year, $m);
        $s_date = "$m_str-01";
        $e_date = date('Y-m-t', strtotime($s_date));

        // Personal Salary/Income
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM personal_income WHERE user_id = :uid AND payment_status = 'received' AND income_date BETWEEN :s AND :e AND is_deleted = 0");
        $stmt->execute(['uid' => $user['id'], 's' => $s_date, 'e' => $e_date]);
        $p_inc = (float)$stmt->fetchColumn();

        // Owner Business Share Drawn (Received Cash)
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM owner_share_receipts WHERE user_id = :uid AND receipt_date BETWEEN :s AND :e AND is_deleted = 0");
        $stmt->execute(['uid' => $user['id'], 's' => $s_date, 'e' => $e_date]);
        $b_drawn = (float)$stmt->fetchColumn();

        // Personal Expenses
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM personal_expenses WHERE user_id = :uid AND expense_date BETWEEN :s AND :e AND is_deleted = 0");
        $stmt->execute(['uid' => $user['id'], 's' => $s_date, 'e' => $e_date]);
        $p_exp = (float)$stmt->fetchColumn();

        $total_inflows = round($p_inc + $b_drawn, 2);
        $net_savings = round($total_inflows - $p_exp, 2);
        $rate = $total_inflows > 0 ? round(($net_savings / $total_inflows) * 100, 1) : 0.0;

        $savings_data[] = [
            'month' => date('F Y', strtotime($s_date)),
            'personal_income' => $p_inc,
            'business_share_drawn' => $b_drawn,
            'total_inflows' => $total_inflows,
            'personal_expenses' => $p_exp,
            'net_savings' => $net_savings,
            'savings_rate' => $rate
        ];
    }
}

// -------------------------------------------------------------
// CSV Export Handler
// -------------------------------------------------------------
if ($is_export_csv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=report_' . $report_type . '_' . date('Ymd') . '.csv');
    $output = fopen('php://output', 'w');

    if ($report_type === 'business_pl') {
        fputcsv($output, ['Business Profit & Loss Statement', "Year: $year", "Month: " . ($month ?: 'All')]);
        fputcsv($output, []);
        fputcsv($output, ['REVENUE CATEGORY', 'AMOUNT']);
        foreach ($pl_data['revenue_items'] as $item) {
            fputcsv($output, [$item['cat_name'] ?: 'General Revenue', $item['total']]);
        }
        fputcsv($output, ['TOTAL REVENUE', $pl_data['total_rev']]);
        fputcsv($output, []);
        fputcsv($output, ['EXPENSE CATEGORY', 'AMOUNT']);
        foreach ($pl_data['expense_items'] as $item) {
            fputcsv($output, [$item['cat_name'] ?: 'General Expense', $item['total']]);
        }
        fputcsv($output, ['TOTAL EXPENSES', $pl_data['total_exp']]);
        fputcsv($output, ['NET PROFIT', $pl_data['net_profit']]);
    } elseif ($report_type === 'business_comparison') {
        fputcsv($output, ['Business Name', 'Revenue', 'Expenses', 'Net Profit', 'Your Share Earned', 'Profit Margin %']);
        foreach ($comparison_data as $row) {
            fputcsv($output, [$row['name'], $row['revenue'], $row['expenses'], $row['net_profit'], $row['owner_earned'], $row['margin'] . '%']);
        }
    } elseif ($report_type === 'monthly_savings') {
        fputcsv($output, ['Month', 'Salary/Personal Income', 'Business Share Drawn', 'Total Inflows', 'Personal Expenses', 'Net Savings', 'Savings Rate %']);
        foreach ($savings_data as $row) {
            fputcsv($output, [$row['month'], $row['personal_income'], $row['business_share_drawn'], $row['total_inflows'], $row['personal_expenses'], $row['net_savings'], $row['savings_rate'] . '%']);
        }
    }
    fclose($output);
    exit;
}

require_once ROOT_PATH . '/includes/header.php';
?>

<!-- Report Selector & Filters -->
<div class="card" style="padding: 18px 20px; margin-bottom: 20px;">
  <form method="GET" action="" class="filter-bar" style="margin-bottom: 0;">
    <div class="filter-group">
      <span style="font-weight: 700; font-size: 13px;">Report Type:</span>
      <select name="report" class="form-control" style="width: auto; font-weight: 600;" onchange="this.form.submit()">
        <option value="business_pl" <?= $report_type === 'business_pl' ? 'selected' : '' ?>>Business Profit & Loss (P&L)</option>
        <option value="business_comparison" <?= $report_type === 'business_comparison' ? 'selected' : '' ?>>Business Comparison Matrix</option>
        <option value="monthly_savings" <?= $report_type === 'monthly_savings' ? 'selected' : '' ?>>Monthly Personal Savings & Cash Flow</option>
      </select>
    </div>

    <div class="filter-group">
      <?php if ($report_type === 'business_pl'): ?>
        <select name="business_id" class="form-control" style="width: auto;" onchange="this.form.submit()">
          <option value="0">All Businesses Consolidated</option>
          <?php foreach ($businesses as $b): ?>
            <option value="<?= $b['id'] ?>" <?= $business_id == $b['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($b['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <input type="month" name="month" value="<?= htmlspecialchars($month) ?>" class="form-control" style="width: auto;" placeholder="Specific Month" onchange="this.form.submit()">
      <?php endif; ?>

      <select name="year" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <?php for ($y = date('Y'); $y >= date('Y') - 4; $y--): ?>
          <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>

      <a href="?report=<?= $report_type ?>" class="btn btn-secondary btn-sm">Reset</a>
    </div>

    <div style="display: flex; gap: 8px;">
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-secondary btn-sm">
        Export CSV
      </a>
      <button type="button" onclick="window.print()" class="btn btn-primary btn-sm">
        Print / PDF
      </button>
    </div>
  </form>
</div>

<!-- ==========================================
     REPORT 1 VIEW: BUSINESS P&L
     ========================================== -->
<?php if ($report_type === 'business_pl'): ?>
  <div class="card">
    <div style="text-align: center; margin-bottom: 24px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 16px;">
      <h2 style="font-size: 20px; font-weight: 800; color: var(--text-main);"><?= APP_NAME ?></h2>
      <div style="font-size: 16px; font-weight: 700; color: var(--primary); margin-top: 2px;">
        Profit & Loss Statement (P&L)
      </div>
      <div style="font-size: 12.5px; color: var(--text-muted); margin-top: 4px;">
        Scope: <strong><?= $business_id > 0 ? htmlspecialchars($businesses[array_search($business_id, array_column($businesses, 'id'))]['name'] ?? 'Business') : 'Consolidated Portfolio' ?></strong> &bull;
        Period: <strong><?= !empty($month) ? date('F Y', strtotime($month . '-01')) : "Year $year" ?></strong>
      </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 24px;">
      <!-- Revenue Breakdown -->
      <div>
        <div style="font-size: 15px; font-weight: 700; color: var(--success-dark); margin-bottom: 12px; border-bottom: 2px solid var(--success-border); padding-bottom: 6px;">
          Operating Revenue
        </div>
        <table class="data-table" style="margin-bottom: 14px;">
          <tbody>
            <?php if (empty($pl_data['revenue_items'])): ?>
              <tr><td style="color: var(--text-muted);">No revenue recorded</td><td style="text-align: right;">-</td></tr>
            <?php else: ?>
              <?php foreach ($pl_data['revenue_items'] as $item): ?>
                <tr>
                  <td><?= htmlspecialchars($item['cat_name'] ?? 'General Revenue') ?></td>
                  <td style="text-align: right; font-weight: 600;" class="money-positive"><?= format_money($item['total']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr style="font-weight: 800; background: var(--bg-surface-subtle);">
              <td>Total Gross Revenue</td>
              <td style="text-align: right; color: var(--success-dark); font-size: 15px;"><?= format_money($pl_data['total_rev']) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Expenses Breakdown -->
      <div>
        <div style="font-size: 15px; font-weight: 700; color: var(--danger-dark); margin-bottom: 12px; border-bottom: 2px solid var(--danger-border); padding-bottom: 6px;">
          Operating Expenses
        </div>
        <table class="data-table" style="margin-bottom: 14px;">
          <tbody>
            <?php if (empty($pl_data['expense_items'])): ?>
              <tr><td style="color: var(--text-muted);">No expenses recorded</td><td style="text-align: right;">-</td></tr>
            <?php else: ?>
              <?php foreach ($pl_data['expense_items'] as $item): ?>
                <tr>
                  <td><?= htmlspecialchars($item['cat_name'] ?? 'General Expense') ?></td>
                  <td style="text-align: right; font-weight: 600;" class="money-negative"><?= format_money($item['total']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr style="font-weight: 800; background: var(--bg-surface-subtle);">
              <td>Total Operating Expenses</td>
              <td style="text-align: right; color: var(--danger-dark); font-size: 15px;"><?= format_money($pl_data['total_exp']) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- P&L Bottom Line -->
    <div class="card" style="margin-top: 24px; margin-bottom: 0; background: var(--bg-surface-subtle); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
      <div>
        <span style="font-size: 15px; font-weight: 700;">Net Profit / Loss for Period:</span>
        <div style="font-size: 12px; color: var(--text-muted);">Calculated as Total Revenue minus Operating Expenses</div>
      </div>
      <div style="font-size: 26px; font-weight: 800; color: <?= $pl_data['net_profit'] >= 0 ? 'var(--success-dark)' : 'var(--danger-dark)' ?>;">
        <?= format_money($pl_data['net_profit']) ?>
      </div>
    </div>
  </div>

<!-- ==========================================
     REPORT 2 VIEW: BUSINESS COMPARISON
     ========================================== -->
<?php elseif ($report_type === 'business_comparison'): ?>
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Business Performance Comparison Matrix (<?= $year ?>)</div>
        <div class="card-subtitle">Side-by-side comparison of revenue, expenses, net profit, and profit margins</div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="data-table mobile-cards">
        <thead>
          <tr>
            <th>Business Enterprise</th>
            <th style="text-align: right;">Revenue</th>
            <th style="text-align: right;">Expenses</th>
            <th style="text-align: right;">Net Profit</th>
            <th style="text-align: right;">Profit Margin</th>
            <th style="text-align: right;">Your Share Earned</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($comparison_data as $row): ?>
            <tr>
              <td data-label="Business">
                <a href="business-detail.php?id=<?= $row['id'] ?>" style="font-weight: 700; color: var(--text-main);">
                  <?= htmlspecialchars($row['name']) ?>
                </a>
              </td>
              <td data-label="Revenue" style="text-align: right;" class="money-positive"><?= format_money($row['revenue']) ?></td>
              <td data-label="Expenses" style="text-align: right;" class="money-negative"><?= format_money($row['expenses']) ?></td>
              <td data-label="Net Profit" style="text-align: right; font-weight: 700;" class="<?= $row['net_profit'] >= 0 ? 'money-positive' : 'money-negative' ?>">
                <?= format_money($row['net_profit']) ?>
              </td>
              <td data-label="Margin" style="text-align: right; font-weight: 600;">
                <span class="badge <?= $row['margin'] >= 20 ? 'badge-success' : 'badge-neutral' ?>">
                  <?= $row['margin'] ?>%
                </span>
              </td>
              <td data-label="Your Share" style="text-align: right; font-weight: 700; color: var(--primary);">
                <?= format_money($row['owner_earned']) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<!-- ==========================================
     REPORT 3 VIEW: MONTHLY SAVINGS
     ========================================== -->
<?php elseif ($report_type === 'monthly_savings'): ?>
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Personal Cash Flow & Savings Statement (<?= $year ?>)</div>
        <div class="card-subtitle">Personal Salary + Drawn Business Profits minus Personal Living Expenses</div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="data-table mobile-cards">
        <thead>
          <tr>
            <th>Month</th>
            <th style="text-align: right;">Personal Salary/Income</th>
            <th style="text-align: right;">Business Share Drawn</th>
            <th style="text-align: right;">Total Inflows</th>
            <th style="text-align: right;">Personal Expenses</th>
            <th style="text-align: right;">Net Savings</th>
            <th style="text-align: right;">Savings Rate</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $tot_pi = 0; $tot_bd = 0; $tot_in = 0; $tot_pe = 0; $tot_ns = 0;
          foreach ($savings_data as $row): 
            $tot_pi += $row['personal_income'];
            $tot_bd += $row['business_share_drawn'];
            $tot_in += $row['total_inflows'];
            $tot_pe += $row['personal_expenses'];
            $tot_ns += $row['net_savings'];
          ?>
            <tr>
              <td data-label="Month"><strong><?= $row['month'] ?></strong></td>
              <td data-label="Salary" style="text-align: right;"><?= format_money($row['personal_income']) ?></td>
              <td data-label="Business Drawn" style="text-align: right;"><?= format_money($row['business_share_drawn']) ?></td>
              <td data-label="Inflows" style="text-align: right; font-weight: 600;" class="money-positive"><?= format_money($row['total_inflows']) ?></td>
              <td data-label="Expenses" style="text-align: right; font-weight: 600;" class="money-negative"><?= format_money($row['personal_expenses']) ?></td>
              <td data-label="Net Savings" style="text-align: right; font-weight: 700;" class="<?= $row['net_savings'] >= 0 ? 'money-positive' : 'money-negative' ?>">
                <?= format_money($row['net_savings']) ?>
              </td>
              <td data-label="Savings Rate" style="text-align: right;">
                <span class="badge <?= $row['savings_rate'] >= 25 ? 'badge-success' : ($row['savings_rate'] > 0 ? 'badge-primary' : 'badge-danger') ?>">
                  <?= $row['savings_rate'] ?>%
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="background: var(--bg-surface-subtle); font-weight: 800;">
            <td>Full Year Total</td>
            <td style="text-align: right;"><?= format_money($tot_pi) ?></td>
            <td style="text-align: right;"><?= format_money($tot_bd) ?></td>
            <td style="text-align: right; color: var(--success-dark);"><?= format_money($tot_in) ?></td>
            <td style="text-align: right; color: var(--danger-dark);"><?= format_money($tot_pe) ?></td>
            <td style="text-align: right; font-size: 15px; color: <?= $tot_ns >= 0 ? 'var(--success-dark)' : 'var(--danger-dark)' ?>;"><?= format_money($tot_ns) ?></td>
            <td style="text-align: right;"><?= $tot_in > 0 ? round(($tot_ns / $tot_in) * 100, 1) : 0 ?>%</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
