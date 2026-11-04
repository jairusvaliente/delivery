<?php
// Display errors for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------
// DATABASE CONNECTION
// ---------------------------------------------------------
$conn = require_once 'connect_db.php'; // Ensures MySQLi $conn object is available

$message = '';
$messageType = '';

// ---------------------------------------------------------
// 1. CREATE ASSIGNMENT
// ---------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'assign') {
    $driver_id   = (int)($_POST['driver_id'] ?? 0);
    $vehicle_id  = (int)($_POST['vehicle_id'] ?? 0);
    $destination = trim($_POST['destination'] ?? '');
    $notes       = trim($_POST['notes'] ?? '');

    if ($driver_id > 0 && $vehicle_id > 0 && !empty($destination)) {
        // Check active assignments
        $chkDriver = $conn->prepare("SELECT id FROM driver_assignments WHERE driver_id = ? AND status = 'active'");
        $chkDriver->bind_param("i", $driver_id);
        $chkDriver->execute();
        $chkDriver->store_result();

        $chkVehicle = $conn->prepare("SELECT id FROM driver_assignments WHERE vehicle_id = ? AND status = 'active'");
        $chkVehicle->bind_param("i", $vehicle_id);
        $chkVehicle->execute();
        $chkVehicle->store_result();

        if ($chkDriver->num_rows > 0) {
            $message = "This driver is already assigned to an active vehicle.";
            $messageType = "danger";
        } elseif ($chkVehicle->num_rows > 0) {
            $message = "This vehicle is already assigned to an active driver.";
            $messageType = "danger";
        } else {
            $stmt = $conn->prepare("INSERT INTO driver_assignments (driver_id, vehicle_id, destination, notes, status, assigned_at) VALUES (?, ?, ?, ?, 'active', NOW())");
            $stmt->bind_param("iiss", $driver_id, $vehicle_id, $destination, $notes);

            if ($stmt->execute()) {
                $updateVehicle = $conn->prepare("UPDATE vehicles SET status = 'in_use' WHERE id = ?");
                if ($updateVehicle) {
                    $updateVehicle->bind_param("i", $vehicle_id);
                    $updateVehicle->execute();
                    $updateVehicle->close();
                }
                $message = "Driver successfully assigned!";
                $messageType = "success";
            } else {
                $message = "Failed to assign driver.";
                $messageType = "danger";
            }
            $stmt->close();
        }
        $chkDriver->close();
        $chkVehicle->close();
    } else {
        $message = "Please fill in all required fields.";
        $messageType = "danger";
    }
}

// ---------------------------------------------------------
// 2. EDIT ASSIGNMENT
// ---------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $assignment_id  = (int)($_POST['assignment_id'] ?? 0);
    $old_vehicle_id = (int)($_POST['old_vehicle_id'] ?? 0);
    $driver_id      = (int)($_POST['driver_id'] ?? 0);
    $vehicle_id     = (int)($_POST['vehicle_id'] ?? 0);
    $destination    = trim($_POST['destination'] ?? '');
    $notes          = trim($_POST['notes'] ?? '');

    if ($assignment_id > 0 && $driver_id > 0 && $vehicle_id > 0 && !empty($destination)) {
        // Check driver uniqueness excluding current assignment
        $chkDriver = $conn->prepare("SELECT id FROM driver_assignments WHERE driver_id = ? AND status = 'active' AND id != ?");
        $chkDriver->bind_param("ii", $driver_id, $assignment_id);
        $chkDriver->execute();
        $chkDriver->store_result();

        // Check vehicle uniqueness excluding current assignment
        $chkVehicle = $conn->prepare("SELECT id FROM driver_assignments WHERE vehicle_id = ? AND status = 'active' AND id != ?");
        $chkVehicle->bind_param("ii", $vehicle_id, $assignment_id);
        $chkVehicle->execute();
        $chkVehicle->store_result();

        if ($chkDriver->num_rows > 0) {
            $message = "This driver is already assigned elsewhere.";
            $messageType = "danger";
        } elseif ($chkVehicle->num_rows > 0) {
            $message = "This vehicle is already assigned elsewhere.";
            $messageType = "danger";
        } else {
            $stmt = $conn->prepare("UPDATE driver_assignments SET driver_id = ?, vehicle_id = ?, destination = ?, notes = ? WHERE id = ?");
            $stmt->bind_param("iissi", $driver_id, $vehicle_id, $destination, $notes, $assignment_id);

            if ($stmt->execute()) {
                // If vehicle changed, release old vehicle and reserve new vehicle
                if ($old_vehicle_id !== $vehicle_id) {
                    $conn->query("UPDATE vehicles SET status = 'available' WHERE id = $old_vehicle_id");
                    $conn->query("UPDATE vehicles SET status = 'in_use' WHERE id = $vehicle_id");
                }
                $message = "Assignment updated successfully!";
                $messageType = "success";
            } else {
                $message = "Failed to update assignment.";
                $messageType = "danger";
            }
            $stmt->close();
        }
        $chkDriver->close();
        $chkVehicle->close();
    }
}

// ---------------------------------------------------------
// 3. UNASSIGN / COMPLETE ASSIGNMENT
// ---------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'unassign') {
    $assignment_id = (int)$_POST['assignment_id'];
    $vehicle_id    = (int)$_POST['vehicle_id'];

    $stmt = $conn->prepare("UPDATE driver_assignments SET status = 'completed', unassigned_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $assignment_id);

    if ($stmt->execute()) {
        $updateVehicle = $conn->prepare("UPDATE vehicles SET status = 'available' WHERE id = ?");
        if ($updateVehicle) {
            $updateVehicle->bind_param("i", $vehicle_id);
            $updateVehicle->execute();
            $updateVehicle->close();
        }
        $message = "Assignment completed and vehicle unassigned!";
        $messageType = "success";
    } else {
        $message = "Failed to unassign driver.";
        $messageType = "danger";
    }
    $stmt->close();
}

// ---------------------------------------------------------
// 4. FETCH DATA
// ---------------------------------------------------------
// Fetch Drivers
$driversQuery = "SELECT id, name, username FROM acct WHERE acctlevel = 'driver' AND status = 'active' ORDER BY name ASC";
$driversResult = $conn->query($driversQuery);
$drivers = $driversResult ? $driversResult->fetch_all(MYSQLI_ASSOC) : [];

// Fetch Vehicles
$vehiclesQuery = "SELECT id, plate_number, model FROM vehicles ORDER BY plate_number ASC";
$vehiclesResult = $conn->query($vehiclesQuery);
$vehicles = $vehiclesResult ? $vehiclesResult->fetch_all(MYSQLI_ASSOC) : [];

// Fetch Destinations
$destinationsQuery = "SELECT DISTINCT name, code FROM deliveries ORDER BY name ASC";
$destinationsResult = $conn->query($destinationsQuery);
$destinations = $destinationsResult ? $destinationsResult->fetch_all(MYSQLI_ASSOC) : [];

// Fetch Assignments Table
$assignmentsQuery = "
    SELECT 
        da.id, 
        da.driver_id,
        da.vehicle_id,
        da.destination,
        da.notes, 
        da.status, 
        da.assigned_at, 
        da.unassigned_at,
        u.name AS driver_name, 
        v.plate_number, 
        v.model AS vehicle_model
    FROM driver_assignments da
    JOIN acct u ON da.driver_id = u.id
    JOIN vehicles v ON da.vehicle_id = v.id
    ORDER BY da.id DESC
";
$assignmentsResult = $conn->query($assignmentsQuery);
$assignments = $assignmentsResult ? $assignmentsResult->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Assignments - UAM Logistics</title>
    
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
            --slate-700:  #334155;
            --slate-800:  #1e293b;
            --slate-900:  #0f172a;
            
            --white:      #ffffff;
            --danger-50:  #fef2f2;
            --danger-600: #dc2626;
            --success-50: #f0fdf4;
            --success-600:#16a34a;

            --radius-lg: 16px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--slate-50); color: var(--slate-900); padding: 1.25rem; }

        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-title {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--slate-900);
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .header-title i { color: var(--primary-600); }

        .btn-add {
            background: linear-gradient(135deg, var(--primary-500), var(--primary-600));
            color: var(--white);
            border: none;
            padding: 0.6rem 1.15rem;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.85rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.2);
        }
        .btn-add:hover { background: var(--primary-700); }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .alert-success { background: var(--success-50); color: var(--success-600); border: 1px solid #bbf7d0; }
        .alert-danger { background: var(--danger-50); color: var(--danger-600); border: 1px solid #fecaca; }

        .table-container {
            background: var(--white);
            border-radius: var(--radius-lg);
            border: 1px solid var(--slate-200);
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
        }

        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { background: var(--slate-100); color: var(--slate-600); font-size: 0.75rem; text-transform: uppercase; font-weight: 700; padding: 0.85rem 1rem; border-bottom: 1px solid var(--slate-200); }
        td { padding: 0.85rem 1rem; font-size: 0.85rem; color: var(--slate-800); border-bottom: 1px solid var(--slate-100); }

        .badge-status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            display: inline-block;
        }
        .badge-active { background: var(--success-50); color: var(--success-600); }
        .badge-completed { background: var(--slate-200); color: var(--slate-600); }

        .action-btns { display: flex; gap: 0.4rem; justify-content: flex-end; }
        .btn-edit {
            background: var(--slate-100);
            color: var(--slate-700);
            border: none;
            padding: 0.4rem 0.65rem;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.78rem;
            cursor: pointer;
        }
        .btn-edit:hover { background: var(--slate-200); }

        .btn-unassign {
            background: var(--danger-50);
            color: var(--danger-600);
            border: none;
            padding: 0.4rem 0.65rem;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.78rem;
            cursor: pointer;
        }
        .btn-unassign:hover { background: #fee2e2; }

        /* MODALS */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 999;
        }
        .modal-overlay.active { display: flex; }

        .modal-card {
            background: var(--white);
            width: 100%;
            max-width: 480px;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .modal-header h3 { font-size: 1.1rem; font-weight: 800; display: flex; align-items: center; gap: 0.5rem; }
        .modal-close { background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--slate-400); }

        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; font-size: 0.78rem; font-weight: 700; color: var(--slate-600); margin-bottom: 0.35rem; }
        .form-group select, .form-group textarea {
            width: 100%;
            padding: 0.65rem 0.85rem;
            border: 1px solid var(--slate-300);
            border-radius: 8px;
            font-size: 0.88rem;
            outline: none;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }
        .btn-cancel { background: var(--slate-100); color: var(--slate-700); border: none; padding: 0.6rem 1rem; border-radius: 8px; font-weight: 700; cursor: pointer; }
        .btn-save { background: var(--primary-600); color: white; border: none; padding: 0.6rem 1.25rem; border-radius: 8px; font-weight: 700; cursor: pointer; }
        .btn-confirm-del { background: var(--danger-600); color: white; border: none; padding: 0.6rem 1.25rem; border-radius: 8px; font-weight: 700; cursor: pointer; }
    </style>
</head>
<body>

<div class="header-bar">
   
    <button class="btn-add" onclick="openAssignModal()">
        <i class="fas fa-plus"></i> New Assignment
    </button>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Driver Name</th>
                <th>Vehicle / Plate No.</th>
                <th>Destination Company</th>
                <th>Status</th>
                <th>Assigned Date</th>
                <th>Notes</th>
                <th style="text-align: right;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($assignments) > 0): ?>
                <?php foreach ($assignments as $row): ?>
                    <tr>
                        <td>#<?= $row['id'] ?></td>
                        <td><strong><?= htmlspecialchars($row['driver_name']) ?></strong></td>
                        <td>
                            <div><strong><?= htmlspecialchars($row['plate_number']) ?></strong></div>
                            <small style="color:var(--slate-500);"><?= htmlspecialchars($row['vehicle_model']) ?></small>
                        </td>
                        <td>
                            <strong style="color:var(--primary-700);"><?= htmlspecialchars($row['destination'] ?: 'N/A') ?></strong>
                        </td>
                        <td>
                            <span class="badge-status <?= $row['status'] === 'active' ? 'badge-active' : 'badge-completed' ?>">
                                <?= htmlspecialchars($row['status']) ?>
                            </span>
                        </td>
                        <td><?= date('M d, Y h:i A', strtotime($row['assigned_at'])) ?></td>
                        <td><?= htmlspecialchars($row['notes'] ?: 'N/A') ?></td>
                        <td style="text-align: right;">
                            <?php if ($row['status'] === 'active'): ?>
                                <div class="action-btns">
                                    <button class="btn-edit" onclick='openEditModal(<?= json_encode($row) ?>)'>
                                        <i class="fas fa-pen"></i> Edit
                                    </button>
                                    <button class="btn-unassign" onclick="openUnassignModal(<?= $row['id'] ?>, <?= $row['vehicle_id'] ?>)">
                                        <i class="fas fa-link-slash"></i> Unassign
                                    </button>
                                </div>
                            <?php else: ?>
                                <small style="color:var(--slate-400);">Completed</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8" style="text-align:center; padding: 2rem; color: var(--slate-400);">
                        No driver assignments recorded yet.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- CREATE/EDIT MODAL -->
<div class="modal-overlay" id="formModal">
    <div class="modal-card">
        <div class="modal-header">
            <h3 id="modalTitle"><i class="fas fa-user-plus" style="color:var(--primary-600)"></i> Assign Driver</h3>
            <button class="modal-close" onclick="closeModal('formModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" id="formAction" value="assign">
            <input type="hidden" name="assignment_id" id="formAssignmentId" value="">
            <input type="hidden" name="old_vehicle_id" id="formOldVehicleId" value="">
            
            <div class="form-group">
                <label>Select Driver</label>
                <select name="driver_id" id="formDriver" required>
                    <option value="">-- Choose Active Driver --</option>
                    <?php foreach ($drivers as $driver): ?>
                        <option value="<?= $driver['id'] ?>">
                            <?= htmlspecialchars($driver['name']) ?> (<?= htmlspecialchars($driver['username']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Select Vehicle</label>
                <select name="vehicle_id" id="formVehicle" required>
                    <option value="">-- Choose Vehicle --</option>
                    <?php foreach ($vehicles as $vehicle): ?>
                        <option value="<?= $vehicle['id'] ?>">
                            <?= htmlspecialchars($vehicle['plate_number']) ?> - <?= htmlspecialchars($vehicle['model']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Select Destination (Company Name)</label>
                <select name="destination" id="formDestination" required>
                    <option value="">-- Choose Destination Company --</option>
                    <?php foreach ($destinations as $dest): ?>
                        <option value="<?= htmlspecialchars($dest['name']) ?>">
                            <?= htmlspecialchars($dest['name']) ?> (<?= htmlspecialchars($dest['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Assignment Notes (Optional)</label>
                <textarea name="notes" id="formNotes" rows="3" placeholder="Add route details or notes..."></textarea>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('formModal')">Cancel</button>
                <button type="submit" class="btn-save" id="formSubmitBtn">Assign Driver</button>
            </div>
        </form>
    </div>
</div>

<!-- UNASSIGN CONFIRMATION MODAL -->
<div class="modal-overlay" id="unassignModal">
    <div class="modal-card" style="max-width: 400px; text-align: center;">
        <div style="font-size: 2.5rem; color: var(--danger-600); margin-bottom: 0.5rem;">
            <i class="fas fa-circle-exclamation"></i>
        </div>
        <h3 style="font-size: 1.1rem; font-weight: 800; margin-bottom: 0.5rem;">End Assignment?</h3>
        <p style="font-size: 0.85rem; color: var(--slate-600); margin-bottom: 1.5rem;">
            Are you sure you want to unassign this driver? The vehicle status will be set back to available.
        </p>
        <form method="POST" action="">
            <input type="hidden" name="action" value="unassign">
            <input type="hidden" name="assignment_id" id="unassignId" value="">
            <input type="hidden" name="vehicle_id" id="unassignVehicleId" value="">
            
            <div class="modal-footer" style="justify-content: center;">
                <button type="button" class="btn-cancel" onclick="closeModal('unassignModal')">Cancel</button>
                <button type="submit" class="btn-confirm-del">Yes, Unassign</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAssignModal() {
        document.getElementById('formAction').value = 'assign';
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus" style="color:var(--primary-600)"></i> Assign Driver';
        document.getElementById('formSubmitBtn').innerText = 'Assign Driver';
        
        document.getElementById('formAssignmentId').value = '';
        document.getElementById('formOldVehicleId').value = '';
        document.getElementById('formDriver').value = '';
        document.getElementById('formVehicle').value = '';
        document.getElementById('formDestination').value = '';
        document.getElementById('formNotes').value = '';

        document.getElementById('formModal').classList.add('active');
    }

    function openEditModal(row) {
        document.getElementById('formAction').value = 'edit';
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-pen" style="color:var(--primary-600)"></i> Edit Assignment #' + row.id;
        document.getElementById('formSubmitBtn').innerText = 'Update Assignment';

        document.getElementById('formAssignmentId').value = row.id;
        document.getElementById('formOldVehicleId').value = row.vehicle_id;
        document.getElementById('formDriver').value = row.driver_id;
        document.getElementById('formVehicle').value = row.vehicle_id;
        document.getElementById('formDestination').value = row.destination;
        document.getElementById('formNotes').value = row.notes;

        document.getElementById('formModal').classList.add('active');
    }

    function openUnassignModal(id, vehicleId) {
        document.getElementById('unassignId').value = id;
        document.getElementById('unassignVehicleId').value = vehicleId;
        document.getElementById('unassignModal').classList.add('active');
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');
    }

    window.onclick = function(event) {
        if (event.target.classList.contains('modal-overlay')) {
            event.target.classList.remove('active');
        }
    }
</script>

</body>
</html>