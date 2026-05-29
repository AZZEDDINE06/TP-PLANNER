<?php
require_once dirname(__DIR__) . '/config/config.php';
requireTeacher();

$conn = getDB();
$teacherId = currentTeacherId();
$isAdmin = isAdmin();

// Counts
$classesCount = 0;
$sessionsCount = 0;
if ($isAdmin) {
    $classesCount = $conn->query('SELECT COUNT(*) FROM classes')->fetch_row()[0];
    $sessionsCount = $conn->query('SELECT COUNT(*) FROM tp_sessions')->fetch_row()[0];
} else {
    $stmt = $conn->prepare('SELECT COUNT(*) FROM classes WHERE teacher_id = ?');
    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    $classesCount = $stmt->get_result()->fetch_row()[0] ?? 0;

    $stmt = $conn->prepare('SELECT COUNT(*) FROM tp_sessions s JOIN classes c ON c.id = s.class_id WHERE c.teacher_id = ?');
    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    $sessionsCount = $stmt->get_result()->fetch_row()[0] ?? 0;
}
$quizzesCount = 0;
$quizTable = $conn->query("SHOW TABLES LIKE 'tp_quizzes'");
if ($quizTable && $quizTable->num_rows) {
    if ($isAdmin) {
        $quizzesCount = $conn->query('SELECT COUNT(*) FROM tp_quizzes')->fetch_row()[0];
    } else {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM tp_quizzes q JOIN tp_sessions s ON s.id = q.tp_id JOIN classes c ON c.id = s.class_id WHERE c.teacher_id = ?');
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();
        $quizzesCount = $stmt->get_result()->fetch_row()[0] ?? 0;
    }
}

// Recent classes (schema: id, name, teacher_id)
$recentClasses = [];
try {
    if ($isAdmin) {
        $recentClasses = $conn->query('SELECT id, name, teacher_id FROM classes ORDER BY id DESC LIMIT 5')->fetch_all(MYSQLI_ASSOC);
    } else {
        $stmt = $conn->prepare('SELECT id, name, teacher_id FROM classes WHERE teacher_id = ? ORDER BY id DESC LIMIT 5');
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();
        $recentClasses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) { }

// Recent TP sessions (schema: id, title, class_id, objectives, skills, duration, created_at)
$recentSessions = [];
try {
    if ($isAdmin) {
        $recentSessions = $conn->query('
            SELECT s.id, s.title, s.duration, c.name AS class_name
            FROM tp_sessions s
            LEFT JOIN classes c ON c.id = s.class_id
            ORDER BY s.id DESC LIMIT 5
        ')->fetch_all(MYSQLI_ASSOC);
    } else {
        $stmt = $conn->prepare('
            SELECT s.id, s.title, s.duration, c.name AS class_name
            FROM tp_sessions s
            JOIN classes c ON c.id = s.class_id
            WHERE c.teacher_id = ?
            ORDER BY s.id DESC LIMIT 5
        ');
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();
        $recentSessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) { }

// Quiz scores per class (prefer new quiz_attempts; fallback to legacy quiz_answers)
$scoresByClass = [];
try {
    $r = $conn->query("SHOW TABLES LIKE 'quiz_attempts'");
    if ($r && $r->num_rows) {
        if ($isAdmin) {
            $res = $conn->query("
                SELECT c.name AS class_name, AVG(a.percentage) AS avg_score
                FROM quiz_attempts a
                JOIN tp_sessions ts ON ts.id = a.tp_id
                LEFT JOIN classes c ON c.id = ts.class_id
                GROUP BY ts.class_id, c.name
                ORDER BY avg_score DESC
                LIMIT 8
            ");
        } else {
            $res = $conn->query("
                SELECT c.name AS class_name, AVG(a.percentage) AS avg_score
                FROM quiz_attempts a
                JOIN tp_sessions ts ON ts.id = a.tp_id
                JOIN classes c ON c.id = ts.class_id
                WHERE c.teacher_id = $teacherId
                GROUP BY ts.class_id, c.name
                ORDER BY avg_score DESC
                LIMIT 8
            ");
        }
        if ($res) $scoresByClass = $res->fetch_all(MYSQLI_ASSOC);
    } else {
        if ($isAdmin) {
            $res = $conn->query("
                SELECT c.name AS class_name, AVG(qa.score) AS avg_score
                FROM quiz_answers qa
                JOIN tp_quizzes tq ON tq.id = qa.quiz_id
                JOIN tp_sessions ts ON ts.id = tq.tp_id
                LEFT JOIN classes c ON c.id = ts.class_id
                WHERE qa.score IS NOT NULL
                GROUP BY ts.class_id, c.name
                ORDER BY avg_score DESC
                LIMIT 8
            ");
        } else {
            $res = $conn->query("
                SELECT c.name AS class_name, AVG(qa.score) AS avg_score
                FROM quiz_answers qa
                JOIN tp_quizzes tq ON tq.id = qa.quiz_id
                JOIN tp_sessions ts ON ts.id = tq.tp_id
                JOIN classes c ON c.id = ts.class_id
                WHERE qa.score IS NOT NULL AND c.teacher_id = $teacherId
                GROUP BY ts.class_id, c.name
                ORDER BY avg_score DESC
                LIMIT 8
            ");
        }
        if ($res) $scoresByClass = $res->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) { }

// Incomplete checklists (schema: tp_checklists.tp_id, is_done)
$incompleteChecklists = 0;
try {
    if ($isAdmin) {
        $r = $conn->query("SELECT COUNT(DISTINCT tp_id) AS n FROM tp_checklists WHERE is_done = 0");
    } else {
        $r = $conn->query("SELECT COUNT(DISTINCT ch.tp_id) AS n
                           FROM tp_checklists ch
                           JOIN tp_sessions s ON s.id = ch.tp_id
                           JOIN classes c ON c.id = s.class_id
                           WHERE ch.is_done = 0 AND c.teacher_id = $teacherId");
    }
    if ($r && $row = $r->fetch_assoc()) $incompleteChecklists = (int) $row['n'];
} catch (Exception $e) { }

$pageTitle = 'Dashboard - ' . APP_NAME;
require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/navbar.php';
?>
<main class="container py-4">
    <h1 class="page-title mb-4">Dashboard</h1>

    <?php if ($msg = flash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= escape($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($incompleteChecklists > 0): ?>
        <div class="alert alert-warning alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong><?= $incompleteChecklists ?></strong> TP session(s) have incomplete checklist items.
            <a href="<?= APP_URL ?>/pages/tp_sessions.php" class="alert-link">View sessions</a>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted small mb-1">Classes</p>
                            <h3 class="mb-0"><?= (int) $classesCount ?></h3>
                        </div>
                        <i class="bi bi-people text-primary" style="font-size: 2rem;"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card stat-card stat-success h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted small mb-1">TP Sessions</p>
                            <h3 class="mb-0"><?= (int) $sessionsCount ?></h3>
                        </div>
                        <i class="bi bi-journal-text text-success" style="font-size: 2rem;"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card stat-card stat-warning h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted small mb-1">Mini Quizzes</p>
                            <h3 class="mb-0"><?= (int) $quizzesCount ?></h3>
                        </div>
                        <i class="bi bi-question-circle text-warning" style="font-size: 2rem;"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card stat-card stat-info h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted small mb-1">Answers</p>
                            <h3 class="mb-0"><?php
                                try {
                                    echo (int) $conn->query('SELECT COUNT(*) FROM quiz_answers')->fetch_row()[0];
                                } catch (Exception $e) { echo '0'; }
                            ?></h3>
                        </div>
                        <i class="bi bi-pencil-square text-info" style="font-size: 2rem;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">Quiz scores by class</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="scoresChart"></canvas>
                    </div>
                    <?php if (empty($scoresByClass)): ?>
                        <p class="text-muted text-center py-3 mb-0">No quiz data yet. Record answers in TP sessions to see scores here.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Recent classes</h5>
                    <a href="<?= APP_URL ?>/pages/classes.php" class="btn btn-sm btn-outline-primary">View all</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentClasses)): ?>
                        <div class="empty-state">
                            <i class="bi bi-people"></i>
                            <p class="mb-0">No classes yet</p>
                            <a href="<?= APP_URL ?>/pages/classes.php?action=create" class="btn btn-primary btn-sm mt-2">Add class</a>
                        </div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($recentClasses as $c): ?>
                                <li class="list-group-item d-flex justify-content-between">
                                    <a href="<?= APP_URL ?>/pages/classes.php?id=<?= (int)$c['id'] ?>" class="text-decoration-none"><?= escape($c['name']) ?></a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Recent TP sessions</h5>
            <a href="<?= APP_URL ?>/pages/tp_sessions.php" class="btn btn-sm btn-outline-primary">View all</a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($recentSessions)): ?>
                <div class="empty-state">
                    <i class="bi bi-journal-text"></i>
                    <p class="mb-0">No TP sessions yet</p>
                    <a href="<?= APP_URL ?>/pages/tp_sessions.php?action=create" class="btn btn-primary btn-sm mt-2">Create session</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Title</th><th>Class</th><th>Duration</th><th>Date</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($recentSessions as $s): ?>
                                <tr>
                                    <td><?= escape($s['title']) ?></td>
                                    <td><?= escape($s['class_name'] ?? '-') ?></td>
                                    <?php
                                    $dur = 60;
                                    if (isset($s['duration']) && $s['duration'] !== null) $dur = (int)$s['duration'];
                                    ?>
                                    <td><?= $dur ?> min</td>
                                    <td>—</td>
                                    <td>
                                        <a href="<?= APP_URL ?>/pages/tp_view.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php
$extraScripts = '';
if (!empty($scoresByClass)) {
    $labels = array_map(function($r) { return json_encode($r['class_name'] ?: 'No class'); }, $scoresByClass);
        // avg_score may be 0..100 (quiz_attempts) or 0..1 (legacy quiz_answers)
        $values = array_map(function($r) {
            $v = (float) $r['avg_score'];
            return $v <= 1.0 ? round($v * 100, 2) : round($v, 2);
        }, $scoresByClass);
    $extraScripts = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
    $extraScripts .= '<script>
    document.addEventListener("DOMContentLoaded", function() {
        var ctx = document.getElementById("scoresChart");
        if (!ctx) return;
        new Chart(ctx.getContext("2d"), {
            type: "bar",
            data: {
                labels: [' . implode(',', $labels) . '],
                datasets: [{
                    label: "Average score",
                    data: [' . implode(',', $values) . '],
                    backgroundColor: "rgba(13, 110, 253, 0.7)",
                    borderColor: "#0d6efd",
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, max: 100 }
                }
            }
        });
    });
    </script>';
}
require_once dirname(__DIR__) . '/includes/footer.php';
