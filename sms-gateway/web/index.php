<?php
require_once __DIR__ . '/inc/bootstrap.php';
header('Location: ' . (current_user_id() ? 'dashboard.php' : 'login.php'));
