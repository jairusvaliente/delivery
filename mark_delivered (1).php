<?php
// ─── TEMP DEBUG ─────────────────────────────────────
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ─────────────────────────────────────────────────────

// mark_delivered.php — Sets driver_assignments.status = 'delivered'
header('Content-Type: application/json');

// Make sure connect_db.php exists before requiring it
if (!file_exists(__DIR__ . '/connect_db.php')) {
    echo json_encode([
        'success' => false,
        'error'   => 'connect_db.php not found in ' . __DIR__
    ]);
    exit;
}

require_once __DIR__ . '/connect_db.php';

// Sanity check that $conn was returned
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode([
        'success' => false,
        'error'   => 'connect_db.php did not return a valid $conn'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$assignmentId = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0;

if ($assignmentId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid assignment id']);
    exit;
}

try {
    // Check the column width first — logs into the response for diagnosis
    $colInfo = $conn->query("SHOW COLUMNS FROM driver_assignments LIKE 'status'")->fetch_assoc();
    $colType = $colInfo['Type'] ?? 'UNKNOWN';

    // Check current status of this assignment
    $check = $conn->prepare("SELECT id, status FROM driver_assignments WHERE id = ?");
    $check->bind_param('i', $assignmentId);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$row) {
        echo json_encode([
            'success' => false,
            'error'   => 'Assignment ID not found',
            'debug'   => ['column_type' => $colType]
        ]);
        exit;
    }

    if ($row['status'] !== 'active') {
        echo json_encode([
            'success' => false,
            'error'   => 'Cannot deliver — current status is "' . $row['status'] . '"',
            'debug'   => ['column_type' => $colType]
        ]);
        exit;
    }

    // Perform the update
    $stmt = $conn->prepare("
        UPDATE driver_assignments
        SET status = 'delivered',
            unassigned_at = NOW()
        WHERE id = ?
          AND status = 'active'
    ");
    $stmt->bind_param('i', $assignmentId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        // Verify the value was stored correctly
        $verify = $conn->prepare("SELECT status, LENGTH(status) AS len FROM driver_assignments WHERE id = ?");
        $verify->bind_param('i', $assignmentId);
        $verify->execute();
        $check = $verify->get_result()->fetch_assoc();
        $verify->close();

        $storedStatus = $check['status'] ?? '';
        $storedLen    = (int)($check['len'] ?? 0);

        if ($storedStatus !== 'delivered') {
            echo json_encode([
                'success' => false,
                'error'   => 'Value was truncated by the column. Expected "delivered" (9 chars), got "' . $storedStatus . '" (' . $storedLen . ' chars).',
                'debug'   => ['column_type' => $colType]
            ]);
            exit;
        }

        echo json_encode([
            'success'       => true,
            'assignment_id' => $assignmentId,
            'new_status'    => $storedStatus,
            'debug'         => ['column_type' => $colType]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error'   => 'UPDATE affected 0 rows',
            'debug'   => ['column_type' => $colType]
        ]);
    }

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine()
    ]);
}