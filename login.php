<?php
// =====================================================================
// Operator login (D5 Users). Passwords are checked with password_verify().
// =====================================================================

require __DIR__ . '/lib/auth.php';

if (current_user() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $user = db_one($conn, 'SELECT * FROM users WHERE username = ?', 's', [$username]);
    if ($user !== null && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);           // new session id after login
        $_SESSION['username'] = $user['username'];
        db_exec($conn, 'UPDATE users SET last_login = NOW() WHERE id = ?', 'i', [$user['id']]);
        header('Location: index.php');
        exit;
    }
    usleep(300000);                            // slow down password guessing a little
    $error = 'Wrong username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Smart Bin - Login</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-page">
  <form class="login-box" method="post" autocomplete="on">
    <div class="brand big"><span class="logo">♻</span> Smart Bin</div>
    <p class="muted">Operator dashboard</p>
    <?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <label>Username <input name="username" required autofocus value="<?= e($_POST['username'] ?? '') ?>"></label>
    <label>Password <input name="password" type="password" required></label>
    <button class="btn primary" type="submit">Log in</button>
  </form>
</body>
</html>
