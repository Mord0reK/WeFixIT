<?php
declare(strict_types=1);

/*
    Endpoint wylogowania.

    Kończy sesję i wygasza cookie sesyjne. Sesję oraz CSRF obsługuje wspólny
    moduł auth, żeby nie duplikować logiki czyszczenia.
*/
require_once __DIR__ . '/../../../app/json.php';
require_once __DIR__ . '/../../../app/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_response(405, ['error' => 'Nieprawidłowe żądanie.']);
}

if (!session_start_secure()) {
    error_log('Nie udało się uruchomić sesji podczas wylogowania.');
    json_response(500, ['error' => 'Nie udało się wylogować. Wystąpił problem z serwerem.']);
}

if (!csrf_verify(csrf_token_from_request())) {
    json_response(403, ['error' => 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.']);
}

if (!auth_logout_user()) {
    json_response(500, ['error' => 'Nie udało się wylogować. Wystąpił problem z serwerem.']);
}

json_response(200, ['success' => true]);
