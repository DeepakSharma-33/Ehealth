<?php
require_once __DIR__ . '/../../config/config.php';
logout_user();
header('Location: executive_login.php');
exit;