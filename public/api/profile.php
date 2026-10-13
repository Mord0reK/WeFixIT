<?php
declare(strict_types=1);

/*
    Endpoint PATCH /api/profile.

    Aktualizuje cztery pola własnego profilu zalogowanego użytkownika.
    Tożsamość pochodzi wyłącznie z sesji, więc pola id, rola i aktywny w body
    są ignorowane. Nie ma parametru wskazującego inne konto.
*/
require_once __DIR__ . '/../../app/json.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/users.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    header('Allow: PATCH');
    json_response(405, ['error' => 'Nieprawidłowe żądanie.']);
}

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));

if ($contentType !== 'application/json') {
    json_response(415, ['error' => 'Oczekiwano zawartości w formacie JSON.']);
}

// Najpierw authentication (401), potem CSRF (403), żeby brak sesji był rozróżnialny.
$user = require_api_login();

if (!csrf_verify(csrf_token_from_request())) {
    json_response(403, ['error' => 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.']);
}

try {
    $data = json_decode(file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    json_response(400, ['error' => 'Nieprawidłowe żądanie.']);
}

if (!$data instanceof stdClass) {
    json_response(400, ['error' => 'Nieprawidłowe żądanie.']);
}

$dataArray = (array) $data;
$errors = user_profile_errors($dataArray);

if ($errors !== []) {
    json_response(422, ['error' => 'Nieprawidłowe dane profilu.', 'errors' => $errors]);
}

$userId = auth_user_id();
$email = user_field($dataArray, 'email');

if ($userId === null) {
    json_response(401, ['error' => 'Wymagane zalogowanie.']);
}

$existing = user_find_by_email($email);

if (is_array($existing) && (int) $existing['id'] !== $userId) {
    json_response(409, [
        'error' => 'Ten adres e-mail jest już zajęty.',
        'errors' => ['email' => 'Ten adres e-mail jest już zajęty.'],
    ]);
}

try {
    if (!user_update_profile($userId, $dataArray)) {
        json_response(500, ['error' => 'Nie udało się zapisać profilu. Wystąpił problem z serwerem.']);
    }
} catch (PDOException $e) {
    // Unikalność e-maila rozstrzyga ostatecznie baza, gdy dwa żądania dotrą równocześnie.
    if ($e->getCode() === '23000') {
        json_response(409, [
            'error' => 'Ten adres e-mail jest już zajęty.',
            'errors' => ['email' => 'Ten adres e-mail jest już zajęty.'],
        ]);
    }

    error_log('Profile endpoint: ' . $e->getMessage());
    json_response(500, ['error' => 'Nie udało się zapisać profilu. Wystąpił problem z serwerem.']);
} catch (Throwable $e) {
    error_log('Profile endpoint: ' . $e->getMessage());
    json_response(500, ['error' => 'Nie udało się zapisać profilu. Wystąpił problem z serwerem.']);
}

$fresh = user_find_by_id($userId);

if (!is_array($fresh)) {
    json_response(500, ['error' => 'Nie udało się zapisać profilu. Wystąpił problem z serwerem.']);
}

json_response(200, [
    'success' => true,
    'user' => [
        'id' => (int) $fresh['id'],
        'imie' => (string) $fresh['imie'],
        'nazwisko' => (string) $fresh['nazwisko'],
        'email' => (string) $fresh['email'],
        'telefon' => (string) $fresh['telefon'],
        'rola' => (string) $fresh['rola'],
    ],
]);
