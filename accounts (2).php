<?php
// Display errors for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------
// DATABASE CONNECTION (Using your connect_db.php file)
// ---------------------------------------------------------
$conn = require_once 'connect_db.php'; // Ensures MySQLi$conn object is loaded

$message = '';$messageType = '';

// ---------------------------------------------------------
// 1. ADD ACCOUNT (REGISTRATION)
// ---------------------------------------------------------
if (isset($_POST['action']) &&$_POST['action'] === 'add') {
    $name      = trim($_POST['name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $password  =$_POST['password'] ?? '';
    $acctlevel =$_POST['acctlevel'] ?? 'driver';
    $status    =$_POST['status'] ?? 'active';

    if (!empty($name) && !empty($username) && !empty($password)) {
        // Check if username already exists in acct table
        $chkStmt =$conn->prepare("SELECT id FROM acct WHERE username = ?");
        $chkStmt->bind_param("s", $username);
        $chkStmt->execute();$chkStmt->store_result();

        if ($chkStmt->num_rows > 0) {$message = "Username already taken!";
            $messageType = "danger";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            $stmt =$conn->prepare("INSERT INTO acct (name, username, password, acctlevel, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("sssss", $name, $username,$hashedPassword, $acctlevel,$status);

            if ($stmt->execute()) {$message = "Account successfully registered!";
                $messageType = "success";
            } else {
                $message = "Error registering account!";
                $messageType = "danger";
            }
            $stmt->close();
        }
        $chkStmt->close();
    } else {
        $message = "Please fill in all required fields!";
        $messageType = "danger";
    }
}

// ---------------------------------------------------------
// 2. EDIT ACCOUNT
// ---------------------------------------------------------
if (isset($_POST['action']) &&$_POST['action'] === 'edit') {
    $id        = (int)$_POST['user_id'];
    $name      = trim($_POST['name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $acctlevel =$_POST['acctlevel'] ?? 'driver';
    $status    =$_POST['status'] ?? 'active';
    $password  =$_POST['password'] ?? '';

    if (!empty($name) && !empty($username)) {
        // Ensure username is not taken by another user ID
        $chkStmt =$conn->prepare("SELECT id FROM acct WHERE username = ? AND id != ?");
        $chkStmt->bind_param("si", $username,$id);
        $chkStmt->execute();$chkStmt->store_result();

        if ($chkStmt->num_rows > 0) {$message = "Username is already taken by another account!";
            $messageType = "danger";
        } else {
            if (!empty($password)) {
                // Update with a new password
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                $stmt =$conn->prepare("UPDATE acct SET name = ?, username = ?, password = ?, acctlevel = ?, status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("sssssi", $name,$username, $hashedPassword,$acctlevel, $status,$id);
            } else {
                // Update without touching password
                $stmt =$conn->prepare("UPDATE acct SET name = ?, username = ?, acctlevel = ?, status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("ssssi", $name, $username,$acctlevel, $status,$id);
            }

            if ($stmt->execute()) {$message = "Account successfully updated!";
                $messageType = "success";
            } else {
                $message = "Failed to update account.";
                $messageType = "danger";
            }
            $stmt->close();
        }
        $chkStmt->close();
    }
}

// ---------------------------------------------------------
// 3. DELETE ACCOUNT
// ---------------------------------------------------------
if (isset($_POST['action']) &&$_POST['action'] === 'delete') {
    $id = (int)$_POST['user_id'];
    $stmt =$conn->prepare("DELETE FROM acct WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {$message = "Account deleted successfully!";
        $messageType = "success";
    } else {
        $message = "Failed to delete account!";
        $messageType = "danger";
    }
    $stmt->close();
}

// FETCH ALL ACCOUNTS FROM `acct` TABLE
$result =$conn->query("SELECT id, name, username, acctlevel, status, created_at, updated_at FROM acct ORDER BY id DESC");
$accounts = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Management - UAM Logistics</title>
    
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />

    <style>
        :root {
            --primary-50:  #fff7ed;
            --primary-100: #ffedd5;
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

            --radius-md: 12px;
            --radius-lg: 16px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--slate-50); color: var(--slate-900); padding: 1.5rem 2rem; }

        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-title {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--slate-900);
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .header-title i { color: var(--primary-600); }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .btn-add {
            background: linear-gradient(135deg, var(--primary-500), var(--primary-600));
            color: var(--white);
            border: none;
            padding: 0.65rem 1.25rem;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.88rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.2);
            transition: all 0.2s;
        }
        .btn-add:hover { background: var(--primary-700); }

        /* ALERT MESSAGES */
        .alert {
            padding: 0.85rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .alert-success { background: var(--success-50); color: var(--success-600); border: 1px solid #bbf7d0; }
        .alert-danger { background: var(--danger-50); color: var(--danger-600); border: 1px solid #fecaca; }

        /* TABLE CONTAINER */
        .table-container {
            background: var(--white);
            border-radius: var(--radius-lg);
            border: 1px solid var(--slate-200);
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
        }

        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { background: var(--slate-100); color: var(--slate-600); font-size: 0.75rem; text-transform: uppercase; font-weight: 700; padding: 1rem; border-bottom: 1px solid var(--slate-200); }
        td { padding: 1rem; font-size: 0.88rem; color: var(--slate-800); border-bottom: 1px solid var(--slate-100); }
        tr:last-child td { border-bottom: none; }

        .badge-role {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            display: inline-block;
        }
        .badge-admin { background: var(--primary-100); color: var(--primary-700); }
        .badge-driver { background: var(--slate-200); color: var(--slate-700); }

        .badge-status {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: capitalize;
        }
        .badge-active { background: var(--success-50); color: var(--success-600); }
        .badge-inactive { background: var(--danger-50); color: var(--danger-600); }

        .btn-action {
            border: none;
            background: transparent;
            font-size: 0.95rem;
            cursor: pointer;
            padding: 0.3rem 0.5rem;
            border-radius: 6px;
            transition: background 0.2s;
        }
        .btn-edit { color: var(--slate-600); }
        .btn-edit:hover { background: var(--slate-100); color: var(--primary-600); }
        .btn-delete { color: var(--danger-600); }
        .btn-delete:hover { background: var(--danger-50); }

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
        .modal-close:hover { color: var(--danger-600); }

        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; font-size: 0.78rem; font-weight: 700; color: var(--slate-600); margin-bottom: 0.35rem; }
        .form-group input, .form-group select {
            width: 100%;
            padding: 0.65rem 0.85rem;
            border: 1px solid var(--slate-300);
            border-radius: 8px;
            font-size: 0.88rem;
            outline: none;
        }
        .form-group input:focus, .form-group select:focus { border-color: var(--primary-500); }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }
        .btn-cancel { background: var(--slate-100); color: var(--slate-700); border: none; padding: 0.6rem 1rem; border-radius: 8px; font-weight: 700; cursor: pointer; }
        .btn-save { background: var(--primary-600); color: white; border: none; padding: 0.6rem 1.25rem; border-radius: 8px; font-weight: 700; cursor: pointer; }
    </style>
</head>
<body>

<div class="header-bar">
    <div class="header-title">
        <i class="fas fa-users-gear"></i> Account Management
    </div>
    <div class="nav-actions">
        <!-- Button to register new account directly -->
        <button class="btn-add" onclick="openAddModal()">
            <i class="fas fa-user-plus"></i> Register New Account
        </button>
    </div>
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
                <th>Full Name</th>
                <th>Username</th>
                <th>Account Level</th>
                <th>Status</th>
                <th>Created Date</th>
                <th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($accounts) > 0): ?>
                <?php foreach ($accounts as$user): ?>
                    <tr>
                        <td>#<?= $user['id'] ?></td>
                        <td><strong><?= htmlspecialchars($user['name']) ?></strong></td>
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td>
                            <span class="badge-role <?= $user['acctlevel'] === 'admin' ? 'badge-admin' : 'badge-driver' ?>">
                                <?= htmlspecialchars($user['acctlevel']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge-status <?= $user['status'] === 'active' ? 'badge-active' : 'badge-inactive' ?>">
                                <?= htmlspecialchars($user['status'] ?? 'active') ?>
                            </span>
                        </td>
                        <td><?= !empty($user['created_at']) ? date('M d, Y', strtotime($user['created_at'])) : 'N/A' ?></td>
                        <td style="text-align: right;">
                            <button class="btn-action btn-edit" onclick="openEditModal(<?= htmlspecialchars(json_encode($user)) ?>)" title="Edit Account">
                                <i class="fas fa-pen-to-square"></i>
                            </button>
                            <button class="btn-action btn-delete" onclick="openDeleteModal(<?= $user['id'] ?>, '<?= htmlspecialchars($user['name']) ?>')" title="Delete Account">
                                <i class="fas fa-trash-can"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" style="text-align:center; padding: 2rem; color: var(--slate-400);">No accounts registered yet.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 1. ADD / REGISTRATION MODAL -->
<div class="modal-overlay" id="addModal">
    <div class="modal-card">
        <div class="modal-header">
            <h3><i class="fas fa-user-plus" style="color:var(--primary-600)"></i> Register Account</h3>
            <button class="modal-close" onclick="closeModal('addModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" placeholder="e.g., Juan Dela Cruz" required>
            </div>
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" placeholder="e.g., jdelacruz" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter password" required>
            </div>
            <div class="form-group">
                <label>Account Level</label>
                <select name="acctlevel" required>
                    <option value="driver">Driver</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn-save">Create Account</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. EDIT MODAL -->
<div class="modal-overlay" id="editModal">
    <div class="modal-card">
        <div class="modal-header">
            <h3><i class="fas fa-user-pen" style="color:var(--primary-600)"></i> Edit Account</h3>
            <button class="modal-close" onclick="closeModal('editModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="user_id" id="edit_user_id">
            
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" id="edit_name" required>
            </div>
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" id="edit_username" required>
            </div>
            <div class="form-group">
                <label>Password <small style="color:var(--slate-400); font-weight:normal;">(Leave blank to keep current)</small></label>
                <input type="password" name="password" placeholder="Leave blank to keep current">
            </div>
            <div class="form-group">
                <label>Account Level</label>
                <select name="acctlevel" id="edit_acctlevel" required>
                    <option value="driver">Driver</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" id="edit_status" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn-save">Update Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. DELETE CONFIRMATION MODAL -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-card">
        <div class="modal-header">
            <h3 style="color:var(--danger-600);"><i class="fas fa-triangle-exclamation"></i> Confirm Delete</h3>
            <button class="modal-close" onclick="closeModal('deleteModal')"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" id="delete_user_id">
            
            <p style="font-size:0.9rem; color:var(--slate-600); margin-bottom:1.5rem;">
                Are you sure you want to delete the account for <strong id="delete_user_name" style="color:var(--slate-900);"></strong>? This action cannot be undone.
            </p>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn-save" style="background:var(--danger-600);">Delete Account</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddModal() {
        document.getElementById('addModal').classList.add('active');
    }

    function openEditModal(user) {
        document.getElementById('edit_user_id').value = user.id;
        document.getElementById('edit_name').value = user.name;
        document.getElementById('edit_username').value = user.username;
        document.getElementById('edit_acctlevel').value = user.acctlevel;
        document.getElementById('edit_status').value = user.status || 'active';
        document.getElementById('editModal').classList.add('active');
    }

    function openDeleteModal(id, name) {
        document.getElementById('delete_user_id').value = id;
        document.getElementById('delete_user_name').innerText = name;
        document.getElementById('deleteModal').classList.add('active');
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');
    }

    // Close modals on clicking backdrop
    window.onclick = function(event) {
        if (event.target.classList.contains('modal-overlay')) {
            event.target.classList.remove('active');
        }
    }
</script>

</body>
</html>