<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ────────────────────────────────────────────────────────────
// Prevent browser caching (fixes stale driver state across logins)
// ────────────────────────────────────────────────────────────
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ────────────────────────────────────────────────────────────
// Handle logout
// ────────────────────────────────────────────────────────────
if (isset($_POST['logout'])) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '',
            time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/connect_db.php';

// ────────────────────────────────────────────────────────────
// Determine which driver is viewing this page
// ────────────────────────────────────────────────────────────
$driverId = 0;

if (isset($_SESSION['user_id'], $_SESSION['acctlevel']) &&
    strtolower($_SESSION['acctlevel']) === 'driver') {
    $driverId = (int)$_SESSION['user_id'];
}
elseif (isset($_GET['driver_id'])) {
    $driverId = (int)$_GET['driver_id'];
}

if ($driverId <= 0) {
    header('Location: index.php');
    exit;
}

$driver  = null;
$dbError = null;

try {
    $dStmt = $conn->prepare("SELECT id, name, username, acctlevel FROM acct WHERE id = ? LIMIT 1");
    $dStmt->bind_param('i', $driverId);
    $dStmt->execute();
    $driver = $dStmt->get_result()->fetch_assoc();
    $dStmt->close();

    if (!$driver) {
        header('Location: index.php');
        exit;
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

// 2. Load this driver's active assignments + delivery lat/lng
$assignments = [];
try {
    $aStmt = $conn->prepare("
        SELECT id, driver_id, vehicle_id, destination, notes, status, assigned_at, unassigned_at
        FROM driver_assignments
        WHERE driver_id = ? AND status = 'active'
        ORDER BY id ASC
    ");
    $aStmt->bind_param('i', $driverId);
    $aStmt->execute();
    $rows = $aStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $aStmt->close();

    $deliveryLocations = [];
    $r = $conn->query("SELECT id, code, name, address, lat, lng FROM deliveries");
    while ($loc = $r->fetch_assoc()) {
        $deliveryLocations[strtolower(trim($loc['name']))] = $loc;
    }
    $r->free();

    foreach ($rows as &$a) {
        $key = strtolower(trim($a['destination']));
        if (isset($deliveryLocations[$key])) {
            $a['code']    = $deliveryLocations[$key]['code'];
            $a['address'] = $deliveryLocations[$key]['address'];
            $a['lat']     = (float)$deliveryLocations[$key]['lat'];
            $a['lng']     = (float)$deliveryLocations[$key]['lng'];
        } else {
            $a['code']    = strtoupper(substr($a['destination'], 0, 3));
            $a['address'] = '';
            $a['lat']     = null;
            $a['lng']     = null;
        }
        $assignments[] = $a;
    }
    unset($a);
} catch (Throwable $e) {
    if (!$dbError) $dbError = $e->getMessage();
}

// 3. Load THIS driver's most recent GPS position
$lastLocation = null;
try {
    $lStmt = $conn->prepare("
        SELECT driver_id, latitude, longitude, accuracy, heading, speed, updated_at,
               TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS seconds_ago
        FROM driver_locations
        WHERE driver_id = ?
        LIMIT 1
    ");
    $lStmt->bind_param('i', $driverId);
    $lStmt->execute();
    $lastLocation = $lStmt->get_result()->fetch_assoc();
    $lStmt->close();
} catch (Throwable $e) { /* table may not exist yet */ }

$depotCoords = [14.139944, 121.124139];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Driver Console &middot; UAM Logistics</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <style>
        :root {
            --primary-50:  #fff7ed;
            --primary-100: #ffedd5;
            --primary-200: #fed7aa;
            --primary-500: #f97316;
            --primary-600: #ea580c;
            --primary-700: #c2410c;
            --slate-50:   #f8fafc;
            --slate-100:  #f1f5f9;
            --slate-200:  #e2e8f0;
            --slate-300:  #cbd5e1;
            --slate-400:  #94a3b8;
            --slate-500:  #64748b;
            --slate-600:  #475569;
            --slate-700:  #334155;
            --slate-800:  #1e293b;
            --slate-900:  #0f172a;
            --white:      #ffffff;
            --success-50: #f0fdf4;
            --success-500:#22c55e;
            --success-600:#16a34a;
            --danger-50:  #fef2f2;
            --danger-100: #fee2e2;
            --danger-600: #dc2626;
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.06), 0 1px 2px rgba(15, 23, 42, 0.04);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; }
        html, body { height: 100%; }
        body { background: var(--slate-100); color: var(--slate-900); overflow: hidden; padding: 1.25rem; }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--slate-300); border-radius: 10px; }

        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem;
        }
        .page-header .title-block {
            display: flex; align-items: center; gap: 0.85rem;
        }
        .page-header .driver-avatar {
            width: 48px; height: 48px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-500), var(--primary-600));
            color: var(--white); display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem; font-weight: 800; flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.3);
        }
        .page-header .driver-meta .name { font-size: 1.1rem; font-weight: 800; color: var(--slate-900); }
        .page-header .driver-meta .role {
            font-size: 0.72rem; color: var(--primary-600); font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.06em;
        }
        .page-header .right-actions { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .page-header .badge-driver {
            background: var(--white); border: 1px solid var(--slate-200);
            padding: 0.35rem 0.8rem; border-radius: 2rem;
            font-size: 0.72rem; font-weight: 700; color: var(--slate-600);
            display: flex; align-items: center; gap: 0.4rem;
        }

        .gps-pill {
            display: inline-flex; align-items: center; gap: 0.4rem;
            background: var(--slate-100);
            border: 1px solid var(--slate-200);
            color: var(--slate-500);
            padding: 0.35rem 0.8rem;
            border-radius: 2rem;
            font-size: 0.72rem;
            font-weight: 700;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .gps-pill .gps-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--slate-400);
            display: inline-block;
            transition: all 0.2s ease;
        }
        .gps-pill.gps-active {
            background: var(--success-50);
            border-color: #86efac;
            color: var(--success-600);
        }
        .gps-pill.gps-active .gps-dot {
            background: var(--success-600);
            animation: gpsPulse 1.5s infinite;
        }
        .gps-pill.gps-error {
            background: var(--danger-50);
            border-color: #fca5a5;
            color: var(--danger-600);
        }
        .gps-pill.gps-error .gps-dot { background: var(--danger-600); }

        @keyframes gpsPulse {
            0%,100% { opacity: 1; transform: scale(1); }
            50%     { opacity: 0.4; transform: scale(1.3); }
        }

        .btn-logout {
            background: var(--danger-50); color: var(--danger-600);
            border: 1px solid var(--danger-100); padding: 0.5rem 1rem;
            border-radius: var(--radius-sm); font-size: 0.78rem; font-weight: 700;
            cursor: pointer; display: flex; align-items: center; gap: 0.4rem;
            transition: all 0.18s ease; white-space: nowrap;
        }
        .btn-logout:hover {
            background: #fde8e8; border-color: #fca5a5;
            transform: translateY(-1px); box-shadow: 0 3px 8px rgba(220, 38, 38, 0.15);
        }
        .btn-logout:active { transform: translateY(0); }

        .error-banner {
            background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;
            border-radius: 8px; padding: 10px 14px; font-size: 0.8rem;
            margin-bottom: 1rem; font-family: monospace;
        }

        .grid {
            display: grid;
            grid-template-columns: 320px minmax(0, 1fr);
            gap: 1rem;
            height: calc(100vh - 100px);
        }
        .left-panel, .right-panel { display: flex; flex-direction: column; gap: 1rem; min-height: 0; min-width: 0; }

        .card-box {
            background: var(--white); border-radius: var(--radius-lg);
            border: 1px solid var(--slate-200); box-shadow: var(--shadow-sm);
            display: flex; flex-direction: column; overflow: hidden; min-height: 0;
        }
        .panel-header {
            padding: 0.85rem 1.15rem; border-bottom: 1px solid var(--slate-100);
            display: flex; justify-content: space-between; align-items: center;
            flex-shrink: 0; gap: 0.5rem;
        }
        .panel-header h3 { font-size: 0.85rem; font-weight: 700; color: var(--slate-800); display: flex; align-items: center; gap: 0.5rem; }
        .panel-header h3 i { color: var(--primary-600); }
        .panel-header .count-badge {
            background: var(--primary-50); color: var(--primary-700);
            border: 1px solid var(--primary-200); padding: 0.15rem 0.6rem;
            border-radius: 2rem; font-size: 0.7rem; font-weight: 700; flex-shrink: 0;
        }

        .assignment-list {
            flex: 1 1 auto; min-height: 200px; max-height: 40vh; overflow-y: auto;
            padding: 0.5rem;
        }
        .assignment-item {
            display: flex; align-items: center; gap: 0.6rem;
            padding: 0.65rem 0.75rem; border-radius: var(--radius-md);
            cursor: pointer; transition: all 0.18s ease; background: var(--white);
            margin-bottom: 0.35rem; border: 1px solid transparent;
        }
        .assignment-item:last-child { margin-bottom: 0; }
        .assignment-item:hover { background: var(--slate-50); border-color: var(--slate-200); }
        .assignment-item.active-item { background: var(--primary-50); border-color: var(--primary-200); }

        .assignment-icon {
            width: 34px; height: 34px; border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.7rem; color: white; flex-shrink: 0; font-weight: 800;
            background: linear-gradient(135deg, var(--primary-500), var(--primary-600));
            box-shadow: 0 2px 6px rgba(234, 88, 12, 0.2);
        }
        .assignment-item.active-item .assignment-icon { box-shadow: 0 2px 6px rgba(234, 88, 12, 0.4); }
        .assignment-info { flex: 1; min-width: 0; }
        .assignment-title {
            font-size: 0.83rem; font-weight: 700; color: var(--slate-900);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .assignment-subtitle {
            font-size: 0.72rem; color: var(--slate-500); margin-top: 0.15rem;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        .chat-panel { flex: 1 1 auto; display: flex; flex-direction: column; min-height: 200px; }
        .chat-messages {
            flex: 1; overflow-y: auto; padding: 1rem; background: var(--slate-50);
            display: flex; flex-direction: column; gap: 0.6rem; min-height: 0;
        }
        .chat-msg {
            max-width: 85%; padding: 0.6rem 0.85rem; border-radius: var(--radius-md);
            font-size: 0.8rem; line-height: 1.45; box-shadow: var(--shadow-sm);
            word-wrap: break-word; overflow-wrap: anywhere;
        }
        .chat-msg.admin {
            align-self: flex-start; background: var(--white);
            border: 1px solid var(--slate-200); color: var(--slate-800);
            border-bottom-left-radius: 3px;
        }
        .chat-msg.driver {
            align-self: flex-end;
            background: linear-gradient(135deg, var(--primary-500), var(--primary-600));
            color: var(--white); border-bottom-right-radius: 3px;
        }
        .chat-msg .msg-meta { display: block; font-size: 0.65rem; opacity: 0.75; margin-top: 0.25rem; }

        .chat-empty {
            flex: 1; display: flex; flex-direction: column; align-items: center;
            justify-content: center; color: var(--slate-400);
            font-size: 0.78rem; gap: 0.5rem; padding: 2rem 1rem; text-align: center;
        }
        .chat-empty i { font-size: 1.6rem; color: var(--slate-300); }

        .chat-input-area {
            border-top: 1px solid var(--slate-100); padding: 0.75rem;
            background: var(--white); flex-shrink: 0;
        }
        .chat-input-row { display: flex; gap: 0.5rem; }
        .chat-input-row input[type="text"] {
            flex: 1; min-width: 0; padding: 0.7rem 0.9rem;
            border: 1px solid var(--slate-300); border-radius: var(--radius-sm);
            font-size: 0.82rem; outline: none; transition: border-color 0.2s;
        }
        .chat-input-row input[type="text"]:focus { border-color: var(--primary-500); }
        .chat-input-row button.send-btn {
            background: var(--primary-600); color: white; border: none;
            padding: 0.7rem 1.1rem; border-radius: var(--radius-sm);
            font-weight: 600; font-size: 0.82rem; cursor: pointer;
            display: flex; align-items: center; gap: 0.35rem; flex-shrink: 0;
        }
        .chat-input-row button.send-btn:hover:not(:disabled) { background: var(--primary-700); }
        .chat-input-row button.send-btn:disabled { opacity: 0.55; cursor: not-allowed; }

        .attach-btn {
            background: var(--slate-100); color: var(--slate-600);
            border: 1px solid var(--slate-200); padding: 0 0.85rem;
            border-radius: var(--radius-sm); cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.95rem; flex-shrink: 0;
        }
        .attach-btn:hover:not(:disabled) {
            background: var(--primary-50); border-color: var(--primary-200); color: var(--primary-600);
        }
        .attach-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .attach-preview {
            display: flex; align-items: center; justify-content: space-between;
            gap: 0.5rem; background: var(--primary-50); border: 1px solid var(--primary-200);
            border-radius: var(--radius-sm); padding: 0.5rem 0.7rem;
            margin-bottom: 0.5rem; font-size: 0.75rem;
        }
        .attach-preview .attach-info {
            display: flex; align-items: center; gap: 0.45rem;
            color: var(--primary-700); font-weight: 600; min-width: 0; flex: 1;
        }
        .attach-preview .attach-info span:first-of-type {
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px;
        }
        .attach-preview .attach-size { color: var(--slate-500); font-weight: 500; font-size: 0.7rem; flex-shrink: 0; }
        .attach-preview .attach-remove {
            background: transparent; border: none; color: var(--primary-700);
            cursor: pointer; font-size: 0.8rem; padding: 0.15rem 0.3rem;
            border-radius: 4px; flex-shrink: 0;
        }
        .attach-preview .attach-remove:hover { background: var(--primary-100); color: var(--danger-600); }

        .chat-msg .msg-attachment { display: block; margin-top: 0.5rem; max-width: 100%; }
        .chat-msg .msg-attachment img,
        .chat-msg .msg-attachment video {
            display: block; width: 100%; max-width: 240px; max-height: 280px;
            object-fit: cover; border-radius: var(--radius-sm);
            background: #000; cursor: pointer;
        }
        .chat-msg .msg-attachment .file-pill {
            display: flex; align-items: center; gap: 0.55rem;
            background: var(--slate-100); border: 1px solid var(--slate-200);
            border-radius: var(--radius-sm); padding: 0.55rem 0.7rem;
            text-decoration: none; color: var(--slate-800); font-size: 0.76rem;
            max-width: 240px;
        }
        .chat-msg .msg-attachment .file-pill i { font-size: 1.25rem; color: var(--primary-600); flex-shrink: 0; }
        .chat-msg .msg-attachment .file-pill .fp-body { min-width: 0; flex: 1; }
        .chat-msg .msg-attachment .file-pill .fp-name { font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .chat-msg .msg-attachment .file-pill .fp-size { color: var(--slate-500); font-size: 0.68rem; }

        /* SINGLE MAP */
        .map-panel {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 260px;
        }
        .map-panel .map-panel-header {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--slate-100);
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap;
            gap: 0.6rem;
            flex-shrink: 0;
        }
        .map-panel .map-panel-header h3 {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--slate-800);
            display: flex; align-items: center; gap: 0.45rem;
        }
        .map-panel .map-panel-header h3 i { color: var(--primary-600); }

        .map-legend {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            font-size: 0.68rem;
            font-weight: 600;
            color: var(--slate-600);
        }
        .map-legend .lg-item {
            display: flex; align-items: center; gap: 0.3rem;
        }
        .map-legend .lg-dot {
            width: 10px; height: 10px; border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.25);
            display: inline-block;
        }
        .lg-dot.depot { background: #1e293b; }
        .lg-dot.dest  { background: #ea580c; }
        .lg-dot.you   { background: #2563eb; }

        .map-mini-badge {
            font-size: 0.65rem;
            font-weight: 700;
            padding: 0.15rem 0.55rem;
            border-radius: 2rem;
            background: var(--slate-100);
            color: var(--slate-500);
            white-space: nowrap;
        }
        .map-mini-badge.live {
            background: var(--success-50);
            color: var(--success-600);
            border: 1px solid #86efac;
        }
        .map-mini-badge.stale {
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #fcd34d;
        }

        .map-panel .map-panel-body {
            flex: 1;
            min-height: 300px;
            position: relative;
        }
        .map-panel .map-panel-body > div {
            width: 100%;
            height: 100%;
            min-height: 300px;
        }

        .custom-map-icon {
            display: flex; align-items: center; justify-content: center;
            color: white; border-radius: 50%; font-size: 12px;
            box-shadow: 0 3px 8px rgba(0,0,0,0.3); border: 2px solid white;
        }
        .icon-depot { background: #1e293b; }
        .icon-destination { background: #ea580c; }
        .icon-driver {
            background: #2563eb;
            animation: driverPulse 1.8s ease-in-out infinite;
        }
        .icon-driver.stale { background: #94a3b8; animation: none; }
        @keyframes driverPulse {
            0%,100% { box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.65); }
            50%     { box-shadow: 0 0 0 14px rgba(37, 99, 235, 0); }
        }

        @media (max-width: 1200px) {
            .grid { grid-template-columns: 300px minmax(0, 1fr); }
        }

        @media (max-width: 900px) {
            body { overflow: auto; padding: 1rem; }
            .grid { grid-template-columns: 1fr; height: auto; }
            .chat-panel { min-height: 400px; height: 480px; }
            .assignment-list { max-height: 260px; }
            .map-panel .map-panel-body { min-height: 400px; }
        }
    </style>
</head>
<body>

<?php if ($dbError): ?>
    <div class="error-banner">
        <strong>Database error:</strong> <?= htmlspecialchars($dbError) ?>
    </div>
<?php endif; ?>

<div class="page-header">
    <div class="title-block">
        <div class="driver-avatar">
            <?php
                $initials = '';
                foreach (preg_split('/\s+/', trim($driver['name'])) as $p) {
                    if ($p !== '') $initials .= strtoupper($p[0]);
                    if (strlen($initials) >= 2) break;
                }
                echo htmlspecialchars($initials ?: 'D');
            ?>
        </div>
        <div class="driver-meta">
            <div class="name"><?= htmlspecialchars($driver['name']) ?></div>
            <div class="role">Driver Console</div>
        </div>
    </div>

    <div class="right-actions">
        <div class="gps-pill" id="gpsPill" title="Live location sharing">
            <span class="gps-dot"></span>
            <span id="gpsPillText">GPS: waiting…</span>
        </div>

        <div class="badge-driver">
            <i class="fas fa-id-badge"></i>
            ID: <?= (int)$driver['id'] ?> · <?= htmlspecialchars($driver['username']) ?>
        </div>

        <form method="POST" action="driver.php" style="margin:0;">
            <button type="submit" name="logout" class="btn-logout" title="Sign out">
                <i class="fas fa-right-from-bracket"></i> Logout
            </button>
        </form>
    </div>
</div>

<div class="grid">

    <div class="left-panel">
        <div class="card-box">
            <div class="panel-header">
                <h3><i class="fas fa-list-check"></i> My Assignments</h3>
                <span class="count-badge" id="assignmentCount"><?= count($assignments) ?> Active</span>
            </div>
            <div class="assignment-list" id="assignmentList">
                <?php if (empty($assignments)): ?>
                    <div style="padding:1.2rem;text-align:center;color:#94a3b8;font-style:italic;font-size:0.8rem;">
                        No active assignments
                    </div>
                <?php else: ?>
                    <?php foreach ($assignments as $index => $a): ?>
                        <div class="assignment-item <?= $index === 0 ? 'active-item' : '' ?>"
                             data-assignment-id="<?= (int)$a['id'] ?>"
                             data-destination="<?= htmlspecialchars($a['destination']) ?>"
                             data-address="<?= htmlspecialchars($a['address'] ?? '') ?>"
                             data-lat="<?= $a['lat'] !== null ? htmlspecialchars($a['lat']) : '' ?>"
                             data-lng="<?= $a['lng'] !== null ? htmlspecialchars($a['lng']) : '' ?>"
                             onclick="selectAssignment(this)">
                            <div class="assignment-icon"><?= htmlspecialchars($a['code']) ?></div>
                            <div class="assignment-info">
                                <div class="assignment-title"><?= htmlspecialchars($a['destination']) ?></div>
                                <div class="assignment-subtitle">Assignment #<?= (int)$a['id'] ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card-box" style="flex:0 0 auto;">
            <div class="panel-header">
                <h3><i class="fas fa-circle-info"></i> Selected Assignment</h3>
            </div>
            <div style="padding:1rem;">
                <div style="display:flex; flex-direction:column; gap:0.5rem;">
                    <div>
                        <div style="font-size:0.65rem; color:#94a3b8; font-weight:700; text-transform:uppercase;">Destination</div>
                        <div id="infoDest" style="font-weight:700; color:#334155;">—</div>
                    </div>
                    <div>
                        <div style="font-size:0.65rem; color:#94a3b8; font-weight:700; text-transform:uppercase;">Address</div>
                        <div id="infoAddress" style="font-weight:600; color:#475569; font-size:0.78rem;">—</div>
                    </div>
                    <div>
                        <div style="font-size:0.65rem; color:#94a3b8; font-weight:700; text-transform:uppercase;">Assignment ID</div>
                        <div id="infoId" style="font-weight:700; color:#334155;">—</div>
                    </div>
                    <div>
                        <div style="font-size:0.65rem; color:#94a3b8; font-weight:700; text-transform:uppercase;">My Current GPS</div>
                        <div id="infoGps" style="font-weight:700; color:#334155; font-size:0.78rem;">
                            <?php if ($lastLocation): ?>
                                <?= htmlspecialchars(number_format((float)$lastLocation['latitude'], 5)) ?>,
                                <?= htmlspecialchars(number_format((float)$lastLocation['longitude'], 5)) ?>
                                <?php if ($lastLocation['speed'] !== null): ?>
                                    · <?= htmlspecialchars(number_format((float)$lastLocation['speed'] * 3.6, 1)) ?> km/h
                                <?php endif; ?>
                                <span style="color:#94a3b8;font-weight:500;">
                                    (<?= (int)$lastLocation['seconds_ago'] ?>s ago)
                                </span>
                            <?php else: ?>
                                <span style="color:#94a3b8;font-style:italic;font-weight:500;">Not shared yet</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="chat-panel card-box" style="flex:1; min-height:220px;">
            <div class="panel-header">
                <h3><i class="fas fa-comments"></i> Chat with Admin</h3>
                <span id="chatStatus" style="color: var(--slate-400); font-weight:700; font-size:0.7rem; display:flex; align-items:center; gap:0.3rem;">
                    <i class="fas fa-circle" style="font-size:0.45rem;"></i>
                    <span id="chatStatusText">Idle</span>
                </span>
            </div>

            <div class="chat-messages" id="chatContainer">
                <div class="chat-empty">
                    <i class="fas fa-comment-dots"></i>
                    <div>Select an assignment to start chatting</div>
                    <div style="font-size:0.7rem;">Messages with the admin will appear here.</div>
                </div>
            </div>

            <div class="chat-input-area">
                <div class="attach-preview" id="attachPreview" style="display:none;">
                    <div class="attach-info">
                        <i class="fas fa-paperclip"></i>
                        <span id="attachName">file.jpg</span>
                        <span id="attachSize" class="attach-size">(0 KB)</span>
                    </div>
                    <button type="button" class="attach-remove" onclick="clearAttachment()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="chat-input-row">
                    <input type="file" id="chatFileInput"
                           accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip,.rar"
                           style="display:none;" onchange="handleFilePick(event)">

                    <button type="button" class="attach-btn" id="attachBtn"
                            onclick="document.getElementById('chatFileInput').click()" disabled>
                        <i class="fas fa-paperclip"></i>
                    </button>

                    <input type="text" id="chatInput"
                           placeholder="Select an assignment first..."
                           disabled onkeypress="handleChatKeyPress(event)">

                    <button class="send-btn" id="chatSendBtn" onclick="sendChatMessage()" disabled>
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="right-panel">
        <div class="map-panel card-box">
            <div class="map-panel-header">
                <h3><i class="fas fa-map-location-dot"></i> Live Route Map</h3>

                <div class="map-legend">
                    <span class="lg-item"><span class="lg-dot depot"></span> Depot</span>
                    <span class="lg-item"><span class="lg-dot dest"></span> Delivery</span>
                    <span class="lg-item"><span class="lg-dot you"></span> You</span>
                </div>

                <span class="map-mini-badge" id="mapBadge">GPS waiting</span>
            </div>

            <div class="map-panel-body">
                <div id="liveMap"></div>
            </div>
        </div>
    </div>

</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const VIEWER_ROLE        = 'driver';
    const DRIVER_ID_FOR_TEST = <?= (int)$driver['id'] ?>;
    const DEPOT_COORDS       = [<?= $depotCoords[0] ?>, <?= $depotCoords[1] ?>];

    let activeAssignmentId   = null;
    let activeDestination    = null;
    let activeDeliveryLatLng = null;

    let lastMessageId = 0;
    let chatPollTimer = null;
    let pendingFile   = null;

    // GPS state
    let gpsWatchId    = null;
    let lastGpsSentAt = 0;
    const GPS_SEND_EVERY_MS = 10000;

    // Include driver_id + speed so JS can verify the marker belongs to this driver
    let lastKnownGps = <?= $lastLocation ? json_encode([
        'driver_id'   => (int)$lastLocation['driver_id'],
        'latitude'    => (float)$lastLocation['latitude'],
        'longitude'   => (float)$lastLocation['longitude'],
        'speed'       => $lastLocation['speed'] !== null ? (float)$lastLocation['speed'] : null,
        'seconds_ago' => (int)$lastLocation['seconds_ago'],
    ]) : 'null' ?>;

    // ═══════════════════════════════════════════════════════════
    // LEAFLET — single overlay map
    // ═══════════════════════════════════════════════════════════
    let liveMap;
    let depotMarker, deliveryMarker, driverMarker;
    let deliveryRouteLine, driverRouteLine, guideLine;

    function initMap() {
        liveMap = L.map('liveMap', {
            zoomControl: true,
            attributionControl: false
        }).setView(DEPOT_COORDS, 12);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap'
        }).addTo(liveMap);

        depotMarker = L.marker(DEPOT_COORDS, {
            icon: L.divIcon({
                className: 'custom-map-icon icon-depot',
                html: '<i class="fas fa-warehouse"></i>',
                iconSize: [28, 28]
            })
        }).addTo(liveMap).bindPopup('<b>Depot Origin</b>');

        setTimeout(() => { liveMap.invalidateSize(); }, 300);
        window.addEventListener('resize', () => { if (liveMap) liveMap.invalidateSize(); });
    }

    function decodePolyline(str, precision) {
        let index = 0, lat = 0, lng = 0, coordinates = [], factor = Math.pow(10, precision || 5);
        while (index < str.length) {
            let byte, shift = 0, result = 0;
            do { byte = str.charCodeAt(index++) - 63; result |= (byte & 0x1f) << shift; shift += 5; } while (byte >= 0x20);
            let dlat = ((result & 1) ? ~(result >> 1) : (result >> 1));
            lat += dlat;
            shift = 0; result = 0;
            do { byte = str.charCodeAt(index++) - 63; result |= (byte & 0x1f) << shift; shift += 5; } while (byte >= 0x20);
            let dlng = ((result & 1) ? ~(result >> 1) : (result >> 1));
            lng += dlng;
            coordinates.push([lat / factor, lng / factor]);
        }
        return coordinates;
    }

    function fitAll() {
        if (!liveMap) return;
        const pts = [DEPOT_COORDS];

        if (deliveryMarker)  pts.push(deliveryMarker.getLatLng());
        if (driverMarker)    pts.push(driverMarker.getLatLng());

        if (pts.length === 1) {
            liveMap.setView(pts[0], 13);
        } else {
            liveMap.fitBounds(L.latLngBounds(pts), { padding: [40, 40] });
        }
    }

    function drawDeliveryRoute(destLatLng, destName) {
        if (!liveMap) return;

        if (deliveryRouteLine) { liveMap.removeLayer(deliveryRouteLine); deliveryRouteLine = null; }
        if (deliveryMarker)    { liveMap.removeLayer(deliveryMarker);    deliveryMarker    = null; }

        deliveryMarker = L.marker(destLatLng, {
            icon: L.divIcon({
                className: 'custom-map-icon icon-destination',
                html: '<i class="fas fa-location-dot"></i>',
                iconSize: [28, 28]
            })
        }).addTo(liveMap).bindPopup('<b>' + destName + '</b><br>Delivery Destination');

        const url = `https://router.project-osrm.org/route/v1/driving/${DEPOT_COORDS[1]},${DEPOT_COORDS[0]};${destLatLng[1]},${destLatLng[0]}?overview=full&geometries=polyline`;

        fetch(url)
            .then(r => r.json())
            .then(data => {
                if (!data.routes || data.routes.length === 0) return;
                const coords = decodePolyline(data.routes[0].geometry);
                deliveryRouteLine = L.polyline(coords, {
                    color: '#ea580c', weight: 5, opacity: 0.85,
                    lineCap: 'round', lineJoin: 'round'
                }).addTo(liveMap);

                fitAll();
            })
            .catch(err => console.warn('OSRM delivery route error:', err));
    }

    function drawDriverRoute() {
        if (!liveMap || !driverMarker || !activeDeliveryLatLng) return;

        if (driverRouteLine) { liveMap.removeLayer(driverRouteLine); driverRouteLine = null; }
        if (guideLine)       { liveMap.removeLayer(guideLine);       guideLine       = null; }

        const driverLatLng = driverMarker.getLatLng();
        const url = `https://router.project-osrm.org/route/v1/driving/${driverLatLng.lng},${driverLatLng.lat};${activeDeliveryLatLng[1]},${activeDeliveryLatLng[0]}?overview=full&geometries=polyline`;

        fetch(url)
            .then(r => r.json())
            .then(data => {
                if (!data.routes || data.routes.length === 0) return;
                const coords = decodePolyline(data.routes[0].geometry);

                driverRouteLine = L.polyline(coords, {
                    color: '#2563eb', weight: 5, opacity: 0.9,
                    lineCap: 'round', lineJoin: 'round'
                }).addTo(liveMap);

                guideLine = L.polyline([driverLatLng, activeDeliveryLatLng], {
                    color: '#94a3b8', weight: 2, dashArray: '6,6', opacity: 0.55
                }).addTo(liveMap);
            })
            .catch(err => console.warn('OSRM driver route error:', err));
    }

    function placeDriverMarker(lat, lng, stale, speedMps) {
        if (!liveMap) return;

        const icon = L.divIcon({
            className: 'custom-map-icon icon-driver' + (stale ? ' stale' : ''),
            html: '<i class="fas fa-truck-fast"></i>',
            iconSize: [32, 32]
        });

        const latlng = [lat, lng];

        if (driverMarker) {
            driverMarker.setLatLng(latlng);
            driverMarker.setIcon(icon);
        } else {
            driverMarker = L.marker(latlng, { icon: icon, zIndexOffset: 500 })
                .addTo(liveMap)
                .bindPopup('<b>You</b><br>' + Number(lat).toFixed(6) + ', ' + Number(lng).toFixed(6));

            fitAll();
        }

        const spdTxt = (speedMps != null) ? '<br>Speed: ' + (speedMps * 3.6).toFixed(1) + ' km/h' : '';

        driverMarker.setPopupContent(
            '<b>You</b>' +
            '<br>' + Number(lat).toFixed(6) + ', ' + Number(lng).toFixed(6) +
            spdTxt +
            (stale ? '<br><span style="color:#b45309;">stale</span>' : '')
        );
    }

    function setGpsPill(state, text) {
        const pill = document.getElementById('gpsPill');
        const txt  = document.getElementById('gpsPillText');
        pill.classList.remove('gps-active', 'gps-error');
        if (state === 'active') pill.classList.add('gps-active');
        if (state === 'error')  pill.classList.add('gps-error');
        txt.innerText = text;

        const badge = document.getElementById('mapBadge');
        if (badge) {
            badge.classList.remove('live', 'stale');
            if (state === 'active')      { badge.classList.add('live');  badge.innerText = 'GPS live'; }
            else if (state === 'error')  { badge.classList.add('stale'); badge.innerText = 'GPS error'; }
            else                          { badge.innerText = 'GPS waiting'; }
        }
    }

    function updateInfoGps(lat, lng, secsAgo, speedMps) {
        const el = document.getElementById('infoGps');
        if (!el) return;
        const latStr = Number(lat).toFixed(5);
        const lngStr = Number(lng).toFixed(5);
        const agoTxt = secsAgo === 0 ? 'just now' : secsAgo + 's ago';
        const spdTxt = (speedMps != null) ? ' · ' + (speedMps * 3.6).toFixed(1) + ' km/h' : '';
        el.innerHTML =
            latStr + ', ' + lngStr + spdTxt +
            ' <span style="color:#94a3b8;font-weight:500;">(' + agoTxt + ')</span>';
    }

    // ═══════════════════════════════════════════════════════════
    // AUTO-DELIVER UI
    // ═══════════════════════════════════════════════════════════
    function showArrivedState() {
        // GPS pill: mark delivered
        setGpsPill('active', 'GPS: DELIVERED');

        // Stop GPS streaming
        stopGpsTracking();

        // Replace the chat panel with an arrival message
        const c = document.getElementById('chatContainer');
        if (c) {
            c.innerHTML = `
                <div class="chat-empty">
                    <i class="fas fa-circle-check" style="color:#16a34a;font-size:2rem;"></i>
                    <div><strong>You've arrived!</strong></div>
                    <div style="font-size:0.75rem;">Delivery has been marked as delivered automatically.</div>
                </div>`;
        }

        // Disable chat input controls
        const input     = document.getElementById('chatInput');
        const sendBtn   = document.getElementById('chatSendBtn');
        const attachBtn = document.getElementById('attachBtn');
        if (input)     input.disabled = true;
        if (sendBtn)   sendBtn.disabled = true;
        if (attachBtn) attachBtn.disabled = true;

        // Update the assignment badge + map badge
        const badge = document.getElementById('mapBadge');
        if (badge) {
            badge.classList.remove('live', 'stale');
            badge.classList.add('live');
            badge.innerText = 'DELIVERED';
        }
    }

    function pushLocationToServer(pos) {
        const now = Date.now();
        if (now - lastGpsSentAt < GPS_SEND_EVERY_MS) return;
        lastGpsSentAt = now;

        const c = pos.coords;

        placeDriverMarker(c.latitude, c.longitude, false, c.speed);
        if (activeDeliveryLatLng) drawDriverRoute();

        const fd = new FormData();
        fd.append('driver_id', DRIVER_ID_FOR_TEST);
        fd.append('latitude',  c.latitude);
        fd.append('longitude', c.longitude);
        if (c.accuracy != null) fd.append('accuracy', c.accuracy);
        if (c.heading  != null) fd.append('heading',  c.heading);
        if (c.speed    != null) fd.append('speed',    c.speed);

        fetch('update_location.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    lastKnownGps = {
                        driver_id: DRIVER_ID_FOR_TEST,
                        latitude: data.latitude,
                        longitude: data.longitude,
                        speed: c.speed,
                        seconds_ago: 0,
                    };
                    updateInfoGps(data.latitude, data.longitude, 0, c.speed);
                    const spd = (c.speed != null) ? (c.speed * 3.6).toFixed(1) + ' km/h' : 'n/a';
                    setGpsPill('active', 'GPS: live · ' + spd);
                    console.log('[GPS] sent', c.latitude.toFixed(6), c.longitude.toFixed(6), 'speed', c.speed);

                    // ─── NEW: check if the server auto-marked delivered ───
                    if (data.auto_delivered) {
                        console.log('[GPS] Auto-delivered by server!');
                        showArrivedState();
                    }
                } else {
                    setGpsPill('error', 'GPS: ' + (data.error || 'rejected'));
                    console.warn('[GPS] server rejected:', data.error);
                }
            })
            .catch(err => {
                setGpsPill('error', 'GPS: send failed');
                console.warn('[GPS] send failed:', err);
            });
    }

    function startGpsTracking() {
        if (!('geolocation' in navigator)) {
            setGpsPill('error', 'GPS: not supported');
            return;
        }
        if (gpsWatchId !== null) return;

        setGpsPill('', 'GPS: requesting…');

        gpsWatchId = navigator.geolocation.watchPosition(
            pos => pushLocationToServer(pos),
            err => {
                const msg = ({
                    1: 'permission denied',
                    2: 'position unavailable',
                    3: 'timeout',
                })[err.code] || err.message;
                setGpsPill('error', 'GPS: ' + msg);
                console.warn('[GPS] watch error:', err.code, msg);
            },
            {
                enableHighAccuracy: true,
                maximumAge: 5000,
                timeout: 15000,
            }
        );
    }

    function stopGpsTracking() {
        if (gpsWatchId !== null) {
            navigator.geolocation.clearWatch(gpsWatchId);
            gpsWatchId = null;
        }
    }

    function setChatStatus(text, isActive) {
        const t = document.getElementById('chatStatusText');
        const c = document.getElementById('chatStatus');
        if (t) t.innerText = text;
        if (c) c.style.color = isActive ? 'var(--success-600)' : 'var(--slate-400)';
    }

    function formatTime(iso) {
        if (!iso) return '';
        const parts = iso.split(' ');
        if (parts.length < 2) return iso;
        const [h, m] = parts[1].split(':');
        let hour = parseInt(h, 10);
        const ampm = hour >= 12 ? 'PM' : 'AM';
        hour = hour % 12 || 12;
        return hour + ':' + m + ' ' + ampm;
    }

    function formatBytes(bytes) {
        if (!bytes) return '0 B';
        const k = 1024, sizes = ['B','KB','MB','GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return (bytes / Math.pow(k, i)).toFixed(i === 0 ? 0 : 1) + ' ' + sizes[i];
    }

    function guessIconForMime(mime) {
        if (!mime) return 'fa-file';
        if (mime.startsWith('image/')) return 'fa-file-image';
        if (mime.startsWith('video/')) return 'fa-file-video';
        if (mime === 'application/pdf') return 'fa-file-pdf';
        if (mime.includes('word')) return 'fa-file-word';
        if (mime.includes('excel') || mime.includes('spreadsheet')) return 'fa-file-excel';
        if (mime.includes('zip') || mime.includes('rar') || mime.includes('compressed')) return 'fa-file-zipper';
        if (mime.startsWith('text/')) return 'fa-file-lines';
        return 'fa-file';
    }

    function buildAttachmentNode(msg) {
        if (!msg.attachment_path) return null;
        const wrap = document.createElement('div');
        wrap.className = 'msg-attachment';
        const url = msg.attachment_path;
        const type = msg.attachment_mime || '';

        if (type.startsWith('image/')) {
            const img = document.createElement('img');
            img.src = url; img.alt = msg.attachment_name || 'image';
            img.loading = 'lazy';
            img.onclick = () => window.open(url, '_blank');
            wrap.appendChild(img);
        } else if (type.startsWith('video/')) {
            const v = document.createElement('video');
            v.src = url; v.controls = true; v.preload = 'metadata';
            wrap.appendChild(v);
        } else {
            const a = document.createElement('a');
            a.className = 'file-pill'; a.href = url;
            a.target = '_blank'; a.download = msg.attachment_name || '';

            const icon = document.createElement('i');
            icon.className = 'fas ' + guessIconForMime(type);

            const body = document.createElement('div'); body.className = 'fp-body';
            const name = document.createElement('div'); name.className = 'fp-name';
            name.innerText = msg.attachment_name || 'file';
            const size = document.createElement('div'); size.className = 'fp-size';
            size.innerText = formatBytes(parseInt(msg.attachment_size, 10) || 0);

            body.appendChild(name); body.appendChild(size);
            a.appendChild(icon); a.appendChild(body);
            wrap.appendChild(a);
        }
        return wrap;
    }

    function renderMessage(msg) {
        const wrap = document.createElement('div');
        const isMine = (msg.sender_role === VIEWER_ROLE);
        wrap.className = 'chat-msg ' + (isMine ? 'driver' : 'admin');
        wrap.dataset.messageId = msg.id;

        if (msg.message && msg.message.trim() !== '') {
            const t = document.createElement('div');
            t.innerText = msg.message;
            wrap.appendChild(t);
        }

        const a = buildAttachmentNode(msg);
        if (a) wrap.appendChild(a);

        const meta = document.createElement('span');
        meta.className = 'msg-meta';
        meta.innerText = (isMine ? 'You' : 'Admin') + ' · ' + formatTime(msg.created_at);
        wrap.appendChild(meta);

        return wrap;
    }

    function selectAssignment(el) {
        document.querySelectorAll('.assignment-item').forEach(i => i.classList.remove('active-item'));
        el.classList.add('active-item');

        const assignmentId = parseInt(el.dataset.assignmentId, 10);
        const destination  = el.dataset.destination || '—';
        const address      = el.dataset.address     || '';
        const latStr       = el.dataset.lat         || '';
        const lngStr       = el.dataset.lng         || '';

        activeDestination = destination;

        document.getElementById('infoDest').innerText    = destination;
        document.getElementById('infoAddress').innerText = address || '—';
        document.getElementById('infoId').innerText      = assignmentId;

        const lat = parseFloat(latStr);
        const lng = parseFloat(lngStr);

        if (!isNaN(lat) && !isNaN(lng)) {
            activeDeliveryLatLng = [lat, lng];
            drawDeliveryRoute([lat, lng], destination);
            if (driverMarker) drawDriverRoute();
        } else {
            activeDeliveryLatLng = null;
            if (deliveryRouteLine) { liveMap.removeLayer(deliveryRouteLine); deliveryRouteLine = null; }
            if (deliveryMarker)    { liveMap.removeLayer(deliveryMarker);    deliveryMarker    = null; }
            if (driverRouteLine)   { liveMap.removeLayer(driverRouteLine);   driverRouteLine   = null; }
            if (guideLine)         { liveMap.removeLayer(guideLine);         guideLine         = null; }
        }

        loadChatForAssignment(assignmentId);
    }

    function loadChatForAssignment(assignmentId) {
        activeAssignmentId = assignmentId;
        lastMessageId = 0;
        clearAttachment();

        const input   = document.getElementById('chatInput');
        const sendBtn = document.getElementById('chatSendBtn');
        const attach  = document.getElementById('attachBtn');

        input.disabled = false; sendBtn.disabled = false;
        if (attach) attach.disabled = false;
        input.placeholder = 'Type a message to admin...';
        setChatStatus('Loading...', false);

        const container = document.getElementById('chatContainer');
        container.innerHTML = '<div class="chat-empty"><i class="fas fa-spinner fa-spin"></i><div>Loading...</div></div>';

        fetch(`get_messages.php?assignment_id=${assignmentId}&viewer_role=${VIEWER_ROLE}&driver_id=${DRIVER_ID_FOR_TEST}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.error || 'Failed');
                container.innerHTML = '';
                if (!data.messages || data.messages.length === 0) {
                    container.innerHTML = `
                        <div class="chat-empty">
                            <i class="fas fa-comment-dots"></i>
                            <div>No messages yet</div>
                            <div style="font-size:0.7rem;">Send the first message to admin.</div>
                        </div>`;
                } else {
                    data.messages.forEach(m => {
                        container.appendChild(renderMessage(m));
                        lastMessageId = Math.max(lastMessageId, parseInt(m.id, 10));
                    });
                }
                container.scrollTop = container.scrollHeight;
                setChatStatus('Active', true);
                startChatPolling();
                markMessagesRead();
            })
            .catch(err => {
                console.error('Chat load error:', err);
                container.innerHTML = `<div class="chat-empty"><i class="fas fa-exclamation-triangle" style="color:#dc2626;"></i><div>Failed to load: ${err.message}</div></div>`;
                setChatStatus('Error', false);
            });
    }

    function startChatPolling() {
        if (chatPollTimer) clearInterval(chatPollTimer);
        chatPollTimer = setInterval(pollNewMessages, 3000);
    }
    function stopChatPolling() {
        if (chatPollTimer) { clearInterval(chatPollTimer); chatPollTimer = null; }
    }

    function pollNewMessages() {
        if (!activeAssignmentId) return;
        fetch(`get_messages.php?assignment_id=${activeAssignmentId}&after_id=${lastMessageId}&viewer_role=${VIEWER_ROLE}&driver_id=${DRIVER_ID_FOR_TEST}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success || !data.messages || data.messages.length === 0) return;
                const container = document.getElementById('chatContainer');
                const empty = container.querySelector('.chat-empty');
                if (empty) empty.remove();
                data.messages.forEach(m => {
                    if (container.querySelector(`[data-message-id="${m.id}"]`)) return;
                    container.appendChild(renderMessage(m));
                    lastMessageId = Math.max(lastMessageId, parseInt(m.id, 10));
                });
                container.scrollTop = container.scrollHeight;
                markMessagesRead();
            })
            .catch(err => console.error('Poll error:', err));
    }

    function markMessagesRead() {
        if (!activeAssignmentId) return;
        const p = new URLSearchParams();
        p.append('assignment_id', activeAssignmentId);
        p.append('viewer_role', VIEWER_ROLE);
        p.append('driver_id', DRIVER_ID_FOR_TEST);
        fetch('mark_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: p.toString()
        }).catch(() => {});
    }

    function handleFilePick(e) {
        const file = e.target.files[0];
        if (!file) return;
        if (file.size > 50 * 1024 * 1024) {
            alert('File too large (max 50 MB)');
            e.target.value = '';
            return;
        }
        pendingFile = file;
        document.getElementById('attachName').innerText = file.name;
        document.getElementById('attachSize').innerText = '(' + formatBytes(file.size) + ')';
        document.getElementById('attachPreview').style.display = 'flex';
    }

    function clearAttachment() {
        pendingFile = null;
        const fi = document.getElementById('chatFileInput');
        if (fi) fi.value = '';
        const pv = document.getElementById('attachPreview');
        if (pv) pv.style.display = 'none';
    }

    function sendChatMessage() {
        if (!activeAssignmentId) return;
        const input = document.getElementById('chatInput');
        const sendBtn = document.getElementById('chatSendBtn');
        const attach = document.getElementById('attachBtn');
        const text = input.value.trim();

        if (!text && !pendingFile) return;

        input.disabled = true; sendBtn.disabled = true;
        if (attach) attach.disabled = true;

        const fd = new FormData();
        fd.append('assignment_id', activeAssignmentId);
        fd.append('message', text);
        fd.append('sender_role', VIEWER_ROLE);
        fd.append('driver_id', DRIVER_ID_FOR_TEST);
        if (pendingFile) fd.append('attachment', pendingFile);

        fetch('send_message.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.error || 'Failed');
                const container = document.getElementById('chatContainer');
                const empty = container.querySelector('.chat-empty');
                if (empty) empty.remove();
                container.appendChild(renderMessage({
                    id: data.message_id,
                    sender_role: data.sender_role,
                    message: data.message,
                    attachment_path: data.attachment_path,
                    attachment_name: data.attachment_name,
                    attachment_mime: data.attachment_mime,
                    attachment_size: data.attachment_size,
                    created_at: data.created_at
                }));
                lastMessageId = Math.max(lastMessageId, parseInt(data.message_id, 10));
                input.value = '';
                clearAttachment();
                container.scrollTop = container.scrollHeight;
            })
            .catch(err => { alert('Send failed: ' + err.message); })
            .finally(() => {
                input.disabled = false; sendBtn.disabled = false;
                if (attach) attach.disabled = false;
                input.focus();
            });
    }

    function handleChatKeyPress(e) {
        if (e.key === 'Enter') { e.preventDefault(); sendChatMessage(); }
    }

    window.addEventListener('beforeunload', function () {
        stopChatPolling();
        stopGpsTracking();
    });

    document.addEventListener('DOMContentLoaded', function () {
        initMap();

        // Restore marker only if the saved location belongs to THIS driver
        if (lastKnownGps &&
            lastKnownGps.latitude &&
            lastKnownGps.longitude &&
            lastKnownGps.driver_id === DRIVER_ID_FOR_TEST) {

            const stale = lastKnownGps.seconds_ago > 60;
            placeDriverMarker(lastKnownGps.latitude, lastKnownGps.longitude, stale, lastKnownGps.speed);
            updateInfoGps(lastKnownGps.latitude, lastKnownGps.longitude, lastKnownGps.seconds_ago, lastKnownGps.speed);
        }

        const first = document.querySelector('.assignment-item');
        if (first) {
            selectAssignment(first);
        }

        startGpsTracking();
    });
</script>

</body>
</html>
