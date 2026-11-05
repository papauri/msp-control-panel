<?php
require_once __DIR__ . '/../app/bootstrap.php';
web_boot();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['admin_id'])) {
    header('Location: ' . (empty($_SESSION['admin_id']) ? 'login.php' : 'index.php'));
    exit;
}
csrf_require();
audit_log(db(), (int)$_SESSION['admin_id'], 'logout', null, null, null);
auth_destroy_session();
header('Location: login.php');
exit;
