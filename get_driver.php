<?php
// get_driver.php — Fetch driver_assignments row by destination, then get driver from acct
header('Content-Type: application/json');

$db_host = '162.159.24.67';
$db_user = 'root';
$db_pass = '';
$db_name = 'uam_logistics';

$destination = isset($_GET['destination']) ? trim($_GET['destination']) : '';

if ($destination === '') {
    echo json_encode(['success' => false, 'error' => 'No destination provided']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // 1. Get the active assignment row for this destination
    $stmt = $pdo->prepare("
        SELECT id, driver_id, vehicle_id, destination, notes, status, assigned_at, unassigned_at
        FROM driver_assignments
        WHERE status = 'active'
          AND LOWER(TRIM(destination)) = LOWER(TRIM(:dest))
        ORDER BY assigned_at DESC
        LIMIT 1
    ");
    $stmt->execute([':dest' => $destination]);
    $assignment = $stmt->fetch();

    if (!$assignment) {
        echo json_encode(['success' => false, 'error' => 'No active assignment found']);
        exit;
    }

    // 2. Get the driver name from acct using driver_id
    $driverName = null;
    $driverUsername = null;

    if (!empty($assignment['driver_id'])) {
        $dStmt = $pdo->prepare("SELECT id, name, username FROM acct WHERE id = :id LIMIT 1");
        $dStmt->execute([':id' => $assignment['driver_id']]);
        $driver = $dStmt->fetch();
        if ($driver) {
            $driverName     = $driver['name'];
            $driverUsername = $driver['username'];
        }
    }

    echo json_encode([
        'success'          => true,
        'assignment_id'    => $assignment['id'],
        'driver_id'        => $assignment['driver_id'],
        'driver_name'      => $driverName,
        'driver_username'  => $driverUsername,
        'vehicle_id'       => $assignment['vehicle_id'],
        'destination'      => $assignment['destination'],
        'notes'            => $assignment['notes'],
        'status'           => $assignment['status'],
        'assigned_at'      => $assignment['assigned_at'],
        'unassigned_at'    => $assignment['unassigned_at'],
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
