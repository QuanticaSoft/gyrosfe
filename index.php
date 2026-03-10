<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
auth_start_session();

if (!empty($_SESSION['user_id'])) {
    header('Location: /gyrosfe/ui/main.php');
} else {
    header('Location: /gyrosfe/ui/login.php');
}
exit;