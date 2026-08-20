<?php
declare(strict_types=1);
require_once 'config.php';

$error = '';
$success = '';
$createdUser = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role             = trim((string)($_POST['role'] ?? ''));
    $fullName         = trim((string)($_POST['full_name'] ?? ''));
    $username         = strtolower(trim((string)($_POST['username'] ?? '')));
    $password         = (string)($_POST['password'] ?? '');
    $confirmPassword  = (string)($_POST['confirm_password'] ?? '');

    if (!in_array($role, ['doctor', 'reporter'], true)) {
        $error = 'Please select a valid role.';
    } elseif ($fullName === '' || $username === '' || $password === '' || $confirmPassword === '') {
        $error = 'All fields are required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $username)) {
        $error = 'Username must be 3-30 chars and use letters, numbers, dot, underscore or dash.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Password and Confirm Password do not match.';
    } else {
        try {
            $table = $role === 'doctor' ? 'doctors' : 'reporters';

            // Prevent duplicates inside selected role table
            $chk = db()->prepare("SELECT id FROM {$table} WHERE username = ? LIMIT 1");
            $chk->execute([$username]);
            if ($chk->fetch()) {
                throw new RuntimeException('Username already exists in selected role.');
            }

            $stmt = db()->prepare("
                INSERT INTO {$table} (full_name, username, password_hash)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$fullName, $username, password_hash($password, PASSWORD_DEFAULT)]);

            $success = ucfirst($role) . ' registered successfully.';

            $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $scheme = $isHttps ? 'https://' : 'http://';
            $basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            $loginPage = $role === 'doctor' ? 'login.php' : 'reporter_login.php';

            $createdUser = [
                'role' => $role,
                'full_name' => $fullName,
                'username' => $username,
                'plain_password' => $password, // shown once for admin sharing
                'login_url' => $scheme . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $basePath . '/' . $loginPage
            ];
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Registration failed. Please check data and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title><?= APP_NAME ?> — Register User</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0c111b; --panel:#111827; --panel2:#0f172a; --text:#e5e7eb; --muted:#94a3b8;
  --primary:#3b82f6; --primaryH:#2563eb; --border:#243247; --dangerBg:#3a1319; --dangerText:#fecdd3;
  --okBg:#0f2f1f; --okText:#bbf7d0;
}
*{box-sizing:border-box}
body{margin:0;font-family:Inter,sans-serif;color:var(--text);background:radial-gradient(1000px 400px at 50% -120px, rgba(59,130,246,.2), transparent 60%), var(--bg);min-height:100vh;display:grid;place-items:center;padding:20px}
.wrap{width:100%;max-width:600px}
.card{background:linear-gradient(180deg,var(--panel),var(--panel2));border:1px solid var(--border);border-radius:16px;padding:24px;box-shadow:0 20px 50px rgba(2,8,23,.4)}
h1{margin:0 0 8px;text-align:center;font-size:32px}
.sub{margin:0 0 22px;text-align:center;color:var(--muted)}
.alert{padding:10px 12px;border-radius:10px;margin-bottom:12px;font-size:14px}
.alert.err{background:var(--dangerBg);color:var(--dangerText);border:1px solid rgba(248,113,113,.35)}
.alert.ok{background:var(--okBg);color:var(--okText);border:1px solid rgba(34,197,94,.35)}
.field{margin-bottom:12px}
label{display:block;margin-bottom:6px;font-size:14px;color:#cbd5e1;font-weight:600}
input,select{width:100%;height:44px;border:1px solid #334155;border-radius:10px;background:#0b1220;color:#f8fafc;padding:0 12px;font-size:15px;outline:none}
input:focus,select:focus{border-color:#60a5fa;box-shadow:0 0 0 3px rgba(96,165,250,.2)}
.input-wrap{position:relative}
.eye{position:absolute;right:10px;top:50%;transform:translateY(-50%);border:0;background:transparent;color:#93c5fd;cursor:pointer;font-size:13px;font-weight:700}
.row{display:flex;gap:10px;margin-top:16px;flex-wrap:wrap}
.btn{height:42px;padding:0 16px;border-radius:10px;border:1px solid transparent;font-weight:600;font-size:15px;cursor:pointer}
.btn.primary{background:var(--primary);color:#fff}
.btn.primary:hover{background:var(--primaryH)}
.summary{margin-top:16px;padding:14px;border-radius:12px;background:#0b1322;border:1px dashed #3b82f6}
.summary h3{margin:0 0 10px;font-size:16px;color:#bfdbfe}
.summary p{margin:6px 0;font-size:14px}
.k{color:#93c5fd;font-weight:600}
.copy-btn{margin-top:10px;background:#1e293b;color:#e2e8f0;border:1px solid #334155;padding:8px 10px;border-radius:8px;cursor:pointer}
.note{font-size:12px;color:#94a3b8;margin-top:8px}
#pwdMatchMsg{margin-top:6px;font-size:12px;color:#94a3b8}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>User Registration</h1>
    <p class="sub">Create Doctor or Reporter account</p>

    <?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <form method="POST" id="regForm" autocomplete="off">
      <div class="field">
        <label for="role">User Type *</label>
        <select id="role" name="role" required>
          <option value="">Select user type...</option>
          <option value="doctor" <?= (($_POST['role'] ?? '') === 'doctor') ? 'selected' : '' ?>>Doctor</option>
          <option value="reporter" <?= (($_POST['role'] ?? '') === 'reporter') ? 'selected' : '' ?>>Reporter</option>
        </select>
      </div>

      <div class="field">
        <label for="full_name">Full Name *</label>
        <input id="full_name" name="full_name" type="text" required value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
      </div>

      <div class="field">
        <label for="username">Username *</label>
        <input id="username" name="username" type="text" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="e.g. dr_ahmed">
      </div>

      <div class="field">
        <label for="password">Password *</label>
        <div class="input-wrap">
          <input id="password" name="password" type="password" required minlength="6">
          <button type="button" class="eye" data-target="password">Show</button>
        </div>
      </div>

      <div class="field">
        <label for="confirm_password">Confirm Password *</label>
        <div class="input-wrap">
          <input id="confirm_password" name="confirm_password" type="password" required minlength="6">
          <button type="button" class="eye" data-target="confirm_password">Show</button>
        </div>
        <div id="pwdMatchMsg"></div>
      </div>

      <div class="row">
        <button type="submit" class="btn primary">Create User</button>
      </div>
    </form>

    <?php if ($createdUser): ?>
      <div class="summary" id="loginSummary">
        <h3>Login Summary (Send to <?= htmlspecialchars(ucfirst($createdUser['role'])) ?>)</h3>
        <p><span class="k">Role:</span> <?= htmlspecialchars(ucfirst($createdUser['role'])) ?></p>
        <p><span class="k">Name:</span> <?= htmlspecialchars($createdUser['full_name']) ?></p>
        <p><span class="k">Login URL:</span> <?= htmlspecialchars($createdUser['login_url']) ?></p>
        <p><span class="k">Username:</span> <?= htmlspecialchars($createdUser['username']) ?></p>
        <p><span class="k">Password:</span> <?= htmlspecialchars($createdUser['plain_password']) ?></p>
        <button type="button" class="copy-btn" id="copySummary">Copy Summary</button>
        <div class="note">This password is shown once. Share securely.</div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
const passwordEl = document.getElementById('password');
const confirmEl = document.getElementById('confirm_password');
const msgEl = document.getElementById('pwdMatchMsg');
const formEl = document.getElementById('regForm');

function setMsg(text, color) {
  msgEl.textContent = text;
  msgEl.style.color = color;
}
function validateMatchLive() {
  const p = passwordEl.value || '';
  const c = confirmEl.value || '';
  if (!c.length) {
    setMsg('', '#94a3b8');
    confirmEl.setCustomValidity('');
    return;
  }
  if (p === c) {
    setMsg('✓ Passwords match', '#22c55e');
    confirmEl.setCustomValidity('');
  } else {
    setMsg('✗ Passwords do not match', '#ef4444');
    confirmEl.setCustomValidity('Passwords do not match');
  }
}
passwordEl.addEventListener('input', validateMatchLive);
confirmEl.addEventListener('input', validateMatchLive);

formEl.addEventListener('submit', (e) => {
  validateMatchLive();
  if (!formEl.checkValidity()) {
    e.preventDefault();
    formEl.reportValidity();
  }
});

document.querySelectorAll('.eye').forEach(btn => {
  btn.addEventListener('click', () => {
    const input = document.getElementById(btn.dataset.target);
    if (!input) return;
    const isPwd = input.type === 'password';
    input.type = isPwd ? 'text' : 'password';
    btn.textContent = isPwd ? 'Hide' : 'Show';
  });
});

const copyBtn = document.getElementById('copySummary');
if (copyBtn) {
  copyBtn.addEventListener('click', async () => {
    const block = document.getElementById('loginSummary');
    const text = block.innerText.replace('Copy Summary', '').trim();
    try {
      await navigator.clipboard.writeText(text);
      copyBtn.textContent = 'Copied!';
      setTimeout(() => copyBtn.textContent = 'Copy Summary', 1300);
    } catch {
      alert('Could not copy automatically. Please copy manually.');
    }
  });
}
</script>
</body>
</html>