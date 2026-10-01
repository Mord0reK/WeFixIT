<?php

function logoutJsonResponse(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    logoutJsonResponse(405, ['error' => 'Nieprawidłowe żądanie.']);
}

if (session_status() !== PHP_SESSION_ACTIVE && !@session_start()) {
    error_log('Nie udało się uruchomić sesji podczas wylogowania.');
    logoutJsonResponse(500, ['error' => 'Nie udało się wylogować. Wystąpił problem z serwerem.']);
}

$_SESSION = [];

if (!@session_destroy()) {
    error_log('Nie udało się usunąć sesji podczas wylogowania.');
    logoutJsonResponse(500, ['error' => 'Nie udało się wylogować. Wystąpił problem z serwerem.']);
}

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
}

logoutJsonResponse(200, ['success' => true]);
