<?php
// get_conversation.php — Fetch all messages for a given assignment (read-only history view)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/connect_db.php';

$assignmentId = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;

if ($assignmentId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid assignment id']);
    exit;
}

try {
    // 1. Find the conversation for this assignment
    $cStmt = $conn->prepare("SELECT id FROM chat_conversations WHERE assignment_id = ? LIMIT 1");
    $cStmt->bind_param('i', $assignmentId);
    $cStmt->execute();
    $conv = $cStmt->get_result()->fetch_assoc();
    $cStmt->close();

    if (!$conv) {
        echo json_encode([
            'success'   => true,
            'messages'  => [],
        ]);
        exit;
    }

    $conversationId = (int)$conv['id'];

    // 2. Fetch all messages
    $mStmt = $conn->prepare("
        SELECT id, sender_id, sender_role, message_type, message,
               attachment_path, attachment_name, attachment_mime, attachment_size,
               is_read, created_at
        FROM chat_messages
        WHERE conversation_id = ?
        ORDER BY id ASC
    ");
    $mStmt->bind_param('i', $conversationId);
    $mStmt->execute();
    $rows = $mStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $mStmt->close();

    echo json_encode([
        'success'         => true,
        'conversation_id' => $conversationId,
        'messages'        => $rows,
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
