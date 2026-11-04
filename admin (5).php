<?php
// Enable temporary error reporting to diagnose server issues
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Safe session fallbacks
$userName = $_SESSION['name'] ?? 'Admin User';
$userRole = ucfirst($_SESSION['acctlevel'] ?? 'Administrator');

// Handle Logout
if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard &middot; UAM Logistics</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

    <style>
        :root {
            --primary-50:  #fff7ed;
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
            --slate-800:  #1e293b;
            --slate-900:  #0f172a;

            --white:      #ffffff;
            --danger-50:  #fef2f2;
            --danger-100: #fee2e2;
            --danger-600: #dc2626;

            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; }
        html, body { height: 100%; }
        body { background: var(--slate-100); color: var(--slate-900); display: flex; overflow: hidden; }

        /* SIDEBAR */
        .side-panel {
            width: 260px;
            background: var(--white);
            border-right: 1px solid var(--slate-200);
            padding: 1.5rem 1rem;
            height: 100vh;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            z-index: 20;
        }

        .side-panel .brand {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 0 0.5rem 1.5rem;
            border-bottom: 1px solid var(--slate-100);
            margin-bottom: 1.25rem;
        }

        .side-panel .brand-icon {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, var(--primary-500), var(--primary-600));
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 1.2rem;
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.25);
        }

        .side-panel .brand h1 { font-size: 1.25rem; font-weight: 800; color: var(--slate-900); letter-spacing: -0.03em; }
        .side-panel .brand span { color: var(--primary-600); font-weight: 600; font-size: 0.68rem; display: block; letter-spacing: 0.08em; text-transform: uppercase; }

        .side-panel .admin-info {
            padding: 0.75rem;
            background: var(--slate-50);
            border-radius: var(--radius-md);
            border: 1px solid var(--slate-200);
            margin-bottom: 1.25rem;
        }

        .side-panel .admin-info .user { display: flex; align-items: center; gap: 0.75rem; }
        .side-panel .admin-info .user i { font-size: 2rem; color: var(--slate-400); }
        .side-panel .admin-info .user .name { font-weight: 700; color: var(--slate-900); font-size: 0.88rem; line-height: 1.2; }
        .side-panel .admin-info .user .role { font-size: 0.72rem; color: var(--primary-600); font-weight: 600; margin-top: 2px; }

        .side-panel .nav-menu { list-style: none; flex: 1; }
        .side-panel .nav-menu li { margin-bottom: 0.35rem; }
        .side-panel .nav-menu li a {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-sm);
            color: var(--slate-600);
            text-decoration: none;
            transition: all 0.2s ease;
            font-weight: 600;
            font-size: 0.88rem;
            cursor: pointer;
        }
        .side-panel .nav-menu li a i { width: 20px; text-align: center; font-size: 1rem; color: var(--slate-400); transition: all 0.2s; }
        .side-panel .nav-menu li a:hover { background: var(--primary-50); color: var(--primary-700); }
        .side-panel .nav-menu li a:hover i { color: var(--primary-600); }
        .side-panel .nav-menu li a.active {
            background: linear-gradient(135deg, var(--primary-500), var(--primary-600));
            color: var(--white);
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.2);
        }
        .side-panel .nav-menu li a.active i { color: var(--white); }

        .side-panel .logout-section { padding-top: 1rem; border-top: 1px solid var(--slate-100); }
        .side-panel .logout-section button {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-sm);
            color: var(--danger-600);
            background: var(--danger-50);
            border: 1px solid var(--danger-100);
            transition: all 0.2s;
            font-weight: 700;
            font-size: 0.85rem;
            cursor: pointer;
        }
        .side-panel .logout-section button:hover { background: #fde8e8; border-color: #fca5a5; }

        /* MAIN CONTENT IFRAME WRAPPER */
        .main-frame-wrapper {
            flex: 1;
            height: 100vh;
            background: var(--slate-50);
            overflow: hidden;
        }

        .dashboard-iframe {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }

        /* MODAL STYLES FOR DRIVER ASSIGNMENT POPUP */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .modal-overlay.active { display: flex; }

        .modal-card-lg {
            background: var(--white);
            width: 90%;
            max-width: 1100px;
            height: 85vh;
            border-radius: var(--radius-lg);
            padding: 1.25rem 1.5rem;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--slate-200);
        }
        .modal-header h3 { font-size: 1.15rem; font-weight: 800; display: flex; align-items: center; gap: 0.6rem; color: var(--slate-900); }
        .modal-header h3 i { color: var(--primary-600); }
        .modal-close { background: none; border: none; font-size: 1.25rem; cursor: pointer; color: var(--slate-400); transition: color 0.2s; }
        .modal-close:hover { color: var(--danger-600); }

        .modal-iframe-container {
            width: 100%;
            height: 100%;
            flex: 1;
            border: none;
            border-radius: var(--radius-sm);
            background: var(--slate-50);
        }
    </style>
</head>
<body>

<div class="side-panel">
    <div class="brand">
        <div class="brand-icon">
            <i class="fas fa-truck-fast"></i>
        </div>
        <div>
            <h1>UAM</h1>
            <span>Logistics Platform</span>
        </div>
    </div>
    <div class="admin-info">
        <div class="user">
            <i class="fas fa-user-circle"></i>
            <div>
                <div class="name"><?= htmlspecialchars($userName) ?></div>
                <div class="role"><?= htmlspecialchars($userRole) ?></div>
            </div>
        </div>
    </div>
    <ul class="nav-menu">
        <li>
            <a href="admindashboard.php" target="contentFrame" class="active" id="nav-dashboard" onclick="setActiveNav(this)">
                <i class="fas fa-chart-line"></i> <span>Dashboard</span>
            </a>
        </li>
        <li>
            <a href="javascript:void(0);" onclick="triggerVehicleModal(this)">
                <i class="fas fa-truck-front"></i> <span>Vehicles</span>
            </a>
        </li>
        <li>
            <a href="accounts.php" target="contentFrame" onclick="setActiveNav(this)">
                <i class="fas fa-users-gear"></i> <span>Accounts</span>
            </a>
        </li>
        <li>
            <a href="javascript:void(0);" onclick="openDriverModal(this)">
                <i class="fas fa-user-check"></i> <span>Driver Assignments</span>
            </a>
        </li>
        <li>
            <a href="history.php" target="contentFrame" onclick="setActiveNav(this)">
                <i class="fas fa-clock-rotate-left"></i> <span>Delivery History</span>
            </a>
        </li>
    </ul>
    <div class="logout-section">
        <form method="POST" action="">
            <button type="submit" name="logout">
                <i class="fas fa-right-from-bracket"></i> <span>Logout</span>
            </button>
        </form>
    </div>
</div>

<div class="main-frame-wrapper">
    <iframe name="contentFrame" id="contentFrame" src="admindashboard.php" class="dashboard-iframe"></iframe>
</div>

<!-- DRIVER ASSIGNMENT POPUP MODAL -->
<div class="modal-overlay" id="driverModal">
    <div class="modal-card-lg">
        <div class="modal-header">
            <h3><i class="fas fa-user-check"></i> Driver Assignments</h3>
            <button class="modal-close" onclick="closeDriverModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <iframe id="driverAssignmentIframe" class="modal-iframe-container" src="about:blank"></iframe>
    </div>
</div>

<script>
    function setActiveNav(element) {
        document.querySelectorAll('.nav-menu a').forEach(el => el.classList.remove('active'));
        element.classList.add('active');
    }

    // Opens driver_assignment.php in a popup modal
    function openDriverModal(element) {
        setActiveNav(element);
        const modal = document.getElementById('driverModal');
        const iframe = document.getElementById('driverAssignmentIframe');
        iframe.src = 'driver_assignment.php';
        modal.classList.add('active');
    }

    // Closes the Driver Assignment Modal
    function closeDriverModal() {
        const modal = document.getElementById('driverModal');
        const iframe = document.getElementById('driverAssignmentIframe');
        modal.classList.remove('active');
        iframe.src = 'about:blank';
    }

    // Opens the vehicle modal inside admindashboard.php, loading it first if needed
    function triggerVehicleModal(element) {
        setActiveNav(element);
        const frame = document.getElementById('contentFrame');

        if (frame.contentWindow && frame.contentWindow.location.href.includes('admindashboard.php')) {
            if (typeof frame.contentWindow.openVehicleModal === 'function') {
                frame.contentWindow.openVehicleModal();
            }
        } else {
            frame.src = 'admindashboard.php';
            frame.onload = function() {
                if (frame.contentWindow && typeof frame.contentWindow.openVehicleModal === 'function') {
                    frame.contentWindow.openVehicleModal();
                }
                frame.onload = null;
            };
        }
    }

    // Close modal when clicking outside the modal content area
    window.addEventListener('click', function(event) {
        const modal = document.getElementById('driverModal');
        if (event.target === modal) {
            closeDriverModal();
        }
    });
</script>

</body>
</html>