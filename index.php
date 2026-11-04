<?php
/**
 * KhataShata - Entry Point
 */
require_once __DIR__ . '/config/app.php';

if (auth_check()) {
    redirect('dashboard.php');
} else {
    redirect('login.php');
}
