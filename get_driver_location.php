<?php
// get_driver_location.php — Admin fetches the driver's latest GPS + off-route status
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/connect_db.php';

$driverId = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;

if ($driverId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing driver_id']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT driver_id, latitude, longitude, accuracy, heading, speed, updated_at,
               TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS seconds_ago,
               off_route, distance_off_route_m, nearest_route_lat, nearest_route_lng,
               off_route_since, off_route_count
        FROM driver_locations
        WHERE driver_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $driverId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'No location recorded']);
        exit;
    }

    echo json_encode([
        'success'              => true,
        'driver_id'            => (int)$row['driver_id'],
        'latitude'             => (float)$row['latitude'],
        'longitude'            => (float)$row['longitude'],
        'accuracy'             => $row['accuracy']   !== null ? (float)$row['accuracy']   : null,
        'heading'              => $row['heading']    !== null ? (float)$row['heading']    : null,
        'speed'                => $row['speed']      !== null ? (float)$row['speed']      : null,
        'updated_at'           => $row['updated_at'],
        'seconds_ago'          => (int)$row['seconds_ago'],

        // Off-route info
        'off_route'            => (bool)$row['off_route'],
        'distance_off_route_m' => $row['distance_off_route_m'] !== null ? (float)$row['distance_off_route_m'] : null,
        'nearest_route_lat'    => $row['nearest_route_lat']    !== null ? (float)$row['nearest_route_lat']    : null,
        'nearest_route_lng'    => $row['nearest_route_lng']    !== null ? (float)$row['nearest_route_lng']    : null,
        'off_route_since'      => $row['off_route_since'],
        'off_route_count'      => (int)$row['off_route_count'],
    ]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

