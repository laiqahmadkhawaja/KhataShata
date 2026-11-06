<?php
/**
 * KhataShata - Sidebar Component
 */
$active_nav = $active_nav ?? 'dashboard';
?>
<aside class="app-sidebar">
  <div class="sidebar-brand">
    <div class="sidebar-logo-icon">KS</div>
    <div>
      <div class="sidebar-brand-name"><?= APP_NAME ?></div>
      <div class="sidebar-brand-tag">Portfolio & Financials</div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="sidebar-nav-title">Main</div>
    <a href="<?= BASE_URL ?>/dashboard.php" class="sidebar-link <?= $active_nav === 'dashboard' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      <span>Dashboard</span>
    </a>

    <div class="sidebar-nav-title">Business Portfolio</div>
    <a href="<?= BASE_URL ?>/businesses.php" class="sidebar-link <?= $active_nav === 'businesses' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M9 8h1"/><path d="M9 12h1"/><path d="M9 16h1"/><path d="M14 8h1"/><path d="M14 12h1"/><path d="M14 16h1"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg>
      <span>Businesses</span>
    </a>
    <a href="<?= BASE_URL ?>/saas-deals.php" class="sidebar-link <?= $active_nav === 'saas_deals' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      <span>Software Deals & SaaS</span>
      <span class="badge badge-success" style="font-size: 9px; margin-left: auto; padding: 1px 5px;">New</span>
    </a>
    <a href="<?= BASE_URL ?>/partners.php" class="sidebar-link <?= $active_nav === 'partners' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <span>Partners</span>
    </a>
    <a href="<?= BASE_URL ?>/business-income.php" class="sidebar-link <?= $active_nav === 'business_income' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
      <span>Business Income</span>
    </a>
    <a href="<?= BASE_URL ?>/business-expense.php" class="sidebar-link <?= $active_nav === 'business_expense' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
      <span>Business Expenses</span>
    </a>
    <a href="<?= BASE_URL ?>/monthly-closing.php" class="sidebar-link <?= $active_nav === 'monthly_closing' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="m9 16 2 2 4-4"/></svg>
      <span>Monthly Closings</span>
    </a>
    <a href="<?= BASE_URL ?>/profit-distribution.php" class="sidebar-link <?= $active_nav === 'profit_distribution' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
      <span>Profit & Ledgers</span>
    </a>
    <a href="<?= BASE_URL ?>/owner-receipts.php" class="sidebar-link <?= $active_nav === 'owner_receipts' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
      <span>Owner Share Receipts</span>
    </a>

    <div class="sidebar-nav-title">Personal Finances</div>
    <a href="<?= BASE_URL ?>/personal-income.php" class="sidebar-link <?= $active_nav === 'personal_income' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/></svg>
      <span>Personal Income</span>
    </a>
    <a href="<?= BASE_URL ?>/personal-expenses.php" class="sidebar-link <?= $active_nav === 'personal_expenses' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      <span>Personal Expenses</span>
    </a>
    <a href="<?= BASE_URL ?>/accounts.php" class="sidebar-link <?= $active_nav === 'accounts' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
      <span>Accounts & Wallets</span>
    </a>
    <a href="<?= BASE_URL ?>/transactions.php" class="sidebar-link <?= $active_nav === 'transactions' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      <span>Transactions</span>
    </a>
    <a href="<?= BASE_URL ?>/loans.php" class="sidebar-link <?= $active_nav === 'loans' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 9V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><rect x="9" y="9" width="12" height="10" rx="2"/><line x1="15" y1="14" x2="15.01" y2="14"/></svg>
      <span>Loans & Debts</span>
    </a>
    <a href="<?= BASE_URL ?>/investments.php" class="sidebar-link <?= $active_nav === 'investments' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
      <span>Investments & Assets</span>
    </a>
    <a href="<?= BASE_URL ?>/reports.php" class="sidebar-link <?= $active_nav === 'reports' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
      <span>Reports & Analytics</span>
    </a>

    <div class="sidebar-nav-title">System</div>
    <a href="<?= BASE_URL ?>/settings.php" class="sidebar-link <?= $active_nav === 'settings' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      <span>Settings</span>
    </a>
    <a href="<?= BASE_URL ?>/backup.php" class="sidebar-link <?= $active_nav === 'backup' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      <span>Database Backup</span>
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="user-avatar-sm"><?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?></div>
      <div class="user-info-text">
        <div class="user-name-label"><?= htmlspecialchars($user['name'] ?? 'User') ?></div>
        <div class="user-role-label"><?= htmlspecialchars(ucfirst($user['role'] ?? 'Owner')) ?></div>
      </div>
    </div>
    <a href="<?= BASE_URL ?>/logout.php" title="Logout" style="color: var(--text-muted); display:flex; align-items:center;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    </a>
  </div>
</aside>
