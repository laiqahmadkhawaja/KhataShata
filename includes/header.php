<?php
/**
 * KhataShata - Header Template
 */
require_once __DIR__ . '/../config/app.php';
require_auth();

$user = current_user();
$page_title = $page_title ?? 'Dashboard';
$active_nav = $active_nav ?? 'dashboard';

// Fetch unread notifications count
$db = get_db_connection();
$n_stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0");
$n_stmt->execute(['uid' => $user['id']]);
$unread_notifs = (int)$n_stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta name="csrf-token" content="<?= csrf_token() ?>">
  <title><?= htmlspecialchars($page_title) ?> | <?= APP_NAME ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= APP_VERSION ?>">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="app-container">

  <!-- Desktop Sidebar -->
  <?php require_once __DIR__ . '/sidebar.php'; ?>

  <!-- Main Content Wrapper -->
  <div class="app-main">
    
    <!-- Topbar -->
    <header class="app-topbar">
      <div class="topbar-left">
        <h1 class="topbar-page-title"><?= htmlspecialchars($page_title) ?></h1>
      </div>
      <div class="topbar-right">
        <!-- Theme Switcher -->
        <button type="button" class="icon-btn" id="theme-toggle-btn" title="Toggle Light/Dark Theme">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
        </button>

        <!-- Notifications -->
        <a href="<?= BASE_URL ?>/notifications.php" class="icon-btn" title="Notifications">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <?php if ($unread_notifs > 0): ?>
            <span class="badge-dot"></span>
          <?php endif; ?>
        </a>

        <!-- Quick Add (Desktop) -->
        <a href="<?= BASE_URL ?>/quick-add.php" class="btn btn-primary btn-sm">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          <span>New Entry</span>
        </a>
      </div>
    </header>

    <!-- Main Page Body -->
    <main class="content-wrapper">
      <?php
      // Display any flash messages
      $flashes = flash_get();
      foreach ($flashes as $f): ?>
        <div class="alert alert-<?= htmlspecialchars($f['type']) ?>">
          <div><?= htmlspecialchars($f['message']) ?></div>
        </div>
      <?php endforeach; ?>
