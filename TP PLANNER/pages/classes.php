<?php
require_once dirname(__DIR__) . '/config/config.php';
requireTeacher();

$conn = getDB();
$message = '';
$error = '';
$teacherId = currentTeacherId();
$isAdmin = isAdmin();

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id']) && verify_csrf()) {
    $id = (int) $_POST['delete_id'];
    try {
        if ($isAdmin) {
            $conn->query("DELETE FROM classes WHERE id = $id");
        } else {
            $conn->query("DELETE FROM classes WHERE id = $id AND teacher_id = $teacherId");
            if ($conn->affected_rows === 0) throw new Exception('Forbidden');
        }
        flash('success', 'Class deleted.');
        redirect(APP_URL . '/pages/classes.php');
    } catch (Exception $e) {
        $error = 'Could not delete (maybe in use).';
    }
}

// Create/Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $name = trim($_POST['name'] ?? '');
    $teacher_id = null;
    if ($isAdmin) {
        $teacher_id = !empty($_POST['teacher_id']) ? (int) $_POST['teacher_id'] : null;
    } else {
        $teacher_id = $teacherId;
    }
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($name === '') {
        $error = 'Le nom est requis.';
    } else {
        try {
            if ($id) {
                if ($isAdmin) {
                    $stmt = $conn->prepare('UPDATE classes SET name = ?, teacher_id = ? WHERE id = ?');
                    $stmt->bind_param('sii', $name, $teacher_id, $id);
                } else {
                    $stmt = $conn->prepare('UPDATE classes SET name = ?, teacher_id = ? WHERE id = ? AND teacher_id = ?');
                    $stmt->bind_param('siii', $name, $teacher_id, $id, $teacherId);
                }
                $stmt->execute();
                if (!$isAdmin && $stmt->affected_rows === 0) throw new Exception('Forbidden');
                flash('success', 'Classe mise à jour.');
            } else {
                $stmt = $conn->prepare('INSERT INTO classes (name, teacher_id) VALUES (?, ?)');
                $stmt->bind_param('si', $name, $teacher_id);
                $stmt->execute();
                flash('success', 'Classe créée.');
            }
            redirect(APP_URL . '/pages/classes.php');
        } catch (Exception $e) {
            $error = 'Enregistrement impossible.';
        }
    }
}

// Teachers for dropdown
$teachers = [];
if ($isAdmin) {
    $teachers = $conn->query("SELECT id, name FROM users WHERE role IN ('teacher','admin') ORDER BY name")->fetch_all(MYSQLI_ASSOC);
}

// List with search
$search = trim($_GET['search'] ?? '');
$sql = 'SELECT c.id, c.name, c.teacher_id,
        (SELECT COUNT(*) FROM tp_sessions WHERE class_id = c.id) AS session_count
        FROM classes c WHERE 1=1';
$params = [];
$types = '';
if (!$isAdmin) {
    $sql .= ' AND c.teacher_id = ?';
    $params[] = $teacherId;
    $types .= 'i';
}
if ($search !== '') {
    $sql .= ' AND c.name LIKE ?';
    $params[] = "%$search%";
    $types .= 's';
}
$sql .= ' ORDER BY c.name';
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
foreach ($classes as &$c) {
    $c['teacher_name'] = null;
    if (!empty($c['teacher_id'])) {
        foreach ($teachers as $t) {
            if ((int)$t['id'] === (int)$c['teacher_id']) { $c['teacher_name'] = $t['name']; break; }
        }
        if (!$c['teacher_name'] && !$isAdmin) $c['teacher_name'] = $_SESSION['username'] ?? null;
    }
}
unset($c);

// Edit one
$edit = null;
if (isset($_GET['id']) && !isset($_GET['delete'])) {
    $editId = (int) $_GET['id'];
    foreach ($classes as $c) {
        if ((int)$c['id'] === $editId) { $edit = $c; break; }
    }
    if (!$edit) {
        $r = $conn->query("SELECT id, name, teacher_id FROM classes WHERE id = $editId");
        if ($r && $row = $r->fetch_assoc()) $edit = $row;
    }
}

$pageTitle = 'Classes - ' . APP_NAME;
require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/navbar.php';
?>
<main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="page-title mb-0">Classes</h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#classModal" data-action="create">
            <i class="bi bi-plus-lg me-1"></i> Add class
        </button>
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
            <form method="get" class="row g-2">
                <div class="col-md-8">
                    <input type="text" name="search" class="form-control" placeholder="Search classes..." value="<?= escape($search) ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-outline-primary me-2">Search</button>
                    <a href="<?= APP_URL ?>/pages/classes.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($classes)): ?>
                <div class="empty-state">
                    <i class="bi bi-people"></i>
                    <p class="mb-0">No classes found</p>
                    <button type="button" class="btn btn-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#classModal">Add class</button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Nom</th>
                                <th>Enseignant</th>
                                <th>Sessions TP</th>
                                <th width="140">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classes as $c): ?>
                                <tr>
                                    <td><strong><?= escape($c['name']) ?></strong></td>
                                    <td><?= escape($c['teacher_name'] ?? '—') ?></td>
                                    <td><span class="badge bg-secondary"><?= (int)($c['session_count'] ?? 0) ?></span></td>
                                    <td>
                                        <a href="<?= APP_URL ?>/pages/tp_sessions.php?class_id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary">Sessions</a>
                                        <button type="button" class="btn btn-sm btn-outline-secondary btn-edit-class" data-id="<?= (int)$c['id'] ?>" data-name="<?= escape($c['name']) ?>" data-teacher="<?= (int)($c['teacher_id'] ?? 0) ?>">Modifier</button>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette classe ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="delete_id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer</button>
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

<!-- Class modal -->
<div class="modal fade" id="classModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="id" id="classId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="classModalTitle">Add class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nom *</label>
                        <input type="text" name="name" id="className" class="form-control" required>
                    </div>
    <?php if ($isAdmin): ?>
        <div class="mb-3">
            <label class="form-label">Enseignant</label>
            <select name="teacher_id" id="classTeacher" class="form-select">
                <option value="">— Aucun —</option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"><?= escape($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.btn-edit-class').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('classId').value = this.dataset.id;
        document.getElementById('className').value = this.dataset.name;
        var teacherSelect = document.getElementById('classTeacher');
        if (teacherSelect) teacherSelect.value = this.dataset.teacher || '';
        document.getElementById('classModalTitle').textContent = 'Modifier la classe';
        new bootstrap.Modal(document.getElementById('classModal')).show();
    });
});
document.getElementById('classModal').addEventListener('show.bs.modal', function(e) {
    if (e.relatedTarget && e.relatedTarget.dataset.action === 'create') {
        document.getElementById('classId').value = '';
        document.getElementById('className').value = '';
        var teacherSelect = document.getElementById('classTeacher');
        if (teacherSelect) teacherSelect.value = '';
        document.getElementById('classModalTitle').textContent = 'Ajouter une classe';
    }
});
</script>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
