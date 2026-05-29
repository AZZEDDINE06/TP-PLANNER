<?php
require_once dirname(__DIR__) . '/config/config.php';

if (isLoggedIn()) {
    if (isStudentLoggedIn()) {
        redirect(APP_URL . '/pages/student_dashboard.php');
    }
    redirect(APP_URL . '/pages/dashboard.php');
}

$error = '';
$errorType = ''; // 'no_account' | 'wrong_password' | 'empty' | 'invalid_request'
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Invalid request.';
        $errorType = 'invalid_request';
    } else {
        $loginInput = trim($_POST['username'] ?? ''); // email or username
        $password = $_POST['password'] ?? '';
        if ($loginInput === '' || $password === '') {
            $error = 'Veuillez saisir l\'email et le mot de passe.';
            $errorType = 'empty';
        } else {
            try {
                $conn = getDB();
                $loginCol = getUsersLoginColumn();
                if (!in_array($loginCol, ALLOWED_LOGIN_COLUMNS, true)) {
                    $loginCol = 'email';
                }
                $stmt = $conn->prepare("SELECT id, name, `$loginCol`, password, role FROM users WHERE `$loginCol` = ? LIMIT 1");
                $stmt->bind_param('s', $loginInput);
                $stmt->execute();
                $res = $stmt->get_result();
                $user = $res->fetch_assoc();
                if ($user && $password === (string)($user['password'] ?? '')) {
                    $_SESSION['auth_type'] = 'teacher';
                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['username'] = $user['name'] ?? $user[$loginCol] ?? $loginInput;
                    $_SESSION['role'] = $user['role'];
                    redirect(APP_URL . '/pages/dashboard.php');
                }
                if (!$user) {
                    $error = "Cet utilisateur n'a pas de compte.";
                    $errorType = 'no_account';
                } else {
                    $error = 'Invalid username or password.';
                    $errorType = 'wrong_password';
                }
            } catch (mysqli_sql_exception $e) {
                if (strpos($e->getMessage(), 'Unknown column') !== false) {
                    $error = "Configuration base de données : la table users n'a pas de colonne reconnue (username, email, login, name, user_name). Définissez USER_LOGIN_COLUMN dans config/config.php.";
                    $errorType = 'invalid_request';
                } else {
                    throw $e;
                }
            }
        }
    }
}

$pageTitle = 'Login - ' . APP_NAME;
require_once dirname(__DIR__) . '/includes/header.php';
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="card shadow border-0 rounded-3">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <i class="bi bi-clipboard2-pulse text-primary" style="font-size: 3rem;"></i>
                        <h4 class="mt-2 fw-bold"><?= escape(APP_NAME) ?></h4>
                        <p class="text-muted small">Sign in to your account</p>
                    </div>
                    <?php if ($error): ?>
                        <div id="loginAlert" class="alert alert-dismissible fade show <?= $errorType === 'no_account' ? 'alert-warning' : 'alert-danger' ?> py-3" role="alert">
                            <?php if ($errorType === 'no_account'): ?>
                                <i class="bi bi-person-x me-2"></i>
                            <?php endif; ?>
                            <strong><?= escape($error) ?></strong>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    <form method="post" action="">
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="username" class="form-control" value="<?= escape(old('username')) ?>"
                                   autocomplete="email" required autofocus placeholder="votre@email.com">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" autocomplete="current-password" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Sign In</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    var alertEl = document.getElementById('loginAlert');
    if (alertEl) {
        alertEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        alertEl.classList.add('login-alert-show');
    }
})();
</script>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
