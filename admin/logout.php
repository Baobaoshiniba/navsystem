<?php
/**
 * 后台 - 退出登录
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';

secure_session_start();
$_SESSION = [];
session_destroy();
redirect('login.php');
