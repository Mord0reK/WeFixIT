<?php
declare(strict_types=1);

/*
    Endpoint POST /api/reservations.

    Tworzy rezerwację zalogowanego użytkownika. Identyfikator klienta pochodzi
    wyłącznie z sesji, a cenę, status i czas zakończenia ustala serwer.
*/
require_once __DIR__ . '/../../app/json.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/rezerwacje_klienta.php';
require_once __DIR__ . '/../../app/log.php';

json_require_method(['POST']);
json_require_content_type();

$user = require_api_login();

if (!csrf_verify(csrf_token_from_request())) {
    json_response(403, ['error' => 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.']);
}

$data = json_read_body();
$result = reservation_create((int) $user['id'], $data);

if ($result['status'] === 'invalid') {
    json_response(422, [
        'error' => 'Nieprawidłowe dane rezerwacji.',
        'errors' => $result['errors'],
    ]);
}

if ($result['status'] === 'not_found') {
    json_response(409, ['error' => 'Wybrany termin nie jest dostępny.']);
}

if ($result['status'] === 'out_of_horizon') {
    json_response(422, ['error' => 'Wybrany termin jest poza dostępnym zakresem dat.']);
}

if ($result['status'] === 'conflict') {
    json_response(409, ['error' => 'Wybrany termin jest już zajęty.']);
}

if ($result['status'] !== 'ok') {
    json_response(500, ['error' => 'Nie udało się utworzyć rezerwacji. Wystąpił problem z serwerem.']);
}

$reservation = is_array($result['rezerwacja']) ? $result['rezerwacja'] : [];

log_event(
    (int) $user['id'],
    'rezerwacja_utworzona',
    'Utworzono rezerwację.',
    'info',
    'rezerwacja',
    (int) $result['id'],
    [
        'usluga_id' => (int) ($reservation['usluga_id'] ?? 0),
        'pracownik_id' => (int) ($reservation['pracownik_id'] ?? 0),
        'status' => (string) ($reservation['status_rezerwacji'] ?? 'oczekujaca'),
    ]
);

json_response(201, [
    'success' => true,
    'id' => (int) $result['id'],
    'rezerwacja' => $reservation,
]);
