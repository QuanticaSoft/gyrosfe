<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db_connect.php';
require_once __DIR__ . '/../lib/auth.php';

function esc(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

auth_start_session();

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $email = strtolower(trim((string) ($_POST['email'] ?? '')));
  $pass = (string) ($_POST['password'] ?? '');

  if ($email === '' || $pass === '') {
    $error = 'Email y password son requeridos.';
  } else {
    try {
      $pdo = db_connect();
      $st = $pdo->prepare('SELECT id, email, pass_hash, role, is_active, full_name FROM "User" WHERE email = :e');
      $st->execute([':e' => $email]);
      $u = $st->fetch(PDO::FETCH_ASSOC);

    if (!$u || !$u['is_active'] || !password_verify($pass, (string) $u['pass_hash'])) {
        $error = 'Credenciales inválidas.';
      } else {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $u['id'];
        $_SESSION['email'] = (string) $u['email'];
        $_SESSION['role'] = (string) $u['role'];
        $_SESSION['full_name'] = (string) ($u['full_name'] ?? '');
        header('Location: /gyrosfe/ui/main.php');   //dashboard.php');
        exit;
      }
    } catch (Throwable $e) {
      $error = 'Error interno.';
    }
  }
}

header('Content-Type: text/html; charset=utf-8');
?>


<!doctype html>
<html lang="es">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/gyrosfe/assets/app.css?v=1">

</head>


<body>

  <div class="card">
    <h2>Gyros</h2>

    <div class="spacer"></div>


    <form method="post" novalidate>
      <label>Email</label>
      <input type="email" name="email" required autocomplete="username">

      <label>Password</label>
      <input type="password" name="password" required autocomplete="current-password">

      <div class="actions">
        <button type="submit">Login</button>
      </div>
    </form>

    <div class="small">Acceso restringido.</div>

    <?php if (!empty($error)): ?>
      <div class="err"><?= esc($error) ?></div>
    <?php endif; ?>
  </div>


</body>

</html>
