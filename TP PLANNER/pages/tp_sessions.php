<?php
require_once dirname(__DIR__) . '/config/config.php';
requireTeacher();

$conn = getDB();
$error = '';
$teacherId = currentTeacherId();
$isAdmin = isAdmin();

// Delete (schema: tp_id for child tables, quiz_answers.quiz_id)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id']) && verify_csrf()) {
    $id = (int) $_POST['delete_id'];
    try {
        if (!$isAdmin) {
            $chk = $conn->query("SELECT s.id FROM tp_sessions s JOIN classes c ON c.id = s.class_id WHERE s.id = $id AND c.teacher_id = $teacherId LIMIT 1");
            if (!$chk || $chk->num_rows === 0) throw new Exception('Forbidden');
        }
        $conn->query("DELETE FROM quiz_answers WHERE quiz_id IN (SELECT id FROM tp_quizzes WHERE tp_id = $id)");
        $conn->query("DELETE FROM tp_materials WHERE tp_id = $id");
        $conn->query("DELETE FROM tp_checklists WHERE tp_id = $id");
        $conn->query("DELETE FROM tp_steps WHERE tp_id = $id");
        $conn->query("DELETE FROM tp_quizzes WHERE tp_id = $id");
        $conn->query("DELETE FROM tp_sessions WHERE id = $id");
        flash('success', 'Session TP supprimée.');
        redirect(APP_URL . '/pages/tp_sessions.php');
    } catch (Exception $e) {
        $error = 'Suppression impossible.';
    }
}

$search = trim($_GET['search'] ?? '');
$classFilter = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;
$sort = $_GET['sort'] ?? 'created';
$order = strtoupper($_GET['order'] ?? 'DESC');
if (!in_array($order, ['ASC', 'DESC'])) $order = 'DESC';

$sql = 'SELECT s.id, s.title, s.objectives, s.skills, s.duration, s.created_at, s.class_id, c.name AS class_name
        FROM tp_sessions s
        LEFT JOIN classes c ON c.id = s.class_id
        WHERE 1=1';
$params = [];
$types = '';
if (!$isAdmin) {
    $sql .= ' AND (c.teacher_id = ?)';
    $params[] = $teacherId;
    $types .= 'i';
}
if ($search !== '') {
    $sql .= ' AND (s.title LIKE ? OR s.objectives LIKE ? OR s.skills LIKE ?)';
    $p = "%$search%";
    $params = array_merge($params, [$p, $p, $p]);
    $types .= 'sss';
}
if ($classFilter > 0) {
    $sql .= ' AND s.class_id = ?';
    $params[] = $classFilter;
    $types .= 'i';
}
$sortCol = ['created' => 's.created_at', 'title' => 's.title', 'duration' => 's.duration'][$sort] ?? 's.created_at';
$sql .= " ORDER BY $sortCol $order";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$classesList = [];
if ($isAdmin) {
    $classesList = $conn->query('SELECT id, name FROM classes ORDER BY name')->fetch_all(MYSQLI_ASSOC);
} else {
    $stmt = $conn->prepare('SELECT id, name FROM classes WHERE teacher_id = ? ORDER BY name');
    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    $classesList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$pageTitle = 'TP Sessions - ' . APP_NAME;
require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/navbar.php';
?>
<main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="page-title mb-0">TP Sessions</h1>
        <a href="<?= APP_URL ?>/pages/tp_edit.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> New session</a>
    </div>

    <?php if ($msg = flash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= escape($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= escape($error) ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row g-3">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="Search by title, objectives, skills..." value="<?= escape($search) ?>">
                </div>
                <div class="col-md-3">
                    <select name="class_id" class="form-select">
                        <option value="">All classes</option>
                        <?php foreach ($classesList as $cl): ?>
                            <option value="<?= (int)$cl['id'] ?>" <?= $classFilter === (int)$cl['id'] ? 'selected' : '' ?>><?= escape($cl['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="sort" class="form-select">
                        <option value="created" <?= $sort === 'created' ? 'selected' : '' ?>>Date création</option>
                        <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>Title</option>
                        <option value="duration" <?= $sort === 'duration' ? 'selected' : '' ?>>Duration</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="order" class="form-select">
                        <option value="DESC" <?= $order === 'DESC' ? 'selected' : '' ?>>Newest first</option>
                        <option value="ASC" <?= $order === 'ASC' ? 'selected' : '' ?>>Oldest first</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($sessions)): ?>
                <div class="empty-state">
                    <i class="bi bi-journal-text"></i>
                    <p class="mb-0">No TP sessions found</p>
                    <a href="<?= APP_URL ?>/pages/tp_edit.php" class="btn btn-primary btn-sm mt-2">Create session</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Title</th>
                                <th>Class</th>
                                <th>Duration</th>
                                <th>Créé le</th>
                                <th width="200">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessions as $s): ?>
                                <tr>
                                    <td><strong><?= escape($s['title']) ?></strong></td>
                                    <td><?= escape($s['class_name'] ?? '-') ?></td>
                                    <?php
                                    $dur = 60;
                                    if (isset($s['duration']) && $s['duration'] !== null) {
                                        $dur = (int)$s['duration'];
                                    }
                                    ?>
                                    <td><?= $dur ?> min</td>
                                    <td><?= !empty($s['created_at']) ? escape(date('d/m/Y', strtotime($s['created_at']))) : '—' ?></td>
                                    <td>
                                        <a href="<?= APP_URL ?>/pages/tp_view.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                                        <a href="<?= APP_URL ?>/pages/tp_edit.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this TP session?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="delete_id" value="<?= (int)$s['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
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
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
