<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/connect_db.php';

$history = [];
$dbError = null;
$stats = ['total' => 0, 'today' => 0, 'week' => 0, 'month' => 0];

try {
    // 1. Fetch all delivered assignments
    $sql = "
        SELECT id, driver_id, vehicle_id, destination, notes, status, assigned_at, unassigned_at
        FROM driver_assignments
        WHERE status = 'delivered'
        ORDER BY unassigned_at DESC, id DESC
    ";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) { $history[] = $row; }
    $result->free();

    // 2. Lookups
    $drivers = [];
    $r = $conn->query("SELECT id, name, username FROM acct");
    while ($d = $r->fetch_assoc()) { $drivers[(int)$d['id']] = $d; }
    $r->free();

    $vehicles = [];
    $r = $conn->query("SELECT id, plate_number, model, type FROM vehicles");
    while ($v = $r->fetch_assoc()) { $vehicles[(int)$v['id']] = $v; }
    $r->free();

    $deliveryLocations = [];
    $r = $conn->query("SELECT id, code, name, address FROM deliveries");
    while ($loc = $r->fetch_assoc()) {
        $deliveryLocations[strtolower(trim($loc['name']))] = $loc;
    }
    $r->free();

    // 3. Enrich
    $now = new DateTime();
    $todayStart  = (new DateTime())->setTime(0, 0, 0);
    $weekStart   = (clone $now)->modify('-7 days');
    $monthStart  = (clone $now)->modify('-30 days');

    foreach ($history as &$h) {
        $dId = (int)$h['driver_id'];
        $h['driver_name'] = $drivers[$dId]['name'] ?? null;
        $h['driver_username'] = $drivers[$dId]['username'] ?? null;

        $vId = (int)$h['vehicle_id'];
        $h['vehicle_plate'] = $vehicles[$vId]['plate_number'] ?? null;
        $h['vehicle_model'] = $vehicles[$vId]['model'] ?? null;
        $h['vehicle_type']  = $vehicles[$vId]['type'] ?? null;

        $locKey = strtolower(trim($h['destination']));
        if (isset($deliveryLocations[$locKey])) {
            $h['code']    = $deliveryLocations[$locKey]['code'];
            $h['address'] = $deliveryLocations[$locKey]['address'];
        } else {
            $h['code']    = strtoupper(substr($h['destination'], 0, 3));
            $h['address'] = '';
        }

        // Message count for the conversation badge
        $h['message_count'] = 0;
        try {
            $mc = $conn->prepare("
                SELECT COUNT(*) AS c
                FROM chat_messages cm
                INNER JOIN chat_conversations cc ON cc.id = cm.conversation_id
                WHERE cc.assignment_id = ?
            ");
            $mc->bind_param('i', $h['id']);
            $mc->execute();
            $h['message_count'] = (int)$mc->get_result()->fetch_assoc()['c'];
            $mc->close();
        } catch (Throwable $e) { /* silent */ }

        // Stats
        if (!empty($h['unassigned_at'])) {
            $deliveredAt = new DateTime($h['unassigned_at']);
            if ($deliveredAt >= $todayStart) $stats['today']++;
            if ($deliveredAt >= $weekStart)  $stats['week']++;
            if ($deliveredAt >= $monthStart) $stats['month']++;
        }
    }
    unset($h);

    $stats['total'] = count($history);

} catch (Throwable $e) {
    $dbError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Delivery History &middot; UAM Logistics</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

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
            --success-100:#dcfce7;
            --success-500:#22c55e;
            --success-600:#16a34a;
            --success-700:#15803d;
            --danger-50:  #fef2f2;
            --danger-100: #fee2e2;
            --danger-600: #dc2626;
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.06), 0 1px 2px rgba(15, 23, 42, 0.04);
            --shadow-md: 0 4px 12px rgba(15, 23, 42, 0.08);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; }
        html, body { height: 100%; }
        body { background: var(--slate-50); color: var(--slate-900); padding: 1.25rem 1.5rem; overflow-y: auto; }

        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--slate-300); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--slate-400); }

        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.75rem;
        }
        .page-header .page-title {
            font-size: 1.3rem; font-weight: 800; color: var(--slate-900);
            letter-spacing: -0.02em; display: flex; align-items: center; gap: 0.6rem;
        }
        .page-header .page-title i { color: var(--primary-600); }
        .page-header .page-title .sub {
            font-size: 0.75rem; font-weight: 500; color: var(--slate-500); margin-left: 0.5rem;
        }

        .btn-refresh {
            background: var(--white); color: var(--slate-700);
            border: 1px solid var(--slate-200); padding: 0.5rem 0.9rem;
            border-radius: var(--radius-sm); font-size: 0.78rem; font-weight: 700;
            cursor: pointer; display: flex; align-items: center; gap: 0.4rem;
            transition: all 0.18s ease;
        }
        .btn-refresh:hover {
            background: var(--slate-50); border-color: var(--slate-300); color: var(--primary-700);
        }

        .error-banner {
            background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;
            border-radius: 8px; padding: 10px 14px; font-size: 0.8rem;
            margin-bottom: 1rem; font-family: monospace;
        }

        .stats-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 0.75rem; margin-bottom: 1.25rem;
        }
        .stat-card {
            background: var(--white); border: 1px solid var(--slate-200);
            border-radius: var(--radius-md); padding: 0.9rem 1.05rem;
            display: flex; align-items: center; gap: 0.85rem;
            box-shadow: var(--shadow-sm); transition: all 0.18s ease;
        }
        .stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
        .stat-icon {
            width: 42px; height: 42px; border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.15rem; flex-shrink: 0;
        }
        .stat-icon.orange { background: var(--primary-50); color: var(--primary-600); }
        .stat-icon.green  { background: var(--success-50); color: var(--success-600); }
        .stat-icon.blue   { background: #eff6ff; color: #2563eb; }
        .stat-icon.purple { background: #f5f3ff; color: #7c3aed; }

        .stat-body { flex: 1; min-width: 0; }
        .stat-body .stat-label {
            font-size: 0.68rem; font-weight: 700; color: var(--slate-500);
            text-transform: uppercase; letter-spacing: 0.04em;
            display: block; margin-bottom: 2px;
        }
        .stat-body .stat-value {
            font-size: 1.35rem; font-weight: 800; color: var(--slate-900);
            letter-spacing: -0.02em;
        }

        .filters-bar {
            background: var(--white); border: 1px solid var(--slate-200);
            border-radius: var(--radius-md); padding: 0.75rem 1rem;
            margin-bottom: 1rem; display: flex; flex-wrap: wrap;
            gap: 0.65rem; align-items: center; box-shadow: var(--shadow-sm);
        }
        .search-box {
            flex: 1 1 260px; display: flex; align-items: center; gap: 0.55rem;
            background: var(--slate-50); border: 1px solid var(--slate-200);
            border-radius: var(--radius-sm); padding: 0.5rem 0.75rem;
            transition: border-color 0.18s ease;
        }
        .search-box:focus-within { border-color: var(--primary-500); background: var(--white); }
        .search-box i { color: var(--slate-400); font-size: 0.82rem; }
        .search-box input {
            flex: 1; border: none; background: transparent; outline: none;
            font-size: 0.82rem; color: var(--slate-900); min-width: 0;
        }
        .search-box input::placeholder { color: var(--slate-400); }

        .filter-select {
            background: var(--slate-50); border: 1px solid var(--slate-200);
            border-radius: var(--radius-sm); padding: 0.55rem 0.75rem;
            font-size: 0.78rem; font-weight: 600; color: var(--slate-700);
            outline: none; cursor: pointer; transition: border-color 0.18s ease;
        }
        .filter-select:focus { border-color: var(--primary-500); background: var(--white); }

        .btn-clear {
            background: transparent; border: 1px solid var(--slate-200);
            color: var(--slate-500); padding: 0.55rem 0.85rem;
            border-radius: var(--radius-sm); font-size: 0.75rem; font-weight: 700;
            cursor: pointer; display: flex; align-items: center; gap: 0.35rem;
            transition: all 0.18s ease;
        }
        .btn-clear:hover { background: var(--slate-100); color: var(--slate-700); }

        .history-card {
            background: var(--white); border: 1px solid var(--slate-200);
            border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden;
        }

        .table-wrapper { overflow-x: auto; overflow-y: visible; }

        table {
            width: 100%; border-collapse: collapse; font-size: 0.82rem; min-width: 950px;
        }
        thead th {
            background: var(--slate-50); color: var(--slate-600);
            text-align: left; padding: 0.75rem 1rem;
            font-size: 0.68rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.05em; border-bottom: 1px solid var(--slate-200);
            white-space: nowrap; position: sticky; top: 0; z-index: 5;
        }
        tbody tr {
            border-bottom: 1px solid var(--slate-100);
            transition: background 0.15s ease;
            cursor: pointer;   /* ← new: entire row is clickable */
        }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: var(--primary-50); }

        tbody td {
            padding: 0.85rem 1rem; vertical-align: middle;
            color: var(--slate-700); white-space: nowrap;
        }
        tbody td.wrap { white-space: normal; max-width: 260px; }

        .col-id { font-family: monospace; font-weight: 700; color: var(--slate-500); }
        .col-destination { font-weight: 700; color: var(--slate-900); }
        .col-address { color: var(--slate-500); font-size: 0.78rem; }

        .driver-cell { display: flex; align-items: center; gap: 0.5rem; }
        .driver-avatar {
            width: 28px; height: 28px; border-radius: 50%;
            background: linear-gradient(135deg, var(--success-500), var(--success-600));
            color: white; display: flex; align-items: center; justify-content: center;
            font-size: 0.7rem; font-weight: 800; flex-shrink: 0;
        }
        .driver-name { font-weight: 600; color: var(--slate-800); }
        .driver-id { font-size: 0.7rem; color: var(--slate-400); }

        .vehicle-cell { font-weight: 600; color: var(--slate-700); }
        .vehicle-model { font-size: 0.72rem; color: var(--slate-400); }

        .badge-delivered {
            display: inline-flex; align-items: center; gap: 0.35rem;
            background: var(--success-100); color: var(--success-700);
            padding: 0.25rem 0.65rem; border-radius: 2rem;
            font-size: 0.68rem; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.03em;
        }
        .badge-delivered i { font-size: 0.6rem; }

        .badge-messages {
            display: inline-flex; align-items: center; gap: 0.35rem;
            background: var(--primary-100); color: var(--primary-700);
            padding: 0.25rem 0.65rem; border-radius: 2rem;
            font-size: 0.68rem; font-weight: 800;
            margin-left: 0.35rem;
        }
        .badge-messages.no-msg {
            background: var(--slate-100);
            color: var(--slate-400);
        }

        .date-cell { font-size: 0.78rem; color: var(--slate-600); line-height: 1.35; }
        .date-cell .date-main { font-weight: 700; color: var(--slate-800); display: block; }
        .date-cell .date-time { font-size: 0.72rem; color: var(--slate-400); }

        .empty-state { padding: 3rem 2rem; text-align: center; color: var(--slate-400); }
        .empty-state i { font-size: 2.5rem; color: var(--slate-300); margin-bottom: 0.85rem; }
        .empty-state h3 { font-size: 1rem; font-weight: 700; color: var(--slate-600); margin-bottom: 0.35rem; }
        .empty-state p { font-size: 0.82rem; color: var(--slate-400); }

        .table-footer {
            padding: 0.85rem 1.15rem; border-top: 1px solid var(--slate-100);
            display: flex; justify-content: space-between; align-items: center;
            font-size: 0.75rem; color: var(--slate-500);
            background: var(--white); flex-wrap: wrap; gap: 0.5rem;
        }
        .table-footer strong { color: var(--slate-700); }

        /* ==================== CONVERSATION MODAL ==================== */
        .conv-overlay {
            position: fixed; inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            padding: 1rem;
            animation: convOverlayIn 0.2s ease;
        }
        .conv-overlay.active { display: flex; }
        @keyframes convOverlayIn { from { opacity: 0; } to { opacity: 1; } }

        .conv-modal {
            background: var(--white);
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 720px;
            height: 85vh;
            max-height: 720px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
            animation: convModalIn 0.28s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        @keyframes convModalIn {
            from { opacity: 0; transform: scale(0.92) translateY(20px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }

        .conv-header {
            background: linear-gradient(135deg, var(--primary-500), var(--primary-600));
            color: var(--white);
            padding: 1.1rem 1.35rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-shrink: 0;
            gap: 1rem;
        }
        .conv-header-info { min-width: 0; flex: 1; }
        .conv-header-title {
            font-size: 1.05rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.25rem;
        }
        .conv-header-title i { font-size: 0.9rem; opacity: 0.9; }
        .conv-header-meta {
            font-size: 0.75rem;
            opacity: 0.9;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .conv-header-meta span {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }
        .conv-close {
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.3);
            color: var(--white);
            width: 32px; height: 32px;
            border-radius: 50%;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
            transition: all 0.18s ease;
        }
        .conv-close:hover { background: rgba(255,255,255,0.3); transform: rotate(90deg); }

        .conv-subheader {
            background: var(--slate-50);
            border-bottom: 1px solid var(--slate-200);
            padding: 0.7rem 1.35rem;
            display: flex;
            gap: 1.25rem;
            font-size: 0.72rem;
            flex-wrap: wrap;
            flex-shrink: 0;
        }
        .conv-subheader .item {
            display: flex; align-items: center; gap: 0.4rem;
            color: var(--slate-500);
        }
        .conv-subheader .item strong {
            color: var(--slate-700);
            font-weight: 700;
        }

        .conv-body {
            flex: 1;
            overflow-y: auto;
            padding: 1.25rem 1.35rem;
            background: var(--slate-50);
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            min-height: 0;
        }

        .conv-loading {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--slate-400);
            gap: 0.75rem;
            font-size: 0.82rem;
        }
        .conv-loading i { font-size: 1.8rem; color: var(--slate-300); }

        .conv-empty {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--slate-400);
            gap: 0.5rem;
            text-align: center;
            padding: 2rem;
        }
        .conv-empty i { font-size: 2rem; color: var(--slate-300); margin-bottom: 0.35rem; }
        .conv-empty h3 { font-size: 0.95rem; font-weight: 700; color: var(--slate-600); }
        .conv-empty p { font-size: 0.78rem; }

        .conv-msg {
            max-width: 80%;
            padding: 0.65rem 0.9rem;
            border-radius: var(--radius-md);
            font-size: 0.82rem;
            line-height: 1.45;
            box-shadow: var(--shadow-sm);
            word-wrap: break-word;
            overflow-wrap: anywhere;
        }
        .conv-msg.admin {
            align-self: flex-end;
            background: linear-gradient(135deg, var(--primary-500), var(--primary-600));
            color: var(--white);
            border-bottom-right-radius: 3px;
        }
        .conv-msg.driver {
            align-self: flex-start;
            background: var(--white);
            border: 1px solid var(--slate-200);
            color: var(--slate-800);
            border-bottom-left-radius: 3px;
        }
        .conv-msg .conv-meta {
            display: block;
            font-size: 0.65rem;
            opacity: 0.75;
            margin-top: 0.3rem;
        }
        .conv-msg .conv-attachment {
            display: block;
            margin-top: 0.5rem;
            max-width: 100%;
        }
        .conv-msg .conv-attachment img,
        .conv-msg .conv-attachment video {
            display: block;
            width: 100%;
            max-width: 260px;
            max-height: 320px;
            object-fit: cover;
            border-radius: var(--radius-sm);
            background: #000;
            cursor: pointer;
        }
        .conv-msg .conv-attachment .file-pill {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            background: rgba(0,0,0,0.06);
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: var(--radius-sm);
            padding: 0.55rem 0.7rem;
            text-decoration: none;
            color: inherit;
            font-size: 0.76rem;
            max-width: 260px;
        }
        .conv-msg.admin .conv-attachment .file-pill {
            background: rgba(255,255,255,0.15);
            border-color: rgba(255,255,255,0.25);
        }
        .conv-msg .conv-attachment .file-pill i {
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .conv-msg .conv-attachment .file-pill .fp-body { min-width: 0; flex: 1; }
        .conv-msg .conv-attachment .file-pill .fp-name {
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .conv-msg .conv-attachment .file-pill .fp-size {
            font-size: 0.68rem;
            opacity: 0.75;
        }

        @media (max-width: 768px) {
            body { padding: 1rem; }
            .page-header .page-title { font-size: 1.1rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filters-bar { padding: 0.65rem 0.75rem; }
            .search-box { flex: 1 1 100%; }
            .conv-modal { height: 92vh; max-height: none; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
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
    <div class="page-title">
        <i class="fas fa-clock-rotate-left"></i>
        Delivery History
        <span class="sub">(All completed deliveries)</span>
    </div>
    <button class="btn-refresh" onclick="window.location.reload()">
        <i class="fas fa-rotate"></i> Refresh
    </button>
</div>

<!-- STATS -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fas fa-box-check"></i></div>
        <div class="stat-body">
            <span class="stat-label">Total Delivered</span>
            <span class="stat-value"><?= number_format($stats['total']) ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-calendar-day"></i></div>
        <div class="stat-body">
            <span class="stat-label">Today</span>
            <span class="stat-value"><?= number_format($stats['today']) ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-calendar-week"></i></div>
        <div class="stat-body">
            <span class="stat-label">Last 7 Days</span>
            <span class="stat-value"><?= number_format($stats['week']) ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-calendar-alt"></i></div>
        <div class="stat-body">
            <span class="stat-label">Last 30 Days</span>
            <span class="stat-value"><?= number_format($stats['month']) ?></span>
        </div>
    </div>
</div>

<!-- FILTERS -->
<div class="filters-bar">
    <div class="search-box">
        <i class="fas fa-magnifying-glass"></i>
        <input type="text" id="searchInput" placeholder="Search by destination, driver, vehicle, or address..." oninput="applyFilters()">
    </div>
    <select class="filter-select" id="filterDate" onchange="applyFilters()">
        <option value="all">All time</option>
        <option value="today">Today</option>
        <option value="week">Last 7 days</option>
        <option value="month">Last 30 days</option>
    </select>
    <button class="btn-clear" onclick="clearFilters()">
        <i class="fas fa-times"></i> Clear
    </button>
</div>

<!-- TABLE -->
<div class="history-card">
    <?php if (empty($history)): ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <h3>No delivery history yet</h3>
            <p>Completed deliveries will appear here.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table id="historyTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Destination</th>
                        <th>Address</th>
                        <th>Driver</th>
                        <th>Vehicle</th>
                        <th>Status</th>
                        <th>Assigned</th>
                        <th>Delivered</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $h): ?>
                        <?php
                            $driverName = $h['driver_name'] ?? '—';
                            $initials = '';
                            if ($driverName && $driverName !== '—') {
                                $parts = preg_split('/\s+/', trim($driverName));
                                foreach ($parts as $p) {
                                    if ($p !== '') $initials .= strtoupper($p[0]);
                                    if (strlen($initials) >= 2) break;
                                }
                            }
                            $assignedTs  = !empty($h['assigned_at'])   ? strtotime($h['assigned_at'])   : null;
                            $deliveredTs = !empty($h['unassigned_at']) ? strtotime($h['unassigned_at']) : null;

                            $searchBlob = strtolower(
                                ($h['destination'] ?? '') . ' ' .
                                ($h['address'] ?? '') . ' ' .
                                ($driverName) . ' ' .
                                ($h['vehicle_plate'] ?? '') . ' ' .
                                ($h['vehicle_model'] ?? '')
                            );
                            $deliveredDate = $deliveredTs ? date('Y-m-d', $deliveredTs) : '';
                            $msgCount = (int)($h['message_count'] ?? 0);
                        ?>
                        <tr
                            data-assignment-id="<?= (int)$h['id'] ?>"
                            data-destination="<?= htmlspecialchars($h['destination']) ?>"
                            data-driver-name="<?= htmlspecialchars($driverName) ?>"
                            data-vehicle="<?= htmlspecialchars(($h['vehicle_plate'] ?? '') . ' ' . ($h['vehicle_model'] ?? '')) ?>"
                            data-search="<?= htmlspecialchars($searchBlob) ?>"
                            data-delivered="<?= $deliveredDate ?>"
                            data-delivered-ts="<?= $deliveredTs ?: 0 ?>"
                            onclick="openConversation(this)"
                        >
                            <td class="col-id">#<?= htmlspecialchars($h['id']) ?></td>

                            <td class="col-destination">
                                <?= htmlspecialchars($h['destination']) ?>
                            </td>

                            <td class="col-address wrap" title="<?= htmlspecialchars($h['address'] ?? '') ?>">
                                <?= htmlspecialchars($h['address'] ?: '—') ?>
                            </td>

                            <td>
                                <?php if ($h['driver_name']): ?>
                                    <div class="driver-cell">
                                        <div class="driver-avatar"><?= htmlspecialchars($initials ?: '?') ?></div>
                                        <div>
                                            <div class="driver-name"><?= htmlspecialchars($h['driver_name']) ?></div>
                                            <div class="driver-id">ID: <?= htmlspecialchars($h['driver_id']) ?></div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-style:italic;">Unassigned</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($h['vehicle_plate'] || $h['vehicle_model']): ?>
                                    <div class="vehicle-cell"><?= htmlspecialchars($h['vehicle_plate'] ?: '—') ?></div>
                                    <div class="vehicle-model"><?= htmlspecialchars($h['vehicle_model'] ?: '') ?></div>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-style:italic;">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge-delivered">
                                    <i class="fas fa-circle-check"></i> Delivered
                                </span>
                                <?php if ($msgCount > 0): ?>
                                    <span class="badge-messages">
                                        <i class="fas fa-comments"></i> <?= $msgCount ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge-messages no-msg">
                                        <i class="fas fa-comment-slash"></i> 0
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="date-cell">
                                    <?php if ($assignedTs): ?>
                                        <span class="date-main"><?= date('M j, Y', $assignedTs) ?></span>
                                        <span class="date-time"><?= date('g:i A', $assignedTs) ?></span>
                                    <?php else: ?>
                                        <span class="date-time">—</span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td>
                                <div class="date-cell">
                                    <?php if ($deliveredTs): ?>
                                        <span class="date-main"><?= date('M j, Y', $deliveredTs) ?></span>
                                        <span class="date-time"><?= date('g:i A', $deliveredTs) ?></span>
                                    <?php else: ?>
                                        <span class="date-time">—</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer">
            <span>Showing <strong id="visibleCount"><?= count($history) ?></strong> of <strong><?= count($history) ?></strong> deliveries</span>
            <span><i class="fas fa-info-circle"></i> Click a row to see the chat conversation</span>
        </div>
    <?php endif; ?>
</div>

<!-- ==================== CONVERSATION MODAL ==================== -->
<div class="conv-overlay" id="convOverlay">
    <div class="conv-modal" role="dialog" aria-modal="true">
        <div class="conv-header">
            <div class="conv-header-info">
                <div class="conv-header-title">
                    <i class="fas fa-comments"></i>
                    <span id="convTitle">Conversation</span>
                </div>
                <div class="conv-header-meta">
                    <span><i class="fas fa-user"></i> <span id="convDriver">—</span></span>
                    <span><i class="fas fa-truck"></i> <span id="convVehicle">—</span></span>
                </div>
            </div>
            <button class="conv-close" onclick="closeConversation()" title="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="conv-subheader">
            <div class="item"><i class="fas fa-hashtag"></i> Assignment: <strong id="convId">—</strong></div>
            <div class="item"><i class="fas fa-message"></i> Messages: <strong id="convCount">0</strong></div>
        </div>

        <div class="conv-body" id="convBody">
            <!-- Filled by JS -->
        </div>
    </div>
</div>

<script>
    // ============================================================
    // Filters
    // ============================================================
    function applyFilters() {
        const query = document.getElementById('searchInput').value.toLowerCase().trim();
        const dateF = document.getElementById('filterDate').value;
        const rows  = document.querySelectorAll('#historyTable tbody tr');

        const now = new Date();
        const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
        const startOfWeek  = startOfToday - 7 * 24 * 60 * 60 * 1000;
        const startOfMonth = startOfToday - 30 * 24 * 60 * 60 * 1000;

        let visible = 0;

        rows.forEach(row => {
            const blob = row.dataset.search || '';
            const dTs  = parseInt(row.dataset.deliveredTs || '0', 10);

            let matchText = true;
            let matchDate = true;

            if (query !== '') matchText = blob.includes(query);

            if (dateF === 'today')      matchDate = dTs >= startOfToday;
            else if (dateF === 'week')  matchDate = dTs >= startOfWeek;
            else if (dateF === 'month') matchDate = dTs >= startOfMonth;

            if (matchText && matchDate) {
                row.style.display = '';
                visible++;
            } else {
                row.style.display = 'none';
            }
        });

        document.getElementById('visibleCount').innerText = visible;
    }

    function clearFilters() {
        document.getElementById('searchInput').value = '';
        document.getElementById('filterDate').value = 'all';
        applyFilters();
    }

    // ============================================================
    // Conversation modal
    // ============================================================
    let convOpen = false;

    function formatConvTime(iso) {
        if (!iso) return '';
        // "2026-09-21 17:05:42"  →  "Sep 21 · 5:05 PM"
        const parts = iso.split(' ');
        if (parts.length < 2) return iso;
        const [datePart, timePart] = parts;
        const [y, mo, d] = datePart.split('-');
        const [h, m] = timePart.split(':');
        let hour = parseInt(h, 10);
        const ampm = hour >= 12 ? 'PM' : 'AM';
        hour = hour % 12 || 12;
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        const monthName = months[parseInt(mo, 10) - 1] || mo;
        return `${monthName} ${parseInt(d, 10)} · ${hour}:${m} ${ampm}`;
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

    function buildConvAttachment(msg) {
        if (!msg.attachment_path) return null;
        const wrap = document.createElement('div');
        wrap.className = 'conv-attachment';
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
            v.src = url; v.controls = true; v.preload = 'metadata';
            wrap.appendChild(v);
        } else {
            const a = document.createElement('a');
            a.className = 'file-pill';
            a.href = url;
            a.target = '_blank';
            a.download = msg.attachment_name || '';

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

    function renderConvMessage(msg) {
        const wrap = document.createElement('div');
        const isAdmin = (msg.sender_role === 'admin');
        wrap.className = 'conv-msg ' + (isAdmin ? 'admin' : 'driver');

        if (msg.message && msg.message.trim() !== '') {
            const t = document.createElement('div');
            t.innerText = msg.message;
            wrap.appendChild(t);
        }

        const a = buildConvAttachment(msg);
        if (a) wrap.appendChild(a);

        const meta = document.createElement('span');
        meta.className = 'conv-meta';
        meta.innerText = (isAdmin ? 'Admin' : 'Driver') + ' · ' + formatConvTime(msg.created_at);
        wrap.appendChild(meta);

        return wrap;
    }

    function openConversation(row) {
        const assignmentId = parseInt(row.dataset.assignmentId, 10);
        const destination  = row.dataset.destination || '—';
        const driverName   = row.dataset.driverName  || '—';
        const vehicle      = row.dataset.vehicle     || '—';

        // Fill header
        document.getElementById('convTitle').innerText   = destination;
        document.getElementById('convDriver').innerText  = driverName;
        document.getElementById('convVehicle').innerText = vehicle || '—';
        document.getElementById('convId').innerText      = '#' + assignmentId;
        document.getElementById('convCount').innerText   = '…';

        const body = document.getElementById('convBody');
        body.innerHTML = `
            <div class="conv-loading">
                <i class="fas fa-spinner fa-spin"></i>
                <div>Loading conversation…</div>
            </div>`;

        document.getElementById('convOverlay').classList.add('active');
        convOpen = true;

        fetch('get_conversation.php?assignment_id=' + encodeURIComponent(assignmentId))
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.error || 'Failed to load');

                document.getElementById('convCount').innerText = data.messages ? data.messages.length : 0;

                body.innerHTML = '';
                if (!data.messages || data.messages.length === 0) {
                    body.innerHTML = `
                        <div class="conv-empty">
                            <i class="fas fa-comment-slash"></i>
                            <h3>No conversation was recorded</h3>
                            <p>This assignment has no chat history.</p>
                        </div>`;
                    return;
                }

                data.messages.forEach(m => body.appendChild(renderConvMessage(m)));
                body.scrollTop = body.scrollHeight;
            })
            .catch(err => {
                console.error('Conversation load error:', err);
                body.innerHTML = `
                    <div class="conv-empty">
                        <i class="fas fa-exclamation-triangle" style="color:#dc2626;"></i>
                        <h3>Failed to load conversation</h3>
                        <p>${err.message}</p>
                    </div>`;
            });
    }

    function closeConversation() {
        document.getElementById('convOverlay').classList.remove('active');
        convOpen = false;
        document.getElementById('convBody').innerHTML = '';
    }

    // Click backdrop to close
    document.getElementById('convOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeConversation();
    });

    // Esc key closes
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && convOpen) closeConversation();
    });
</script>

</body>
</html>
