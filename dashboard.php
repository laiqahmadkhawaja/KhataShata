<?php
/**
 * KhataShata - Executive Dashboard
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$page_title = 'Financial Dashboard';
$active_nav = 'dashboard';

// Filter Period
$period = $_GET['period'] ?? 'this_month';
$custom_from = $_GET['from'] ?? null;
$custom_to = $_GET['to'] ?? null;

$metrics = FinancialEngine::getDashboardMetrics($period, $custom_from, $custom_to, $user['id']);

$db = get_db_connection();

// Chart Data 1: Last 6 Months Income vs Expense
$monthly_trends = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $m_start = $m . '-01';
    $m_end = date('Y-m-t', strtotime($m_start));

    // Business Income + Personal Income
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM business_income WHERE user_id = :u AND financial_month = :m AND is_deleted = 0");
    $stmt->execute(['u' => $user['id'], 'm' => $m]);
    $b_inc = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM personal_income WHERE user_id = :u AND payment_status = 'received' AND income_date BETWEEN :s AND :e AND is_deleted = 0");
    $stmt->execute(['u' => $user['id'], 's' => $m_start, 'e' => $m_end]);
    $p_inc = (float)$stmt->fetchColumn();

    // Business Expense + Personal Expense
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM business_expenses WHERE user_id = :u AND financial_month = :m AND is_deleted = 0");
    $stmt->execute(['u' => $user['id'], 'm' => $m]);
    $b_exp = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM personal_expenses WHERE user_id = :u AND expense_date BETWEEN :s AND :e AND is_deleted = 0");
    $stmt->execute(['u' => $user['id'], 's' => $m_start, 'e' => $m_end]);
    $p_exp = (float)$stmt->fetchColumn();

    $monthly_trends[] = [
        'month' => date('M Y', strtotime($m_start)),
        'income' => $b_inc + $p_inc,
        'expense' => $b_exp + $p_exp,
        'savings' => ($b_inc + $p_inc) - ($b_exp + $p_exp)
    ];
}

// Chart Data 2: Net Profit by Business
$stmt = $db->prepare("
    SELECT b.name, COALESCE(SUM(bi.amount), 0) - COALESCE((SELECT SUM(be.amount) FROM business_expenses be WHERE be.business_id = b.id AND be.is_deleted = 0), 0) as profit
    FROM businesses b
    LEFT JOIN business_income bi ON b.id = bi.business_id AND bi.is_deleted = 0
    WHERE b.user_id = :u AND b.is_deleted = 0
    GROUP BY b.id
    ORDER BY profit DESC
    LIMIT 6
");
$stmt->execute(['u' => $user['id']]);
$business_profits = $stmt->fetchAll();

// Chart Data 3: Personal Expense Categories
$stmt = $db->prepare("
    SELECT ec.name, COALESCE(SUM(pe.amount), 0) as total
    FROM expense_categories ec
    JOIN personal_expenses pe ON ec.id = pe.category_id AND pe.is_deleted = 0
    WHERE ec.user_id = :u
    GROUP BY ec.id
    ORDER BY total DESC
    LIMIT 6
");
$stmt->execute(['u' => $user['id']]);
$expense_cats = $stmt->fetchAll();

// Recent Activities (Latest 8 items across all types)
$stmt = $db->prepare("
    (SELECT 'Business Income' as type, title, amount, transaction_date as date, 'in' as dir, 'business-income.php' as link FROM business_income WHERE user_id = :u1 AND is_deleted = 0)
    UNION ALL
    (SELECT 'Business Expense' as type, title, amount, date, 'out' as dir, 'business-expense.php' as link FROM business_expenses WHERE user_id = :u2 AND is_deleted = 0)
    UNION ALL
    (SELECT 'Personal Income' as type, title, amount, income_date as date, 'in' as dir, 'personal-income.php' as link FROM personal_income WHERE user_id = :u3 AND is_deleted = 0)
    UNION ALL
    (SELECT 'Personal Expense' as type, title, amount, expense_date as date, 'out' as dir, 'personal-expenses.php' as link FROM personal_expenses WHERE user_id = :u4 AND is_deleted = 0)
    UNION ALL
    (SELECT 'Partner Payout' as type, CONCAT('Partner payment: ', reference_no) as title, amount, payment_date as date, 'out' as dir, 'profit-distribution.php' as link FROM partner_payments WHERE user_id = :u5 AND is_deleted = 0)
    UNION ALL
    (SELECT 'Owner Receipt' as type, CONCAT('Owner share draw: ', reference_no) as title, amount, receipt_date as date, 'in' as dir, 'owner-receipts.php' as link FROM owner_share_receipts WHERE user_id = :u6 AND is_deleted = 0)
    ORDER BY date DESC, amount DESC
    LIMIT 8
");
$stmt->execute([
    'u1' => $user['id'],
    'u2' => $user['id'],
    'u3' => $user['id'],
    'u4' => $user['id'],
    'u5' => $user['id'],
    'u6' => $user['id']
]);
$recent_activities = $stmt->fetchAll();

require_once ROOT_PATH . '/includes/header.php';
?>

<!-- Period Filter Header -->
<div class="card" style="padding: 14px 18px; margin-bottom: 20px;">
  <form method="GET" action="" class="filter-bar" style="margin-bottom: 0;">
    <div class="filter-group">
      <span style="font-weight: 700; font-size: 13px; color: var(--text-main);">Financial Horizon:</span>
      <select name="period" class="form-control" style="width: auto; padding: 6px 12px;" onchange="if(this.value !== 'custom') this.form.submit(); else document.getElementById('custom-date-fields').style.display='flex';">
        <option value="this_month" <?= $period === 'this_month' ? 'selected' : '' ?>>This Month (<?= date('M Y') ?>)</option>
        <option value="previous_month" <?= $period === 'previous_month' ? 'selected' : '' ?>>Previous Month</option>
        <option value="last_3_months" <?= $period === 'last_3_months' ? 'selected' : '' ?>>Last 3 Months</option>
        <option value="last_6_months" <?= $period === 'last_6_months' ? 'selected' : '' ?>>Last 6 Months</option>
        <option value="this_year" <?= $period === 'this_year' ? 'selected' : '' ?>>This Year (<?= date('Y') ?>)</option>
        <option value="previous_year" <?= $period === 'previous_year' ? 'selected' : '' ?>>Previous Year</option>
        <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom Range</option>
      </select>
    </div>

    <div id="custom-date-fields" class="filter-group" style="display: <?= $period === 'custom' ? 'flex' : 'none' ?>;">
      <input type="date" name="from" value="<?= htmlspecialchars($custom_from ?? '') ?>" class="form-control" style="width: auto; padding: 6px 10px;">
      <span style="color: var(--text-muted);">to</span>
      <input type="date" name="to" value="<?= htmlspecialchars($custom_to ?? '') ?>" class="form-control" style="width: auto; padding: 6px 10px;">
      <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
    </div>

    <div style="font-size: 12px; color: var(--text-muted);">
      Range: <strong><?= format_date($metrics['start_date']) ?></strong> &mdash; <strong><?= format_date($metrics['end_date']) ?></strong>
    </div>
  </form>
</div>

<!-- Financial Overview Cards Grid (Section 4.1) -->
<div class="metrics-grid">
  <!-- Card 1: Total Business Net Profit -->
  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label">Business Net Profit</span>
      <div class="stat-icon <?= $metrics['bus_net_profit'] >= 0 ? 'success' : 'danger' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
      </div>
    </div>
    <div class="stat-value" style="color: <?= $metrics['bus_net_profit'] >= 0 ? 'var(--success-dark)' : 'var(--danger-dark)' ?>;">
      <?= format_money($metrics['bus_net_profit']) ?>
    </div>
    <div class="stat-sub">
      <span>Rev: <?= format_money($metrics['bus_revenue']) ?></span> &bull; 
      <span>Exp: <?= format_money($metrics['bus_expenses']) ?></span>
    </div>
  </div>

  <!-- Card 2: Your Business Share Earned -->
  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label">Your Share Earned</span>
      <div class="stat-icon primary">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/></svg>
      </div>
    </div>
    <div class="stat-value" style="color: var(--primary);">
      <?= format_money($metrics['owner_share_earned']) ?>
    </div>
    <div class="stat-sub">
      Finalized profit allocations
    </div>
  </div>

  <!-- Card 3: Business Share Received -->
  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label">Share Received In Cash</span>
      <div class="stat-icon success">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
      </div>
    </div>
    <div class="stat-value" style="color: var(--success-dark);">
      <?= format_money($metrics['owner_share_received']) ?>
    </div>
    <div class="stat-sub">
      Drawn into personal accounts
    </div>
  </div>

  <!-- Card 4: Pending Business Receivables -->
  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label">Pending Receivables</span>
      <div class="stat-icon warning">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </div>
    </div>
    <div class="stat-value" style="color: var(--warning-dark);">
      <?= format_money($metrics['pending_receivables']) ?>
    </div>
    <div class="stat-sub">
      Earned profit not yet drawn
    </div>
  </div>

  <!-- Card 5: Salary & Personal Income -->
  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label">Salary & Other Income</span>
      <div class="stat-icon success">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
      </div>
    </div>
    <div class="stat-value">
      <?= format_money($metrics['personal_income']) ?>
    </div>
    <div class="stat-sub">
      Personal salary, freelance, rent
    </div>
  </div>

  <!-- Card 6: Personal Expenses -->
  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label">Personal Expenses</span>
      <div class="stat-icon danger">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      </div>
    </div>
    <div class="stat-value" style="color: var(--danger-dark);">
      <?= format_money($metrics['personal_expenses']) ?>
    </div>
    <div class="stat-sub">
      Living expenses & bills
    </div>
  </div>

  <!-- Card 7: Personal Net Savings -->
  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label">Personal Net Savings</span>
      <div class="stat-icon <?= $metrics['personal_savings'] >= 0 ? 'success' : 'danger' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
      </div>
    </div>
    <div class="stat-value" style="color: <?= $metrics['personal_savings'] >= 0 ? 'var(--success-dark)' : 'var(--danger-dark)' ?>;">
      <?= format_money($metrics['personal_savings']) ?>
    </div>
    <div class="stat-sub">
      Personal inflows minus outflows
    </div>
  </div>

  <!-- Card 8: Total Cash & Bank Balance -->
  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label">Total Liquid Cash</span>
      <div class="stat-icon info">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
      </div>
    </div>
    <div class="stat-value" style="color: var(--primary);">
      <?= format_money($metrics['total_cash_balance']) ?>
    </div>
    <div class="stat-sub">
      Across cash, banks & wallets
    </div>
  </div>

  <!-- Card 9: Total Net Worth -->
  <div class="stat-card" style="background: linear-gradient(135deg, var(--bg-surface), var(--primary-light));">
    <div class="stat-header">
      <span class="stat-label" style="font-weight: 700; color: var(--primary);">Total Net Worth</span>
      <div class="stat-icon primary">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
      </div>
    </div>
    <div class="stat-value" style="color: var(--text-main); font-size: 24px;">
      <?= format_money($metrics['net_worth']) ?>
    </div>
    <div class="stat-sub">
      Assets + Cash + Investments - Debts
    </div>
  </div>
</div>

<!-- Charts Section -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 20px; margin-bottom: 24px;">
  <!-- Chart 1: Cash Flow & Trends -->
  <div class="card" style="margin-bottom: 0;">
    <div class="card-header">
      <div>
        <div class="card-title">Cash Flow & Monthly Inflow vs Outflow</div>
        <div class="card-subtitle">Last 6 months trend of income and expenses</div>
      </div>
    </div>
    <div style="height: 280px; position: relative;">
      <canvas id="cashFlowChart"></canvas>
    </div>
  </div>

  <!-- Chart 2: Business Net Profit Performance -->
  <div class="card" style="margin-bottom: 0;">
    <div class="card-header">
      <div>
        <div class="card-title">Business Profitability</div>
        <div class="card-subtitle">Cumulative net profit contribution by business</div>
      </div>
    </div>
    <div style="height: 280px; position: relative;">
      <canvas id="businessProfitChart"></canvas>
    </div>
  </div>
</div>

<!-- Secondary Analytics & Recent Activities -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 20px;">
  <!-- Expense Distribution Breakdown -->
  <div class="card" style="margin-bottom: 0;">
    <div class="card-header">
      <div>
        <div class="card-title">Personal Expense Categories</div>
        <div class="card-subtitle">Where your personal money is going</div>
      </div>
    </div>
    <div style="height: 260px; position: relative;">
      <canvas id="expenseCategoryChart"></canvas>
    </div>
  </div>

  <!-- Recent Financial Activities -->
  <div class="card" style="margin-bottom: 0;">
    <div class="card-header">
      <div>
        <div class="card-title">Recent Transactions</div>
        <div class="card-subtitle">Latest recorded entries across all modules</div>
      </div>
      <a href="<?= BASE_URL ?>/transactions.php" class="btn btn-secondary btn-sm">View All</a>
    </div>

    <?php if (empty($recent_activities)): ?>
      <div class="empty-state" style="padding: 24px 12px;">
        <div class="empty-state-text">No transactions recorded yet. Tap "New Entry" to add your first revenue or expense!</div>
        <a href="<?= BASE_URL ?>/quick-add.php" class="btn btn-primary btn-sm">Record Transaction</a>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>Description</th>
              <th>Date</th>
              <th style="text-align: right;">Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent_activities as $act): ?>
              <tr>
                <td>
                  <span class="badge <?= $act['dir'] === 'in' ? 'badge-success' : 'badge-danger' ?>">
                    <?= htmlspecialchars($act['type']) ?>
                  </span>
                </td>
                <td>
                  <a href="<?= BASE_URL ?>/<?= htmlspecialchars($act['link']) ?>" style="font-weight: 600; color: var(--text-main);">
                    <?= htmlspecialchars($act['title']) ?>
                  </a>
                </td>
                <td style="color: var(--text-muted); font-size: 12px;">
                  <?= format_date($act['date']) ?>
                </td>
                <td style="text-align: right;" class="<?= $act['dir'] === 'in' ? 'money-positive' : 'money-negative' ?>">
                  <?= $act['dir'] === 'in' ? '+' : '-' ?><?= format_money($act['amount']) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Chart.js Render Scripts -->
<script>
document.addEventListener('DOMContentLoaded', () => {
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const textColor = isDark ? '#94a3b8' : '#64748b';
  const gridColor = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';

  // Chart 1: Cash Flow
  const cfCtx = document.getElementById('cashFlowChart');
  if (cfCtx) {
    const monthlyLabels = <?= json_encode(array_column($monthly_trends, 'month')) ?>;
    const incomeData = <?= json_encode(array_column($monthly_trends, 'income')) ?>;
    const expenseData = <?= json_encode(array_column($monthly_trends, 'expense')) ?>;

    new Chart(cfCtx, {
      type: 'bar',
      data: {
        labels: monthlyLabels,
        datasets: [
          {
            label: 'Total Inflow',
            data: incomeData,
            backgroundColor: '#10b981',
            borderRadius: 6
          },
          {
            label: 'Total Outflow',
            data: expenseData,
            backgroundColor: '#ef4444',
            borderRadius: 6
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { labels: { color: textColor } }
        },
        scales: {
          x: { grid: { color: gridColor }, ticks: { color: textColor } },
          y: { grid: { color: gridColor }, ticks: { color: textColor } }
        }
      }
    });
  }

  // Chart 2: Business Profit
  const bpCtx = document.getElementById('businessProfitChart');
  if (bpCtx) {
    const bpLabels = <?= json_encode(array_column($business_profits, 'name')) ?>;
    const bpData = <?= json_encode(array_column($business_profits, 'profit')) ?>;

    new Chart(bpCtx, {
      type: 'bar',
      data: {
        labels: bpLabels.length > 0 ? bpLabels : ['No Business Data Yet'],
        datasets: [{
          label: 'Net Profit',
          data: bpData.length > 0 ? bpData : [0],
          backgroundColor: '#2563eb',
          borderRadius: 6
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false }
        },
        scales: {
          x: { grid: { color: gridColor }, ticks: { color: textColor } },
          y: { grid: { color: gridColor }, ticks: { color: textColor } }
        }
      }
    });
  }

  // Chart 3: Expense Categories
  const ecCtx = document.getElementById('expenseCategoryChart');
  if (ecCtx) {
    const ecLabels = <?= json_encode(array_column($expense_cats, 'name')) ?>;
    const ecData = <?= json_encode(array_column($expense_cats, 'total')) ?>;

    new Chart(ecCtx, {
      type: 'doughnut',
      data: {
        labels: ecLabels.length > 0 ? ecLabels : ['No Expenses Yet'],
        datasets: [{
          data: ecData.length > 0 ? ecData : [1],
          backgroundColor: ['#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4'],
          borderWidth: 2,
          borderColor: isDark ? '#111827' : '#ffffff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { color: textColor, boxWidth: 12 } }
        }
      }
    });
  }
});
</script>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
