<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/connect_db.php';

$viewerRole = isset($_POST['viewer_role']) ? trim($_POST['viewer_role']) : 'admin';
if (!in_array($viewerRole, ['admin', 'driver'], true)) {
    $viewerRole = 'admin';
}

// ─────── Auth: session OR driver_id fallback ───────
if (!isset($_SESSION['user_id'])) {
    if ($viewerRole === 'driver' && isset($_POST['driver_id']) && (int)$_POST['driver_id'] > 0) {
        $_SESSION['user_id']   = (int)$_POST['driver_id'];
        $_SESSION['acctlevel'] = 'driver';
    } else {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
}

$assignmentId = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0;

if ($assignmentId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid assignment id']);
    exit;
}

try {
    $cStmt = $conn->prepare("SELECT id FROM chat_conversations WHERE assignment_id = ? LIMIT 1");
    $cStmt->bind_param('i', $assignmentId);
    $cStmt->execute();
    $conv = $cStmt->get_result()->fetch_assoc();
    $cStmt->close();

    if (!$conv) {
        echo json_encode(['success' => true, 'updated' => 0]);
        exit;
    }

    $conversationId = (int)$conv['id'];
    $oppositeRole   = ($viewerRole === 'admin') ? 'driver' : 'admin';

    $uStmt = $conn->prepare("
        UPDATE chat_messages
        SET is_read = 1, read_at = NOW()
        WHERE conversation_id = ?
          AND sender_role = ?
          AND is_read = 0
    ");
    $uStmt->bind_param('is', $conversationId, $oppositeRole);
    $uStmt->execute();
    $updated = $uStmt->affected_rows;
    $uStmt->close();

    echo json_encode(['success' => true, 'updated' => $updated]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}