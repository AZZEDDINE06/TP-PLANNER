<?php
require_once dirname(__DIR__) . '/config/config.php';
requireTeacher();

$conn = getDB();
$teacherId = currentTeacherId();
$isAdmin = isAdmin();
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$session = null;
$steps = [];
$materials = [];
$checklists = [];
$quizzes = [];

if ($id) {
    if ($isAdmin) {
        $r = $conn->prepare('SELECT * FROM tp_sessions WHERE id = ?');
        $r->bind_param('i', $id);
    } else {
        $r = $conn->prepare('SELECT s.* FROM tp_sessions s JOIN classes c ON c.id = s.class_id WHERE s.id = ? AND c.teacher_id = ?');
        $r->bind_param('ii', $id, $teacherId);
    }
    $r->execute();
    $session = $r->get_result()->fetch_assoc();
    if (!$session) {
        flash('error', 'Session not found.');
        redirect(APP_URL . '/pages/tp_sessions.php');
    }
    $steps = $conn->query("SELECT * FROM tp_steps WHERE tp_id = $id ORDER BY step_number")->fetch_all(MYSQLI_ASSOC);
    $materials = $conn->query("SELECT * FROM tp_materials WHERE tp_id = $id ORDER BY type, name")->fetch_all(MYSQLI_ASSOC);
    $checklists = $conn->query("SELECT * FROM tp_checklists WHERE tp_id = $id ORDER BY phase, id")->fetch_all(MYSQLI_ASSOC);
    $quizzes = $conn->query("SELECT * FROM tp_quizzes WHERE tp_id = $id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $title = trim($_POST['title'] ?? '');
    $objectives = trim($_POST['objectives'] ?? '');
    $skills = trim($_POST['skills'] ?? '');
    $durationInput = trim($_POST['duration'] ?? '');
    $duration = $durationInput === '' ? null : (int)$durationInput;
    $class_id = !empty($_POST['class_id']) ? (int) $_POST['class_id'] : null;
    if ($title === '' || $class_id === null || $objectives === '' || $skills === '') {
        $error = 'Veuillez remplir tous les champs requis (Titre, Classe, Objectives, Skills).';
    } else {
        try {
            if (!$isAdmin) {
                $chk = $conn->prepare('SELECT id FROM classes WHERE id = ? AND teacher_id = ? LIMIT 1');
                $chk->bind_param('ii', $class_id, $teacherId);
                $chk->execute();
                if ($chk->get_result()->num_rows === 0) throw new Exception('Forbidden');
            }
            if ($duration === null) {
                $duration = isset($session['duration']) && $session['duration'] !== null ? (int)$session['duration'] : 60;
            }
            if ($id) {
                $stmt = $conn->prepare('UPDATE tp_sessions SET title=?, objectives=?, skills=?, duration=?, class_id=? WHERE id=?');
                $stmt->bind_param('sssiii', $title, $objectives, $skills, $duration, $class_id, $id);
                $stmt->execute();
                $sid = $id;
            } else {
                $stmt = $conn->prepare('INSERT INTO tp_sessions (title, objectives, skills, duration, class_id) VALUES (?,?,?,?,?)');
                $stmt->bind_param('sssii', $title, $objectives, $skills, $duration, $class_id);
                $stmt->execute();
                $sid = $conn->insert_id;
            }

            // Steps (tp_id, step_number, description)
            $conn->query("DELETE FROM tp_steps WHERE tp_id = $sid");
            if (!empty($_POST['step_description'])) {
                $st = $conn->prepare('INSERT INTO tp_steps (tp_id, step_number, description) VALUES (?,?,?)');
                foreach ($_POST['step_description'] as $i => $desc) {
                    $desc = trim($desc);
                    if ($desc === '') continue;
                    $ord = $i + 1;
                    $st->bind_param('iis', $sid, $ord, $desc);
                    $st->execute();
                }
            }

            // Materials (tp_id, name, type)
            $conn->query("DELETE FROM tp_materials WHERE tp_id = $sid");
            if (!empty($_POST['mat_name'])) {
                $mt = $conn->prepare('INSERT INTO tp_materials (tp_id, name, type) VALUES (?,?,?)');
                foreach ($_POST['mat_name'] as $i => $name) {
                    $name = trim($name);
                    if ($name === '') continue;
                    $type = $_POST['mat_type'][$i] ?? 'reagent';
                    $mt->bind_param('iss', $sid, $name, $type);
                    $mt->execute();
                }
            }

            // Checklists (tp_id, phase, item, is_done)
            $conn->query("DELETE FROM tp_checklists WHERE tp_id = $sid");
            if (!empty($_POST['check_phase'])) {
                $ct = $conn->prepare('INSERT INTO tp_checklists (tp_id, phase, item, is_done) VALUES (?,?,?,0)');
                foreach ($_POST['check_phase'] as $i => $phase) {
                    $text = trim($_POST['check_text'][$i] ?? '');
                    if ($text === '') continue;
                    $ct->bind_param('iss', $sid, $phase, $text);
                    $ct->execute();
                }
            }

            // Quizzes (tp_id, question, option_a, option_b, option_c, option_d, correct_option)
            $conn->query("DELETE FROM tp_quizzes WHERE tp_id = $sid");
            if (!empty($_POST['quiz_question'])) {
                $qt = $conn->prepare('INSERT INTO tp_quizzes (tp_id, question, option_a, option_b, option_c, option_d, correct_option) VALUES (?,?,?,?,?,?,?)');
                $corrects = $_POST['quiz_correct'] ?? [];
                foreach ($_POST['quiz_question'] as $i => $q) {
                    $q = trim($q);
                    if ($q === '') continue;
                    $a = trim($_POST['quiz_a'][$i] ?? '');
                    $b = trim($_POST['quiz_b'][$i] ?? '');
                    $c = trim($_POST['quiz_c'][$i] ?? '');
                    $d = trim($_POST['quiz_d'][$i] ?? '');
                    $cor = $corrects[$i] ?? 'A';
                    $qt->bind_param('issssss', $sid, $q, $a, $b, $c, $d, $cor);
                    $qt->execute();
                }
            }

            flash('success', $id ? 'TP session updated.' : 'TP session created.');
            redirect(APP_URL . '/pages/tp_view.php?id=' . $sid);
        } catch (Exception $e) {
            $error = 'Save failed: ' . $e->getMessage();
        }
    }
}

$classesList = [];
if ($isAdmin) {
    $classesList = $conn->query('SELECT id, name FROM classes ORDER BY name')->fetch_all(MYSQLI_ASSOC);
} else {
    $stmt = $conn->prepare('SELECT id, name FROM classes WHERE teacher_id = ? ORDER BY name');
    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    $classesList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$pageTitle = ($id ? 'Edit' : 'New') . ' TP Session - ' . APP_NAME;
require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/navbar.php';
?>
<main class="container py-4">
    <h1 class="page-title mb-4"><?= $id ? 'Edit' : 'New' ?> TP Session</h1>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= escape($error) ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <?= csrf_field() ?>
        <div class="card mb-4">
            <div class="card-header bg-white"><h5 class="mb-0">Session details</h5></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Title *</label>
                        <input type="text" name="title" class="form-control" value="<?= escape($session['title'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Class *</label>
                        <select name="class_id" class="form-select" required>
                            <option value="">— None —</option>
                            <?php foreach ($classesList as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= (isset($session['class_id']) && (int)$session['class_id'] === (int)$c['id']) ? 'selected' : '' ?>><?= escape($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Objectives *</label>
                        <div data-ok-textarea>
                            <textarea name="objectives" class="form-control js-required" rows="4" required><?= escape($session['objectives'] ?? '') ?></textarea>
                            <div class="d-flex justify-content-end gap-2 mt-2">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-ok-btn>OK</button>
                                <button type="button" class="btn btn-sm btn-link d-none" data-edit-btn>Modifier</button>
                            </div>
                            <div class="border rounded p-2 d-none" data-preview></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Skills *</label>
                        <div data-ok-textarea>
                            <textarea name="skills" class="form-control js-required" rows="4" required placeholder="e.g. pipetting, titration"><?= escape($session['skills'] ?? '') ?></textarea>
                            <div class="d-flex justify-content-end gap-2 mt-2">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-ok-btn>OK</button>
                                <button type="button" class="btn btn-sm btn-link d-none" data-edit-btn>Modifier</button>
                            </div>
                            <div class="border rounded p-2 d-none" data-preview></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Durée (min)</label>
                        <?php
                        $currentDuration = 60;
                        if (isset($session['duration']) && $session['duration'] !== null) {
                            $currentDuration = (int)$session['duration'];
                        }
                        ?>
                        <input type="number" name="duration" class="form-control" value="<?= $currentDuration ?>" min="1">
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Steps</h5>
                <button type="button" class="btn btn-sm btn-outline-primary" id="addStep">+ Add step</button>
            </div>
            <div class="card-body">
                <div id="stepsContainer">
                    <?php foreach (array_merge($steps, [['description'=>'']]) as $idx => $step): ?>
                    <div class="step-row border-bottom py-2 mb-2">
                        <div class="row g-2">
                            <div class="col-md-11">
                                <textarea name="step_description[]" class="form-control form-control-sm" rows="2" placeholder="Description de l'étape"><?= escape($step['description'] ?? '') ?></textarea>
                            </div>
                            <div class="col-md-1">
                                <button type="button" class="btn btn-sm btn-outline-danger remove-step">×</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Materials (reagents / equipment)</h5>
                <button type="button" class="btn btn-sm btn-outline-primary" id="addMat">+ Add</button>
            </div>
            <div class="card-body">
                <div id="matsContainer">
                    <?php
                    $mats = $materials;
                    if (empty($mats)) $mats = [['type'=>'reagent','name'=>'']];
                    foreach ($mats as $m):
                    ?>
                    <div class="mat-row row g-2 mb-2">
                        <div class="col-md-2">
                            <select name="mat_type[]" class="form-select form-select-sm">
                                <option value="reagent" <?= ($m['type'] ?? '') === 'reagent' ? 'selected' : '' ?>>Réactif</option>
                                <option value="equipment" <?= ($m['type'] ?? '') === 'equipment' ? 'selected' : '' ?>>Équipement</option>
                            </select>
                        </div>
                        <div class="col-md-8"><input type="text" name="mat_name[]" class="form-control form-control-sm" placeholder="Nom" value="<?= escape($m['name'] ?? '') ?>"></div>
                        <div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger remove-mat">×</button></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Checklist (before / during / after)</h5>
                <button type="button" class="btn btn-sm btn-outline-primary" id="addCheck">+ Add</button>
            </div>
            <div class="card-body">
                <div id="checkContainer">
                    <?php
                    $checks = $checklists;
                    if (empty($checks)) $checks = [['phase'=>'before','item'=>'']];
                    foreach ($checks as $ch):
                    ?>
                    <div class="check-row row g-2 mb-2">
                        <div class="col-md-2">
                            <select name="check_phase[]" class="form-select form-select-sm">
                                <option value="before" <?= ($ch['phase'] ?? '') === 'before' ? 'selected' : '' ?>>Avant</option>
                                <option value="during" <?= ($ch['phase'] ?? '') === 'during' ? 'selected' : '' ?>>Pendant</option>
                                <option value="after" <?= ($ch['phase'] ?? '') === 'after' ? 'selected' : '' ?>>Après</option>
                            </select>
                        </div>
                        <div class="col-md-9"><input type="text" name="check_text[]" class="form-control form-control-sm" placeholder="Élément" value="<?= escape($ch['item'] ?? '') ?>"></div>
                        <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger remove-check">×</button></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Mini-quiz questions</h5>
                <button type="button" class="btn btn-sm btn-outline-primary" id="addQuiz">+ Add question</button>
            </div>
            <div class="card-body">
                <div id="quizContainer">
                    <?php
                    $quizzes = $quizzes;
                    if (empty($quizzes)) $quizzes = [['question'=>'','option_a'=>'','option_b'=>'','option_c'=>'','option_d'=>'','correct_option'=>'A']];
                    foreach ($quizzes as $qz):
                    ?>
                    <div class="quiz-row card mb-3">
                        <div class="card-body">
                            <input type="text" name="quiz_question[]" class="form-control mb-2" placeholder="Question" value="<?= escape($qz['question'] ?? '') ?>">
                            <div class="row g-2">
                                <div class="col-md-6"><input type="text" name="quiz_a[]" class="form-control form-control-sm" placeholder="A" value="<?= escape($qz['option_a'] ?? '') ?>"></div>
                                <div class="col-md-6"><input type="text" name="quiz_b[]" class="form-control form-control-sm" placeholder="B" value="<?= escape($qz['option_b'] ?? '') ?>"></div>
                                <div class="col-md-6"><input type="text" name="quiz_c[]" class="form-control form-control-sm" placeholder="C" value="<?= escape($qz['option_c'] ?? '') ?>"></div>
                                <div class="col-md-6"><input type="text" name="quiz_d[]" class="form-control form-control-sm" placeholder="D" value="<?= escape($qz['option_d'] ?? '') ?>"></div>
                            </div>
                            <div class="mt-2">
                                <label class="me-2 small">Correct:</label>
                                <?php $co = $qz['correct_option'] ?? 'A'; ?>
                                <label class="me-2"><input type="radio" name="quiz_correct[]" value="A" <?= $co==='A'?'checked':'' ?>> A</label>
                                <label class="me-2"><input type="radio" name="quiz_correct[]" value="B" <?= $co==='B'?'checked':'' ?>> B</label>
                                <label class="me-2"><input type="radio" name="quiz_correct[]" value="C" <?= $co==='C'?'checked':'' ?>> C</label>
                                <label class="me-2"><input type="radio" name="quiz_correct[]" value="D" <?= $co==='D'?'checked':'' ?>> D</label>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger mt-2 remove-quiz">Remove question</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <button type="submit" class="btn btn-primary">Save TP session</button>
            <a href="<?= APP_URL ?>/pages/tp_sessions.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</main>
<script>
(function() {
    // Front validation + highlight required textareas
    var form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            var invalid = [];
            form.querySelectorAll('.js-required').forEach(function(el) {
                if (!(el.value || '').trim()) invalid.push(el);
            });
            invalid.forEach(function(el) { el.classList.add('is-invalid'); });
            if (invalid.length) {
                e.preventDefault();
                invalid[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
        form.querySelectorAll('.js-required').forEach(function(el) {
            el.addEventListener('input', function() { this.classList.remove('is-invalid'); });
        });
    }

    function addStep() {
        var html = '<div class="step-row border-bottom py-2 mb-2">' +
            '<div class="row g-2">' +
            '<div class="col-md-11">' +
            '<textarea name="step_description[]" class="form-control form-control-sm" rows="2" placeholder="Description de l\'étape"></textarea>' +
            '</div>' +
            '<div class="col-md-1">' +
            '<button type="button" class="btn btn-sm btn-outline-danger remove-step">×</button>' +
            '</div>' +
            '</div>' +
            '</div>';
        document.getElementById('stepsContainer').insertAdjacentHTML('beforeend', html);
    }
    function addMat() {
        var html = '<div class="mat-row row g-2 mb-2"><div class="col-md-2"><select name="mat_type[]" class="form-select form-select-sm"><option value="reagent">Réactif</option><option value="equipment">Équipement</option></select></div><div class="col-md-8"><input type="text" name="mat_name[]" class="form-control form-control-sm" placeholder="Nom"></div><div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger remove-mat">×</button></div></div>';
        document.getElementById('matsContainer').insertAdjacentHTML('beforeend', html);
    }
    function addCheck() {
        var html = '<div class="check-row row g-2 mb-2"><div class="col-md-2"><select name="check_phase[]" class="form-select form-select-sm"><option value="before">Before</option><option value="during">During</option><option value="after">After</option></select></div><div class="col-md-9"><input type="text" name="check_text[]" class="form-control form-control-sm" placeholder="Item"></div><div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger remove-check">×</button></div></div>';
        document.getElementById('checkContainer').insertAdjacentHTML('beforeend', html);
    }
    function addQuiz() {
        var idx = document.querySelectorAll('.quiz-row').length;
        var html = '<div class="quiz-row card mb-3"><div class="card-body"><input type="text" name="quiz_question[]" class="form-control mb-2" placeholder="Question"><div class="row g-2"><div class="col-md-6"><input type="text" name="quiz_a[]" class="form-control form-control-sm" placeholder="A"></div><div class="col-md-6"><input type="text" name="quiz_b[]" class="form-control form-control-sm" placeholder="B"></div><div class="col-md-6"><input type="text" name="quiz_c[]" class="form-control form-control-sm" placeholder="C"></div><div class="col-md-6"><input type="text" name="quiz_d[]" class="form-control form-control-sm" placeholder="D"></div></div><div class="mt-2"><label class="me-2 small">Correct:</label><label class="me-2"><input type="radio" name="quiz_correct[]" value="A" checked> A</label><label class="me-2"><input type="radio" name="quiz_correct[]" value="B"> B</label><label class="me-2"><input type="radio" name="quiz_correct[]" value="C"> C</label><label class="me-2"><input type="radio" name="quiz_correct[]" value="D"> D</label></div><button type="button" class="btn btn-sm btn-outline-danger mt-2 remove-quiz">Remove question</button></div></div>';
        document.getElementById('quizContainer').insertAdjacentHTML('beforeend', html);
    }
    document.getElementById('addStep').addEventListener('click', addStep);
    document.getElementById('addMat').addEventListener('click', addMat);
    document.getElementById('addCheck').addEventListener('click', addCheck);
    document.getElementById('addQuiz').addEventListener('click', addQuiz);
    document.getElementById('stepsContainer').addEventListener('click', function(e) { if (e.target.classList.contains('remove-step')) e.target.closest('.step-row').remove(); });
    document.getElementById('matsContainer').addEventListener('click', function(e) { if (e.target.classList.contains('remove-mat')) e.target.closest('.mat-row').remove(); });
    document.getElementById('checkContainer').addEventListener('click', function(e) { if (e.target.classList.contains('remove-check')) e.target.closest('.check-row').remove(); });
    document.getElementById('quizContainer').addEventListener('click', function(e) { if (e.target.classList.contains('remove-quiz')) e.target.closest('.quiz-row').remove(); });
})();
</script>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
