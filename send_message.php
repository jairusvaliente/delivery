<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/connect_db.php';

$senderRole = isset($_POST['sender_role']) ? trim($_POST['sender_role']) : 'admin';
if (!in_array($senderRole, ['admin', 'driver'], true)) {
    $senderRole = 'admin';
}

// ─────── Auth: session OR driver_id fallback ───────
if (!isset($_SESSION['user_id'])) {
    if ($senderRole === 'driver' && isset($_POST['driver_id']) && (int)$_POST['driver_id'] > 0) {
        $_SESSION['user_id']   = (int)$_POST['driver_id'];
        $_SESSION['acctlevel'] = 'driver';
    } else {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
}

$assignmentId = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0;
$message      = isset($_POST['message'])       ? trim($_POST['message'])       : '';

if ($assignmentId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid assignment id']);
    exit;
}
if (mb_strlen($message) > 2000) {
    echo json_encode(['success' => false, 'error' => 'Message too long (max 2000 chars)']);
    exit;
}

$senderId = (int)$_SESSION['user_id'];
$hasFile  = isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE;

if ($message === '' && !$hasFile) {
    echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
    exit;
}

$allowedMime = [
    'image/jpeg'=>'image','image/png'=>'image','image/gif'=>'image','image/webp'=>'image',
    'video/mp4'=>'video','video/quicktime'=>'video','video/x-msvideo'=>'video','video/x-matroska'=>'video','video/webm'=>'video',
    'application/pdf'=>'file','application/msword'=>'file',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'file',
    'application/vnd.ms-excel'=>'file',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'file',
    'text/plain'=>'file','text/csv'=>'file',
    'application/zip'=>'file','application/x-rar-compressed'=>'file','application/x-rar'=>'file',
];
$extMap = [
    'image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp',
    'video/mp4'=>'mp4','video/quicktime'=>'mov','video/x-msvideo'=>'avi','video/x-matroska'=>'mkv','video/webm'=>'webm',
    'application/pdf'=>'pdf','application/msword'=>'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
    'application/vnd.ms-excel'=>'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx',
    'text/plain'=>'txt','text/csv'=>'csv',
    'application/zip'=>'zip','application/x-rar-compressed'=>'rar','application/x-rar'=>'rar',
];

$maxSize = 50 * 1024 * 1024;

$attachPath = null;
$attachName = null;
$attachMime = null;
$attachSize = null;
$messageType = 'text';

try {
    // Validate assignment
    $aStmt = $conn->prepare("SELECT id, driver_id FROM driver_assignments WHERE id = ? LIMIT 1");
    $aStmt->bind_param('i', $assignmentId);
    $aStmt->execute();
    $assignment = $aStmt->get_result()->fetch_assoc();
    $aStmt->close();

    if (!$assignment) {
        echo json_encode(['success' => false, 'error' => 'Assignment not found']);
        exit;
    }
    $driverId = (int)$assignment['driver_id'];

    // File upload
    if ($hasFile) {
        $file = $_FILES['attachment'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'Upload error code: ' . $file['error']]);
            exit;
        }
        if ($file['size'] > $maxSize) {
            echo json_encode(['success' => false, 'error' => 'File too large (max 50 MB)']);
            exit;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!isset($allowedMime[$mime])) {
            echo json_encode(['success' => false, 'error' => 'File type not allowed: ' . $mime]);
            exit;
        }

        $messageType = $allowedMime[$mime];
        $ext         = $extMap[$mime] ?? 'bin';
        $subDir      = 'uploads/chat/' . date('Y/m');
        $absDir      = __DIR__ . '/' . $subDir;

        if (!is_dir($absDir) && !mkdir($absDir, 0755, true)) {
            echo json_encode(['success' => false, 'error' => 'Cannot create upload folder']);
            exit;
        }

        $safeName = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
        $absPath  = $absDir . '/' . $safeName;
        $relPath  = $subDir . '/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $absPath)) {
            echo json_encode(['success' => false, 'error' => 'Failed to save file']);
            exit;
        }

        $attachPath = $relPath;
        $attachName = basename($file['name']);
        $attachMime = $mime;
        $attachSize = (int)$file['size'];
    }

    // Find or create conversation
    $cStmt = $conn->prepare("SELECT id FROM chat_conversations WHERE assignment_id = ? LIMIT 1");
    $cStmt->bind_param('i', $assignmentId);
    $cStmt->execute();
    $conv = $cStmt->get_result()->fetch_assoc();
    $cStmt->close();

    if ($conv) {
        $conversationId = (int)$conv['id'];
    } else {
        $adminId = ($senderRole === 'admin') ? $senderId : 1;
        $ins = $conn->prepare("INSERT INTO chat_conversations (assignment_id, driver_id, admin_id) VALUES (?, ?, ?)");
        $ins->bind_param('iii', $assignmentId, $driverId, $adminId);
        $ins->execute();
        $conversationId = (int)$conn->insert_id;
        $ins->close();
    }

    // Insert message
    $mStmt = $conn->prepare("
        INSERT INTO chat_messages
            (conversation_id, sender_id, sender_role, message_type, message,
             attachment_path, attachment_name, attachment_mime, attachment_size, is_read)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
    ");
    $mStmt->bind_param(
        'iissssssi',
        $conversationId, $senderId, $senderRole, $messageType, $message,
        $attachPath, $attachName, $attachMime, $attachSize
    );
    $mStmt->execute();
    $messageId = (int)$conn->insert_id;
    $mStmt->close();

    $upd = $conn->prepare("UPDATE chat_conversations SET updated_at = NOW(), last_message_id = ? WHERE id = ?");
    $upd->bind_param('ii', $messageId, $conversationId);
    $upd->execute();
    $upd->close();

    echo json_encode([
        'success'         => true,
        'message_id'      => $messageId,
        'conversation_id' => $conversationId,
        'sender_role'     => $senderRole,
        'message_type'    => $messageType,
        'message'         => $message,
        'attachment_path' => $attachPath,
        'attachment_name' => $attachName,
        'attachment_mime' => $attachMime,
        'attachment_size' => $attachSize,
        'created_at'      => date('Y-m-d H:i:s')
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine()
    ]);
}
