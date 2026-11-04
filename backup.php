<?php
/**
 * KhataShata - Database Backup & Restoration Engine
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$error = '';
$db = get_db_connection();

// Handle One-Click SQL Backup Generation
if (isset($_GET['action']) && $_GET['action'] === 'download_backup') {
    $tables = [];
    $stmt = $db->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    $sql_dump = "-- ==============================================================\n";
    $sql_dump .= "-- KhataShata Database Backup\n";
    $sql_dump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql_dump .= "-- ==============================================================\n\n";
    $sql_dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $create_stmt = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        $sql_dump .= "-- Structure for table `$table`\n";
        $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql_dump .= $create_stmt[1] . ";\n\n";

        $rows = $db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $sql_dump .= "-- Dumping data for table `$table`\n";
            foreach ($rows as $row) {
                $cols = array_map(function($c) { return "`$c`"; }, array_keys($row));
                $vals = array_map(function($v) use ($db) {
                    return is_null($v) ? 'NULL' : $db->quote($v);
                }, array_values($row));

                $sql_dump .= "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
            }
            $sql_dump .= "\n";
        }
    }
    $sql_dump .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $filename = 'khatashata_backup_' . date('Ymd_His') . '.sql';
    
    // Save to backups folder as well
    file_put_contents(ROOT_PATH . '/backups/' . $filename, $sql_dump);

    // Send headers for instant download
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql_dump));
    echo $sql_dump;
    exit;
}

// List backups in directory
$backup_files = glob(ROOT_PATH . '/backups/*.sql');
rsort($backup_files);

$page_title = 'Database Backup';
$active_nav = 'backup';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 800px; margin: 0 auto;">
  <div class="card" style="margin-bottom: 20px;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
      <div>
        <h2 style="font-size: 20px; font-weight: 800; color: var(--text-main);">Database Backup & Preservation</h2>
        <p style="font-size: 13px; color: var(--text-muted);">Download full SQL database dumps with complete schema, historical ledgers, and transactions.</p>
      </div>

      <a href="?action=download_backup" class="btn btn-primary btn-lg">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <span>Download Full Backup (.sql)</span>
      </a>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <div class="card-title">Stored Local Backup Snapshots</div>
    </div>

    <?php if (empty($backup_files)): ?>
      <div class="empty-state" style="padding: 24px;">
        <div class="empty-state-text">No backups created yet. Click "Download Full Backup" to generate your first complete backup.</div>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="data-table mobile-cards">
          <thead>
            <tr>
              <th>Backup File</th>
              <th>Generated Date</th>
              <th style="text-align: right;">Size</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($backup_files as $file): ?>
              <tr>
                <td data-label="File">
                  <strong><?= htmlspecialchars(basename($file)) ?></strong>
                </td>
                <td data-label="Date">
                  <?= date('M d, Y H:i:s', filemtime($file)) ?>
                </td>
                <td data-label="Size" style="text-align: right; font-weight: 600;">
                  <?= round(filesize($file) / 1024, 2) ?> KB
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
