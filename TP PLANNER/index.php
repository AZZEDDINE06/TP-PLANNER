<?php
require_once __DIR__ . '/config/config.php';
$pageTitle = APP_NAME;
require_once __DIR__ . '/includes/header.php';
?>

<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card overflow-hidden">
                <div class="row g-0">
                    <div class="col-md-6 p-4 p-lg-5 bg-primary text-white">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <i class="bi bi-clipboard2-pulse" style="font-size: 2.2rem;"></i>
                            <h1 class="h3 fw-bold mb-0"><?= escape(APP_NAME) ?></h1>
                        </div>
                        <p class="mb-4 text-white-50">
                            Plateforme de gestion des TP, mini-quizzes et suivi des scores.
                        </p>
                        <div class="d-flex flex-column gap-2">
                            <a class="btn btn-light btn-lg" href="<?= APP_URL ?>/pages/login.php">
                                <i class="bi bi-person-badge me-2"></i> Login Enseignant
                            </a>
                            <a class="btn btn-outline-light btn-lg" href="<?= APP_URL ?>/pages/student_login.php">
                                <i class="bi bi-mortarboard me-2"></i> Login Étudiant
                            </a>
                        </div>
                        <div class="mt-4 small text-white-50">
                            Accès sécurisé par rôle (enseignant/étudiant).
                        </div>
                    </div>
                    <div class="col-md-6 p-4 p-lg-5">
                        <?php if (isTeacherLoggedIn()): ?>
                            <h2 class="h5 fw-bold">Vous êtes déjà connecté.</h2>
                            <p class="text-muted mb-3">Accéder à votre tableau de bord enseignant.</p>
                            <a class="btn btn-primary" href="<?= APP_URL ?>/pages/dashboard.php">Aller au dashboard</a>
                            <a class="btn btn-outline-secondary ms-2" href="<?= APP_URL ?>/pages/logout.php">Logout</a>
                        <?php elseif (isStudentLoggedIn()): ?>
                            <h2 class="h5 fw-bold">Vous êtes déjà connecté.</h2>
                            <p class="text-muted mb-3">Accéder à votre tableau de bord étudiant.</p>
                            <a class="btn btn-primary" href="<?= APP_URL ?>/pages/student_dashboard.php">Aller au dashboard</a>
                            <a class="btn btn-outline-secondary ms-2" href="<?= APP_URL ?>/pages/logout.php">Logout</a>
                        <?php else: ?>
                            <h2 class="h5 fw-bold mb-3">Bienvenue</h2>
                            <div class="d-flex gap-3 align-items-start">
                                <div class="text-primary"><i class="bi bi-shield-lock" style="font-size: 1.5rem;"></i></div>
                                <div>
                                    <div class="fw-semibold">Connexion Enseignant</div>
                                    <div class="text-muted small">Gérer classes, TP, quizzes et statistiques.</div>
                                </div>
                            </div>
                            <hr>
                            <div class="d-flex gap-3 align-items-start">
                                <div class="text-primary"><i class="bi bi-journal-check" style="font-size: 1.5rem;"></i></div>
                                <div>
                                    <div class="fw-semibold">Connexion Étudiant</div>
                                    <div class="text-muted small">Voir les TP de votre classe et vos scores.</div>
                                </div>
                            </div>
                            <hr>
                            <p class="text-muted small mb-0">
                                Si vous ne pouvez pas vous connecter en tant qu’étudiant, l’enseignant doit d’abord créer votre compte.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="text-center text-muted small mt-4">
                © <?= date('Y') ?> <?= escape(APP_NAME) ?>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
