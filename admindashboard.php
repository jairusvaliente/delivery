<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/connect_db.php';

$assignments = [];
$dbError = null;

try {
    $sql = "
        SELECT id, driver_id, vehicle_id, destination, notes, status, assigned_at, unassigned_at
        FROM driver_assignments
        WHERE status = 'active'
        ORDER BY id ASC
    ";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) { $assignments[] = $row; }
    $result->free();

    $drivers = [];
    $r = $conn->query("SELECT id, name, username, acctlevel, status FROM acct");
    while ($d = $r->fetch_assoc()) { $drivers[(int)$d['id']] = $d; }
    $r->free();

    $vehicles = [];
    $r = $conn->query("SELECT id, plate_number, model, type, capacity, status FROM vehicles");
    while ($v = $r->fetch_assoc()) { $vehicles[(int)$v['id']] = $v; }
    $r->free();

    $deliveryLocations = [];
    $r = $conn->query("SELECT id, code, name, address, lat, lng FROM deliveries");
    while ($loc = $r->fetch_assoc()) {
        $deliveryLocations[strtolower(trim($loc['name']))] = $loc;
    }
    $r->free();

    foreach ($assignments as &$a) {
        $dId = (int)$a['driver_id'];
        if (isset($drivers[$dId])) {
            $a['driver_name']     = $drivers[$dId]['name'];
            $a['driver_username'] = $drivers[$dId]['username'];
        } else {
            $a['driver_name'] = null;
            $a['driver_username'] = null;
        }

        $vId = (int)$a['vehicle_id'];
        if (isset($vehicles[$vId])) {
            $a['vehicle_plate'] = $vehicles[$vId]['plate_number'];
            $a['vehicle_model'] = $vehicles[$vId]['model'];
            $a['vehicle_type']  = $vehicles[$vId]['type'] ?? null;
        } else {
            $a['vehicle_plate'] = null;
            $a['vehicle_model'] = null;
            $a['vehicle_type']  = null;
        }

        $locKey = strtolower(trim($a['destination']));
        if (isset($deliveryLocations[$locKey])) {
            $a['code']    = $deliveryLocations[$locKey]['code'];
            $a['address'] = $deliveryLocations[$locKey]['address'];
            $a['lat']     = (float)$deliveryLocations[$locKey]['lat'];
            $a['lng']     = (float)$deliveryLocations[$locKey]['lng'];
        } else {
            $a['code']    = strtoupper(substr($a['destination'], 0, 3));
            $a['address'] = '';
            $a['lat']     = null;
            $a['lng']     = null;
        }
    }
    unset($a);

} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$depotCoords = [14.139944, 121.124139];
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard View</title>
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
            --danger-500: #ef4444;
            --danger-600: #dc2626;
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.06), 0 1px 2px rgba(15, 23, 42, 0.04);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; }
        html, body { height: 100%; }
        body { background: var(--slate-50); color: var(--slate-900); display: flex; flex-direction: column; overflow: hidden; padding: 1.25rem 1.5rem; }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--slate-300); border-radius: 10px; }

        .dashboard-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1rem; flex-shrink: 0;
        }
        .dashboard-header .page-title {
            font-size: 1.3rem; font-weight: 800; color: var(--slate-900);
            letter-spacing: -0.02em; display: flex; align-items: center; gap: 0.6rem;
        }
        .dashboard-header .page-title i { color: var(--primary-600); }
        .dashboard-header .live-badge {
            background: var(--white); color: var(--primary-700);
            border: 1px solid var(--primary-200); padding: 0.45rem 1rem;
            border-radius: 2rem; font-size: 0.72rem; font-weight: 700;
            display: flex; align-items: center; gap: 0.5rem; box-shadow: var(--shadow-sm);
        }
        .dashboard-header .live-badge i { font-size: 0.5rem; color: var(--primary-500); animation: pulse-dot 1.5s infinite; }

        @keyframes pulse-dot { 0%,100% { opacity:1 } 50% { opacity:.4; transform:scale(1.2) } }

        .error-banner {
            background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;
            border-radius: 8px; padding: 10px 14px; font-size: 0.8rem;
            margin-bottom: 1rem; font-family: monospace;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 380px minmax(0, 1fr);
            gap: 1rem;
            flex: 1; min-height: 0;
        }
        .left-column  { display: flex; flex-direction: column; gap: 1rem; min-height: 0; min-width: 0; }
        .right-column { display: flex; flex-direction: column; gap: 1rem; min-height: 0; min-width: 0; }

        .card-box {
            background: var(--white); border-radius: var(--radius-lg);
            border: 1px solid var(--slate-200); box-shadow: var(--shadow-sm);
            display: flex; flex-direction: column; overflow: hidden;
            min-width: 0; min-height: 0;
        }
        .panel-header {
            padding: 0.85rem 1.15rem; border-bottom: 1px solid var(--slate-100);
            display: flex; justify-content: space-between; align-items: center;
            flex-shrink: 0; background: var(--white); gap: 0.5rem;
        }
        .panel-header h3 {
            font-size: 0.85rem; font-weight: 700; color: var(--slate-800);
            display: flex; align-items: center; gap: 0.5rem;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .panel-header h3 i { color: var(--primary-600); }
        .panel-header .count-badge {
            background: var(--primary-50); color: var(--primary-700);
            border: 1px solid var(--primary-200); padding: 0.15rem 0.6rem;
            border-radius: 2rem; font-size: 0.7rem; font-weight: 700;
            flex-shrink: 0;
        }

        /* LIST */
        .assignment-list-panel { flex: 1 1 auto; min-height: 200px; max-height: 42vh; }
        .assignment-list-panel .panel-body { overflow-y: auto; padding: 0.5rem; flex: 1; }

        .assignment-item {
            display: flex; align-items: center; gap: 0.6rem;
            padding: 0.6rem 0.7rem; border-radius: var(--radius-md);
            cursor: pointer; transition: all 0.25s ease; background: var(--white);
            margin-bottom: 0.35rem; border: 1px solid transparent;
            min-width: 0; position: relative;
        }
        .assignment-item:last-child { margin-bottom: 0; }
        .assignment-item:hover { background: var(--slate-50); border-color: var(--slate-200); }
        .assignment-item.active-item { background: var(--primary-50); border-color: var(--primary-200); }

        .assignment-item.removing {
            opacity: 0;
            transform: translateX(-20px);
            max-height: 0;
            padding-top: 0;
            padding-bottom: 0;
            margin-bottom: 0;
            overflow: hidden;
        }

        .assignment-item.auto-delivered {
            background: var(--success-50);
            border-color: #86efac;
            animation: successFlash 1.2s ease-in-out;
        }
        @keyframes successFlash {
            0%   { background: var(--success-50); }
            50%  { background: #bbf7d0; }
            100% { background: var(--success-50); }
        }

        .assignment-item .assignment-icon {
            width: 34px; height: 34px; border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.7rem; color: white; flex-shrink: 0; font-weight: 800;
            background: linear-gradient(135deg, var(--primary-500), var(--primary-600));
            box-shadow: 0 2px 6px rgba(234, 88, 12, 0.2);
            overflow: hidden;
        }
        .assignment-item.inactive-icon .assignment-icon {
            background: linear-gradient(135deg, var(--slate-600), var(--slate-700));
            box-shadow: none;
        }
        .assignment-item .assignment-info { flex: 1; min-width: 0; }
        .assignment-item .assignment-title {
            font-size: 0.83rem; font-weight: 700; color: var(--slate-900);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .assignment-item .assignment-subtitle {
            font-size: 0.72rem; color: var(--slate-500);
            margin-top: 0.15rem;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .assignment-item .assignment-subtitle .driver-name {
            color: var(--success-600); font-weight: 600;
        }
        .assignment-item .no-loc { color: #dc2626; margin-left: 4px; }

        /* DELIVERED BUTTON */
        .deliver-btn {
            background: var(--success-600);
            color: #fff;
            border: none;
            padding: 0.45rem 0.7rem;
            border-radius: var(--radius-sm);
            font-size: 0.68rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            flex-shrink: 0;
            transition: all 0.18s ease;
            white-space: nowrap;
        }
        .deliver-btn:hover {
            background: #15803d;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(22, 163, 74, 0.35);
        }
        .deliver-btn:active { transform: translateY(0); }
        .deliver-btn:disabled {
            background: var(--slate-300);
            color: var(--slate-500);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .deliver-btn .btn-icon { font-size: 0.75rem; }

        /* CHAT */
        .chat-panel { flex: 1 1 auto; min-height: 300px; display: flex; flex-direction: column; }
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
            align-self: flex-end;
            background: linear-gradient(135deg, var(--primary-500), var(--primary-600));
            color: var(--white); border-bottom-right-radius: 3px;
        }
        .chat-msg.driver {
            align-self: flex-start;
            background: var(--white);
            border: 1px solid var(--slate-200);
            color: var(--slate-800); border-bottom-left-radius: 3px;
        }
        .chat-msg .msg-meta { display: block; font-size: 0.65rem; opacity: 0.7; margin-top: 0.25rem; }
        .chat-empty {
            flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
            color: var(--slate-400); font-size: 0.78rem; gap: 0.5rem; padding: 2rem 1rem; text-align: center;
        }
        .chat-empty i { font-size: 1.6rem; color: var(--slate-300); }

        .chat-input-area {
            border-top: 1px solid var(--slate-100); padding: 0.75rem;
            background: var(--white); flex-shrink: 0;
        }
        .chat-input-row { display: flex; gap: 0.5rem; }
        .chat-input-row input[type="text"] {
            flex: 1; min-width: 0;
            padding: 0.7rem 0.9rem; border: 1px solid var(--slate-300);
            border-radius: var(--radius-sm); background: var(--white);
            color: var(--slate-900); font-size: 0.82rem; outline: none; transition: border-color 0.2s;
        }
        .chat-input-row input[type="text"]:focus { border-color: var(--primary-500); }
        .chat-input-row button.send-btn {
            background: var(--primary-600); color: white; border: none;
            padding: 0.7rem 1.1rem; border-radius: var(--radius-sm);
            font-weight: 600; font-size: 0.82rem; cursor: pointer; transition: background 0.2s;
            display: flex; align-items: center; justify-content: center; gap: 0.35rem;
            flex-shrink: 0;
        }
        .chat-input-row button.send-btn:hover:not(:disabled) { background: var(--primary-700); }
        .chat-input-row button.send-btn:disabled { opacity: 0.55; cursor: not-allowed; }

        /* CHAT ATTACHMENTS */
        .attach-btn {
            background: var(--slate-100);
            color: var(--slate-600);
            border: 1px solid var(--slate-200);
            padding: 0 0.85rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.18s ease;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.95rem; flex-shrink: 0;
        }
        .attach-btn:hover:not(:disabled) {
            background: var(--primary-50);
            border-color: var(--primary-200);
            color: var(--primary-600);
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
            border-radius: 4px; transition: background 0.15s; flex-shrink: 0;
        }
        .attach-preview .attach-remove:hover { background: var(--primary-100); color: var(--danger-600); }

        .chat-msg .msg-attachment { display: block; margin-top: 0.5rem; max-width: 100%; }
        .chat-msg .msg-attachment img,
        .chat-msg .msg-attachment video {
            display: block; width: 100%; max-width: 260px; max-height: 320px;
            object-fit: cover; border-radius: var(--radius-sm);
            background: #000; cursor: pointer;
        }
        .chat-msg .msg-attachment .file-pill {
            display: flex; align-items: center; gap: 0.55rem;
            background: var(--slate-100); border: 1px solid var(--slate-200);
            border-radius: var(--radius-sm); padding: 0.55rem 0.7rem;
            text-decoration: none; color: var(--slate-800); font-size: 0.76rem;
            transition: background 0.15s; max-width: 260px;
        }
        .chat-msg .msg-attachment .file-pill:hover { background: var(--slate-200); }
        .chat-msg .msg-attachment .file-pill i { font-size: 1.25rem; color: var(--primary-600); flex-shrink: 0; }
        .chat-msg .msg-attachment .file-pill .fp-body { min-width: 0; flex: 1; }
        .chat-msg .msg-attachment .file-pill .fp-name { font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .chat-msg .msg-attachment .file-pill .fp-size { color: var(--slate-500); font-size: 0.68rem; }

        /* MAP */
        .map-box { flex: 1 1 auto; min-height: 260px; position: relative; }
        #map { flex: 1; width: 100%; height: 100%; min-height: 260px; z-index: 1; }

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
        .icon-driver.stale {
            background: #94a3b8;
            animation: none;
        }
        .icon-driver.off-route {
            background: #dc2626;
            animation: driverOffPulse 1.4s ease-in-out infinite;
        }
        @keyframes driverPulse {
            0%,100% { box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.65); }
            50%     { box-shadow: 0 0 0 14px rgba(37, 99, 235, 0); }
        }
        @keyframes driverOffPulse {
            0%,100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.7); }
            50%     { box-shadow: 0 0 0 16px rgba(220, 38, 38, 0); }
        }

        /* Driver tracking badge on map panel header */
        .driver-track-badge {
            display: inline-flex; align-items: center; gap: 0.4rem;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            padding: 0.2rem 0.65rem;
            border-radius: 2rem;
            font-size: 0.68rem;
            font-weight: 700;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .driver-track-badge .dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: #2563eb;
            display: inline-block;
            animation: gpsPulseBadge 1.5s infinite;
        }
        .driver-track-badge.stale {
            background: var(--slate-100);
            border-color: var(--slate-200);
            color: var(--slate-500);
        }
        .driver-track-badge.stale .dot {
            background: var(--slate-400);
            animation: none;
        }
        .driver-track-badge.offline {
            background: var(--slate-100);
            border-color: var(--slate-200);
            color: var(--slate-400);
        }
        .driver-track-badge.offline .dot {
            background: var(--slate-300);
            animation: none;
        }
        .driver-track-badge.off-route {
            background: var(--danger-50);
            border-color: #fca5a5;
            color: var(--danger-600);
        }
        .driver-track-badge.off-route .dot {
            background: var(--danger-600);
            animation: gpsPulseBadge 1.2s infinite;
        }
        @keyframes gpsPulseBadge {
            0%,100% { opacity: 1; transform: scale(1); }
            50%     { opacity: 0.4; transform: scale(1.35); }
        }

        /* OVERVIEW */
        .overview-panel { flex: 0 0 auto; min-height: 140px; }
        .overview-panel .panel-body { padding: 0.85rem 1rem; }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.65rem;
        }
        .detail-field {
            display: flex; flex-direction: column; gap: 3px;
            padding: 0.6rem 0.75rem;
            background: var(--slate-50);
            border-radius: var(--radius-sm);
            border: 1px solid var(--slate-200);
            min-width: 0;
            height: 62px;
            justify-content: center;
            overflow: hidden;
        }
        .detail-field .label {
            font-size: 0.62rem; font-weight: 700; color: var(--slate-500);
            text-transform: uppercase; letter-spacing: 0.04em;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .detail-field .value {
            font-size: 0.82rem; font-weight: 700; color: var(--slate-800);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .detail-field .value.placeholder {
            color: var(--slate-400); font-style: italic; font-weight: 500;
        }

        /* OFF-ROUTE BANNER */
        .off-route-banner {
            display: none;
            align-items: center;
            gap: 0.5rem;
            background: var(--danger-50);
            border: 1px solid #fca5a5;
            color: var(--danger-600);
            padding: 0.6rem 0.85rem;
            border-radius: var(--radius-sm);
            font-size: 0.78rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            animation: offRouteBlink 1.6s ease-in-out infinite;
        }
        .off-route-banner.show { display: flex; }
        .off-route-banner i { font-size: 1rem; flex-shrink: 0; }
        @keyframes offRouteBlink {
            0%,100% { background: var(--danger-50); }
            50%     { background: #ffe4e6; }
        }

        /* STATS */
        .info-card { flex: 0 0 auto; padding: 0.9rem 1rem; min-height: 110px; }
        .info-card .card-title {
            font-size: 0.66rem; font-weight: 800; color: var(--slate-400);
            text-transform: uppercase; letter-spacing: 0.06em;
            margin-bottom: 0.6rem; display: flex; align-items: center; gap: 0.4rem;
        }
        .info-grid-4 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 0.65rem;
        }
        .info-block {
            background: var(--slate-50); padding: 0.6rem 0.8rem;
            border-radius: var(--radius-md); border: 1px solid var(--slate-200);
            min-width: 0;
            height: 58px;
            display: flex; flex-direction: column; justify-content: center;
            overflow: hidden;
        }
        .info-block .info-label {
            font-size: 0.62rem; color: var(--slate-500); text-transform: uppercase;
            font-weight: 700; display: block; margin-bottom: 2px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .info-block .info-value {
            font-size: 0.85rem; font-weight: 800; color: var(--slate-900);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .info-block .info-value.placeholder {
            color: var(--slate-400); font-style: italic; font-weight: 500; font-size: 0.78rem;
        }

        /* TOAST */
        .toast-container {
            position: fixed; top: 1.2rem; right: 1.2rem;
            display: flex; flex-direction: column; gap: 0.5rem;
            z-index: 10001; pointer-events: none;
        }
        .toast {
            background: var(--white);
            border-left: 4px solid var(--success-600);
            padding: 0.85rem 1.1rem;
            border-radius: var(--radius-sm);
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.15);
            font-size: 0.82rem; font-weight: 600; color: var(--slate-800);
            display: flex; align-items: center; gap: 0.6rem;
            min-width: 240px; max-width: 340px;
            animation: toastIn 0.35s ease forwards;
            pointer-events: auto;
        }
        .toast.error { border-left-color: var(--danger-600); }
        .toast i { font-size: 1rem; color: var(--success-600); }
        .toast.error i { color: var(--danger-600); }

        @keyframes toastIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
        @keyframes toastOut { from { opacity: 1; transform: translateX(0); } to { opacity: 0; transform: translateX(20px); } }
        .toast.hiding { animation: toastOut 0.3s ease forwards; }

        /* CONFIRM MODAL */
        .confirm-overlay {
            position: fixed; inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(6px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10002;
            padding: 1rem;
        }
        .confirm-overlay.active { display: flex; }

        .confirm-modal {
            background: var(--white);
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 440px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
            overflow: hidden;
            animation: modalPop 0.28s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }
        @keyframes modalPop { from { opacity: 0; transform: scale(0.92) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }

        .confirm-modal-header {
            padding: 1.5rem 1.5rem 0.75rem;
            display: flex; flex-direction: column; align-items: center; text-align: center;
        }
        .confirm-modal-icon {
            width: 56px; height: 56px; border-radius: 50%;
            background: var(--success-50); color: var(--success-600);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; margin-bottom: 0.85rem;
            animation: iconPulse 2s ease-in-out infinite;
        }
        @keyframes iconPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.35); }
            50%      { box-shadow: 0 0 0 12px rgba(22, 163, 74, 0); }
        }
        .confirm-modal-title { font-size: 1.05rem; font-weight: 800; color: var(--slate-900); margin-bottom: 0.35rem; }
        .confirm-modal-message {
            font-size: 0.83rem; color: var(--slate-500); line-height: 1.5;
            text-align: center; padding: 0 1.5rem 1.25rem;
        }
        .confirm-modal-info {
            background: var(--slate-50);
            border-top: 1px solid var(--slate-200);
            border-bottom: 1px solid var(--slate-200);
            padding: 0.85rem 1.5rem;
            display: flex; flex-direction: column; gap: 0.35rem;
        }
        .confirm-modal-info-row { display: flex; justify-content: space-between; gap: 0.75rem; font-size: 0.78rem; }
        .confirm-modal-info-row .info-key { color: var(--slate-500); font-weight: 600; flex-shrink: 0; }
        .confirm-modal-info-row .info-val {
            color: var(--slate-800); font-weight: 700;
            text-align: right; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0;
        }

        .confirm-modal-actions { padding: 1rem 1.25rem 1.25rem; display: flex; gap: 0.6rem; }
        .confirm-modal-actions button {
            flex: 1; padding: 0.75rem 1rem; border-radius: var(--radius-sm);
            font-size: 0.83rem; font-weight: 700; cursor: pointer;
            transition: all 0.18s ease;
            display: flex; align-items: center; justify-content: center; gap: 0.4rem;
            border: none;
        }
        .btn-cancel { background: var(--slate-100); color: var(--slate-700); border: 1px solid var(--slate-200) !important; }
        .btn-cancel:hover { background: var(--slate-200); }
        .btn-confirm {
            background: var(--success-600); color: var(--white);
            box-shadow: 0 3px 10px rgba(22, 163, 74, 0.3);
        }
        .btn-confirm:hover { background: #15803d; transform: translateY(-1px); box-shadow: 0 5px 14px rgba(22, 163, 74, 0.4); }
        .btn-confirm:active { transform: translateY(0); }
        .btn-confirm:disabled { background: var(--slate-300); color: var(--slate-500); cursor: not-allowed; box-shadow: none; transform: none; }

        /* VEHICLE MODAL */
        .vehicle-modal-overlay {
            position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px); display: none; align-items: center;
            justify-content: center; z-index: 9999; padding: 1rem;
        }
        .vehicle-modal-overlay.active { display: flex; }
        .vehicle-modal-container {
            background: #ffffff; border-radius: var(--radius-lg);
            width: 100%; max-width: 950px; height: 85vh; position: relative;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); overflow: hidden;
        }
        .vehicle-modal-close {
            position: absolute; top: 12px; right: 16px; background: #f1f5f9;
            border: 1px solid #cbd5e1; width: 32px; height: 32px; border-radius: 50%;
            font-size: 1rem; color: var(--slate-600); cursor: pointer; z-index: 10000;
            display: flex; align-items: center; justify-content: center; transition: all 0.2s ease;
        }
        .vehicle-modal-close:hover { background: #e2e8f0; color: var(--slate-900); }

        /* RESPONSIVE */
        @media (max-width: 1200px) {
            .dashboard-grid { grid-template-columns: 340px minmax(0, 1fr); }
        }
        @media (max-width: 1000px) {
            body { overflow: auto; }
            .dashboard-grid { grid-template-columns: 1fr; height: auto; }
            .left-column, .right-column { min-height: auto; }
            .assignment-list-panel { max-height: 320px; }
            .chat-panel { min-height: 350px; height: 420px; }
            .map-box { height: 380px; }
            .details-grid { grid-template-columns: repeat(2, 1fr); }
            .info-grid-4 { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 640px) {
            body { padding: 1rem; }
            .dashboard-header { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
            .dashboard-header .page-title { font-size: 1.1rem; }
            .details-grid { grid-template-columns: 1fr; }
            .info-grid-4  { grid-template-columns: 1fr 1fr; }
            .detail-field { height: auto; min-height: 58px; }
            .info-block   { height: auto; min-height: 54px; }
            .chat-input-row button.send-btn { padding: 0.7rem 0.85rem; }
            .chat-input-row button.send-btn span { display: none; }
            .deliver-btn .btn-label { display: none; }
            .deliver-btn { padding: 0.5rem 0.6rem; }
            .confirm-modal-actions { flex-direction: column-reverse; }
        }
    </style>
</head>
<body>

<?php if ($dbError): ?>
    <div class="error-banner">
        <strong>Database error:</strong> <?= htmlspecialchars($dbError) ?>
    </div>
<?php endif; ?>

<div class="dashboard-header">
    <div class="page-title"><i class="fas fa-route"></i> Live Dispatch Monitor</div>
    <div class="live-badge"><i class="fas fa-circle"></i> SYSTEM ONLINE</div>
</div>

<div class="dashboard-grid">

    <!-- LEFT COLUMN -->
    <div class="left-column">

        <div class="assignment-list-panel card-box">
            <div class="panel-header">
                <h3><i class="fas fa-list-check"></i> Active Assignments</h3>
                <span class="count-badge" id="assignmentCount"><?= count($assignments) ?> Rows</span>
            </div>
            <div class="panel-body" id="assignmentListBody">
                <?php if (empty($assignments)): ?>
                    <div class="empty-list-msg" style="padding:1.2rem;text-align:center;color:#94a3b8;font-style:italic;font-size:0.8rem;">
                        No active assignments
                    </div>
                <?php else: ?>
                    <?php foreach ($assignments as $index => $a): ?>
                        <div class="assignment-item <?= $index === 0 ? 'active-item' : 'inactive-icon' ?>"
                             data-assignment-id="<?= htmlspecialchars($a['id']) ?>"
                             data-destination="<?= htmlspecialchars($a['destination']) ?>"
                             data-driver-id="<?= htmlspecialchars($a['driver_id']) ?>"
                             data-driver-name="<?= htmlspecialchars($a['driver_name'] ?? '') ?>"
                             data-vehicle-id="<?= htmlspecialchars($a['vehicle_id']) ?>"
                             data-vehicle-plate="<?= htmlspecialchars($a['vehicle_plate'] ?? '') ?>"
                             data-vehicle-model="<?= htmlspecialchars($a['vehicle_model'] ?? '') ?>"
                             data-address="<?= htmlspecialchars($a['address'] ?? '') ?>"
                             data-lat="<?= $a['lat'] !== null ? htmlspecialchars($a['lat']) : '' ?>"
                             data-lng="<?= $a['lng'] !== null ? htmlspecialchars($a['lng']) : '' ?>"
                             onclick="selectAssignment(this)">
                            <div class="assignment-icon"><?= htmlspecialchars($a['code']) ?></div>
                            <div class="assignment-info">
                                <div class="assignment-title" title="<?= htmlspecialchars($a['destination']) ?>">
                                    <?= htmlspecialchars($a['destination']) ?>
                                    <?php if ($a['lat'] === null): ?>
                                        <span class="no-loc" title="No lat/lng found">⚠</span>
                                    <?php endif; ?>
                                </div>
                                <div class="assignment-subtitle">
                                    <?php if (!empty($a['driver_name'])): ?>
                                        <span class="driver-name">
                                            <i class="fas fa-user" style="font-size:0.6rem;"></i>
                                            <?= htmlspecialchars($a['driver_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;font-style:italic;">Unassigned</span>
                                    <?php endif; ?>
                                    <?php if (!empty($a['vehicle_plate'])): ?>
                                        · <?= htmlspecialchars($a['vehicle_plate']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button class="deliver-btn"
                                    title="Mark as delivered"
                                    onclick="event.stopPropagation(); openConfirmModal(this);">
                                <i class="fas fa-check btn-icon"></i>
                                <span class="btn-label">Mark as delivered</span>
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- CHAT PANEL -->
        <div class="chat-panel card-box">
            <div class="panel-header">
                <h3><i class="fas fa-comments"></i> Driver Communications</h3>
                <span id="chatStatus" style="color: var(--slate-400); font-weight:700; font-size:0.7rem; display:flex; align-items:center; gap:0.3rem;">
                    <i class="fas fa-circle" style="font-size:0.45rem;"></i>
                    <span id="chatStatusText">Idle</span>
                </span>
            </div>

            <div class="chat-messages" id="chatContainer">
                <div class="chat-empty">
                    <i class="fas fa-comment-dots"></i>
                    <div>Select an assignment to start chatting</div>
                    <div style="font-size:0.7rem;">Messages with the assigned driver will appear here.</div>
                </div>
            </div>

            <div class="chat-input-area">
                <div class="attach-preview" id="attachPreview" style="display:none;">
                    <div class="attach-info">
                        <i class="fas fa-paperclip"></i>
                        <span id="attachName">file.jpg</span>
                        <span id="attachSize" class="attach-size">(0 KB)</span>
                    </div>
                    <button type="button" class="attach-remove" onclick="clearAttachment()" title="Remove">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="chat-input-row">
                    <input type="file" id="chatFileInput"
                           accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip,.rar"
                           style="display:none;"
                           onchange="handleFilePick(event)">

                    <button type="button" class="attach-btn" id="attachBtn"
                            title="Attach a file"
                            onclick="document.getElementById('chatFileInput').click()" disabled>
                        <i class="fas fa-paperclip"></i>
                    </button>

                    <input type="text" id="chatInput"
                           placeholder="Select an assignment first..."
                           disabled onkeypress="handleChatKeyPress(event)">

                    <button class="send-btn" id="chatSendBtn" onclick="sendChatMessage()" disabled>
                        <i class="fas fa-paper-plane"></i> <span>Send</span>
                    </button>
                </div>
            </div>
        </div>

    </div>

    <!-- RIGHT COLUMN -->
    <div class="right-column">

        <div class="map-box card-box">
            <div class="panel-header">
                <h3><i class="fas fa-map-location-dot"></i> Real-Time Road Route Navigation</h3>
                <span class="driver-track-badge offline" id="driverTrackBadge">
                    <span class="dot"></span>
                    <span id="driverTrackText">No driver tracked</span>
                </span>
            </div>
            <div id="map"></div>
        </div>

        <div class="overview-panel card-box">
            <div class="panel-header">
                <h3><i class="fas fa-circle-info"></i> Assignment Overview</h3>
            </div>
            <div class="panel-body">
                <!-- OFF-ROUTE BANNER -->
                <div class="off-route-banner" id="offRouteBanner">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span>Driver is <strong>off the assigned route</strong> — <span id="offRouteDistance">—</span> away</span>
                </div>

                <div class="details-grid">
                    <div class="detail-field">
                        <span class="label">Destination</span>
                        <span class="value placeholder" id="val-dest">No assignment selected</span>
                    </div>
                    <div class="detail-field">
                        <span class="label">Address</span>
                        <span class="value placeholder" id="val-address">No assignment selected</span>
                    </div>
                    <div class="detail-field">
                        <span class="label">Driver</span>
                        <span class="value placeholder" id="val-driver">No assignment selected</span>
                    </div>
                    <div class="detail-field">
                        <span class="label">Vehicle</span>
                        <span class="value placeholder" id="val-vehicle">No assignment selected</span>
                    </div>
                    <div class="detail-field">
                        <span class="label">Assignment ID</span>
                        <span class="value placeholder" id="val-assignment-id">No assignment selected</span>
                    </div>
                    <div class="detail-field">
                        <span class="label">Driver GPS</span>
                        <span class="value placeholder" id="val-driver-gps">Not tracked</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="info-card card-box">
            <div class="card-title"><i class="fas fa-gauge-high"></i> Active Route Stats</div>
            <div class="info-grid-4">
                <div class="info-block">
                    <span class="info-label">Active Driver</span>
                    <span class="info-value placeholder" id="summary-driver">No assignment selected</span>
                </div>
                <div class="info-block">
                    <span class="info-label">Destination</span>
                    <span class="info-value placeholder" id="summary-dest">No assignment selected</span>
                </div>
                <div class="info-block">
                    <span class="info-label">Distance</span>
                    <span class="info-value placeholder" id="summary-distance">—</span>
                </div>
                <div class="info-block">
                    <span class="info-label">Estimated Time</span>
                    <span class="info-value placeholder" id="summary-duration">—</span>
                </div>
                <div class="info-block">
                    <span class="info-label">Current Speed</span>
                    <span class="info-value placeholder" id="summary-speed">—</span>
                </div>
            </div>
        </div>

    </div>

</div>

<!-- CONFIRM MODAL -->
<div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
        <div class="confirm-modal-header">
            <div class="confirm-modal-icon">
                <i class="fas fa-truck-fast"></i>
            </div>
            <div class="confirm-modal-title" id="confirmTitle">Mark as Delivered?</div>
        </div>
        <div class="confirm-modal-message">
            This will update the assignment status to <strong>delivered</strong> and remove it from the active list.
        </div>
        <div class="confirm-modal-info">
            <div class="confirm-modal-info-row">
                <span class="info-key">Destination</span>
                <span class="info-val" id="confirmDest">—</span>
            </div>
            <div class="confirm-modal-info-row">
                <span class="info-key">Driver</span>
                <span class="info-val" id="confirmDriver">—</span>
            </div>
            <div class="confirm-modal-info-row">
                <span class="info-key">Assignment ID</span>
                <span class="info-val" id="confirmId">—</span>
            </div>
        </div>
        <div class="confirm-modal-actions">
            <button class="btn-cancel" onclick="closeConfirmModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button class="btn-confirm" id="confirmBtn" onclick="confirmDelivered()">
                <i class="fas fa-check"></i> Yes, Delivered
            </button>
        </div>
    </div>
</div>

<div class="vehicle-modal-overlay" id="vehicleModalWrapper">
    <div class="vehicle-modal-container">
        <button class="vehicle-modal-close" onclick="closeVehicleModal()"><i class="fas fa-times"></i></button>
        <iframe src="vehicle.php" style="width:100%; height:100%; border:none; border-radius:16px;"></iframe>
    </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const depotOrigin = [<?= $depotCoords[0] ?>, <?= $depotCoords[1] ?>];
    const VIEWER_ROLE = 'admin';

    let map, originMarker, destMarker, routePolyline;
    let activeDeliveryLatLng = null;

    // ============================================================
    // Toast
    // ============================================================
    function showToast(message, isError = false) {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = 'toast' + (isError ? ' error' : '');
        toast.innerHTML = `<i class="fas ${isError ? 'fa-circle-exclamation' : 'fa-circle-check'}"></i><span>${message}</span>`;
        container.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('hiding');
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    }

    // ============================================================
    // Confirm modal
    // ============================================================
    let pendingBtn = null, pendingRow = null, pendingId = null;

    function openConfirmModal(btn) {
        pendingBtn = btn;
        pendingRow = btn.closest('.assignment-item');
        pendingId  = pendingRow?.dataset.assignmentId;
        if (!pendingRow || !pendingId) return;

        document.getElementById('confirmDest').innerText   = pendingRow.dataset.destination || '—';
        document.getElementById('confirmDriver').innerText = pendingRow.dataset.driverName  || 'Unassigned';
        document.getElementById('confirmId').innerText     = pendingRow.dataset.assignmentId;

        const confirmBtn = document.getElementById('confirmBtn');
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-check"></i> Yes, Delivered';

        document.getElementById('confirmOverlay').classList.add('active');
    }

    function closeConfirmModal() {
        document.getElementById('confirmOverlay').classList.remove('active');
        pendingBtn = null; pendingRow = null; pendingId = null;
    }

    function confirmDelivered() {
        if (!pendingId || !pendingBtn || !pendingRow) { closeConfirmModal(); return; }

        const confirmBtn = document.getElementById('confirmBtn');
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

        const row = pendingRow, btn = pendingBtn, id = pendingId;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin btn-icon"></i><span class="btn-label">Saving...</span>';

        fetch('mark_delivered.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'assignment_id=' + encodeURIComponent(id)
        })
        .then(res => res.json())
        .then(data => {
            closeConfirmModal();
            if (data.success) {
                showToast('Delivery marked as delivered');
                row.classList.add('removing');

                if (activeAssignmentId === parseInt(id, 10)) {
                    stopChatPolling();
                    activeAssignmentId = null;
                    clearAttachment();
                    document.getElementById('chatContainer').innerHTML = `
                        <div class="chat-empty">
                            <i class="fas fa-comment-dots"></i>
                            <div>Assignment delivered</div>
                            <div style="font-size:0.7rem;">Chat is closed for this assignment.</div>
                        </div>`;
                    setChatStatus('Closed', false);
                    document.getElementById('chatInput').disabled = true;
                    document.getElementById('chatSendBtn').disabled = true;
                    document.getElementById('attachBtn').disabled = true;

                    stopDriverTracking();
                }

                setTimeout(() => {
                    row.remove();
                    const remaining = document.querySelectorAll('.assignment-item').length;
                    const countEl = document.getElementById('assignmentCount');
                    if (countEl) countEl.innerText = remaining + ' Rows';

                    const body = document.getElementById('assignmentListBody');
                    if (remaining === 0 && !body.querySelector('.empty-list-msg')) {
                        const msg = document.createElement('div');
                        msg.className = 'empty-list-msg';
                        msg.style.cssText = 'padding:1.2rem;text-align:center;color:#94a3b8;font-style:italic;font-size:0.8rem;';
                        msg.innerText = 'No active assignments';
                        body.appendChild(msg);
                    }
                }, 300);

                if (row.classList.contains('active-item')) {
                    const ids = ['val-dest','val-address','val-driver','val-vehicle','val-assignment-id','val-driver-gps',
                                 'summary-driver','summary-dest','summary-distance','summary-duration','summary-speed'];
                    ids.forEach(elId => {
                        const el = document.getElementById(elId);
                        if (!el) return;
                        el.innerText = 'No assignment selected';
                        el.classList.add('placeholder');
                    });
                    hideOffRouteBanner();
                }
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check btn-icon"></i><span class="btn-label">Delivered</span>';
                showToast(data.error || 'Failed to update', true);
            }
        })
        .catch(err => {
            console.error(err);
            closeConfirmModal();
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check btn-icon"></i><span class="btn-label">Delivered</span>';
            showToast('Network error', true);
        });
    }

    document.getElementById('confirmOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeConfirmModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('confirmOverlay').classList.contains('active')) closeConfirmModal();
    });

    // ============================================================
    // Select assignment
    // ============================================================
    function selectAssignment(element) {
        document.querySelectorAll('.assignment-item').forEach(el => {
            el.classList.remove('active-item');
            el.classList.add('inactive-icon');
        });
        element.classList.add('active-item');
        element.classList.remove('inactive-icon');

        const destination  = element.dataset.destination  || '—';
        const driverId     = element.dataset.driverId     || '';
        const driverName   = element.dataset.driverName   || '';
        const vehiclePlate = element.dataset.vehiclePlate || '';
        const vehicleModel = element.dataset.vehicleModel || '';
        const assignmentId = element.dataset.assignmentId || '—';
        const address      = element.dataset.address      || '';
        const latStr       = element.dataset.lat          || '';
        const lngStr       = element.dataset.lng          || '';

        const destEl    = document.getElementById('val-dest');
        const addrEl    = document.getElementById('val-address');
        const driverEl  = document.getElementById('val-driver');
        const vehicleEl = document.getElementById('val-vehicle');
        const assignEl  = document.getElementById('val-assignment-id');
        const gpsEl     = document.getElementById('val-driver-gps');

        destEl.innerText = destination; destEl.title = destination;
        destEl.classList.remove('placeholder');

        addrEl.innerText = address || 'No address on file';
        addrEl.title = address || '';
        addrEl.classList.toggle('placeholder', !address);

        if (driverName) {
            driverEl.innerText = driverName + (driverId ? ' (ID: ' + driverId + ')' : '');
            driverEl.title = driverEl.innerText;
            driverEl.classList.remove('placeholder');
        } else {
            driverEl.innerText = 'Unassigned';
            driverEl.title = '';
            driverEl.classList.add('placeholder');
        }

        if (vehiclePlate || vehicleModel) {
            const parts = [];
            if (vehiclePlate) parts.push(vehiclePlate);
            if (vehicleModel) parts.push(vehicleModel);
            vehicleEl.innerText = parts.join(' — ');
            vehicleEl.title = vehicleEl.innerText;
            vehicleEl.classList.remove('placeholder');
        } else {
            vehicleEl.innerText = 'No vehicle';
            vehicleEl.title = '';
            vehicleEl.classList.add('placeholder');
        }

        assignEl.innerText = assignmentId; assignEl.title = assignmentId;
        assignEl.classList.remove('placeholder');

        gpsEl.innerText = 'Waiting…';
        gpsEl.classList.add('placeholder');

        const sumDriver = document.getElementById('summary-driver');
        const sumDest   = document.getElementById('summary-dest');
        sumDriver.innerText = driverName || 'Unassigned';
        sumDriver.title = sumDriver.innerText;
        sumDriver.classList.toggle('placeholder', !driverName);
        sumDest.innerText = destination;
        sumDest.title = destination;
        sumDest.classList.remove('placeholder');

        const lat = parseFloat(latStr);
        const lng = parseFloat(lngStr);

        if (!isNaN(lat) && !isNaN(lng)) {
            activeDeliveryLatLng = [lat, lng];
            const dEl = document.getElementById('summary-distance');
            const tEl = document.getElementById('summary-duration');
            dEl.innerText = 'Calculating...'; dEl.classList.add('placeholder');
            tEl.innerText = 'Calculating...'; tEl.classList.add('placeholder');
            fetchRoadRoute(depotOrigin, [lat, lng], destination);
        } else {
            activeDeliveryLatLng = null;
            if (routePolyline) { map.removeLayer(routePolyline); routePolyline = null; }
            if (destMarker)    { map.removeLayer(destMarker);    destMarker    = null; }
            const dEl = document.getElementById('summary-distance');
            const tEl = document.getElementById('summary-duration');
            dEl.innerText = '—'; dEl.classList.add('placeholder');
            tEl.innerText = '—'; tEl.classList.add('placeholder');
        }

        loadChatForAssignment(parseInt(assignmentId, 10));

        const driverIdNum = parseInt(driverId, 10);
        if (driverIdNum > 0) {
            startDriverTracking(driverIdNum);
        } else {
            stopDriverTracking();
        }
    }

    // ============================================================
    // Map
    // ============================================================
    function initMap() {
        if (map) return;
        map = L.map('map', { center: depotOrigin, zoom: 11, zoomControl: false });
        L.control.zoom({ position: 'bottomright' }).addTo(map);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(map);

        const depotIcon = L.divIcon({
            className: 'custom-map-icon icon-depot',
            html: '<i class="fas fa-warehouse"></i>',
            iconSize: [28, 28]
        });
        originMarker = L.marker(depotOrigin, { icon: depotIcon }).addTo(map).bindPopup('<b>Depot Origin</b>');
        setTimeout(() => { map.invalidateSize(); }, 300);
        window.addEventListener('resize', () => { if (map) map.invalidateSize(); });
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

    function fetchRoadRoute(origin, dest, destName) {
        if (routePolyline) map.removeLayer(routePolyline);
        if (originMarker) map.removeLayer(originMarker);
        if (destMarker) map.removeLayer(destMarker);

        const depotIcon = L.divIcon({ className: 'custom-map-icon icon-depot', html: '<i class="fas fa-warehouse"></i>', iconSize: [28, 28] });
        const destIcon  = L.divIcon({ className: 'custom-map-icon icon-destination', html: '<i class="fas fa-location-dot"></i>', iconSize: [28, 28] });

        originMarker = L.marker(origin, { icon: depotIcon }).addTo(map).bindPopup('<b>Depot Origin</b>');
        destMarker   = L.marker(dest,   { icon: destIcon  }).addTo(map).bindPopup('<b>' + destName + '</b>');

        map.setView(dest, 13);

        const url = `https://router.project-osrm.org/route/v1/driving/${origin[1]},${origin[0]};${dest[1]},${dest[0]}?overview=full&geometries=polyline`;

        fetch(url)
            .then(res => res.json())
            .then(data => {
                if (!data.routes || data.routes.length === 0) return;
                const route = data.routes[0];
                const coords = decodePolyline(route.geometry);

                routePolyline = L.polyline(coords, {
                    color: '#ea580c', weight: 6, opacity: 0.9, lineCap: 'round', lineJoin: 'round'
                }).addTo(map);

                updateRouteStats(
                    (route.distance / 1000).toFixed(1) + ' km',
                    Math.round(route.duration / 60) + ' mins'
                );

                map.fitBounds(L.latLngBounds(coords), { padding: [50, 50] });
            })
            .catch(err => console.error("OSRM Routing Error:", err));
    }

    function updateRouteStats(dist, dur) {
        const dEl = document.getElementById('summary-distance');
        const tEl = document.getElementById('summary-duration');
        dEl.innerText = dist; dEl.classList.remove('placeholder');
        tEl.innerText = dur; tEl.classList.remove('placeholder');
    }

    // ============================================================
    // Off-route banner helpers
    // ============================================================
    function showOffRouteBanner(distanceM) {
        const banner = document.getElementById('offRouteBanner');
        const dist   = document.getElementById('offRouteDistance');
        if (!banner || !dist) return;
        dist.innerText = distanceM + ' m';
        banner.classList.add('show');
    }
    function hideOffRouteBanner() {
        const banner = document.getElementById('offRouteBanner');
        if (banner) banner.classList.remove('show');
    }

    // ============================================================
    // AUTO-DELIVER HANDLER (called when assignment is no longer active)
    // ============================================================
    function handleAutoDelivered(assignmentId) {
        const row = document.querySelector(`.assignment-item[data-assignment-id="${assignmentId}"]`);

        // Show toast
        showToast('📍 Driver arrived — delivery marked as delivered');

        // Highlight the row briefly, then remove
        if (row) {
            row.classList.add('auto-delivered');
            setTimeout(() => {
                row.classList.add('removing');
                setTimeout(() => {
                    row.remove();
                    const remaining = document.querySelectorAll('.assignment-item').length;
                    const countEl = document.getElementById('assignmentCount');
                    if (countEl) countEl.innerText = remaining + ' Rows';

                    const body = document.getElementById('assignmentListBody');
                    if (remaining === 0 && !body.querySelector('.empty-list-msg')) {
                        const msg = document.createElement('div');
                        msg.className = 'empty-list-msg';
                        msg.style.cssText = 'padding:1.2rem;text-align:center;color:#94a3b8;font-style:italic;font-size:0.8rem;';
                        msg.innerText = 'No active assignments';
                        body.appendChild(msg);
                    }
                }, 400);
            }, 900);
        }

        // Was this the currently selected one?
        const wasSelected = (activeAssignmentId === parseInt(assignmentId, 10));

        if (wasSelected) {
            // Close chat
            stopChatPolling();
            activeAssignmentId = null;
            clearAttachment();
            document.getElementById('chatContainer').innerHTML = `
                <div class="chat-empty">
                    <i class="fas fa-circle-check" style="color:#16a34a;font-size:2rem;"></i>
                    <div>Delivery completed</div>
                    <div style="font-size:0.7rem;">Driver arrived at destination.</div>
                </div>`;
            setChatStatus('Completed', true);
            document.getElementById('chatInput').disabled = true;
            document.getElementById('chatSendBtn').disabled = true;
            document.getElementById('attachBtn').disabled = true;

            // Stop driver tracking
            stopDriverTracking();

            // Reset overview
            const ids = ['val-dest','val-address','val-driver','val-vehicle','val-assignment-id','val-driver-gps',
                         'summary-driver','summary-dest','summary-distance','summary-duration','summary-speed'];
            ids.forEach(elId => {
                const el = document.getElementById(elId);
                if (!el) return;
                el.innerText = 'No assignment selected';
                el.classList.add('placeholder');
            });
            hideOffRouteBanner();
        }
    }

    // ============================================================
    // DRIVER GPS TRACKING (with speed + off-route + auto-deliver detection)
    // ============================================================
    let driverMarker    = null;
    let driverPollTimer = null;
    let currentDriverId = null;

    let driverRouteLine = null;
    let driverGuideLine = null;

    let driverRequestToken = 0;

    function createDriverIcon(stale = false, offRoute = false) {
        let cls = 'custom-map-icon icon-driver';
        if (offRoute) cls += ' off-route';
        else if (stale) cls += ' stale';

        return L.divIcon({
            className: cls,
            html: '<i class="fas fa-truck-fast"></i>',
            iconSize: [32, 32]
        });
    }

    function setDriverTrackBadge(state, text) {
        const badge = document.getElementById('driverTrackBadge');
        const txt   = document.getElementById('driverTrackText');
        if (!badge || !txt) return;
        badge.classList.remove('stale', 'offline', 'off-route');
        if (state === 'stale')     badge.classList.add('stale');
        if (state === 'offline')   badge.classList.add('offline');
        if (state === 'off-route') badge.classList.add('off-route');
        txt.innerText = text;
    }

    function clearDriverLayer() {
        if (driverMarker)    { map.removeLayer(driverMarker);    driverMarker    = null; }
        if (driverRouteLine) { map.removeLayer(driverRouteLine); driverRouteLine = null; }
        if (driverGuideLine) { map.removeLayer(driverGuideLine); driverGuideLine = null; }
    }

    function drawDriverToDestinationRoute(token) {
        if (!map || !driverMarker || !activeDeliveryLatLng) return;

        if (driverRouteLine) { map.removeLayer(driverRouteLine); driverRouteLine = null; }
        if (driverGuideLine) { map.removeLayer(driverGuideLine); driverGuideLine = null; }

        const driverLatLng = driverMarker.getLatLng();
        const url = `https://router.project-osrm.org/route/v1/driving/${driverLatLng.lng},${driverLatLng.lat};${activeDeliveryLatLng[1]},${activeDeliveryLatLng[0]}?overview=full&geometries=polyline`;

        fetch(url)
            .then(r => r.json())
            .then(data => {
                if (token !== driverRequestToken) return;
                if (!data.routes || data.routes.length === 0) return;

                const coords = decodePolyline(data.routes[0].geometry);

                driverRouteLine = L.polyline(coords, {
                    color: '#2563eb', weight: 5, opacity: 0.9,
                    lineCap: 'round', lineJoin: 'round'
                }).addTo(map);

                driverGuideLine = L.polyline([driverLatLng, activeDeliveryLatLng], {
                    color: '#94a3b8', weight: 2, dashArray: '6,6', opacity: 0.5
                }).addTo(map);
            })
            .catch(err => console.warn('Driver route error:', err));
    }

    function pollAssignmentStatus() {
        if (!activeAssignmentId) return;

        const checkId = activeAssignmentId;

        fetch('check_assignment_status.php?assignment_id=' + encodeURIComponent(checkId))
            .then(r => r.json())
            .then(res => {
                if (!res.success) return;
                if (res.is_active) return;  // still active, nothing to do

                // Assignment is no longer active → auto-delivered
                handleAutoDelivered(checkId);
            })
            .catch(() => { /* silent */ });
    }

    function pollDriverLocation() {
        if (!currentDriverId) return;

        // Also check if the assignment has been auto-delivered
        pollAssignmentStatus();

        const requestedDriverId = currentDriverId;
        const myToken           = driverRequestToken;

        fetch('get_driver_location.php?driver_id=' + encodeURIComponent(requestedDriverId))
            .then(r => r.json())
            .then(data => {
                if (requestedDriverId !== currentDriverId) return;
                if (myToken !== driverRequestToken) return;

                if (!data.success) {
                    setDriverTrackBadge('offline', 'Awaiting GPS…');
                    hideOffRouteBanner();
                    return;
                }

                const stale    = data.seconds_ago > 60;
                const offRoute = !!data.off_route;
                const latlng   = [data.latitude, data.longitude];

                if (driverMarker) {
                    driverMarker.setLatLng(latlng);
                    driverMarker.setIcon(createDriverIcon(stale, offRoute));
                } else {
                    driverMarker = L.marker(latlng, {
                        icon: createDriverIcon(stale, offRoute),
                        zIndexOffset: 500
                    }).addTo(map);
                }

                const secsTxt = data.seconds_ago < 5
                    ? 'just now'
                    : data.seconds_ago + ' s ago';

                const staleTag = stale ? ' <span style="color:#dc2626;">(stale)</span>' : '';

                const speedKmh = (data.speed != null) ? (data.speed * 3.6) : null;
                const speedHtml = speedKmh != null
                    ? '<br>Speed: ' + speedKmh.toFixed(1) + ' km/h'
                    : '<br>Speed: n/a';

                let offRouteHtml = '';
                if (offRoute && data.distance_off_route_m != null) {
                    offRouteHtml = '<br><span style="color:#dc2626;font-weight:700;">⚠ OFF ROUTE · ' +
                                   Math.round(data.distance_off_route_m) + ' m from planned</span>';
                }

                driverMarker.bindPopup(
                    '<b>Driver #' + data.driver_id + '</b>' +
                    '<br>Lat: ' + data.latitude.toFixed(6) +
                    '<br>Lng: ' + data.longitude.toFixed(6) +
                    speedHtml +
                    offRouteHtml +
                    '<br>Updated: ' + secsTxt + staleTag
                );

                // Header badge
                if (offRoute) {
                    const distTxt = data.distance_off_route_m != null
                        ? ' · ' + Math.round(data.distance_off_route_m) + ' m off'
                        : '';
                    setDriverTrackBadge('off-route',
                        'OFF ROUTE' + distTxt + ' · ' + secsTxt);
                } else {
                    setDriverTrackBadge(
                        stale ? 'stale' : 'live',
                        'Live GPS · ' + (speedKmh != null ? speedKmh.toFixed(0) + ' km/h' : '—') + ' · ' + secsTxt
                    );
                }

                // Overview: GPS + speed
                const gpsEl = document.getElementById('val-driver-gps');
                if (gpsEl) {
                    const coordTxt = Number(data.latitude).toFixed(5) + ', ' +
                                     Number(data.longitude).toFixed(5);
                    const spdTxt   = speedKmh != null ? ' · ' + speedKmh.toFixed(1) + ' km/h' : '';
                    gpsEl.innerText = coordTxt + spdTxt;
                    gpsEl.title     = coordTxt + spdTxt + ' (' + secsTxt + ')';
                    gpsEl.classList.remove('placeholder');
                }

                // Speed block
                const speedEl = document.getElementById('summary-speed');
                if (speedEl) {
                    if (speedKmh != null) {
                        speedEl.innerText = speedKmh.toFixed(1) + ' km/h';
                        speedEl.classList.remove('placeholder');
                    } else {
                        speedEl.innerText = '—';
                        speedEl.classList.add('placeholder');
                    }
                }

                // Off-route banner
                if (offRoute && data.distance_off_route_m != null) {
                    showOffRouteBanner(Math.round(data.distance_off_route_m));
                } else {
                    hideOffRouteBanner();
                }

                drawDriverToDestinationRoute(myToken);
            })
            .catch(err => {
                if (requestedDriverId !== currentDriverId) return;
                if (myToken !== driverRequestToken) return;
                console.warn('Driver location error:', err);
                setDriverTrackBadge('offline', 'GPS unavailable');
                hideOffRouteBanner();
            });
    }

    function startDriverTracking(driverId) {
        driverRequestToken++;
        clearDriverLayer();
        hideOffRouteBanner();
        currentDriverId = driverId;

        const gpsEl = document.getElementById('val-driver-gps');
        if (gpsEl) {
            gpsEl.innerText = 'Fetching…';
            gpsEl.classList.add('placeholder');
        }
        const speedEl = document.getElementById('summary-speed');
        if (speedEl) {
            speedEl.innerText = '—';
            speedEl.classList.add('placeholder');
        }

        setDriverTrackBadge('offline', 'Fetching GPS…');

        if (driverPollTimer) clearInterval(driverPollTimer);
        pollDriverLocation();
        driverPollTimer = setInterval(pollDriverLocation, 5000);
    }

    function stopDriverTracking() {
        driverRequestToken++;

        if (driverPollTimer) {
            clearInterval(driverPollTimer);
            driverPollTimer = null;
        }

        clearDriverLayer();
        hideOffRouteBanner();
        currentDriverId = null;

        const gpsEl = document.getElementById('val-driver-gps');
        if (gpsEl) {
            gpsEl.innerText = 'Not tracked';
            gpsEl.classList.add('placeholder');
        }
        const speedEl = document.getElementById('summary-speed');
        if (speedEl) {
            speedEl.innerText = '—';
            speedEl.classList.add('placeholder');
        }
        setDriverTrackBadge('offline', 'No driver tracked');
    }

    // ============================================================
    // CHAT — DB-backed with attachments
    // ============================================================
    let activeAssignmentId = null;
    let lastMessageId      = 0;
    let chatPollTimer      = null;
    let pendingFile        = null;

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
        const k = 1024, sizes = ['B', 'KB', 'MB', 'GB'];
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
            img.src = url;
            img.alt = msg.attachment_name || 'image';
            img.loading = 'lazy';
            img.onclick = () => window.open(url, '_blank');
            wrap.appendChild(img);
        } else if (type.startsWith('video/')) {
            const v = document.createElement('video');
            v.src = url;
            v.controls = true;
            v.preload = 'metadata';
            wrap.appendChild(v);
        } else {
            const a = document.createElement('a');
            a.className = 'file-pill';
            a.href = url;
            a.target = '_blank';
            a.download = msg.attachment_name || '';

            const icon = document.createElement('i');
            icon.className = 'fas ' + guessIconForMime(type);

            const body = document.createElement('div');
            body.className = 'fp-body';

            const name = document.createElement('div');
            name.className = 'fp-name';
            name.innerText = msg.attachment_name || 'file';

            const size = document.createElement('div');
            size.className = 'fp-size';
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
        wrap.className = 'chat-msg ' + (isMine ? 'admin' : 'driver');
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
        meta.innerText = (isMine ? 'You' : 'Driver') + ' · ' + formatTime(msg.created_at);
        wrap.appendChild(meta);

        return wrap;
    }

    function loadChatForAssignment(assignmentId) {
        activeAssignmentId = assignmentId;
        lastMessageId = 0;
        clearAttachment();

        const input   = document.getElementById('chatInput');
        const sendBtn = document.getElementById('chatSendBtn');
        const attach  = document.getElementById('attachBtn');

        if (!assignmentId) {
            input.disabled = true; sendBtn.disabled = true;
            if (attach) attach.disabled = true;
            input.placeholder = 'Select an assignment first...';
            setChatStatus('Idle', false);
            return;
        }

        input.disabled = false; sendBtn.disabled = false;
        if (attach) attach.disabled = false;
        input.placeholder = 'Type a message to the driver...';
        setChatStatus('Loading...', false);

        const container = document.getElementById('chatContainer');
        container.innerHTML = '<div class="chat-empty"><i class="fas fa-spinner fa-spin"></i><div>Loading conversation...</div></div>';

        fetch(`get_messages.php?assignment_id=${assignmentId}&viewer_role=${VIEWER_ROLE}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.error || 'Failed');
                container.innerHTML = '';
                if (!data.messages || data.messages.length === 0) {
                    container.innerHTML = `
                        <div class="chat-empty">
                            <i class="fas fa-comment-dots"></i>
                            <div>No messages yet</div>
                            <div style="font-size:0.7rem;">Send the first message to start the conversation.</div>
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
                container.innerHTML = `<div class="chat-empty"><i class="fas fa-exclamation-triangle" style="color:#dc2626;"></i><div>Failed to load chat</div></div>`;
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
        fetch(`get_messages.php?assignment_id=${activeAssignmentId}&after_id=${lastMessageId}&viewer_role=${VIEWER_ROLE}`)
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

        const input   = document.getElementById('chatInput');
        const sendBtn = document.getElementById('chatSendBtn');
        const attach  = document.getElementById('attachBtn');
        const text    = input.value.trim();

        if (!text && !pendingFile) return;

        input.disabled = true; sendBtn.disabled = true;
        if (attach) attach.disabled = true;

        const fd = new FormData();
        fd.append('assignment_id', activeAssignmentId);
        fd.append('message', text);
        fd.append('sender_role', VIEWER_ROLE);
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
                    message_type: data.message_type,
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
            .catch(err => {
                console.error('Send error:', err);
                alert('Failed to send: ' + err.message);
            })
            .finally(() => {
                input.disabled = false; sendBtn.disabled = false;
                if (attach) attach.disabled = false;
                input.focus();
            });
    }

    function handleChatKeyPress(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            sendChatMessage();
        }
    }

    // ============================================================
    // Vehicle modal
    // ============================================================
    function openVehicleModal()  { document.getElementById('vehicleModalWrapper').classList.add('active'); }
    function closeVehicleModal() { document.getElementById('vehicleModalWrapper').classList.remove('active'); }

    window.addEventListener('click', function(e) {
        const modal = document.getElementById('vehicleModalWrapper');
        if (e.target === modal) closeVehicleModal();
    });

    window.addEventListener('beforeunload', function () {
        stopChatPolling();
        stopDriverTracking();
    });

    document.addEventListener('DOMContentLoaded', function () {
        initMap();

        const first = document.querySelector('.assignment-item');
        if (first) {
            selectAssignment(first);
        }
    });
</script>

</body>
</html>
