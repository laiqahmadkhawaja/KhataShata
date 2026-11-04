<?php
/**
 * KhataShata - Settings & Customization
 */
require_once __DIR__ . '/config/app.php';
require_auth();

$user = current_user();
$error = '';
$db = get_db_connection();
$active_subtab = $_GET['tab'] ?? 'currency';

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Update Profile
        if ($action === 'update_profile') {
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $new_pass = trim($_POST['new_password'] ?? '');

            if (empty($name)) {
                $error = 'Name cannot be empty.';
            } else {
                if (!empty($new_pass)) {
                    $hash = password_hash($new_pass, PASSWORD_BCRYPT);
                    $upd = $db->prepare("UPDATE users SET name = :name, phone = :phone, password_hash = :hash, updated_at = NOW() WHERE id = :id");
                    $upd->execute(['name' => $name, 'phone' => $phone, 'hash' => $hash, 'id' => $user['id']]);
                } else {
                    $upd = $db->prepare("UPDATE users SET name = :name, phone = :phone, updated_at = NOW() WHERE id = :id");
                    $upd->execute(['name' => $name, 'phone' => $phone, 'id' => $user['id']]);
                }
                flash_set('success', 'Profile details updated successfully.');
                redirect('settings.php?tab=profile');
            }
        }

        // 2. Update Currency & Formatting
        if ($action === 'update_currency') {
            $symbol = trim($_POST['currency_symbol'] ?? 'Rs.');
            $code = trim($_POST['currency_code'] ?? 'PKR');
            $pos = trim($_POST['currency_position'] ?? 'before');
            $decimals = (int)($_POST['decimals'] ?? 2);
            $thousands = trim($_POST['thousands_sep'] ?? ',');

            set_setting('currency_symbol', $symbol, $user['id']);
            set_setting('currency_code', $code, $user['id']);
            set_setting('currency_position', $pos, $user['id']);
            set_setting('decimals', (string)$decimals, $user['id']);
            set_setting('thousands_sep', $thousands, $user['id']);

            flash_set('success', 'Currency and number formatting saved.');
            redirect('settings.php?tab=currency');
        }

        // 3. Add Business Category
        if ($action === 'add_business_category') {
            $cat_name = trim($_POST['name'] ?? '');
            $cat_desc = trim($_POST['description'] ?? '');
            if (!empty($cat_name)) {
                $ins = $db->prepare("INSERT INTO business_categories (user_id, name, description, is_active) VALUES (:uid, :name, :desc, 1)");
                $ins->execute(['uid' => $user['id'], 'name' => $cat_name, 'desc' => $cat_desc]);
                flash_set('success', "Business category '$cat_name' added.");
                redirect('settings.php?tab=categories');
            }
        }

        // 4. Add Income/Expense Category
        if ($action === 'add_finance_category') {
            $cat_type = $_POST['type'] ?? 'income';
            $cat_name = trim($_POST['name'] ?? '');
            $cat_scope = $_POST['scope'] ?? 'both';

            if (!empty($cat_name)) {
                if ($cat_type === 'income') {
                    $ins = $db->prepare("INSERT INTO income_categories (user_id, name, type, is_active) VALUES (:uid, :name, :scope, 1)");
                    $ins->execute(['uid' => $user['id'], 'name' => $cat_name, 'scope' => $cat_scope]);
                } else {
                    $ins = $db->prepare("INSERT INTO expense_categories (user_id, name, type, is_active) VALUES (:uid, :name, :scope, 1)");
                    $ins->execute(['uid' => $user['id'], 'name' => $cat_name, 'scope' => $cat_scope]);
                }
                flash_set('success', "Category '$cat_name' added successfully.");
                redirect('settings.php?tab=categories');
            }
        }
    }
}

// Fetch categories for display
$b_cats = $db->query("SELECT * FROM business_categories WHERE user_id = {$user['id']} ORDER BY name ASC")->fetchAll();
$inc_cats = $db->query("SELECT * FROM income_categories WHERE user_id = {$user['id']} ORDER BY name ASC")->fetchAll();
$exp_cats = $db->query("SELECT * FROM expense_categories WHERE user_id = {$user['id']} ORDER BY name ASC")->fetchAll();

$page_title = 'Settings & Customization';
$active_nav = 'settings';

require_once ROOT_PATH . '/includes/header.php';
?>

<div style="max-width: 900px; margin: 0 auto;">
  <div class="nav-tabs" style="margin-bottom: 24px;">
    <a href="?tab=currency" class="nav-tab-item <?= $active_subtab === 'currency' ? 'active' : '' ?>">
      Currency & Format
    </a>
    <a href="?tab=categories" class="nav-tab-item <?= $active_subtab === 'categories' ? 'active' : '' ?>">
      Dynamic Categories
    </a>
    <a href="?tab=profile" class="nav-tab-item <?= $active_subtab === 'profile' ? 'active' : '' ?>">
      Profile & Security
    </a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- SUBTAB 1: CURRENCY & FORMATTING -->
  <?php if ($active_subtab === 'currency'): ?>
    <div class="card">
      <div class="card-header">
        <div>
          <div class="card-title">Currency & Number Formatting</div>
          <div class="card-subtitle">Configure how financial figures are displayed across all dashboards and reports</div>
        </div>
      </div>

      <form method="POST" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_currency">

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Currency Symbol <span class="req">*</span></label>
            <input type="text" name="currency_symbol" class="form-control" value="<?= htmlspecialchars(get_setting('currency_symbol', 'Rs.')) ?>" required>
            <div class="form-text">e.g. Rs., PKR, $, €, £</div>
          </div>

          <div class="form-group">
            <label class="form-label">ISO Currency Code</label>
            <input type="text" name="currency_code" class="form-control" value="<?= htmlspecialchars(get_setting('currency_code', 'PKR')) ?>">
            <div class="form-text">e.g. PKR, USD, EUR, GBP</div>
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Symbol Position</label>
            <select name="currency_position" class="form-control">
              <option value="before" <?= get_setting('currency_position') === 'before' ? 'selected' : '' ?>>Before amount (e.g. Rs. 150,000.00)</option>
              <option value="after" <?= get_setting('currency_position') === 'after' ? 'selected' : '' ?>>After amount (e.g. 150,000.00 Rs.)</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Decimal Places</label>
            <select name="decimals" class="form-control">
              <option value="2" <?= get_setting('decimals') == 2 ? 'selected' : '' ?>>2 decimals (.00)</option>
              <option value="0" <?= get_setting('decimals') == 0 ? 'selected' : '' ?>>0 decimals (whole numbers)</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Thousands Separator</label>
          <input type="text" name="thousands_sep" class="form-control" value="<?= htmlspecialchars(get_setting('thousands_sep', ',')) ?>" style="width: 120px;">
        </div>

        <div class="card" style="background: var(--bg-surface-subtle); margin-top: 16px;">
          <span style="font-size: 12px; color: var(--text-muted);">Format Preview:</span>
          <div style="font-size: 20px; font-weight: 800; color: var(--text-main); margin-top: 4px;">
            <?= format_money(1250000.50) ?>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg" style="margin-top: 16px;">
          Save Formatting Preferences
        </button>
      </form>
    </div>

  <!-- SUBTAB 2: DYNAMIC CATEGORIES -->
  <?php elseif ($active_subtab === 'categories'): ?>
    <!-- 1. Business Categories -->
    <div class="card" style="margin-bottom: 24px;">
      <div class="card-header">
        <div>
          <div class="card-title">Business Categories</div>
          <div class="card-subtitle">Organize your portfolio enterprises by industry</div>
        </div>
      </div>

      <form method="POST" action="" style="display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_business_category">
        <input type="text" name="name" class="form-control" placeholder="New business category..." required style="flex: 1; min-width: 200px;">
        <input type="text" name="description" class="form-control" placeholder="Optional description..." style="flex: 1; min-width: 200px;">
        <button type="submit" class="btn btn-primary btn-sm">+ Add Category</button>
      </form>

      <div style="display: flex; flex-wrap: wrap; gap: 8px;">
        <?php foreach ($b_cats as $bc): ?>
          <span class="badge badge-primary" style="padding: 6px 12px; font-size: 13px;">
            <?= htmlspecialchars($bc['name']) ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 2. Income Categories -->
    <div class="card" style="margin-bottom: 24px;">
      <div class="card-header">
        <div>
          <div class="card-title">Income Categories</div>
          <div class="card-subtitle">Revenue streams for business and personal earnings</div>
        </div>
      </div>

      <form method="POST" action="" style="display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_finance_category">
        <input type="hidden" name="type" value="income">
        <input type="text" name="name" class="form-control" placeholder="New income category..." required style="flex: 2; min-width: 200px;">
        <select name="scope" class="form-control" style="flex: 1; min-width: 140px;">
          <option value="both">Business & Personal</option>
          <option value="business">Business Only</option>
          <option value="personal">Personal Only</option>
        </select>
        <button type="submit" class="btn btn-success btn-sm">+ Add Income Category</button>
      </form>

      <div style="display: flex; flex-wrap: wrap; gap: 8px;">
        <?php foreach ($inc_cats as $ic): ?>
          <span class="badge badge-success" style="padding: 6px 12px; font-size: 13px;">
            <?= htmlspecialchars($ic['name']) ?> (<?= $ic['type'] ?>)
          </span>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 3. Expense Categories -->
    <div class="card">
      <div class="card-header">
        <div>
          <div class="card-title">Expense Categories</div>
          <div class="card-subtitle">Operational and household expense classification</div>
        </div>
      </div>

      <form method="POST" action="" style="display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_finance_category">
        <input type="hidden" name="type" value="expense">
        <input type="text" name="name" class="form-control" placeholder="New expense category..." required style="flex: 2; min-width: 200px;">
        <select name="scope" class="form-control" style="flex: 1; min-width: 140px;">
          <option value="both">Business & Personal</option>
          <option value="business">Business Only</option>
          <option value="personal">Personal Only</option>
        </select>
        <button type="submit" class="btn btn-danger btn-sm">+ Add Expense Category</button>
      </form>

      <div style="display: flex; flex-wrap: wrap; gap: 8px;">
        <?php foreach ($exp_cats as $ec): ?>
          <span class="badge badge-danger" style="padding: 6px 12px; font-size: 13px;">
            <?= htmlspecialchars($ec['name']) ?> (<?= $ec['type'] ?>)
          </span>
        <?php endforeach; ?>
      </div>
    </div>

  <!-- SUBTAB 3: PROFILE & SECURITY -->
  <?php elseif ($active_subtab === 'profile'): ?>
    <div class="card">
      <div class="card-header">
        <div>
          <div class="card-title">Personal Profile & Security</div>
          <div class="card-subtitle">Manage your administrator account credentials</div>
        </div>
      </div>

      <form method="POST" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_profile">

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Full Name <span class="req">*</span></label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required>
          </div>

          <div class="form-group">
            <label class="form-label">Email Address (Login Username)</label>
            <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" disabled>
            <div class="form-text">Primary identifier for authentication.</div>
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Contact Number</label>
            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label class="form-label">Change Password (Leave blank to keep current)</label>
            <input type="password" name="new_password" class="form-control" placeholder="Enter new strong password">
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-lg" style="margin-top: 16px;">
          Update Profile Information
        </button>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
