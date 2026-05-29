<?php
/**
 * TP Planner - Application Configuration
 */
session_start();

define('APP_NAME', 'TP Planner');
define('ROOT_PATH', dirname(__DIR__));

// Base path for links (e.g. '' or '/TP PLANNER' if in subfolder)
$script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
if (preg_match('#/pages?/#', $script)) {
    $base = preg_replace('#/pages?/.*#', '', $script);
} else {
    $base = dirname($script);
}
define('BASE_PATH', ($base === '/' || $base === '\\') ? '' : $base);
define('APP_URL', BASE_PATH);

// Timezone
date_default_timezone_set('Europe/Paris');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database: use your tables (users: name, email, password, role)
define('USER_LOGIN_COLUMN', 'email');

// Require database
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/functions.php';
?>
