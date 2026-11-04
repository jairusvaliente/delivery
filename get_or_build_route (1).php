<?php
// get_or_build_route.php — Returns the planned route for an assignment (with cache)
require_once __DIR__ . '/connect_db.php';
require_once __DIR__ . '/osrm_helpers.php';

/**
 * Get the planned polyline for an assignment.
 * Caches in `planned_routes`. Fetches from OSRM if missing.
 * Returns [polyline, distance_m, duration_s, origin_lat, origin_lng, dest_lat, dest_lng]
 * or null on failure.
 */
function get_or_build_planned_route($conn, $assignmentId, $depotLat, $depotLng) {
    $assignmentId = (int)$assignmentId;

    // 1. Try cache
    $stmt = $conn->prepare("
        SELECT origin_lat, origin_lng, dest_lat, dest_lng, polyline, distance_m, duration_s
        FROM planned_routes
        WHERE assignment_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $assignmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        return [
            'polyline'    => $row['polyline'],
            'distance_m'  => (int)$row['distance_m'],
            'duration_s'  => (int)$row['duration_s'],
            'origin_lat'  => (float)$row['origin_lat'],
            'origin_lng'  => (float)$row['origin_lng'],
            'dest_lat'    => (float)$row['dest_lat'],
            'dest_lng'    => (float)$row['dest_lng'],
        ];
    }

    // 2. Need to build it — find the destination lat/lng
    $aStmt = $conn->prepare("
        SELECT destination
        FROM driver_assignments
        WHERE id = ?
        LIMIT 1
    ");
    $aStmt->bind_param('i', $assignmentId);
    $aStmt->execute();
    $assign = $aStmt->get_result()->fetch_assoc();
    $aStmt->close();

    if (!$assign) return null;

    $destName = strtolower(trim($assign['destination']));

    $dStmt = $conn->prepare("
        SELECT lat, lng FROM deliveries
        WHERE LOWER(TRIM(name)) = ?
        LIMIT 1
    ");
    $dStmt->bind_param('s', $destName);
    $dStmt->execute();
    $dest = $dStmt->get_result()->fetch_assoc();
    $dStmt->close();

    if (!$dest || $dest['lat'] === null || $dest['lng'] === null) return null;

    $destLat = (float)$dest['lat'];
    $destLng = (float)$dest['lng'];

    // 3. Ask OSRM
    $route = osrm_fetch_route($depotLat, $depotLng, $destLat, $destLng);
    if (!$route) return null;

    // 4. Save to cache
    $ins = $conn->prepare("
        INSERT INTO planned_routes
            (assignment_id, origin_lat, origin_lng, dest_lat, dest_lng, polyline, distance_m, duration_s)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            origin_lat = VALUES(origin_lat),
            origin_lng = VALUES(origin_lng),
            dest_lat   = VALUES(dest_lat),
            dest_lng   = VALUES(dest_lng),
            polyline   = VALUES(polyline),
            distance_m = VALUES(distance_m),
            duration_s = VALUES(duration_s)
    ");
    $ins->bind_param(
        'iddddsii',
        $assignmentId,
        $depotLat,
        $depotLng,
        $destLat,
        $destLng,
        $route['polyline'],
        $route['distance_m'],
        $route['duration_s']
    );
    $ins->execute();
    $ins->close();

    return [
        'polyline'    => $route['polyline'],
        'distance_m'  => $route['distance_m'],
        'duration_s'  => $route['duration_s'],
        'origin_lat'  => $depotLat,
        'origin_lng'  => $depotLng,
        'dest_lat'    => $destLat,
        'dest_lng'    => $destLng,
    ];
}