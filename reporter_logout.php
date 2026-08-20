<?php
declare(strict_types=1);
require_once 'reporter_auth.php';

$_SESSION['reporter'] = null;
unset($_SESSION['reporter']);

header('Location: reporter_login.php');
exit;
?>