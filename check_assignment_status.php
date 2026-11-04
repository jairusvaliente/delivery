<?php
// check_assignment_status.php — Returns the current status of an assignment
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/connect_db.php';

$assignmentId = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;
if ($assignmentId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing assignment_id']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT id, status, unassigned_at
        FROM driver_assignments
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $assignmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Not found']);
        exit;
    }

    echo json_encode([
        'success'       => true,
        'assignment_id' => (int)$row['id'],
        'status'        => $row['status'],
        'is_active'     => ($row['status'] === 'active'),
        'unassigned_at' => $row['unassigned_at'],
    ]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
