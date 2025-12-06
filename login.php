<?php
// MOSTRAR TODOS OS ERROS NA TELA
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// TENTA CARREGAR O CONFIG.PHP
$arquivoConfig = __DIR__ . '/config.php';
if (!file_exists($arquivoConfig)) {
    die("<p style='color:red'>ERROR: config.php not found at " . htmlspecialchars($arquivoConfig) . "</p>");
}

require_once $arquivoConfig;

if (!isset($conn) || $conn->connect_error) {
    die("<p style='color:red'>Database connection error.</p>");
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $senha = isset($_POST['senha']) ? (string)$_POST['senha'] : '';

    if ($email === '' || $senha === '') {
        $erro = 'Please enter email and password.';
    } else {
        $sql = "SELECT id, email, password_hash FROM " . DB_TABLE . " WHERE email = ? LIMIT 1";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('s', $email);
            if ($stmt->execute()) {
                $stmt->bind_result($id, $email_db, $hash);
                if ($stmt->fetch()) {
                    $hash = (string)$hash;
                    $valid = false;
                    if ($hash !== '' && strpos($hash, '$') === 0) {
                        $valid = password_verify($senha, $hash);
                    }
                    if (!$valid) {
                        $valid = hash_equals($hash, $senha);
                    }

                    if ($valid) {
                        $_SESSION['user_id'] = (int)$id;
                        $_SESSION['user_email'] = (string)$email_db;
                        header('Location: dashboard.php');
                        exit;
                    } else {
                        $erro = 'Invalid credentials.';
                    }
                } else {
                    $erro = 'User not found.';
                }
            } else {
                $erro = 'Failed to execute query: ' . htmlspecialchars($stmt->error, ENT_QUOTES, 'UTF-8');
            }
            $stmt->close();
        } else {
            $erro = 'Failed to prepare query: ' . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Catalog Seller - Login</title>
    <style>
        :root { --bg:#0f172a; --card:#0b1220; --muted:#94a3b8; --text:#e2e8f0; --pri:#22c55e; --pri2:#16a34a; --danger:#ef4444; }
        * { box-sizing:border-box; }
        body { margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji", "Segoe UI Emoji"; background: radial-gradient(1200px 800px at 80% -10%, #1e293b, transparent), var(--bg); color: var(--text); min-height: 100vh; display:flex; align-items:center; justify-content:center; }
        .card { width:100%; max-width: 400px; background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02)); border:1px solid rgba(148,163,184,.2); border-radius:16px; padding:28px; box-shadow: 0 20px 40px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.06); backdrop-filter: blur(6px);
        }
        .title { margin:0 0 8px; font-size: 22px; font-weight:700; }
        .subtitle { margin:0 0 22px; color: var(--muted); font-size: 14px; }
        label { display:block; font-size:13px; color: var(--muted); margin:14px 0 6px; }
        input[type=email], input[type=password] { width:100%; padding:12px 14px; border-radius:10px; border:1px solid rgba(148,163,184,.25); background:#0b1220; color:var(--text); outline:none; transition: border-color .2s, box-shadow .2s; }
        input:focus { border-color:#60a5fa; box-shadow: 0 0 0 3px rgba(59,130,246,.25); }
        .actions { display:flex; align-items:center; justify-content:space-between; margin-top:18px; }
        button { appearance:none; border:0; background: linear-gradient(180deg, var(--pri), var(--pri2)); color:#052e16; font-weight:700; padding:10px 16px; border-radius:10px; cursor:pointer; box-shadow: 0 8px 16px rgba(34,197,94,.25); transition: transform .05s ease, filter .2s; }
        button:active { transform: translateY(1px); }
        .error { margin-top:10px; color: var(--danger); font-size: 14px; }
        .footer { margin-top:18px; font-size:12px; color:var(--muted); text-align:center; }
        .logo { display:block; margin: 0 auto 22px; max-width: 240px; height:auto; background:#ffffff; padding:12px; border-radius:12px; border:1px solid rgba(148,163,184,.25); box-shadow: 0 6px 14px rgba(0,0,0,.25); }
    </style>
    <?php /* Keep error_reporting enabled while developing */ ?>
    <?php if ($erro): ?>
    <meta http-equiv="prefers-reduced-data" content="off" />
    <?php endif; ?>
    </head>
<body>
    <main class="card" role="main" aria-label="Login form">
        <img src="logo.png" alt="Catalog Seller logo" class="logo">
        <h1 class="title">Sign in</h1>
        <p class="subtitle">Enter your credentials to continue</p>
        <?php if ($erro): ?>
            <div class="error" role="alert"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="on" novalidate>
            <label for="email">Email</label>
            <input id="email" type="email" name="email" required placeholder="your@email.com" value="<?= isset($email) ? htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : '' ?>">

            <label for="senha">Password</label>
            <input id="senha" type="password" name="senha" required placeholder="••••••••">

            <div class="actions">
                <span class="footer">Forgot your password? Contact the administrator.</span>
                <button type="submit">Sign in</button>
            </div>
        </form>
    </main>
</body>
</html>
