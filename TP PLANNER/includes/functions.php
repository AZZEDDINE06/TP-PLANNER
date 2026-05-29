<?php
/**
 * TP Planner - Helper Functions
 */

function isTeacherLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']) && (($_SESSION['auth_type'] ?? 'teacher') === 'teacher');
}

function isStudentLoggedIn() {
    return isset($_SESSION['student_id']) && !empty($_SESSION['student_id']) && (($_SESSION['auth_type'] ?? '') === 'student');
}

function isLoggedIn() {
    return isTeacherLoggedIn() || isStudentLoggedIn();
}

function currentTeacherId() {
    return isTeacherLoggedIn() ? (int) $_SESSION['user_id'] : 0;
}

function currentStudentId() {
    return isStudentLoggedIn() ? (int) $_SESSION['student_id'] : 0;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/index.php');
        exit;
    }
}

function isAdmin() {
    return isTeacherLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isTeacher() {
    return isTeacherLoggedIn() && isset($_SESSION['role']) && ($_SESSION['role'] === 'teacher' || $_SESSION['role'] === 'admin');
}

function isStudent() {
    return isStudentLoggedIn() && (($_SESSION['role'] ?? '') === 'student');
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        if (isStudentLoggedIn()) {
            header('Location: ' . APP_URL . '/pages/student_dashboard.php');
        } else {
            header('Location: ' . APP_URL . '/pages/dashboard.php');
        }
        exit;
    }
}

function requireTeacher() {
    requireLogin();
    if (!isTeacher()) {
        if (isStudentLoggedIn()) {
            header('Location: ' . APP_URL . '/pages/student_dashboard.php');
        } else {
            header('Location: ' . APP_URL . '/index.php');
        }
        exit;
    }
}

function requireStudent() {
    requireLogin();
    if (!isStudent()) {
        if (isTeacherLoggedIn()) {
            header('Location: ' . APP_URL . '/pages/dashboard.php');
        } else {
            header('Location: ' . APP_URL . '/index.php');
        }
        exit;
    }
}

function escape($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect($url, $code = 302) {
    header('Location: ' . $url, true, $code);
    exit;
}

function flash($key, $message = null) {
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    $msg = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $msg;
}

function old($key, $default = '') {
    return $_SESSION['old'][$key] ?? $default;
}

function csrf_field() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf_token" value="' . escape($_SESSION['csrf_token']) . '">';
}

function verify_csrf() {
    $token = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/** Allowed column names for login (whitelist for SQL safety) */
define('ALLOWED_LOGIN_COLUMNS', ['username', 'email', 'login', 'name', 'user_name', 'identifiant', 'user_login']);

/**
 * Returns the column name used for login in the users table.
 * Uses config USER_LOGIN_COLUMN if set, else auto-detects from table structure.
 */
function getUsersLoginColumn() {
    if (defined('USER_LOGIN_COLUMN') && in_array(USER_LOGIN_COLUMN, ALLOWED_LOGIN_COLUMNS, true)) {
        return USER_LOGIN_COLUMN;
    }
    try {
        $conn = getDB();
        $res = $conn->query("SHOW COLUMNS FROM users");
        if (!$res) return 'username';
        $columns = [];
        while ($row = $res->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
        foreach (ALLOWED_LOGIN_COLUMNS as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
    } catch (Exception $e) { /* ignore */ }
    return 'username';
}
?>
