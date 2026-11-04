<?php
/**
 * KhataShata - Mobile Bottom Navigation
 */
$active_nav = $active_nav ?? 'dashboard';
?>
<nav class="bottom-nav">
  <div class="bottom-nav-inner">
    <a href="<?= BASE_URL ?>/dashboard.php" class="bottom-nav-item <?= $active_nav === 'dashboard' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      <span>Home</span>
    </a>

    <a href="<?= BASE_URL ?>/businesses.php" class="bottom-nav-item <?= $active_nav === 'businesses' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg>
      <span>Business</span>
    </a>

    <!-- Prominent Center Quick-Add Button (NO MODALS - Navigates to dedicated page) -->
    <a href="<?= BASE_URL ?>/quick-add.php" class="bottom-nav-fab" title="Quick Add Transaction">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    </a>

    <a href="<?= BASE_URL ?>/accounts.php" class="bottom-nav-item <?= $active_nav === 'accounts' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
      <span>Wallets</span>
    </a>

    <a href="<?= BASE_URL ?>/reports.php" class="bottom-nav-item <?= $active_nav === 'reports' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
      <span>Reports</span>
    </a>
  </div>
</nav>
