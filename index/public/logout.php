<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

auth_logout();

header('Location: login.php');
exit;
