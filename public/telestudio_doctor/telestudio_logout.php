<?php
require_once __DIR__ . '/../../config/config.php';
logout_user();
header('Location: telestudio_doctor_login.php');
exit;