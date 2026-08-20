<?php
declare(strict_types=1);
require_once 'config.php';
require_once 'reporter_auth.php';

if (reporterLoggedIn()) {
    header('Location: reporter_index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        $stmt = db()->prepare("SELECT id, full_name, username, password_hash FROM reporters WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $r = $stmt->fetch();

        if ($r && password_verify($password, (string)$r['password_hash'])) {
            $_SESSION['reporter'] = [
                'id' => (int)$r['id'],
                'full_name' => (string)$r['full_name'],
                'username' => (string)$r['username'],
            ];
            header('Location: reporter_index.php');
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
<title><?= defined('APP_NAME') ? APP_NAME : 'DICOM Viewer' ?> — Reporter Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
body{margin:0;font-family:Inter,sans-serif;background:#0c111b;color:#e5e7eb;display:grid;place-items:center;min-height:100vh}
.card{width:min(420px,92vw);background:#111827;border:1px solid #243247;border-radius:14px;padding:24px}
h1{margin:0 0 8px;text-align:center} p{margin:0 0 18px;text-align:center;color:#94a3b8}
label{display:block;margin:10px 0 6px} input{width:100%;height:42px;border:1px solid #334155;background:#0b1220;color:#fff;border-radius:10px;padding:0 12px}
.row{display:flex;gap:10px;margin-top:16px} button{height:42px;padding:0 16px;border:0;border-radius:10px;background:#3b82f6;color:#fff;font-weight:700;cursor:pointer}
.err{background:#3a1319;color:#fecdd3;border:1px solid #7f1d1d;border-radius:8px;padding:8px 10px;margin-bottom:10px}
</style>
</head>
<body>
  <form method="POST" class="card">
    <h1>Reporter Login</h1>
    <p>Sign in to write and manage reports.</p>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <label>Username</label>
    <input type="text" name="username" required>

    <label>Password</label>
    <input type="password" name="password" required>

    <div class="row">
      <button type="submit">Login</button>
    </div>
  </form>
</body>
</html>