<?php
/**
 * KhataShata - Core Utility Functions
 */

function redirect($path) {
    if (!headers_sent()) {
        if (!preg_match('#^https?://#i', $path)) {
            $path = BASE_URL . '/' . ltrim($path, '/');
        }
        header("Location: " . $path);
        exit;
    } else {
        echo '<script>window.location.href="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }
}

function flash_set($type, $message) {
    $_SESSION['flash_messages'][] = [
        'type' => $type, // 'success', 'danger', 'warning', 'info'
        'message' => $message
    ];
}

function flash_get() {
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}

function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim((string)$input), ENT_QUOTES, 'UTF-8');
}

function get_setting($key, $default = '') {
    static $settings_cache = [];
    $user_id = current_user_id() ?? 1;

    if (isset($settings_cache[$user_id][$key])) {
        return $settings_cache[$user_id][$key];
    }

    try {
        $db = get_db_connection();
        $stmt = $db->prepare("SELECT setting_value FROM app_settings WHERE user_id = :uid AND setting_key = :k LIMIT 1");
        $stmt->execute(['uid' => $user_id, 'k' => $key]);
        $val = $stmt->fetchColumn();
        if ($val !== false) {
            $settings_cache[$user_id][$key] = $val;
            return $val;
        }
    } catch (Exception $e) {
        error_log("Error reading setting: " . $e->getMessage());
    }
    return $default;
}

function set_setting($key, $value, $user_id = null) {
    $user_id = $user_id ?? current_user_id() ?? 1;
    $db = get_db_connection();
    $stmt = $db->prepare("INSERT INTO app_settings (user_id, setting_key, setting_value) 
        VALUES (:uid, :k, :v) 
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    return $stmt->execute(['uid' => $user_id, 'k' => $key, 'v' => $value]);
}

function format_money($amount, $currency = null) {
    $amount = (float)$amount;
    $symbol = $currency ?? get_setting('currency_symbol', 'Rs.');
    $decimals = (int)get_setting('decimals', 2);
    $dec_point = get_setting('decimal_point', '.');
    $thousands_sep = get_setting('thousands_sep', ',');
    $position = get_setting('currency_position', 'before');

    $formatted_number = number_format(abs($amount), $decimals, $dec_point, $thousands_sep);
    $sign = $amount < 0 ? '-' : '';

    if ($position === 'after') {
        return $sign . $formatted_number . ' ' . $symbol;
    }
    return $sign . $symbol . ' ' . $formatted_number;
}

function format_percentage($pct, $decimals = 2) {
    return number_format((float)$pct, $decimals) . '%';
}

function format_date($date, $format = 'M d, Y') {
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '-';
    }
    return date($format, strtotime($date));
}

function mask_account($account_number) {
    if (empty($account_number)) return '';
    $clean = trim($account_number);
    $len = strlen($clean);
    if ($len <= 4) return $clean;
    return str_repeat('•', max(0, $len - 4)) . ' ' . substr($clean, -4);
}

function log_audit($user_id, $record_type, $record_id, $action, $previous_values = null, $new_values = null, $reason = null) {
    try {
        $db = get_db_connection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $prev_json = $previous_values !== null ? json_encode($previous_values, JSON_UNESCAPED_UNICODE) : null;
        $new_json = $new_values !== null ? json_encode($new_values, JSON_UNESCAPED_UNICODE) : null;

        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, record_type, record_id, action, previous_values, new_values, reason, ip_address, created_at) 
            VALUES (:uid, :type, :id, :act, :prev, :new, :reason, :ip, NOW())");
        $stmt->execute([
            'uid' => $user_id,
            'type' => $record_type,
            'id' => $record_id,
            'act' => $action,
            'prev' => $prev_json,
            'new' => $new_json,
            'reason' => $reason,
            'ip' => $ip
        ]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
    }
}

function create_notification($user_id, $title, $message, $type = 'general', $link_url = null) {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, notification_type, link_url, is_read, created_at) 
            VALUES (:uid, :title, :msg, :type, :link, 0, NOW())");
        $stmt->execute([
            'uid' => $user_id,
            'title' => $title,
            'msg' => $message,
            'type' => $type,
            'link' => $link_url
        ]);
    } catch (Exception $e) {
        error_log("Notification failed: " . $e->getMessage());
    }
}

function generate_reference($prefix = 'TRX') {
    return strtoupper($prefix) . '-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
}

function handle_file_upload($file_array, $subdir = 'receipts') {
    if (!isset($file_array) || $file_array['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx'];
    $allowed_mimes = [
        'image/jpeg', 'image/png', 'image/webp',
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    $filename = $file_array['name'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_extensions)) {
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file_array['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_mimes)) {
        return null;
    }

    // Limit to 10MB
    if ($file_array['size'] > 10 * 1024 * 1024) {
        return null;
    }

    $target_dir = UPLOADS_PATH . '/' . trim($subdir, '/');
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    $safe_name = uniqid('file_', true) . '.' . $ext;
    $target_path = $target_dir . '/' . $safe_name;

    if (move_uploaded_file($file_array['tmp_name'], $target_path)) {
        return 'uploads/' . trim($subdir, '/') . '/' . $safe_name;
    }
    return null;
}

function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
