<?php
/**
 * KhataShata - Authentication & Session Management
 */

function auth_check() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function current_user() {
    static $user = null;
    if ($user !== null) {
        return $user;
    }
    if (!auth_check()) {
        return null;
    }
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT id, name, email, phone, avatar, role FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        logout();
        return null;
    }
    return $user;
}

function require_auth() {
    if (!auth_check()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
        redirect('login.php');
    }
}

function login($email, $password) {
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => trim($email)]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];

        // Audit log login
        log_audit($user['id'], 'users', $user['id'], 'login', null, ['email' => $user['email']], 'User logged in successfully');
        return true;
    }
    return false;
}

function logout() {
    $user_id = current_user_id();
    if ($user_id) {
        log_audit($user_id, 'users', $user_id, 'logout', null, null, 'User logged out');
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
