<?php
require_once dirname(__DIR__) . '/config/config.php';
requireTeacher();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$id) {
    flash('error', 'Invalid session.');
    redirect(APP_URL . '/pages/tp_sessions.php');
}

$conn = getDB();
$teacherId = currentTeacherId();
$isAdmin = isAdmin();

if ($isAdmin) {
    $session = $conn->query("SELECT s.*, c.name AS class_name FROM tp_sessions s LEFT JOIN classes c ON c.id = s.class_id WHERE s.id = $id")->fetch_assoc();
} else {
    $session = $conn->query("SELECT s.*, c.name AS class_name FROM tp_sessions s JOIN classes c ON c.id = s.class_id WHERE s.id = $id AND c.teacher_id = $teacherId")->fetch_assoc();
}
if (!$session) {
    flash('error', 'Session not found.');
    redirect(APP_URL . '/pages/tp_sessions.php');
}

$steps = $conn->query("SELECT * FROM tp_steps WHERE tp_id = $id ORDER BY step_number")->fetch_all(MYSQLI_ASSOC);
$materials = $conn->query("SELECT * FROM tp_materials WHERE tp_id = $id ORDER BY type, name")->fetch_all(MYSQLI_ASSOC);
$checklists = $conn->query("SELECT * FROM tp_checklists WHERE tp_id = $id ORDER BY phase, id")->fetch_all(MYSQLI_ASSOC);
$quizzes = $conn->query("SELECT * FROM tp_quizzes WHERE tp_id = $id ORDER BY id")->fetch_all(MYSQLI_ASSOC);

// Students list for dropdown (if students table exists)
$classStudents = [];
try {
    $r = $conn->query("SHOW TABLES LIKE 'students'");
    if ($r && $r->num_rows && !empty($session['class_id'])) {
        $cid = (int)$session['class_id'];
        $stmt = $conn->prepare('SELECT id, name, email FROM students WHERE class_id = ? ORDER BY name');
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $classStudents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) { $classStudents = []; }

// Toggle checklist item
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['check_id']) && verify_csrf()) {
        $cid = (int) $_POST['check_id'];
        $done = (int) ($_POST['is_done'] ?? 0);
        $conn->query("UPDATE tp_checklists SET is_done = $done WHERE id = $cid AND tp_id = $id");
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
        redirect(APP_URL . '/pages/tp_view.php?id=' . $id . '#checklist');
    }
    // Record quiz answers
    if (isset($_POST['record_quiz']) && verify_csrf()) {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $studentName = trim($_POST['student_name'] ?? '');

        // If dropdown used, derive name from DB
        if ($studentId > 0) {
            $stmt = $conn->prepare('SELECT name FROM students WHERE id = ? AND class_id = ? LIMIT 1');
            $cid = (int)($session['class_id'] ?? 0);
            $stmt->bind_param('ii', $studentId, $cid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) $studentName = (string)$row['name'];
        }

        if ($studentName !== '') {
            $correctCol = 'correct_option'; // column name in tp_quizzes
            try {
                $conn->begin_transaction();
                $conn->query("DELETE qa FROM quiz_answers qa
                              JOIN tp_quizzes tq ON tq.id = qa.quiz_id
                              WHERE tq.tp_id = $id AND qa.student_name = '" . $conn->real_escape_string($studentName) . "'");

                $stmt = $conn->prepare('INSERT INTO quiz_answers (quiz_id, student_name, selected_option, score) VALUES (?, ?, ?, ?)');

                // New structured scoring tables (optional)
                $useNew = false;
                $qqsExists = $conn->query("SHOW TABLES LIKE 'quiz_question_scores'");
                $attemptExists = $conn->query("SHOW TABLES LIKE 'quiz_attempts'");
                if ($studentId > 0 && $qqsExists && $qqsExists->num_rows && $attemptExists && $attemptExists->num_rows) {
                    $useNew = true;
                    $del = $conn->prepare('DELETE FROM quiz_question_scores WHERE student_id = ? AND tp_id = ?');
                    $del->bind_param('ii', $studentId, $id);
                    $del->execute();
                    $ins = $conn->prepare('INSERT INTO quiz_question_scores (student_id, tp_id, question_id, selected_option, is_correct, score) VALUES (?,?,?,?,?,?)');
                }
                $totalPoints = 0;
                $maxPoints = count($quizzes) * 4;

                foreach ($quizzes as $q) {
                    $qid = (int) $q['id'];
                    $answer = strtoupper(trim($_POST['answer_' . $qid] ?? ''));
                    $correct = strtoupper(trim($q['correct_option'] ?? 'A'));
                    $isCorrect = ($answer === $correct) ? 1 : 0;
                    $score = $isCorrect ? 1 : 0;
                    $stmt->bind_param('issi', $qid, $studentName, $answer, $score);
                    $stmt->execute();

                    if ($useNew) {
                        $points = $isCorrect ? 4 : 0;
                        $totalPoints += $points;
                        $ins->bind_param('iiisii', $studentId, $id, $qid, $answer, $isCorrect, $points);
                        $ins->execute();
                    }
                }

                if ($useNew) {
                    $pct = $maxPoints > 0 ? round(100 * $totalPoints / $maxPoints, 2) : 0.0;
                    $up = $conn->prepare('INSERT INTO quiz_attempts (student_id, tp_id, total_points, max_points, percentage) VALUES (?,?,?,?,?)
                                          ON DUPLICATE KEY UPDATE total_points=VALUES(total_points), max_points=VALUES(max_points), percentage=VALUES(percentage), submitted_at=CURRENT_TIMESTAMP');
                    $up->bind_param('iiiid', $studentId, $id, $totalPoints, $maxPoints, $pct);
                    $up->execute();
                }
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                flash('error', 'Could not record answers.');
                redirect(APP_URL . '/pages/tp_view.php?id=' . $id . '#quiz');
            }
            flash('success', 'Answers recorded for ' . escape($studentName));
            redirect(APP_URL . '/pages/tp_view.php?id=' . $id . '#quiz');
        }
    }
}

// Quiz answers for this session (schema: quiz_answers.quiz_id, selected_option)
try {
    $answersByStudent = [];
    $res = $conn->query("SELECT qa.student_name, qa.selected_option, qa.score FROM quiz_answers qa JOIN tp_quizzes tq ON tq.id = qa.quiz_id WHERE tq.tp_id = $id ORDER BY qa.student_name");
    if ($res) while ($row = $res->fetch_assoc()) {
        $sn = $row['student_name'];
        if (!isset($answersByStudent[$sn])) $answersByStudent[$sn] = ['total' => 0, 'correct' => 0];
        $answersByStudent[$sn]['total']++;
        $answersByStudent[$sn]['correct'] += (int) $row['score'];
    }
} catch (Exception $e) {
    $answersByStudent = [];
}

$pageTitle = escape($session['title']) . ' - ' . APP_NAME;
require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/navbar.php';
?>
<main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/tp_sessions.php">TP Sessions</a></li>
                    <li class="breadcrumb-item active"><?= escape($session['title']) ?></li>
                </ol>
            </nav>
            <h1 class="page-title mb-1"><?= escape($session['title']) ?></h1>
            <p class="text-muted small mb-0">
                <?= escape($session['class_name'] ?? '—') ?>
                <?php
                $dur = 60;
                if (isset($session['duration']) && $session['duration'] !== null) {
                    $dur = (int)$session['duration'];
                }
                ?>
                · <?= $dur ?> min
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= APP_URL ?>/pages/tp_edit.php?id=<?= $id ?>" class="btn btn-outline-primary">Edit</a>
    <a href="<?= APP_URL ?>/pages/tp_scores.php?tp_id=<?= $id ?>" class="btn btn-outline-secondary"><i class="bi bi-graph-up me-1"></i> Scores</a>
            <a href="<?= APP_URL ?>/pages/tp_pdf.php?id=<?= $id ?>" class="btn btn-danger" target="_blank"><i class="bi bi-file-pdf me-1"></i> Export PDF</a>
        </div>
    </div>

    <?php if ($msg = flash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-4" id="tpTabs" role="tablist">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#overview">Overview</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#checklist">Checklist</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#quiz">Mini-Quiz</a></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="overview">
            <div class="row">
                <div class="col-lg-8">
                    <?php if (!empty($session['objectives'])): ?>
                        <div class="card mb-3">
                            <div class="card-header bg-white"><strong>Objectives</strong></div>
                            <div class="card-body"><?= nl2br(escape($session['objectives'])) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($session['skills'])): ?>
                        <p><strong>Skills:</strong> <?= escape($session['skills']) ?></p>
                    <?php endif; ?>
                    <div class="card mb-3">
                        <div class="card-header bg-white"><strong>Steps</strong></div>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($steps as $i => $st): ?>
                                <li class="list-group-item">
                                    <strong>Étape <?= $i + 1 ?>:</strong> <?= escape($st['description'] ?? '') ?>
                                </li>
                            <?php endforeach; ?>
                            <?php if (empty($steps)): ?>
                                <li class="list-group-item text-muted">No steps defined.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header bg-white"><strong>Materials</strong></div>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($materials as $m): ?>
                                <li class="list-group-item">
                                    <?= escape($m['name']) ?> <span class="badge bg-secondary"><?= escape($m['type']) ?></span>
                                </li>
                            <?php endforeach; ?>
                            <?php if (empty($materials)): ?>
                                <li class="list-group-item text-muted">No materials.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="checklist">
            <div class="card">
                <div class="card-body">
                    <?php
                    $byPhase = ['before' => [], 'during' => [], 'after' => []];
                    foreach ($checklists as $ch) {
                        $byPhase[$ch['phase']][] = $ch;
                    }
                    $phaseLabel = ['before' => 'Before TP', 'during' => 'During TP', 'after' => 'After TP'];
                    $phaseBadge = ['before' => 'badge-before', 'during' => 'badge-during', 'after' => 'badge-after'];
                    foreach ($byPhase as $phase => $items):
                        if (empty($items)) continue;
                    ?>
                        <h6 class="mt-3"><span class="badge <?= $phaseBadge[$phase] ?>"><?= $phaseLabel[$phase] ?></span></h6>
                        <ul class="list-group list-group-flush mb-3">
                            <?php foreach ($items as $ch): ?>
                                <li class="list-group-item checklist-item <?= !empty($ch['is_done']) ? 'done' : '' ?> d-flex justify-content-between align-items-center">
                                    <span class="checklist-text"><?= escape($ch['item'] ?? '') ?></span>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="check_id" value="<?= (int)$ch['id'] ?>">
                                        <input type="hidden" name="is_done" value="<?= !empty($ch['is_done']) ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn-sm <?= !empty($ch['is_done']) ? 'btn-success' : 'btn-outline-secondary' ?>">
                                            <?php if (!empty($ch['is_done'])): ?><i class="bi bi-check-lg"></i> Done (click to undo)<?php else: ?>Mark done<?php endif; ?>
                                        </button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                    <?php if (empty($checklists)): ?>
                        <p class="text-muted">No checklist items. <a href="<?= APP_URL ?>/pages/tp_edit.php?id=<?= $id ?>">Edit session</a> to add some.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="quiz">
            <div class="row">
                <div class="col-lg-6">
                    <div class="card mb-4">
                        <div class="card-header bg-white"><strong>Record student answers</strong></div>
                        <div class="card-body">
                            <form method="post" action="">
                                <?= csrf_field() ?>
                                <input type="hidden" name="record_quiz" value="1">
                                <div class="mb-3">
                                    <label class="form-label">Student name</label>
                                    <?php if (!empty($classStudents)): ?>
                                        <select name="student_id" class="form-select" required>
                                            <option value="">— Select student —</option>
                                            <?php foreach ($classStudents as $st): ?>
                                                <option value="<?= (int)$st['id'] ?>"><?= escape($st['name']) ?> (<?= escape($st['email']) ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="text" name="student_name" class="form-control" required placeholder="Full name">
                                        <div class="form-text">Astuce: créez d’abord les étudiants dans l’onglet “Students” pour avoir une liste.</div>
                                    <?php endif; ?>
                                </div>
                                <?php foreach ($quizzes as $q): ?>
                                    <div class="mb-3">
                                        <label class="form-label"><?= escape($q['question'] ?? '') ?></label>
                                        <div class="d-flex gap-3 flex-wrap">
                                            <label><input type="radio" name="answer_<?= (int)$q['id'] ?>" value="A"> <?= escape($q['option_a']) ?></label>
                                            <label><input type="radio" name="answer_<?= (int)$q['id'] ?>" value="B"> <?= escape($q['option_b']) ?></label>
                                            <label><input type="radio" name="answer_<?= (int)$q['id'] ?>" value="C"> <?= escape($q['option_c']) ?></label>
                                            <label><input type="radio" name="answer_<?= (int)$q['id'] ?>" value="D"> <?= escape($q['option_d']) ?></label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($quizzes)): ?>
                                    <p class="text-muted">No quiz questions. <a href="<?= APP_URL ?>/pages/tp_edit.php?id=<?= $id ?>">Edit session</a> to add some.</p>
                                <?php else: ?>
                                    <button type="submit" class="btn btn-primary">Save answers</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header bg-white"><strong>Student performance</strong></div>
                        <div class="card-body p-0">
                            <?php if (empty($answersByStudent)): ?>
                                <p class="p-3 text-muted mb-0">No answers recorded yet.</p>
                            <?php else: ?>
                                <table class="table table-sm mb-0">
                                    <thead><tr><th>Student</th><th>Score</th><th>%</th></tr></thead>
                                    <tbody>
                                        <?php
                                        $totalQ = count($quizzes);
                                        foreach ($answersByStudent as $name => $data):
                                            $pct = $totalQ > 0 ? round(100 * $data['correct'] / $totalQ) : 0;
                                        ?>
                                            <tr>
                                                <td><?= escape($name) ?></td>
                                                <td><?= (int)$data['correct'] ?> / <?= (int)$data['total'] ?></td>
                                                <td><span class="badge <?= $pct >= 70 ? 'bg-success' : ($pct >= 50 ? 'bg-warning text-dark' : 'bg-danger') ?>"><?= $pct ?>%</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
