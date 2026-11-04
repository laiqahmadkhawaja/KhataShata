<?php
/**
 * KhataShata - Login Screen
 */
require_once __DIR__ . '/config/app.php';

if (auth_check()) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password.';
        } else {
            if (login($email, $password)) {
                $redirect = $_SESSION['redirect_after_login'] ?? 'dashboard.php';
                unset($_SESSION['redirect_after_login']);
                redirect($redirect);
            } else {
                $error = 'Invalid email address or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Login | <?= APP_NAME ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= APP_VERSION ?>">
  <style>
    .auth-page {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: radial-gradient(circle at top right, var(--primary-light), var(--bg-app) 60%);
    }
    .auth-card {
      width: 100%;
      max-width: 420px;
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-xl);
      padding: 32px 28px;
      box-shadow: var(--shadow-lg);
    }
    .auth-logo {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 24px;
    }
    .auth-logo .icon {
      width: 44px;
      height: 44px;
      background: linear-gradient(135deg, #2563eb, #10b981);
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 22px;
      font-weight: 800;
    }
    .auth-title {
      font-size: 22px;
      font-weight: 700;
      color: var(--text-main);
      letter-spacing: -0.02em;
    }
    .auth-subtitle {
      font-size: 13px;
      color: var(--text-muted);
      margin-top: 2px;
    }
    .demo-credentials {
      background: var(--bg-surface-subtle);
      border: 1px dashed var(--border-strong);
      border-radius: var(--radius-sm);
      padding: 10px 14px;
      margin-top: 20px;
      font-size: 12px;
      color: var(--text-muted);
    }
  </style>
</head>
<body>
<div class="auth-page">
  <div class="auth-card">
    <div class="auth-logo">
      <div class="icon">KS</div>
      <div>
        <div class="auth-title"><?= APP_NAME ?></div>
        <div class="auth-subtitle"><?= APP_TAGLINE ?></div>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <?= csrf_field() ?>
      
      <div class="form-group">
        <label class="form-label">Email Address <span class="req">*</span></label>
        <input type="email" name="email" class="form-control" placeholder="name@domain.com" required value="<?= htmlspecialchars($_POST['email'] ?? 'admin@khatashata.com') ?>" autofocus>
      </div>

      <div class="form-group">
        <label class="form-label">Password <span class="req">*</span></label>
        <input type="password" name="password" class="form-control" placeholder="Enter your password" required value="password123">
      </div>

      <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top: 24px;">
        Sign In to KhataShata
      </button>
    </form>

    <div class="demo-credentials">
      <strong>Default Administrator Account:</strong><br>
      Email: <code>admin@khatashata.com</code><br>
      Password: <code>password123</code>
    </div>
  </div>
</div>
</body>
</html>
