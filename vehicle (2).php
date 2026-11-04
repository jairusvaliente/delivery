<?php
// Enable error diagnostics for production debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// DATABASE CONNECTION (MySQLi)
$servername = "localhost";
$username   = "ecamonit_ECAMonitoring";
$password   = "awsdrftg1432!@#";
$database   = "ecamonit_delivery";

mysqli_report(MYSQLI_REPORT_OFF);

$message = '';
$messageType = '';

$conn = @new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    $message = "Database Connection Failed: " . $conn->connect_error;
    $messageType = "danger";
} else {
    $conn->set_charset("utf8mb4");
}

// EDIT PREPARATION (Fetch vehicle data for form pre-filling)
$edit_mode = false;
$edit_id = 0;
$edit_plate = '';
$edit_type = '';
$edit_model = '';
$edit_capacity = '';

if (isset($_GET['edit_id']) && $conn && !$conn->connect_error) {
    $edit_id = intval($_GET['edit_id']);
    if ($edit_id > 0) {
        $stmt = $conn->prepare("SELECT id, plate_number, type, model, capacity FROM vehicles WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $edit_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $edit_mode = true;
                $edit_plate = $row['plate_number'];
                $edit_type = $row['type'];
                $edit_model = $row['model'];
                $edit_capacity = $row['capacity'];
            }
            $stmt->close();
        }
    }
}

// HANDLE FORM SUBMISSIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $conn && !$conn->connect_error) {
    
    // Add Vehicle
    if ($_POST['action'] === 'add_vehicle') {
        $plate    = trim($_POST['plate_number'] ?? '');
        $type     = trim($_POST['vehicle_type'] ?? '');
        $capacity = trim($_POST['capacity'] ?? '');
        $model    = trim($_POST['model'] ?? '');

        if (!empty($plate) && !empty($type)) {
            $stmt = $conn->prepare("INSERT INTO vehicles (plate_number, type, model, capacity, status) VALUES (?, ?, ?, ?, 'Active')");
            if ($stmt) {
                $stmt->bind_param("ssss", $plate, $type, $model, $capacity);
                if ($stmt->execute()) {
                    $message = "Vehicle added successfully!";
                    $messageType = "success";
                } else {
                    if ($conn->errno == 1062) {
                        $message = "Error: Plate number already exists.";
                    } else {
                        $message = "Database Error: " . $stmt->error;
                    }
                    $messageType = "danger";
                }
                $stmt->close();
            } else {
                $message = "Prepare Failed: " . $conn->error;
                $messageType = "danger";
            }
        } else {
            $message = "Please fill in all required fields.";
            $messageType = "danger";
        }
    }

    // Update Vehicle
    if ($_POST['action'] === 'update_vehicle') {
        $id       = intval($_POST['vehicle_id'] ?? 0);
        $plate    = trim($_POST['plate_number'] ?? '');
        $type     = trim($_POST['vehicle_type'] ?? '');
        $capacity = trim($_POST['capacity'] ?? '');
        $model    = trim($_POST['model'] ?? '');

        if ($id > 0 && !empty($plate) && !empty($type)) {
            $stmt = $conn->prepare("UPDATE vehicles SET plate_number = ?, type = ?, model = ?, capacity = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("ssssi", $plate, $type, $model, $capacity, $id);
                if ($stmt->execute()) {
                    $message = "Vehicle record updated successfully!";
                    $messageType = "success";
                    $edit_mode = false;
                } else {
                    if ($conn->errno == 1062) {
                        $message = "Error: Plate number already exists on another vehicle.";
                    } else {
                        $message = "Update Error: " . $stmt->error;
                    }
                    $messageType = "danger";
                }
                $stmt->close();
            }
        } else {
            $message = "Please fill in all required fields.";
            $messageType = "danger";
        }
    }

    // Delete Vehicle
    if ($_POST['action'] === 'delete_vehicle') {
        $id = intval($_POST['vehicle_id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM vehicles WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $message = "Vehicle removed successfully.";
                    $messageType = "success";
                } else {
                    $message = "Error deleting vehicle: " . $stmt->error;
                    $messageType = "danger";
                }
                $stmt->close();
            }
        }
    }
}

// Fetch all vehicles
$vehicles = [];
if ($conn && !$conn->connect_error) {
    $result = $conn->query("SELECT id, plate_number, model, type, capacity, status, created_at FROM vehicles ORDER BY id DESC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $vehicles[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Management</title>
    
    <!-- Google Fonts & FontAwesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

    <style>
        :root {
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

            --radius-md: 10px;
            --radius-lg: 14px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: #ffffff; color: var(--slate-900); height: 100vh; display: flex; flex-direction: column; overflow: hidden; }

        /* FORMAL HEADER BAR */
        .modal-header-bar {
            height: 60px;
            background: var(--slate-900);
            color: #ffffff;
            padding: 0 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            border-bottom: 2px solid var(--primary-600);
        }

        .modal-header-bar .title-group {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .modal-header-bar .title-group i {
            color: var(--primary-500);
            font-size: 1.2rem;
        }

        .modal-header-bar h2 {
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: -0.01em;
        }

        .modal-header-bar .header-spacer {
            width: 40px;
        }

        /* CONTAINER BODY */
        .content-body {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            background: var(--slate-50);
        }

        /* ALERT NOTIFICATIONS */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        /* CARD FORM */
        .card-box {
            background: #ffffff;
            border-radius: var(--radius-md);
            border: 1px solid var(--slate-200);
            padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .card-box h3 {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--slate-800);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        .form-group label {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--slate-600);
            text-transform: uppercase;
        }

        .form-group input {
            width: 100%;
            padding: 0.6rem 0.8rem;
            border: 1px solid var(--slate-300);
            border-radius: var(--radius-md);
            font-size: 0.88rem;
            outline: none;
            transition: all 0.2s;
        }

        .form-group input:focus {
            border-color: var(--primary-500);
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.15);
        }

        .button-group {
            grid-column: span 4;
            display: flex;
            gap: 0.75rem;
            margin-top: 0.5rem;
        }

        .btn-submit {
            flex: 1;
            background: linear-gradient(135deg, var(--primary-500), var(--primary-600));
            color: #ffffff;
            border: none;
            padding: 0.75rem;
            border-radius: var(--radius-md);
            font-weight: 700;
            font-size: 0.88rem;
            cursor: pointer;
            transition: opacity 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-submit:hover { opacity: 0.92; }

        .btn-cancel {
            background: var(--slate-200);
            color: var(--slate-700);
            text-decoration: none;
            padding: 0.75rem 1.25rem;
            border-radius: var(--radius-md);
            font-weight: 700;
            font-size: 0.88rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            transition: background 0.2s;
        }

        .btn-cancel:hover { background: var(--slate-300); }

        /* TABLE SECTION */
        .table-container {
            background: #ffffff;
            border-radius: var(--radius-md);
            border: 1px solid var(--slate-200);
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th {
            background: var(--slate-100);
            color: var(--slate-700);
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--slate-200);
        }

        td {
            padding: 0.85rem 1rem;
            font-size: 0.85rem;
            color: var(--slate-800);
            border-bottom: 1px solid var(--slate-100);
            vertical-align: middle;
        }

        tr:last-child td { border-bottom: none; }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .action-cell {
            text-align: right;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        .btn-edit {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            padding: 0.4rem 0.65rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.78rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.2s;
        }

        .btn-edit:hover {
            background: #2563eb;
            color: #ffffff;
        }

        .btn-delete {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
            padding: 0.4rem 0.65rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.78rem;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-delete:hover {
            background: #dc2626;
            color: #ffffff;
        }

        /* CUSTOM DELETE CONFIRMATION MODAL */
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

        .modal-overlay.active {
            display: flex;
        }

        .delete-modal-card {
            background: #ffffff;
            width: 100%;
            max-width: 400px;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            text-align: center;
            animation: modalPop 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalPop {
            0% { transform: scale(0.95); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }

        .delete-modal-icon {
            width: 50px;
            height: 50px;
            background: #fef2f2;
            color: #dc2626;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin: 0 auto 1rem auto;
            border: 1px solid #fecaca;
        }

        .delete-modal-card h4 {
            font-size: 1.1rem;
            color: var(--slate-900);
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .delete-modal-card p {
            font-size: 0.85rem;
            color: var(--slate-600);
            margin-bottom: 1.5rem;
            line-height: 1.4;
        }

        .modal-actions {
            display: flex;
            gap: 0.75rem;
        }

        .modal-actions button {
            flex: 1;
            padding: 0.65rem;
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }

        .btn-modal-cancel {
            background: var(--slate-100);
            color: var(--slate-700);
            border: 1px solid var(--slate-300) !important;
        }

        .btn-modal-cancel:hover { background: var(--slate-200); }

        .btn-modal-confirm {
            background: #dc2626;
            color: #ffffff;
        }

        .btn-modal-confirm:hover { background: #b91c1c; }
    </style>
</head>
<body>

    <!-- FORMAL MODAL HEADER -->
    <div class="modal-header-bar">
        <div class="title-group">
            <i class="fas fa-truck-front"></i>
            <h2>Vehicle Fleet Management</h2>
        </div>
        <div class="header-spacer"></div>
    </div>

    <!-- MAIN BODY CONTENT -->
    <div class="content-body">

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $messageType ?>">
                <i class="fas <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- REGISTER / EDIT VEHICLE FORM -->
        <div class="card-box">
            <h3>
                <i class="fas <?= $edit_mode ? 'fa-pen-to-square' : 'fa-plus-circle' ?>" style="color: var(--primary-600);"></i> 
                <?= $edit_mode ? 'Edit Vehicle Record' : 'Register New Vehicle' ?>
            </h3>
            <form method="POST" action="vehicle.php">
                <input type="hidden" name="action" value="<?= $edit_mode ? 'update_vehicle' : 'add_vehicle' ?>">
                <?php if ($edit_mode): ?>
                    <input type="hidden" name="vehicle_id" value="<?= $edit_id ?>">
                <?php endif; ?>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="plate_number">Plate Number *</label>
                        <input type="text" id="plate_number" name="plate_number" value="<?= htmlspecialchars($edit_plate) ?>" placeholder="e.g. ABC-1234" required>
                    </div>

                    <div class="form-group">
                        <label for="vehicle_type">Type *</label>
                        <input type="text" id="vehicle_type" name="vehicle_type" value="<?= htmlspecialchars($edit_type) ?>" placeholder="e.g. 6-Wheeler Truck, Van" required>
                    </div>

                    <div class="form-group">
                        <label for="model">Model</label>
                        <input type="text" id="model" name="model" value="<?= htmlspecialchars($edit_model) ?>" placeholder="e.g. Isuzu ELF, Canter">
                    </div>

                    <div class="form-group">
                        <label for="capacity">Capacity</label>
                        <input type="text" id="capacity" name="capacity" value="<?= htmlspecialchars($edit_capacity) ?>" placeholder="e.g. 10 Tons">
                    </div>

                    <div class="button-group">
                        <button type="submit" class="btn-submit">
                            <i class="fas <?= $edit_mode ? 'fa-rotate' : 'fa-save' ?>"></i> 
                            <?= $edit_mode ? 'Update Vehicle Record' : 'Save Vehicle Record' ?>
                        </button>

                        <?php if ($edit_mode): ?>
                            <a href="vehicle.php" class="btn-cancel">
                                <i class="fas fa-xmark"></i> Cancel
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <!-- FLEET LIST TABLE -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Plate Number</th>
                        <th>Type</th>
                        <th>Model</th>
                        <th>Capacity</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($vehicles)): ?>
                        <?php foreach ($vehicles as $v): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($v['plate_number']) ?></strong></td>
                                <td><?= htmlspecialchars($v['type']) ?></td>
                                <td><?= htmlspecialchars($v['model'] ?: 'N/A') ?></td>
                                <td><?= htmlspecialchars($v['capacity'] ?: 'N/A') ?></td>
                                <td>
                                    <span class="status-badge">
                                        <i class="fas fa-circle-check" style="font-size:0.6rem; margin-right:3px;"></i>
                                        <?= htmlspecialchars($v['status'] ?? 'Active') ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-cell">
                                        <a href="vehicle.php?edit_id=<?= $v['id'] ?>" class="btn-edit">
                                            <i class="fas fa-pen-to-square"></i> Edit
                                        </a>

                                        <button type="button" class="btn-delete" onclick="openDeleteModal('<?= $v['id'] ?>', '<?= htmlspecialchars($v['plate_number'], ENT_QUOTES) ?>')">
                                            <i class="fas fa-trash-can"></i> Remove
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--slate-500); padding: 2rem;">
                                <i class="fas fa-truck-arrow-right" style="font-size: 2rem; margin-bottom: 0.5rem; display:block; opacity:0.4;"></i>
                                No vehicles registered in database.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <!-- REMOVE CONFIRMATION MODAL -->
    <div class="modal-overlay" id="deleteModal">
        <div class="delete-modal-card">
            <div class="delete-modal-icon">
                <i class="fas fa-triangle-exclamation"></i>
            </div>
            <h4>Remove Vehicle</h4>
            <p>Are you sure you want to remove vehicle <strong id="modalPlateNumber" style="color: var(--slate-900);"></strong>? This action cannot be undone.</p>
            
            <form method="POST" action="vehicle.php">
                <input type="hidden" name="action" value="delete_vehicle">
                <input type="hidden" name="vehicle_id" id="modalVehicleId" value="">
                
                <div class="modal-actions">
                    <button type="button" class="btn-modal-cancel" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn-modal-confirm">Delete Vehicle</button>
                </div>
            </form>
        </div>
    </div>

    <!-- JAVASCRIPT FOR MODAL CONTROL -->
    <script>
        function openDeleteModal(vehicleId, plateNumber) {
            document.getElementById('modalVehicleId').value = vehicleId;
            document.getElementById('modalPlateNumber').textContent = plateNumber;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }

        // Close modal when clicking outside the dialog card
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('deleteModal');
            if (event.target === modal) {
                closeDeleteModal();
            }
        });
    </script>

</body>
</html>