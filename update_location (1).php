<?php
// update_location.php — Driver posts GPS; checks off-route AND auto-delivers on arrival
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/connect_db.php';
require_once __DIR__ . '/osrm_helpers.php';
require_once __DIR__ . '/get_or_build_route.php';

// ─── Thresholds ───
const OFF_ROUTE_THRESHOLD_M = 150;    // meters
const OFF_ROUTE_MIN_HITS    = 2;      // consecutive reports required to flag off-route
const AUTO_DELIVER_RADIUS_M = 200;    // meters from destination
const AUTO_DELIVER_MIN_HITS = 2;      // consecutive near-destination reports required

// ─── Auth: session OR driver_id fallback ───
$driverId = 0;

if (isset($_SESSION['user_id'], $_SESSION['acctlevel']) &&
    strtolower($_SESSION['acctlevel']) === 'driver') {
    $driverId = (int)$_SESSION['user_id'];
} elseif (isset($_POST['driver_id'])) {
    $driverId = (int)$_POST['driver_id'];
}

if ($driverId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$lat      = isset($_POST['latitude'])  ? (float)$_POST['latitude']  : null;
$lng      = isset($_POST['longitude']) ? (float)$_POST['longitude'] : null;
$accuracy = isset($_POST['accuracy'])  ? (float)$_POST['accuracy']  : null;
$heading  = isset($_POST['heading'])   && $_POST['heading'] !== '' ? (float)$_POST['heading'] : null;
$speed    = isset($_POST['speed'])     && $_POST['speed']   !== '' ? (float)$_POST['speed']   : null;

if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    echo json_encode(['success' => false, 'error' => 'Invalid coordinates']);
    exit;
}

$depotLat = 14.139944;
$depotLng = 121.124139;

// ─── Pre-initialize every variable we might bind ───
$offRoute         = 0;
$distanceOffRoute = null;
$nearestLat       = null;
$nearestLng       = null;
$prevSince        = null;
$newCount         = 0;
$newArrivalCount  = 0;
$distanceToDest   = null;
$autoDelivered    = false;
$assignmentId     = null;

try {
    // ─── 1. Find the driver's current active assignment ───
    $aStmt = $conn->prepare("
        SELECT id, destination
        FROM driver_assignments
        WHERE driver_id = ? AND status = 'active'
        ORDER BY id ASC
        LIMIT 1
    ");
    $aStmt->bind_param('i', $driverId);
    $aStmt->execute();
    $assignment = $aStmt->get_result()->fetch_assoc();
    $aStmt->close();

    if ($assignment) {
        $assignmentId = (int)$assignment['id'];

        // ─── 2. Look up destination lat/lng ───
        $destName = strtolower(trim($assignment['destination']));
        $dStmt = $conn->prepare("
            SELECT lat, lng
            FROM deliveries
            WHERE LOWER(TRIM(name)) = ?
            LIMIT 1
        ");
        $dStmt->bind_param('s', $destName);
        $dStmt->execute();
        $destRow = $dStmt->get_result()->fetch_assoc();
        $dStmt->close();

        // ─── 3. Distance from driver to destination ───
        if ($destRow && $destRow['lat'] !== null && $destRow['lng'] !== null) {
            $destLat = (float)$destRow['lat'];
            $destLng = (float)$destRow['lng'];
            $distanceToDest = haversine_m($lat, $lng, $destLat, $destLng);

            // Read the previous arrival counter
            $nearStmt = $conn->prepare("
                SELECT arrival_count
                FROM driver_locations
                WHERE driver_id = ?
                LIMIT 1
            ");
            $nearStmt->bind_param('i', $driverId);
            $nearStmt->execute();
            $nearRow = $nearStmt->get_result()->fetch_assoc();
            $nearStmt->close();

            $prevArrivalCount = (int)($nearRow['arrival_count'] ?? 0);

            if ($distanceToDest <= AUTO_DELIVER_RADIUS_M) {
                // Still/near destination — increment
                $newArrivalCount = $prevArrivalCount + 1;

                if ($newArrivalCount >= AUTO_DELIVER_MIN_HITS) {
                    // Auto-mark as delivered
                    $upd = $conn->prepare("
                        UPDATE driver_assignments
                        SET status = 'delivered', unassigned_at = NOW()
                        WHERE id = ? AND status = 'active'
                    ");
                    $upd->bind_param('i', $assignmentId);
                    $upd->execute();
                    if ($upd->affected_rows > 0) {
                        $autoDelivered = true;
                    }
                    $upd->close();
                }
            } else {
                // Moved away — reset counter
                $newArrivalCount = 0;
            }
        }

        // ─── 4. Off-route check ───
        $route = get_or_build_planned_route($conn, $assignmentId, $depotLat, $depotLng);
        if ($route && !empty($route['polyline'])) {
            $polyline = decode_polyline($route['polyline']);
            $result   = min_distance_to_polyline($lat, $lng, $polyline);

            if ($result['distance_m'] !== null) {
                $distanceOffRoute = (float)$result['distance_m'];
                $nearestLat       = $result['nearest_lat'];
                $nearestLng       = $result['nearest_lng'];

                $prevStmt = $conn->prepare("
                    SELECT off_route, off_route_count, off_route_since
                    FROM driver_locations
                    WHERE driver_id = ?
                    LIMIT 1
                ");
                $prevStmt->bind_param('i', $driverId);
                $prevStmt->execute();
                $prev = $prevStmt->get_result()->fetch_assoc();
                $prevStmt->close();

                $prevCount = (int)($prev['off_route_count'] ?? 0);
                $prevSince = $prev['off_route_since'] ?? null;

                if ($distanceOffRoute > OFF_ROUTE_THRESHOLD_M) {
                    $newCount = $prevCount + 1;
                    $offRoute = ($newCount >= OFF_ROUTE_MIN_HITS) ? 1 : 0;
                    if (!$prevSince) $prevSince = date('Y-m-d H:i:s');
                } else {
                    $newCount  = 0;
                    $prevSince = null;
                    $offRoute  = 0;
                }
            }
        }
    }

    // ─── 5. Upsert driver_locations ───
    $prevSinceVal    = $prevSince;
    $newCountVal     = $newCount;
    $arrivalCountVal = $newArrivalCount;

    $upsert = $conn->prepare("
        INSERT INTO driver_locations
            (driver_id, latitude, longitude, accuracy, heading, speed,
             off_route, distance_off_route_m, nearest_route_lat, nearest_route_lng,
             off_route_since, off_route_count, arrival_count, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            latitude              = VALUES(latitude),
            longitude             = VALUES(longitude),
            accuracy              = VALUES(accuracy),
            heading               = VALUES(heading),
            speed                 = VALUES(speed),
            off_route             = VALUES(off_route),
            distance_off_route_m  = VALUES(distance_off_route_m),
            nearest_route_lat     = VALUES(nearest_route_lat),
            nearest_route_lng     = VALUES(nearest_route_lng),
            off_route_since       = VALUES(off_route_since),
            off_route_count       = VALUES(off_route_count),
            arrival_count         = VALUES(arrival_count),
            updated_at            = NOW()
    ");

    $upsert->bind_param(
        'idddddidddsii',
        $driverId,
        $lat,
        $lng,
        $accuracy,
        $heading,
        $speed,
        $offRoute,
        $distanceOffRoute,
        $nearestLat,
        $nearestLng,
        $prevSinceVal,
        $newCountVal,
        $arrivalCountVal
    );
    $upsert->execute();
    $upsert->close();

    echo json_encode([
        'success'              => true,
        'driver_id'            => $driverId,
        'latitude'             => $lat,
        'longitude'            => $lng,
        'off_route'            => (bool)$offRoute,
        'distance_off_route_m' => $distanceOffRoute !== null ? round($distanceOffRoute, 1) : null,
        'distance_to_dest_m'   => $distanceToDest !== null ? round($distanceToDest, 1) : null,
        'auto_delivered'       => $autoDelivered,
        'assignment_id'        => $assignmentId,
        'updated_at'           => date('Y-m-d H:i:s'),
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
}