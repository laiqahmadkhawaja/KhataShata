<?php
/**
 * KhataShata - Software & Client Deals Breakdown (SaaS Hub)
 * Matches user's exact deal-level Google Sheet model
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$page_title = 'Software Deals & Client Breakdown';
$active_nav = 'saas_deals';

$db = get_db_connection();
$business_id = (int)($_GET['business_id'] ?? 1); // Defaults to SaaS (ID 1)
$status_filter = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');

// Fetch businesses for filter
$biz_stmt = $db->prepare("SELECT id, name FROM businesses WHERE user_id = :uid AND is_deleted = 0 ORDER BY id ASC");
$biz_stmt->execute(['uid' => $user['id']]);
$businesses = $biz_stmt->fetchAll();

// Build query for deals
$sql = "
    SELECT cd.*, b.name as business_name, p.name as partner_name
    FROM client_deals cd
    JOIN businesses b ON cd.business_id = b.id
    LEFT JOIN partners p ON cd.partner_id = p.id
    WHERE cd.user_id = :uid AND cd.is_deleted = 0
";
$params = ['uid' => $user['id']];

if ($business_id > 0) {
    $sql .= " AND cd.business_id = :bid";
    $params['bid'] = $business_id;
}
if (!empty($status_filter)) {
    $sql .= " AND cd.status = :status";
    $params['status'] = $status_filter;
}
if (!empty($search)) {
    $sql .= " AND (cd.client_name LIKE :s OR cd.software_type LIKE :s OR cd.notes LIKE :s)";
    $params['s'] = "%$search%";
}

$sql .= " ORDER BY cd.id ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$raw_deals = $stmt->fetchAll();

// Calculate deal metrics & row values
$deals = [];
$total_one_time = 0.00;
$total_advance = 0.00;
$total_client_paid = 0.00;
$total_client_collected = 0.00;
$total_client_remaining = 0.00;
$total_monthly = 0.00;

$total_partner_cut = 0.00;
$total_partner_paid = 0.00;
$total_partner_remaining = 0.00;

$total_mine_cut = 0.00;
$total_mine_in_hand = 0.00;
$total_mine_remaining = 0.00;

foreach ($raw_deals as $d) {
    $t_amount = (float)$d['total_amount'];
    $adv = (float)$d['advance_amount'];
    $c_paid = (float)$d['client_paid_amount'];
    $c_collected = $adv + $c_paid;
    $c_rem = max(0.00, round($t_amount - $c_collected, 2));
    $m_amount = (float)$d['monthly_amount'];

    // Partner Cut
    $p_pct = (float)$d['partner_cut_pct'];
    $p_cut = round($t_amount * ($p_pct / 100), 2);
    $p_paid = (float)$d['partner_paid_amount'];
    $p_rem = max(0.00, round($p_cut - $p_paid, 2));

    // Mine (Owner) Cut
    $m_pct = 100.0 - $p_pct;
    $m_cut = round($t_amount * ($m_pct / 100), 2);
    // Money kept in owner's hand from collected client payments minus payouts to partner
    $m_in_hand = max(0.00, round($c_collected - $p_paid, 2));
    $m_rem = max(0.00, round($m_cut - $m_in_hand, 2));

    $d['collected_from_client'] = $c_collected;
    $d['client_remaining'] = $c_rem;
    $d['partner_cut_amount'] = $p_cut;
    $d['partner_remaining'] = $p_rem;
    $d['mine_cut_pct'] = $m_pct;
    $d['mine_cut_amount'] = $m_cut;
    $d['mine_in_hand'] = $m_in_hand;
    $d['mine_remaining'] = $m_rem;

    $deals[] = $d;

    // Accumulate totals
    $total_one_time += $t_amount;
    $total_advance += $adv;
    $total_client_paid += $c_paid;
    $total_client_collected += $c_collected;
    $total_client_remaining += $c_rem;
    $total_monthly += $m_amount;

    $total_partner_cut += $p_cut;
    $total_partner_paid += $p_paid;
    $total_partner_remaining += $p_rem;

    $total_mine_cut += $m_cut;
    $total_mine_in_hand += $m_in_hand;
    $total_mine_remaining += $m_rem;
}

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
  <div>
    <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main); letter-spacing: -0.02em;">
      📊 Software Details & Partner Split Breakdown
    </h2>
    <p style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">
      Direct client deal tracking: client installments, monthly subscriptions, and on-demand partner payouts.
    </p>
  </div>
  <div style="display: flex; gap: 8px;">
    <a href="<?= BASE_URL ?>/deal-create.php?business_id=<?= $business_id ?>" class="btn btn-primary">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      <span>+ Add New Client Deal</span>
    </a>
  </div>
</div>

<!-- TOP METRIC CARDS (Google Sheets Row 27 Summary) -->
<div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); margin-bottom: 22px;">
  <div class="stat-card" style="border-left: 4px solid var(--primary);">
    <div class="stat-label">Total Deals Value (One Time)</div>
    <div class="stat-value" style="color: var(--primary);"><?= format_money($total_one_time) ?></div>
    <div class="stat-change" style="color: var(--text-muted);"><?= count($deals) ?> Active Deals</div>
  </div>

  <div class="stat-card" style="border-left: 4px solid var(--success-dark);">
    <div class="stat-label">Collected from Clients</div>
    <div class="stat-value money-positive"><?= format_money($total_client_collected) ?></div>
    <div class="stat-change" style="color: var(--text-muted);">Advance: <?= format_money($total_advance) ?> &bull; Paid: <?= format_money($total_client_paid) ?></div>
  </div>

  <div class="stat-card" style="border-left: 4px solid #f59e0b;">
    <div class="stat-label">Pending Client Receivables</div>
    <div class="stat-value" style="color: #d97706;"><?= format_money($total_client_remaining) ?></div>
    <div class="stat-change" style="color: var(--text-muted);">Remaining to collect</div>
  </div>

  <div class="stat-card" style="border-left: 4px solid #6366f1;">
    <div class="stat-label">Monthly Subscriptions</div>
    <div class="stat-value" style="color: #4f46e5;"><?= format_money($total_monthly) ?> <span style="font-size: 13px; font-weight: normal; color: var(--text-muted);">/ mo</span></div>
    <div class="stat-change" style="color: var(--text-muted);">Recurring retainer inflow</div>
  </div>

  <div class="stat-card" style="border-left: 4px solid #8b5cf6;">
    <div class="stat-label">Partner's Cut (Total: <?= format_money($total_partner_cut) ?>)</div>
    <div class="stat-value" style="color: #7c3aed;"><?= format_money($total_partner_remaining) ?> <span style="font-size: 12px; font-weight: 500; color: var(--text-muted);">Due</span></div>
    <div class="stat-change" style="color: var(--text-muted);">Paid to Partner so far: <?= format_money($total_partner_paid) ?></div>
  </div>

  <div class="stat-card" style="border-left: 4px solid #10b981;">
    <div class="stat-label">Your Share (Total: <?= format_money($total_mine_cut) ?>)</div>
    <div class="stat-value money-positive"><?= format_money($total_mine_in_hand) ?> <span style="font-size: 12px; font-weight: 500; color: var(--text-muted);">In Hand</span></div>
    <div class="stat-change" style="color: var(--text-muted);">Remaining Due: <?= format_money($total_mine_remaining) ?></div>
  </div>
</div>

<!-- FILTER BAR -->
<div class="card" style="padding: 14px 18px; margin-bottom: 20px;">
  <form method="GET" action="" class="filter-bar" style="margin-bottom: 0;">
    <div class="filter-group" style="flex: 1; max-width: 240px;">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search client or software..." class="form-control">
    </div>

    <div class="filter-group">
      <select name="business_id" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <?php foreach ($businesses as $b): ?>
          <option value="<?= $b['id'] ?>" <?= $business_id == $b['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($b['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="status" class="form-control" style="width: auto;" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <option value="Active" <?= $status_filter === 'Active' ? 'selected' : '' ?>>Active</option>
        <option value="Completed" <?= $status_filter === 'Completed' ? 'selected' : '' ?>>Completed</option>
      </select>

      <a href="<?= BASE_URL ?>/saas-deals.php" class="btn btn-secondary btn-sm">Reset</a>
    </div>
  </form>
</div>

<!-- SPREADSHEET BREAKDOWN TABLE -->
<div class="card" style="padding: 0; overflow: hidden; border: 1px solid var(--border-color);">
  <div style="padding: 14px 18px; background: var(--bg-hover); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
    <div style="font-weight: 700; font-size: 14px; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
      <span>📑 Client Software Deals Matrix (Spreadsheet View)</span>
      <span class="badge badge-primary"><?= count($deals) ?> deals</span>
    </div>
    <div style="font-size: 12px; color: var(--text-muted);">
      Formula: Mine In Hand = (Advance + Paid) − Given to Partner
    </div>
  </div>

  <div class="table-responsive">
    <table class="table" style="margin-bottom: 0; font-size: 13px;">
      <thead>
        <tr style="background: #f8fafc; border-bottom: 2px solid var(--border-color);">
          <th style="min-width: 170px;">Client Name</th>
          <th>Software Type</th>
          <th style="text-align: right; background: rgba(59, 130, 246, 0.04);">Total (One Time)</th>
          <th style="text-align: right;">Advance</th>
          <th style="text-align: right;">Paid</th>
          <th style="text-align: right; background: rgba(245, 158, 11, 0.05); color: #b45309;">Remaining</th>
          <th style="text-align: right; color: #4f46e5;">Monthly</th>
          
          <!-- Partner Columns (Purple) -->
          <th style="text-align: right; background: rgba(139, 92, 246, 0.05); color: #6d28d9; border-left: 2px solid #e2e8f0;">Partner Cut</th>
          <th style="text-align: right; background: rgba(139, 92, 246, 0.05); color: #6d28d9;">Paid to Partner</th>
          <th style="text-align: right; background: rgba(139, 92, 246, 0.05); color: #6d28d9;">Remaining</th>

          <!-- Mine Columns (Green) -->
          <th style="text-align: right; background: rgba(16, 185, 129, 0.05); color: #047857; border-left: 2px solid #e2e8f0;">Mine Cut</th>
          <th style="text-align: right; background: rgba(16, 185, 129, 0.05); color: #047857;">In Hand</th>
          <th style="text-align: right; background: rgba(16, 185, 129, 0.05); color: #047857;">Remaining</th>
          
          <th style="text-align: center; min-width: 150px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($deals)): ?>
          <tr>
            <td colspan="14" style="text-align: center; padding: 32px; color: var(--text-muted);">
              No client software deals recorded yet. Click <strong>+ Add New Client Deal</strong> to add one.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($deals as $d): ?>
            <tr>
              <!-- Client Name -->
              <td>
                <div style="font-weight: 700; color: var(--text-main); font-size: 13.5px;">
                  <?= htmlspecialchars($d['client_name']) ?>
                </div>
                <div style="font-size: 11px; color: var(--text-muted);">
                  Status: <span class="badge <?= $d['status'] === 'Completed' ? 'badge-success' : 'badge-primary' ?>" style="font-size: 10px; padding: 1px 5px;"><?= $d['status'] ?></span>
                  <?php if (!empty($d['start_date'])): ?> &bull; <?= date('M Y', strtotime($d['start_date'])) ?><?php endif; ?>
                </div>
              </td>

              <!-- Software Type -->
              <td>
                <span class="badge badge-neutral" style="font-weight: 600; font-size: 11.5px;">
                  <?= htmlspecialchars($d['software_type']) ?>
                </span>
              </td>

              <!-- Total Amount -->
              <td style="text-align: right; font-weight: 700; background: rgba(59, 130, 246, 0.04);">
                <?= format_money($d['total_amount']) ?>
              </td>

              <!-- Advance -->
              <td style="text-align: right; color: var(--success-dark); font-weight: 600;">
                <?= format_money($d['advance_amount']) ?>
              </td>

              <!-- Paid by Client -->
              <td style="text-align: right; color: var(--success-dark); font-weight: 600;">
                <?= format_money($d['client_paid_amount']) ?>
              </td>

              <!-- Client Remaining -->
              <td style="text-align: right; font-weight: 700; background: rgba(245, 158, 11, 0.05); color: <?= $d['client_remaining'] > 0 ? '#b45309' : 'var(--text-muted)' ?>;">
                <?= format_money($d['client_remaining']) ?>
              </td>

              <!-- Monthly Retainer -->
              <td style="text-align: right; font-weight: 600; color: #4f46e5;">
                <?= $d['monthly_amount'] > 0 ? format_money($d['monthly_amount']) : '-' ?>
              </td>

              <!-- Partner Cut (40%) -->
              <td style="text-align: right; background: rgba(139, 92, 246, 0.05); font-weight: 600; color: #6d28d9; border-left: 2px solid #e2e8f0;">
                <?= format_money($d['partner_cut_amount']) ?>
                <div style="font-size: 10px; color: var(--text-muted);"><?= (float)$d['partner_cut_pct'] ?>% (<?= htmlspecialchars($d['partner_name'] ?? 'Partner') ?>)</div>
              </td>

              <!-- Paid to Partner -->
              <td style="text-align: right; background: rgba(139, 92, 246, 0.05); font-weight: 600; color: #7c3aed;">
                <?= format_money($d['partner_paid_amount']) ?>
              </td>

              <!-- Remaining to Partner -->
              <td style="text-align: right; background: rgba(139, 92, 246, 0.05); font-weight: 700; color: <?= $d['partner_remaining'] > 0 ? '#9333ea' : 'var(--text-muted)' ?>;">
                <?= format_money($d['partner_remaining']) ?>
              </td>

              <!-- Mine Cut (60%) -->
              <td style="text-align: right; background: rgba(16, 185, 129, 0.05); font-weight: 600; color: #047857; border-left: 2px solid #e2e8f0;">
                <?= format_money($d['mine_cut_amount']) ?>
                <div style="font-size: 10px; color: var(--text-muted);"><?= (float)$d['mine_cut_pct'] ?>% You</div>
              </td>

              <!-- In Hand (Paid to Me) -->
              <td style="text-align: right; background: rgba(16, 185, 129, 0.05); font-weight: 700; color: var(--success-dark);">
                <?= format_money($d['mine_in_hand']) ?>
              </td>

              <!-- Remaining Due to Me -->
              <td style="text-align: right; background: rgba(16, 185, 129, 0.05); font-weight: 700; color: <?= $d['mine_remaining'] > 0 ? '#059669' : 'var(--text-muted)' ?>;">
                <?= format_money($d['mine_remaining']) ?>
              </td>

              <!-- Quick Actions -->
              <td style="text-align: center; white-space: nowrap;">
                <div style="display: inline-flex; gap: 4px;">
                  <a href="<?= BASE_URL ?>/deal-payment.php?deal_id=<?= $d['id'] ?>" class="btn btn-success btn-xs" title="Record client payment (Advance / Milestone)">
                    + Recv
                  </a>
                  <a href="<?= BASE_URL ?>/deal-payout.php?deal_id=<?= $d['id'] ?>" class="btn btn-secondary btn-xs" style="background:#ede9fe; color:#6d28d9; border-color:#ddd6fe;" title="Pay Partner from collected money">
                    🤝 Pay
                  </a>
                  <a href="<?= BASE_URL ?>/deal-edit.php?id=<?= $d['id'] ?>" class="btn btn-secondary btn-xs" title="Edit Deal">
                    ✏️
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>

      <!-- TOTALS FOOTER (ROW 27 EXACT MATCH) -->
      <tfoot>
        <tr style="background: #f1f5f9; font-weight: 800; font-size: 13.5px; border-top: 2px solid #cbd5e1;">
          <td colspan="2" style="text-transform: uppercase; color: var(--text-main); padding: 12px 14px;">
            Total Sums (مجموعہ)
          </td>
          <td style="text-align: right; background: rgba(59, 130, 246, 0.08); color: var(--primary);">
            <?= format_money($total_one_time) ?>
          </td>
          <td style="text-align: right; color: var(--success-dark);">
            <?= format_money($total_advance) ?>
          </td>
          <td style="text-align: right; color: var(--success-dark);">
            <?= format_money($total_client_paid) ?>
          </td>
          <td style="text-align: right; background: rgba(245, 158, 11, 0.1); color: #b45309;">
            <?= format_money($total_client_remaining) ?>
          </td>
          <td style="text-align: right; color: #4f46e5;">
            <?= format_money($total_monthly) ?>
          </td>

          <!-- Partner Totals -->
          <td style="text-align: right; background: rgba(139, 92, 246, 0.1); color: #6d28d9; border-left: 2px solid #cbd5e1;">
            <?= format_money($total_partner_cut) ?>
          </td>
          <td style="text-align: right; background: rgba(139, 92, 246, 0.1); color: #7c3aed;">
            <?= format_money($total_partner_paid) ?>
          </td>
          <td style="text-align: right; background: rgba(139, 92, 246, 0.1); color: #9333ea;">
            <?= format_money($total_partner_remaining) ?>
          </td>

          <!-- Mine Totals -->
          <td style="text-align: right; background: rgba(16, 185, 129, 0.1); color: #047857; border-left: 2px solid #cbd5e1;">
            <?= format_money($total_mine_cut) ?>
          </td>
          <td style="text-align: right; background: rgba(16, 185, 129, 0.1); color: var(--success-dark);">
            <?= format_money($total_mine_in_hand) ?>
          </td>
          <td style="text-align: right; background: rgba(16, 185, 129, 0.1); color: #059669;">
            <?= format_money($total_mine_remaining) ?>
          </td>

          <td style="text-align: center; color: var(--text-muted); font-size: 11px;">
            100% Balanced
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
