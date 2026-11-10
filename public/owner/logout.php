<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Services\OwnerAuth;

bbiz_send_security_headers(false);
OwnerAuth::logoutOwner();
header('Location: /owner/login.php?logged_out=1');
exit;
