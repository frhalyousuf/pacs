<?php
declare(strict_types=1);
require_once 'config.php';
require_once 'auth.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        $stmt = db()->prepare("
            SELECT id, full_name, username, password_hash
            FROM doctors
            WHERE username = ?
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $doctor = $stmt->fetch();

        if ($doctor && password_verify($password, (string)$doctor['password_hash'])) {
            $_SESSION['doctor'] = [
                'id' => (int)$doctor['id'],
                'full_name' => (string)$doctor['full_name'],
                'username' => (string)$doctor['username'],
            ];
            header('Location: index.php');
            exit;
        }

        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title><?= APP_NAME ?> — Doctor Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0c111b; --panel:#111827; --panel-2:#0f172a; --text:#e5e7eb; --muted:#94a3b8;
  --primary:#3b82f6; --primary-hover:#2563eb; --border:#243247; --danger-bg:#3a1319; --danger-text:#fecdd3;
  --shadow:0 20px 60px rgba(2,8,23,.45);
}
*{box-sizing:border-box}
body{margin:0;font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:var(--text);background:radial-gradient(1200px 500px at 50% -100px, rgba(59,130,246,.16), transparent 60%),var(--bg);min-height:100vh;display:grid;place-items:center;padding:24px}
.auth-wrap{width:100%;max-width:420px}
.auth-card{background:linear-gradient(180deg,var(--panel),var(--panel-2));border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow);padding:28px}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:10px;color:#bfdbfe;font-weight:700;font-size:14px;letter-spacing:.3px;text-transform:uppercase}
.brand-dot{width:10px;height:10px;border-radius:50%;background:var(--primary);box-shadow:0 0 14px rgba(59,130,246,.8)}
h1{margin:0;font-size:34px;line-height:1.1;font-weight:800;letter-spacing:-.5px;text-align:center}
.sub{margin:10px 0 24px;text-align:center;color:var(--muted);font-size:18px}
.alert{border:1px solid rgba(248,113,113,.35);background:var(--danger-bg);color:var(--danger-text);border-radius:10px;padding:10px 12px;margin-bottom:14px;font-size:14px}
.field{margin-bottom:14px}
label{display:block;margin-bottom:7px;font-size:14px;color:#cbd5e1;font-weight:600}
label span{color:#93c5fd}
.input{width:100%;height:44px;border:1px solid #334155;background:#0b1220;color:#f8fafc;border-radius:10px;padding:0 12px;font-size:15px;outline:none;transition:.2s border-color,.2s box-shadow}
.input::placeholder{color:#64748b}
.input:focus{border-color:#60a5fa;box-shadow:0 0 0 3px rgba(96,165,250,.2)}
.row{display:flex;gap:10px;margin-top:18px}
.btn{height:42px;padding:0 18px;border-radius:10px;font-size:16px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;cursor:pointer}
.btn-primary{background:var(--primary);color:white}
.btn-primary:hover{background:var(--primary-hover)}
.btn-ghost{background:transparent;color:#a5b4fc;border-color:#334155}
.btn-ghost:hover{border-color:#475569;color:#c7d2fe}
.footer-note{margin-top:14px;text-align:center;color:#64748b;font-size:12px}
</style>
</head>
<body>
  <div class="auth-wrap">
    <div class="auth-card">
      <div class="brand"><span class="brand-dot"></span> <?= htmlspecialchars((string)APP_NAME) ?></div>
      <h1>Doctor Login</h1>
      <div class="sub">Login to view your patients.</div>

      <?php if ($error): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" autocomplete="on">
        <div class="field">
          <label for="username">Username <span>*</span></label>
          <input class="input" id="username" name="username" type="text" placeholder="doctor_username" required>
        </div>

        <div class="field">
          <label for="password">Password <span>*</span></label>
          <input class="input" id="password" name="password" type="password" placeholder="••••••••" required>
        </div>

        <div class="row">
          <button type="submit" class="btn btn-primary">Login</button>
          <a href="register.php" class="btn btn-ghost">Register</a>
        </div>
      </form>

      <div class="footer-note">Secure access for authorized doctors only</div>
    </div>
  </div>
</body>
</html>