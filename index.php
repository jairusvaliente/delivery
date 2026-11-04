<?php
/* ======================================================================
   index.php - Login page
   --------------------------------------------------------------------
   NOTE: In this database schema, `acct.status` encodes the ROLE
         (1 = admin, 0 = driver), NOT active/inactive.
         The role is also in `acct.acctlevel`, so we rely on acctlevel
         for routing and don't touch `status` at login.
   ====================================================================== */

session_start();

// If already logged in, redirect based on acctlevel
if (isset($_SESSION['acctlevel'], $_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['acctlevel'] === 'admin' ? 'admin.php' : 'driver.php'));
    exit;
}

require_once __DIR__ . '/connect_db.php';

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        // Look up the user (case-insensitive)
        $stmt = $conn->prepare(
            "SELECT id, name, username, password, acctlevel, status
             FROM acct
             WHERE LOWER(username) = LOWER(?)
             LIMIT 1"
        );
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $error = 'Invalid username or password.';
        } else {
            // ─────── Password check ───────
            $passwordOk = password_verify($password, $user['password'])
                       || ($password === $user['password']);

            if (!$passwordOk) {
                $error = 'Invalid username or password.';
            } else {
                // ─────── Success ───────
                session_regenerate_id(true);

                $_SESSION['user_id']   = (int)$user['id'];
                $_SESSION['name']      = $user['name'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['acctlevel'] = strtolower(trim($user['acctlevel']));

                // Update last activity
                $up = $conn->prepare("UPDATE acct SET updated_at = NOW() WHERE id = ?");
                $up->bind_param('i', $user['id']);
                $up->execute();
                $up->close();

                // Redirect by acctlevel
                if ($_SESSION['acctlevel'] === 'admin') {
                    header('Location: admin.php');
                } else {
                    header('Location: driver.php');
                }
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login &middot; UAM Philippines Inc. Logistics</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root {
        --orange-50:  #fff7ed;
        --orange-100: #ffedd5;
        --orange-200: #fed7aa;
        --orange-400: #fb923c;
        --orange-500: #f97316;
        --orange-600: #ea580c;
        --orange-700: #c2410c;
        --slate-900:  #0f172a;
        --slate-700:  #334155;
        --slate-500:  #64748b;
        --slate-300:  #cbd5e1;
        --white:      #ffffff;
        --danger:     #dc2626;
        --shadow-lg:  0 20px 40px -12px rgba(234, 88, 12, 0.35);
        --shadow-md:  0 10px 25px -8px rgba(15, 23, 42, 0.15);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    html, body {
        height: 100%;
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
        color: var(--slate-900);
    }

    body {
        display: grid;
        grid-template-columns: 1fr;
        background: var(--orange-50);
        min-height: 100vh;
    }

    @media (min-width: 960px) {
        body { grid-template-columns: 1.1fr 1fr; }
    }

    .brand-panel {
        position: relative;
        overflow: hidden;
        background: linear-gradient(135deg, var(--orange-600) 0%, var(--orange-500) 55%, var(--orange-400) 100%);
        color: var(--white);
        padding: 48px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-height: 320px;
    }

    .brand-panel::before {
        content: "";
        position: absolute;
        inset: 0;
        background-image:
            repeating-linear-gradient(115deg,
                rgba(255,255,255,0.07) 0 40px,
                transparent 40px 80px);
        pointer-events: none;
    }

    .brand-panel::after {
        content: "";
        position: absolute;
        width: 520px;
        height: 520px;
        right: -160px;
        bottom: -220px;
        border-radius: 50%;
        background: radial-gradient(circle at 30% 30%, rgba(255,255,255,0.28), transparent 65%);
        pointer-events: none;
    }

    .logo {
        display: flex;
        align-items: center;
        gap: 14px;
        position: relative;
        z-index: 1;
    }

    .logo-mark {
        width: 46px;
        height: 46px;
        border-radius: 12px;
        background: var(--white);
        display: grid;
        place-items: center;
        box-shadow: 0 6px 14px rgba(0,0,0,0.18);
    }

    .logo-mark svg { width: 26px; height: 26px; }

    .logo-text {
        font-weight: 800;
        font-size: 1.25rem;
        letter-spacing: -0.02em;
    }
    .logo-text span { opacity: 0.85; font-weight: 500; display: block; font-size: 0.72rem; letter-spacing: 0.14em; text-transform: uppercase; }

    .hero-copy {
        position: relative;
        z-index: 1;
        max-width: 520px;
    }

    .hero-copy h1 {
        font-size: clamp(1.9rem, 3.4vw, 3rem);
        font-weight: 800;
        line-height: 1.08;
        letter-spacing: -0.03em;
        margin-bottom: 16px;
    }

    .hero-copy p {
        font-size: 1.02rem;
        line-height: 1.6;
        opacity: 0.94;
        max-width: 44ch;
    }

    .feature-row {
        position: relative;
        z-index: 1;
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 28px;
    }

    .chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 14px;
        border-radius: 999px;
        background: rgba(255,255,255,0.14);
        backdrop-filter: blur(6px);
        border: 1px solid rgba(255,255,255,0.22);
        font-size: 0.83rem;
        font-weight: 500;
    }

    .chip svg { width: 15px; height: 15px; }

    .hero-footer {
        position: relative;
        z-index: 1;
        font-size: 0.78rem;
        opacity: 0.8;
        letter-spacing: 0.03em;
    }

    .form-panel {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px 24px;
        background:
            radial-gradient(1200px 600px at 100% 0%, var(--orange-100), transparent 60%),
            var(--orange-50);
    }

    .login-card {
        width: 100%;
        max-width: 420px;
        background: var(--white);
        border-radius: 20px;
        padding: 40px 36px 34px;
        box-shadow: var(--shadow-lg);
        border: 1px solid rgba(234, 88, 12, 0.10);
        animation: rise .5s cubic-bezier(.2,.8,.2,1) both;
    }

    @keyframes rise {
        from { opacity: 0; transform: translateY(14px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .form-panel h2 {
        font-size: 1.55rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        margin-bottom: 6px;
    }

    .form-panel .subtitle {
        color: var(--slate-500);
        font-size: 0.9rem;
        margin-bottom: 26px;
    }

    .field { margin-bottom: 18px; }

    .field label {
        display: block;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--slate-700);
        margin-bottom: 7px;
        letter-spacing: 0.01em;
    }

    .input-wrap {
        position: relative;
        display: flex;
        align-items: center;
    }

    .input-wrap .icon {
        position: absolute;
        left: 14px;
        width: 18px;
        height: 18px;
        color: var(--slate-500);
        pointer-events: none;
    }

    .input-wrap input {
        width: 100%;
        padding: 13px 14px 13px 42px;
        border: 1.5px solid var(--slate-300);
        border-radius: 11px;
        font-size: 0.95rem;
        font-family: inherit;
        color: var(--slate-900);
        background: #fbfdff;
        transition: border-color .18s, box-shadow .18s, background .18s;
    }

    .input-wrap input:focus {
        outline: none;
        border-color: var(--orange-500);
        background: var(--white);
        box-shadow: 0 0 0 4px rgba(249, 115, 22, 0.15);
    }

    .toggle-pass {
        position: absolute;
        right: 12px;
        background: none;
        border: none;
        cursor: pointer;
        color: var(--slate-500);
        padding: 4px;
        display: grid;
        place-items: center;
        border-radius: 6px;
    }
    .toggle-pass:hover { color: var(--orange-600); background: var(--orange-50); }
    .toggle-pass svg { width: 18px; height: 18px; }

    .row-between {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin: 4px 0 20px;
        font-size: 0.84rem;
    }

    .remember {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--slate-700);
        cursor: pointer;
        user-select: none;
    }
    .remember input { accent-color: var(--orange-600); width: 15px; height: 15px; }

    .link {
        color: var(--orange-600);
        text-decoration: none;
        font-weight: 600;
    }
    .link:hover { text-decoration: underline; }

    .btn-login {
        width: 100%;
        padding: 14px;
        border: none;
        border-radius: 11px;
        background: linear-gradient(135deg, var(--orange-500), var(--orange-600));
        color: var(--white);
        font-size: 0.98rem;
        font-weight: 700;
        font-family: inherit;
        letter-spacing: 0.01em;
        cursor: pointer;
        box-shadow: 0 10px 22px -8px rgba(234, 88, 12, 0.7);
        transition: transform .12s, box-shadow .18s, filter .18s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }

    .btn-login:hover  { filter: brightness(1.05); box-shadow: 0 14px 26px -8px rgba(234, 88, 12, 0.8); }
    .btn-login:active { transform: translateY(1px) scale(0.995); }
    .btn-login:disabled { opacity: .7; cursor: not-allowed; }

    .btn-login .spinner {
        width: 16px; height: 16px;
        border: 2px solid rgba(255,255,255,0.4);
        border-top-color: #fff;
        border-radius: 50%;
        animation: spin .7s linear infinite;
        display: none;
    }
    .btn-login.loading .spinner { display: inline-block; }
    .btn-login.loading .btn-text { opacity: .85; }

    @keyframes spin { to { transform: rotate(360deg); } }

    .alert {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 12px 14px;
        border-radius: 11px;
        font-size: 0.86rem;
        margin-bottom: 20px;
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: var(--danger);
        animation: shake .4s ease;
    }
    .alert svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }

    @keyframes shake {
        0%,100% { transform: translateX(0); }
        25% { transform: translateX(-4px); }
        75% { transform: translateX(4px); }
    }

    .card-footer {
        margin-top: 24px;
        text-align: center;
        font-size: 0.78rem;
        color: var(--slate-500);
    }

    @media (max-width: 480px) {
        .login-card { padding: 30px 22px 26px; border-radius: 16px; }
        .brand-panel { padding: 32px 24px; min-height: 260px; }
    }
</style>
</head>
<body>

    <aside class="brand-panel">
        <div class="logo">
            <div class="logo-mark">
                <svg viewBox="0 0 24 24" fill="none" stroke="#ea580c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 3h13v13H1z"/>
                    <path d="M14 8h4l3 3v5h-7"/>
                    <circle cx="5.5" cy="18.5" r="2"/>
                    <circle cx="17.5" cy="18.5" r="2"/>
                </svg>
            </div>
            <div class="logo-text">
                UAM Philippines Inc.
                <span>Logistics &amp; Delivery</span>
            </div>
        </div>

        <div class="hero-copy">
            <h1>Deliveries, tracked end&nbsp;to&nbsp;end.</h1>
            <p>Manage routes, drivers and parcels from a single dashboard. Fast, reliable, and built for teams on the move.</p>

            <div class="feature-row">
                <span class="chip">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                    Real-time tracking
                </span>
                <span class="chip">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M2 12h20"/></svg>
                    Route planning
                </span>
                <span class="chip">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/></svg>
                    Proof of delivery
                </span>
            </div>
        </div>

        <div class="hero-footer">&copy; <?= date('Y') ?> All rights reserved.</div>
    </aside>

    <main class="form-panel">
        <div class="login-card">
            <h2>Welcome back</h2>
            <p class="subtitle">Sign in to your account to continue.</p>

            <?php if ($error): ?>
                <div class="alert" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
                    </svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="index.php" id="loginForm" autocomplete="on" novalidate>
                <div class="field">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                        <input type="text" id="username" name="username"
                               value="<?= htmlspecialchars($username) ?>"
                               placeholder="Enter your username" required autofocus>
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        <input type="password" id="password" name="password"
                               placeholder="Enter your password" required>
                        <button type="button" class="toggle-pass" id="togglePass" aria-label="Show password">
                            <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="row-between">
                    <label class="remember">
                        <input type="checkbox" name="remember" value="1">
                        Remember me
                    </label>
                    <a href="#" class="link">Forgot password?</a>
                </div>

                <button type="submit" class="btn-login" id="loginBtn">
                    <span class="spinner"></span>
                    <span class="btn-text">Sign in</span>
                </button>
            </form>

            <p class="card-footer">Need access? Contact your administrator.</p>
        </div>
    </main>

<script>
    const toggleBtn = document.getElementById('togglePass');
    const passInput = document.getElementById('password');
    const eyeIcon   = document.getElementById('eyeIcon');

    toggleBtn.addEventListener('click', () => {
        const show = passInput.type === 'password';
        passInput.type = show ? 'text' : 'password';
        toggleBtn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        eyeIcon.innerHTML = show
            ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M1 1l22 22"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>'
            : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>';
    });

    const form = document.getElementById('loginForm');
    const btn  = document.getElementById('loginBtn');

    form.addEventListener('submit', (e) => {
        const u = document.getElementById('username').value.trim();
        const p = document.getElementById('password').value;
        if (!u || !p) {
            e.preventDefault();
            return;
        }
        btn.classList.add('loading');
        btn.disabled = true;
        btn.querySelector('.btn-text').textContent = 'Signing in…';
    });
</script>
</body>
</html>
