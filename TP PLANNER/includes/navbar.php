<?php if (isLoggedIn()): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="<?= isStudentLoggedIn() ? (APP_URL . '/pages/student_dashboard.php') : (APP_URL . '/pages/dashboard.php') ?>">
            <i class="bi bi-clipboard2-pulse me-2"></i><?= escape(APP_NAME) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav me-auto">
                <?php if (isTeacher()): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/pages/dashboard.php"><i class="bi bi-grid-1x2 me-1"></i> Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/pages/classes.php"><i class="bi bi-people me-1"></i> Classes</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/pages/students.php"><i class="bi bi-mortarboard me-1"></i> Students</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/pages/tp_sessions.php"><i class="bi bi-journal-text me-1"></i> TP Sessions</a>
                    </li>
                <?php elseif (isStudent()): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/pages/student_dashboard.php"><i class="bi bi-grid-1x2 me-1"></i> Dashboard</a>
                    </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-1"></i> <?= escape($_SESSION['username'] ?? 'User') ?>
                        <span class="badge bg-light text-dark ms-1"><?= escape($_SESSION['role'] ?? '') ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/pages/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
<?php endif; ?>
