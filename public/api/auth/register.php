<?php
declare(strict_types=1);

/*
    Endpoint rejestracji konta klienta.

    Waliduje dane po stronie serwera, tworzy konto z rolą ustaloną przez serwer
    i od razu loguje nowego użytkownika, więc trafia na panel.
*/
require_once __DIR__ . '/../../../app/json.php';
require_once __DIR__ . '/../../../app/csrf.php';
require_once __DIR__ . '/../../../app/session.php';
require_once __DIR__ . '/../../../app/users.php';

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
    error_log('Nie udało się uruchomić sesji podczas rejestracji.');
    json_response(500, ['error' => 'Nie udało się zarejestrować konta. Wystąpił problem z serwerem.']);
}

if (!csrf_verify(csrf_token_from_request())) {
    json_response(403, ['error' => 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.']);
}

// Zamiana danych JSON na obiekt stdClass
try {
    $data = json_decode(file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    json_response(400, ['error' => 'Nieprawidłowe żądanie.']);
}

if (!$data instanceof stdClass) {
    json_response(400, ['error' => 'Nieprawidłowe żądanie.']);
}

$dataArray = (array) $data;

$imie = user_field($dataArray, 'imie');
$nazwisko = user_field($dataArray, 'nazwisko');
$email = user_field($dataArray, 'email');
$telefon = user_field($dataArray, 'telefon');
$password = user_field($dataArray, 'password', false);
$passwordPowtorzenie = user_field($dataArray, 'password_powtorzenie', false);

$errors = user_profile_errors($dataArray);

// Hasła obsługiwane są przez bcrypt, który ignoruje wszystko po 72 bajtach.
if ($password === '' || strlen($password) < 8) {
    $errors['password'] = 'Hasło musi mieć co najmniej 8 znaków.';
} elseif (strlen($password) > 72) {
    $errors['password'] = 'Hasło może mieć maksymalnie 72 znaki.';
} elseif ($passwordPowtorzenie !== $password) {
    $errors['password_powtorzenie'] = 'Powtórzone hasło różni się od hasła.';
}

if ($errors !== []) {
    json_response(422, ['error' => 'Nieprawidłowe dane rejestracji.', 'errors' => $errors]);
}

try {
    require_once __DIR__ . '/../../config.php';

    if ($bazaErr || session_status() !== PHP_SESSION_ACTIVE) {
        json_response(500, ['error' => 'Nie udało się zarejestrować konta. Wystąpił problem z serwerem.']);
    }

    if (user_find_by_email($email) !== null) {
        json_response(409, [
            'error' => 'Konto z tym adresem e-mail już istnieje.',
            'errors' => ['email' => 'Konto z tym adresem e-mail już istnieje.'],
        ]);
    }

    // Rolę ustala serwer, klient nie może zgłosić się jako pracownik ani administrator.
    $rejestracja = $pdo->prepare(
        'INSERT INTO uzytkownicy (imie, nazwisko, email, telefon, haslo_hash, rola)
         VALUES (:imie, :nazwisko, :email, :telefon, :haslo_hash, :rola)'
    );

    try {
        $rejestracja->execute([
            'imie' => $imie,
            'nazwisko' => $nazwisko,
            'email' => $email,
            'telefon' => $telefon,
            'haslo_hash' => password_hash($password, PASSWORD_DEFAULT),
            'rola' => 'klient',
        ]);
    } catch (PDOException $e) {
        // Unikalność e-maila rozstrzyga ostatecznie baza, gdy dwa żądania dotrą równocześnie.
        if ($e->getCode() === '23000') {
            json_response(409, [
                'error' => 'Konto z tym adresem e-mail już istnieje.',
                'errors' => ['email' => 'Konto z tym adresem e-mail już istnieje.'],
            ]);
        }

        throw $e;
    }

    $userId = (int) $pdo->lastInsertId();

    if (!session_regenerate_id(true)) {
        json_response(500, ['error' => 'Nie udało się zarejestrować konta. Wystąpił problem z serwerem.']);
    }

    $_SESSION['user_id'] = $userId;
    csrf_rotate_token();

    json_response(201, ['success' => true, 'user_id' => $userId]);

} catch (Throwable $e) {
    error_log('Register endpoint: ' . $e->getMessage());
    json_response(500, ['error' => 'Nie udało się zarejestrować konta. Wystąpił problem z serwerem.']);
}