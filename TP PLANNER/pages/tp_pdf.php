<?php
require_once dirname(__DIR__) . '/config/config.php';
requireTeacher();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$id) {
    header('Location: ' . APP_URL . '/pages/tp_sessions.php');
    exit;
}

$conn = getDB();
$session = $conn->query("SELECT s.*, c.name AS class_name FROM tp_sessions s LEFT JOIN classes c ON c.id = s.class_id WHERE s.id = $id")->fetch_assoc();
if (!$session) {
    header('Location: ' . APP_URL . '/pages/tp_sessions.php');
    exit;
}

$steps = $conn->query("SELECT * FROM tp_steps WHERE tp_id = $id ORDER BY step_number")->fetch_all(MYSQLI_ASSOC);
$materials = $conn->query("SELECT * FROM tp_materials WHERE tp_id = $id ORDER BY type, name")->fetch_all(MYSQLI_ASSOC);
$checklists = $conn->query("SELECT * FROM tp_checklists WHERE tp_id = $id ORDER BY phase, id")->fetch_all(MYSQLI_ASSOC);
$quizzes = $conn->query("SELECT * FROM tp_quizzes WHERE tp_id = $id ORDER BY id")->fetch_all(MYSQLI_ASSOC);

// Students + quiz attempts (optional) for score export
$students = [];
$attempts = [];
try {
    $rS = $conn->query("SHOW TABLES LIKE 'students'");
    $rA = $conn->query("SHOW TABLES LIKE 'quiz_attempts'");
    if ($rS && $rS->num_rows && !empty($session['class_id'])) {
        $cid = (int)$session['class_id'];
        $students = $conn->query("SELECT id, name, email FROM students WHERE class_id = $cid ORDER BY name")->fetch_all(MYSQLI_ASSOC);
    }
    if ($rA && $rA->num_rows) {
        $res = $conn->query("SELECT student_id, total_points, max_points, percentage, submitted_at FROM quiz_attempts WHERE tp_id = $id");
        if ($res) while ($row = $res->fetch_assoc()) $attempts[(int)$row['student_id']] = $row;
    }
} catch (Exception $e) { }

$vendorAutoload = ROOT_PATH . '/vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    die('Please run: composer install (TCPDF is required for PDF export).');
}
require_once $vendorAutoload;

$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator(APP_NAME);
$pdf->SetTitle($session['title']);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10);

$html = '<h1>' . htmlspecialchars($session['title']) . '</h1>';
$dur = 60;
if (isset($session['duration']) && $session['duration'] !== null) $dur = (int)$session['duration'];
$html .= '<p><strong>Classe:</strong> ' . htmlspecialchars($session['class_name'] ?? '-') . ' &nbsp; <strong>Durée:</strong> ' . $dur . ' min</p>';

if (!empty($session['objectives'])) {
    $html .= '<h2>Objectives</h2><p>' . nl2br(htmlspecialchars($session['objectives'])) . '</p>';
}
if (!empty($session['skills'])) {
    $html .= '<p><strong>Skills:</strong> ' . htmlspecialchars($session['skills']) . '</p>';
}

$html .= '<h2>Étapes</h2><ol>';
foreach ($steps as $i => $st) {
    $html .= '<li>' . nl2br(htmlspecialchars($st['description'] ?? '')) . '</li>';
}
$html .= '</ol>';

$html .= '<h2>Matériel</h2><ul>';
foreach ($materials as $m) {
    $html .= '<li>' . htmlspecialchars($m['name']) . ' (' . htmlspecialchars($m['type']) . ')</li>';
}
$html .= '</ul>';

$byPhase = ['before' => 'Avant TP', 'during' => 'Pendant TP', 'after' => 'Après TP'];
$html .= '<h2>Checklist</h2>';
foreach (['before', 'during', 'after'] as $phase) {
    $items = array_filter($checklists, function($c) use ($phase) { return $c['phase'] === $phase; });
    if (empty($items)) continue;
    $html .= '<p><strong>' . $byPhase[$phase] . '</strong></p><ul>';
    foreach ($items as $ch) {
        $html .= '<li>' . htmlspecialchars($ch['item'] ?? '') . '</li>';
    }
    $html .= '</ul>';
}

$html .= '<h2>Mini-Quiz</h2>';
foreach ($quizzes as $i => $q) {
    $html .= '<p><strong>Q' . ($i+1) . ':</strong> ' . htmlspecialchars($q['question'] ?? '') . '</p>';
    $html .= '<p>A) ' . htmlspecialchars($q['option_a']) . ' &nbsp; B) ' . htmlspecialchars($q['option_b']) . ' &nbsp; C) ' . htmlspecialchars($q['option_c']) . ' &nbsp; D) ' . htmlspecialchars($q['option_d']) . ' &nbsp; <em>Réponse: ' . htmlspecialchars($q['correct_option']) . '</em></p>';
}

if (!empty($students) && !empty($attempts)) {
    $html .= '<h2>Scores (Mini-Quiz)</h2>';
    $html .= '<table border="1" cellpadding="4"><thead><tr><th>Étudiant</th><th>Score</th><th>%</th><th>Soumis le</th></tr></thead><tbody>';
    foreach ($students as $st) {
        $sid = (int)$st['id'];
        $a = $attempts[$sid] ?? null;
        if (!$a) continue;
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($st['name']) . ' (' . htmlspecialchars($st['email']) . ')</td>';
        $html .= '<td>' . (int)$a['total_points'] . ' / ' . (int)$a['max_points'] . '</td>';
        $html .= '<td>' . htmlspecialchars($a['percentage']) . '%</td>';
        $html .= '<td>' . htmlspecialchars($a['submitted_at']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
}

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output('TP_' . preg_replace('/[^a-z0-9]/i', '_', $session['title']) . '.pdf', 'I');
