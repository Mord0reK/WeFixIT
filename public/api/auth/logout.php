<?php

// Wspólne helpery odpowiedzi JSON i ochrony CSRF.
require_once __DIR__ . '/../../../app/json.php';
require_once __DIR__ . '/../../../app/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_response(405, ['error' => 'Nieprawidłowe żądanie.']);
}

if (!csrf_start_session()) {
    error_log('Nie udało się uruchomić sesji podczas wylogowania.');
    json_response(500, ['error' => 'Nie udało się wylogować. Wystąpił problem z serwerem.']);
}

if (!csrf_verify(csrf_token_from_request())) {
    json_response(403, ['error' => 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.']);
}

$_SESSION = [];

if (!@session_destroy()) {
    error_log('Nie udało się usunąć sesji podczas wylogowania.');
    json_response(500, ['error' => 'Nie udało się wylogować. Wystąpił problem z serwerem.']);
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

json_response(200, ['success' => true]);
