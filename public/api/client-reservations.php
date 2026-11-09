<?php
declare(strict_types=1);

/*
    Endpoint klienta do anulowania własnej rezerwacji.

    Przyjmuje wyłącznie PATCH z identyfikatorem rezerwacji. Identyfikator
    klienta pochodzi z sesji, a ownership rezerwacji weryfikuje moduł
    rezerwacji klienta. Sukces trafia do dziennika zdarzeń po zakończeniu
    transakcji.
*/
require_once __DIR__ . '/../../app/json.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/rezerwacje_klienta.php';
require_once __DIR__ . '/../../app/log.php';

json_require_method(['PATCH']);
json_require_content_type();

$user = require_api_login();

if (($user['rola'] ?? '') !== 'klient') {
    json_response(403, ['error' => 'Brak uprawnień do anulowania rezerwacji.']);
}

if (!csrf_verify(csrf_token_from_request())) {
    json_response(403, ['error' => 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.']);
}

$data = json_read_body();
$reservationId = $data['id'] ?? null;

if (!is_int($reservationId) || $reservationId <= 0) {
    json_response(422, [
        'error' => 'Nieprawidłowe dane rezerwacji.',
        'errors' => ['id' => 'Podaj identyfikator rezerwacji.'],
    ]);
}

$result = reservation_client_cancel((int) $user['id'], $reservationId);

if ($result['status'] === 'not_found') {
    json_response(404, ['error' => 'Nie znaleziono rezerwacji.']);
}

if ($result['status'] === 'forbidden') {
    json_response(403, ['error' => 'Brak uprawnień do anulowania tej rezerwacji.']);
}

if ($result['status'] === 'not_cancellable') {
    json_response(409, ['error' => 'Tej rezerwacji nie można już anulować.']);
}

if ($result['status'] !== 'ok') {
    json_response(500, ['error' => 'Nie udało się anulować rezerwacji. Wystąpił problem z serwerem.']);
}

log_event(
    (int) $user['id'],
    'rezerwacja_anulowana_klient',
    'Klient anulował rezerwację.',
    'warning',
    'rezerwacja',
    $reservationId,
    [
        'status' => [
            'przed' => (string) ($result['poprzedni_status'] ?? 'oczekujaca'),
            'po' => 'anulowana',
        ],
    ]
);

json_response(200, [
    'success' => true,
    'id' => $reservationId,
    'status' => 'anulowana',
    'anulowano' => $result['anulowano'],
]);
