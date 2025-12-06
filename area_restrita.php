<?php
// area_restrita.php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Restricted Area</title>
</head>
<body>
    <h1>Welcome, <?= htmlspecialchars($_SESSION['user_email'], ENT_QUOTES, 'UTF-8'); ?>!</h1>
    <p>Login completed successfully.</p>
    <p><a href="logout.php">Sign out</a></p>
</body>
</html>
