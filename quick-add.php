<?php
/**
 * KhataShata - Dedicated Quick-Add Entry Hub
 * Central transaction launchpad without any popup modals.
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$page_title = 'Record New Transaction';
$active_nav = 'quick_add';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 900px; margin: 0 auto;">
  <div style="margin-bottom: 24px; text-align: center;">
    <h2 style="font-size: 24px; font-weight: 800; color: var(--text-main); letter-spacing: -0.02em;">Choose Transaction Type</h2>
    <p style="font-size: 13.5px; color: var(--text-muted); margin-top: 4px;">Select the financial operation you want to record with dedicated validation & ledger tracking.</p>
  </div>

  <!-- Business Financial Operations -->
  <div style="margin-bottom: 28px;">
    <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); margin-bottom: 12px;">
      🏢 Business Operations
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px;">
      <a href="<?= BASE_URL ?>/business-income-create.php" class="card" style="margin-bottom:0; display:flex; align-items:center; gap:16px; padding:18px; text-decoration:none;">
        <div class="stat-icon success" style="width:44px; height:44px; border-radius:12px;">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
        </div>
        <div>
          <div style="font-weight: 700; font-size: 15px; color: var(--text-main);">Business Revenue</div>
          <div style="font-size: 12px; color: var(--text-muted);">Record sales, client fees, contract income</div>
        </div>
      </a>

      <a href="<?= BASE_URL ?>/business-expense-create.php" class="card" style="margin-bottom:0; display:flex; align-items:center; gap:16px; padding:18px; text-decoration:none;">
        <div class="stat-icon danger" style="width:44px; height:44px; border-radius:12px;">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
        </div>
        <div>
          <div style="font-weight: 700; font-size: 15px; color: var(--text-main);">Business Expense</div>
          <div style="font-size: 12px; color: var(--text-muted);">Salaries, office rent, tools, operations</div>
        </div>
      </a>

      <a href="<?= BASE_URL ?>/partner-payment.php" class="card" style="margin-bottom:0; display:flex; align-items:center; gap:16px; padding:18px; text-decoration:none;">
        <div class="stat-icon purple" style="width:44px; height:44px; border-radius:12px;">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div>
          <div style="font-weight: 700; font-size: 15px; color: var(--text-main);">Pay Business Partner</div>
          <div style="font-size: 12px; color: var(--text-muted);">Profit share payout & balance settlement</div>
        </div>
      </a>

      <a href="<?= BASE_URL ?>/owner-receipts.php?action=new" class="card" style="margin-bottom:0; display:flex; align-items:center; gap:16px; padding:18px; text-decoration:none;">
        <div class="stat-icon primary" style="width:44px; height:44px; border-radius:12px;">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div>
          <div style="font-weight: 700; font-size: 15px; color: var(--text-main);">Draw Owner Share</div>
          <div style="font-size: 12px; color: var(--text-muted);">Transfer business profit to personal wallet</div>
        </div>
      </a>
    </div>
  </div>

  <!-- Personal Finances -->
  <div style="margin-bottom: 28px;">
    <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); margin-bottom: 12px;">
      👤 Personal Finances
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px;">
      <a href="<?= BASE_URL ?>/personal-income-create.php" class="card" style="margin-bottom:0; display:flex; align-items:center; gap:16px; padding:18px; text-decoration:none;">
        <div class="stat-icon success" style="width:44px; height:44px; border-radius:12px;">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/></svg>
        </div>
        <div>
          <div style="font-weight: 700; font-size: 15px; color: var(--text-main);">Personal Income</div>
          <div style="font-size: 12px; color: var(--text-muted);">Salary, freelancing, consultancy, rent</div>
        </div>
      </a>

      <a href="<?= BASE_URL ?>/personal-expense-create.php" class="card" style="margin-bottom:0; display:flex; align-items:center; gap:16px; padding:18px; text-decoration:none;">
        <div class="stat-icon danger" style="width:44px; height:44px; border-radius:12px;">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div>
          <div style="font-weight: 700; font-size: 15px; color: var(--text-main);">Personal Expense</div>
          <div style="font-size: 12px; color: var(--text-muted);">Household, groceries, utilities, food</div>
        </div>
      </a>

      <a href="<?= BASE_URL ?>/account-transfer.php" class="card" style="margin-bottom:0; display:flex; align-items:center; gap:16px; padding:18px; text-decoration:none;">
        <div class="stat-icon info" style="width:44px; height:44px; border-radius:12px;">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
        </div>
        <div>
          <div style="font-weight: 700; font-size: 15px; color: var(--text-main);">Account Transfer</div>
          <div style="font-size: 12px; color: var(--text-muted);">Move money between Cash, Banks & JazzCash</div>
        </div>
      </a>

      <a href="<?= BASE_URL ?>/loan-create.php" class="card" style="margin-bottom:0; display:flex; align-items:center; gap:16px; padding:18px; text-decoration:none;">
        <div class="stat-icon warning" style="width:44px; height:44px; border-radius:12px;">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 9V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><rect x="9" y="9" width="12" height="10" rx="2"/><line x1="15" y1="14" x2="15.01" y2="14"/></svg>
        </div>
        <div>
          <div style="font-weight: 700; font-size: 15px; color: var(--text-main);">Loan (Lent or Borrowed)</div>
          <div style="font-size: 12px; color: var(--text-muted);">Track personal receivables and liabilities</div>
        </div>
      </a>
    </div>
  </div>

  <div style="text-align: center; margin-top: 10px;">
    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
  </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
