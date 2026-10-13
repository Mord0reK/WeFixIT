<?php
declare(strict_types=1);

/*
    Endpoint logowania.

    Weryfikuje dane, uruchamia sesję i zwraca rolę użytkownika, żeby frontend
    wybrał panel właściwy dla tej roli. Komunikat błędu nie ujawnia, czy podany
    e-mail istnieje.
*/
require_once __DIR__ . '/../../../app/json.php';
require_once __DIR__ . '/../../../app/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_response(405, ['error' => 'Nieprawidłowe żądanie.']);
}

// Sprawdzenie czy zapytanie w formacie JSON
$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));

if ($contentType !== 'application/json') {
    json_response(415, ['error' => 'Oczekiwano zawartości w formacie JSON.']);
}

if (!session_start_secure()) {
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
    if ($bazaErr || !isset($pdo)) {
        json_response(500, ['error' => 'Nie udało się zalogować. Wystąpił problem z serwerem.']);
    }

    $statement = $pdo->prepare(
        'SELECT id, haslo_hash, rola, aktywny FROM uzytkownicy WHERE email = :email LIMIT 1'
    );

    $statement->execute(['email' => $email]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);

    // Ten sam komunikat dla nieistniejącego e-maila, złego hasła i nieaktywnego konta.
    if (!is_array($user) || !password_verify($password, $user['haslo_hash']) || (int) $user['aktywny'] !== 1) {
        json_response(401, ['error' => 'Nieprawidłowy e-mail lub hasło.']);
    }

    if (!auth_login_user((int) $user['id'])) {
        json_response(500, ['error' => 'Nie udało się zalogować. Wystąpił problem z serwerem.']);
    }

    json_response(200, [
        'success' => true,
        'user_id' => (int) $user['id'],
        'rola' => (string) $user['rola'],
    ]);
} catch (Throwable $e) {
    error_log('Login endpoint: ' . $e->getMessage());
    json_response(500, ['error' => 'Nie udało się zalogować. Wystąpił problem z serwerem.']);
}
