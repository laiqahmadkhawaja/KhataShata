<?php
/**
 * KhataShata - In-App Notifications & Alerts
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$page_title = 'Notifications & Reminders';
$active_nav = 'notifications';

$db = get_db_connection();

// Mark all as read if requested
if (isset($_GET['action']) && $_GET['action'] === 'mark_all_read') {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :uid");
    $stmt->execute(['uid' => $user['id']]);
    flash_set('success', 'All notifications marked as read.');
    redirect('notifications.php');
}

$stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 50");
$stmt->execute(['uid' => $user['id']]);
$notifications = $stmt->fetchAll();

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 800px; margin: 0 auto;">
  <div class="card" style="margin-bottom: 20px;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
      <div>
        <h2 style="font-size: 20px; font-weight: 800; color: var(--text-main);">Notifications & Reminders</h2>
        <p style="font-size: 13px; color: var(--text-muted);">Alerts for pending monthly closings, loan maturities, and partner disbursements.</p>
      </div>

      <?php if (!empty($notifications)): ?>
        <a href="?action=mark_all_read" class="btn btn-secondary btn-sm">
          Mark All as Read
        </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($notifications)): ?>
    <div class="card">
      <div class="empty-state">
        <div class="empty-state-icon">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        </div>
        <div class="empty-state-title">You're all caught up!</div>
        <div class="empty-state-text">No active alerts or pending financial reminders at this moment.</div>
      </div>
    </div>
  <?php else: ?>
    <div style="display: flex; flex-direction: column; gap: 10px;">
      <?php foreach ($notifications as $n): ?>
        <div class="card" style="margin-bottom: 0; padding: 16px 20px; border-left: 4px solid <?= $n['is_read'] ? 'var(--border-subtle)' : 'var(--primary)' ?>; background: <?= $n['is_read'] ? 'var(--bg-surface)' : 'var(--primary-light)' ?>;">
          <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;">
            <div>
              <div style="display: flex; align-items: center; gap: 8px;">
                <strong style="font-size: 14.5px; color: var(--text-main);"><?= htmlspecialchars($n['title']) ?></strong>
                <?php if (!$n['is_read']): ?>
                  <span class="badge badge-primary" style="font-size: 10px;">New</span>
                <?php endif; ?>
              </div>
              <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px; line-height: 1.5;">
                <?= htmlspecialchars($n['message']) ?>
              </p>
              <?php if (!empty($n['link_url'])): ?>
                <div style="margin-top: 8px;">
                  <a href="<?= BASE_URL ?>/<?= htmlspecialchars($n['link_url']) ?>" class="btn btn-secondary btn-sm" style="padding: 4px 10px;">
                    View Details &rarr;
                  </a>
                </div>
              <?php endif; ?>
            </div>
            <div style="font-size: 11.5px; color: var(--text-muted); white-space: nowrap;">
              <?= format_date($n['created_at'], 'M d, H:i') ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
