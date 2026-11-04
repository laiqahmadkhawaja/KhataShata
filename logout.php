<?php
/**
 * KhataShata - Logout Script
 */
require_once __DIR__ . '/config/app.php';

logout();
redirect('login.php');
