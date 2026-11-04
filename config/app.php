<?php
/**
 * KhataShata - Main App Bootstrap
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/database.php';
require_once ROOT_PATH . '/includes/csrf.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/financial_engine.php';

// Production safe error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', ROOT_PATH . '/error.log');
