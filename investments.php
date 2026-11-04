<?php
/**
 * KhataShata - Investment & Asset Management & Net Worth Calculator
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$page_title = 'Investments & Asset Portfolio';
$active_nav = 'investments';

$db = get_db_connection();

// 1. Investments
$stmt = $db->prepare("SELECT i.*, b.name as business_name FROM investments i LEFT JOIN businesses b ON i.business_id = b.id WHERE i.user_id = :uid AND i.is_deleted = 0 ORDER BY i.current_value DESC");
$stmt->execute(['uid' => $user['id']]);
$investments = $stmt->fetchAll();

$total_invested = 0.00;
$total_investment_val = 0.00;
foreach ($investments as $inv) {
    if ($inv['status'] === 'active') {
        $total_invested += (float)$inv['invested_amount'];
        $total_investment_val += (float)$inv['current_value'];
    }
}
$investment_roi = $total_invested > 0 ? (($total_investment_val - $total_invested) / $total_invested) * 100 : 0.0;

// 2. Physical Assets
$stmt = $db->prepare("SELECT * FROM assets WHERE user_id = :uid AND is_deleted = 0 ORDER BY current_estimated_value DESC");
$stmt->execute(['uid' => $user['id']]);
$assets = $stmt->fetchAll();

$total_assets_purchase = 0.00;
$total_assets_val = 0.00;
foreach ($assets as $ast) {
    $total_assets_purchase += (float)$ast['purchase_value'];
    $total_assets_val += (float)$ast['current_estimated_value'];
}

// 3. Liquid accounts
$stmt = $db->prepare("SELECT COALESCE(SUM(current_balance), 0.00) FROM accounts WHERE user_id = :uid AND status = 'active' AND is_deleted = 0");
$stmt->execute(['uid' => $user['id']]);
$liquid_cash = (float)$stmt->fetchColumn();

// 4. Loans given (Asset) & Borrowed (Liability)
$stmt = $db->prepare("SELECT COALESCE(SUM(current_balance), 0.00) FROM loans WHERE user_id = :uid AND loan_type = 'given' AND status = 'active' AND is_deleted = 0");
$stmt->execute(['uid' => $user['id']]);
$loans_given = (float)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COALESCE(SUM(current_balance), 0.00) FROM loans WHERE user_id = :uid AND loan_type = 'borrowed' AND status = 'active' AND is_deleted = 0");
$stmt->execute(['uid' => $user['id']]);
$loans_borrowed = (float)$stmt->fetchColumn();

// Total Assets & Net Worth
$total_gross_assets = round($liquid_cash + $total_investment_val + $total_assets_val + $loans_given, 2);
$total_liabilities = round($loans_borrowed, 2);
$net_worth = round($total_gross_assets - $total_liabilities, 2);

require_once ROOT_PATH . '/includes/header.php';
?>

<!-- Net Worth Scoreboard Banner -->
<div class="card" style="margin-bottom: 24px; padding: 24px; background: linear-gradient(135deg, var(--bg-surface), var(--primary-light)); border-color: var(--primary-border);">
  <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
    <div>
      <span style="font-size: 13px; font-weight: 700; color: var(--primary); text-transform: uppercase; letter-spacing: 0.05em;">Financial Balance Sheet & Net Worth</span>
      <div style="font-size: 32px; font-weight: 800; color: var(--text-main); margin-top: 4px;">
        <?= format_money($net_worth) ?>
      </div>
      <div style="font-size: 12.5px; color: var(--text-muted); margin-top: 2px;">
        Total Assets (<?= format_money($total_gross_assets) ?>) minus Total Liabilities (<?= format_money($total_liabilities) ?>)
      </div>
    </div>

    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
      <a href="<?= BASE_URL ?>/investment-create.php" class="btn btn-primary">
        + Add Investment
      </a>
      <a href="<?= BASE_URL ?>/asset-create.php" class="btn btn-secondary">
        + Add Physical Asset
      </a>
    </div>
  </div>
</div>

<!-- Balance Sheet Pillar Cards -->
<div class="metrics-grid">
  <div class="stat-card">
    <span class="stat-label">Liquid Cash & Accounts</span>
    <div class="stat-value" style="color: var(--primary);"><?= format_money($liquid_cash) ?></div>
    <div class="stat-sub">Bank, cash & wallets</div>
  </div>

  <div class="stat-card">
    <span class="stat-label">Investments Portfolio</span>
    <div class="stat-value money-positive"><?= format_money($total_investment_val) ?></div>
    <div class="stat-sub">Stocks, gold, funds, crypto</div>
  </div>

  <div class="stat-card">
    <span class="stat-label">Physical Assets Value</span>
    <div class="stat-value"><?= format_money($total_assets_val) ?></div>
    <div class="stat-sub">Vehicles, property, equipment</div>
  </div>

  <div class="stat-card">
    <span class="stat-label">Loans Given (Receivables)</span>
    <div class="stat-value money-positive"><?= format_money($loans_given) ?></div>
    <div class="stat-sub">Personal receivables due</div>
  </div>

  <div class="stat-card">
    <span class="stat-label">Liabilities & Debts</span>
    <div class="stat-value money-negative"><?= format_money($total_liabilities) ?></div>
    <div class="stat-sub">Borrowed loans outstanding</div>
  </div>
</div>

<!-- Section 1: Investments Portfolio -->
<div class="card" style="margin-bottom: 24px;">
  <div class="card-header">
    <div>
      <div class="card-title">Investments Portfolio</div>
      <div class="card-subtitle">Invested capital vs current estimated market value</div>
    </div>
    <a href="<?= BASE_URL ?>/investment-create.php" class="btn btn-primary btn-sm">+ New Investment</a>
  </div>

  <?php if (empty($investments)): ?>
    <div class="empty-state" style="padding: 24px;">
      <div class="empty-state-text">No investment records registered yet.</div>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="data-table mobile-cards">
        <thead>
          <tr>
            <th>Investment Name</th>
            <th>Category</th>
            <th>Date</th>
            <th style="text-align: right;">Invested Capital</th>
            <th style="text-align: right;">Current Market Value</th>
            <th style="text-align: right;">Gain / Loss</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($investments as $inv): 
            $gain = (float)$inv['current_value'] - (float)$inv['invested_amount'];
          ?>
            <tr>
              <td data-label="Name">
                <strong><?= htmlspecialchars($inv['name']) ?></strong>
                <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($inv['description'] ?? '') ?></div>
              </td>
              <td data-label="Category">
                <span class="badge badge-primary"><?= ucfirst(str_replace('_', ' ', $inv['category'])) ?></span>
              </td>
              <td data-label="Date"><?= format_date($inv['investment_date']) ?></td>
              <td data-label="Invested" style="text-align: right;"><?= format_money($inv['invested_amount']) ?></td>
              <td data-label="Current Value" style="text-align: right; font-weight: 700;"><?= format_money($inv['current_value']) ?></td>
              <td data-label="Gain/Loss" style="text-align: right; font-weight: 700;" class="<?= $gain >= 0 ? 'money-positive' : 'money-negative' ?>">
                <?= $gain >= 0 ? '+' : '' ?><?= format_money($gain) ?>
              </td>
              <td data-label="Status">
                <span class="badge <?= $inv['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>">
                  <?= ucfirst($inv['status']) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Section 2: Physical Assets -->
<div class="card">
  <div class="card-header">
    <div>
      <div class="card-title">Physical & Fixed Assets</div>
      <div class="card-subtitle">Equipment, vehicles, electronics, and property</div>
    </div>
    <a href="<?= BASE_URL ?>/asset-create.php" class="btn btn-secondary btn-sm">+ New Asset</a>
  </div>

  <?php if (empty($assets)): ?>
    <div class="empty-state" style="padding: 24px;">
      <div class="empty-state-text">No physical assets recorded.</div>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="data-table mobile-cards">
        <thead>
          <tr>
            <th>Asset Name</th>
            <th>Category</th>
            <th>Purchase Date</th>
            <th style="text-align: right;">Purchase Cost</th>
            <th style="text-align: right;">Estimated Current Value</th>
            <th>Remarks</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($assets as $ast): ?>
            <tr>
              <td data-label="Name"><strong><?= htmlspecialchars($ast['name']) ?></strong></td>
              <td data-label="Category">
                <span class="badge badge-neutral"><?= ucfirst($ast['category']) ?></span>
              </td>
              <td data-label="Date"><?= format_date($ast['purchase_date']) ?></td>
              <td data-label="Cost" style="text-align: right;"><?= format_money($ast['purchase_value']) ?></td>
              <td data-label="Current Value" style="text-align: right; font-weight: 700;" class="money-positive">
                <?= format_money($ast['current_estimated_value']) ?>
              </td>
              <td data-label="Remarks"><?= htmlspecialchars($ast['notes'] ?? '-') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
