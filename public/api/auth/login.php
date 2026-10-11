<?php

// Wspólne helpery odpowiedzi JSON i ochrony CSRF.
require_once __DIR__ . '/../../../app/json.php';
require_once __DIR__ . '/../../../app/csrf.php';

// Sprawdzenie czy zapytanie w formacie JSON
$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $contentType !== 'application/json') {
    header('Allow: POST');
    json_response(405, ['error' => 'Nieprawidłowe żądanie.']);
}

if (!csrf_start_session()) {
    error_log('Nie udało się uruchomić sesji podczas logowania.');
    json_response(500, ['error' => 'Nie udało się zalogować. Wystąpił problem z serwerem.']);
}

if (!csrf_verify(csrf_token_from_request())) {
    json_response(403, ['error' => 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.']);
}

// Zamiana danych JSON na obiekt stdClass
try {
    $data = json_decode(file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    json_response(400, ['error' => 'Nieprawidłowe żądanie.']);
}

if (!$data instanceof stdClass) {
    json_response(400, ['error' => 'Nieprawidłowe żądanie.']);
}

$email = isset($data->email) && is_string($data->email) ? trim($data->email) : '';
$password = $data->password ?? null;
$errors = [];

// Sprawdzenie poprawności danych logowania
if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $errors['email'] = 'Podaj prawidłowy adres e-mail.';
}

if (!is_string($password) || $password === '') {
    $errors['password'] = 'Podaj hasło jako niepusty ciąg znaków.';
}

if ($errors !== []) {
    json_response(422, ['error' => 'Nieprawidłowe dane logowania.', 'errors' => $errors]);
}

try {
    require __DIR__ . '/../../config.php';

    if ($bazaErr || session_status() !== PHP_SESSION_ACTIVE) {
        json_response(500, ['error' => 'Nie udało się zalogować. Wystąpił problem z serwerem.']);
    }

    $statement = $pdo->prepare(
        'SELECT id, haslo_hash, aktywny FROM uzytkownicy WHERE email = :email LIMIT 1'
    );

    $statement->execute(['email' => $email]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['haslo_hash']) || (int) $user['aktywny'] !== 1) {
        json_response(401, ['error' => 'Nieprawidłowy e-mail lub hasło.']);
    }

    if (!session_regenerate_id(true)) {
        json_response(500, ['error' => 'Nie udało się zalogować. Wystąpił problem z serwerem.']);
    }

    $_SESSION['user_id'] = (int) $user['id'];
    csrf_rotate_token();

    json_response(200, ['success' => true, 'user_id' => $_SESSION['user_id']]);

} catch (Throwable $e) {
    error_log('Login endpoint: ' . $e->getMessage());
    json_response(500, ['error' => 'Nie udało się zalogować. Wystąpił problem z serwerem.']);
}
