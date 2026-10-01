<?php

if (session_status() !== PHP_SESSION_ACTIVE && !@session_start()) {
    error_log('Nie udało się uruchomić sesji na chronionej stronie.');
    http_response_code(500);
    exit('Nie udało się uruchomić sesji.');
}

header('Cache-Control: no-store');

$userId = $_SESSION['user_id'] ?? null;

if (!is_int($userId) || $userId <= 0) {
    unset($_SESSION['user_id']);

    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    header('Location: ' . $basePath . '/login');
    exit;
}
