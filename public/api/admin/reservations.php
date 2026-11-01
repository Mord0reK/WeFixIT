<?php
declare(strict_types=1);

/*
    Endpoint administracyjny rezerwacji.

    Obsługuje GET (lista z filtrami) i PATCH (zmiana statusu po id).
    Rezerwacji nie usuwamy, dlatego DELETE celowo nie znajduje się na liście
    dozwolonych metod. Anulowanie jest zmianą statusu i trafia do logu jako
    osobne zdarzenie ostrzegawcze.
*/
require_once __DIR__ . '/../../../app/json.php';
require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/rezerwacje.php';
require_once __DIR__ . '/../../../app/log.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
json_require_method(['GET', 'PATCH']);

if ($method === 'PATCH') {
    json_require_content_type();
}

$user = require_api_login();

if (($user['rola'] ?? '') !== 'admin') {
    json_response(403, ['error' => 'Brak uprawnień do zarządzania rezerwacjami.']);
}

if ($method === 'GET') {
    $reservations = rezerwacje_list([
        'data_od' => $_GET['data_od'] ?? null,
        'data_do' => $_GET['data_do'] ?? null,
        'status' => $_GET['status'] ?? null,
        'pracownik_id' => $_GET['pracownik_id'] ?? null,
        'usluga_id' => $_GET['usluga_id'] ?? null,
        'uzytkownik_id' => $_GET['uzytkownik_id'] ?? null,
        'kierunek' => $_GET['kierunek'] ?? null,
    ]);

    if ($reservations === null) {
        json_response(500, ['error' => 'Nie udało się pobrać rezerwacji. Wystąpił problem z serwerem.']);
    }

    json_response(200, ['success' => true, 'rezerwacje' => $reservations]);
}

if (!csrf_verify(csrf_token_from_request())) {
    json_response(403, ['error' => 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.']);
}

$data = json_read_body();
$errors = rezerwacja_status_change_errors($data);

if ($errors !== []) {
    json_response(422, ['error' => 'Nieprawidłowe dane rezerwacji.', 'errors' => $errors]);
}

$reservationId = (int) $data['id'];
$targetStatus = (string) $data['status'];
$result = rezerwacja_set_status($reservationId, $targetStatus);

if ($result['status'] === 'not_found') {
    json_response(404, ['error' => 'Nie znaleziono rezerwacji.']);
}

if ($result['status'] === 'invalid_transition') {
    json_response(422, [
        'error' => 'Nieprawidłowe dane rezerwacji.',
        'errors' => ['status' => REZERWACJA_TRANSITION_ERROR],
    ]);
}

if ($result['status'] !== 'ok') {
    json_response(500, ['error' => 'Nie udało się zmienić statusu rezerwacji. Wystąpił problem z serwerem.']);
}

$details = [
    'status' => [
        'przed' => $result['poprzedni_status'],
        'po' => $result['nowy_status'],
    ],
];

if ($targetStatus === 'anulowana') {
    log_event(
        (int) $user['id'],
        'rezerwacja_anulowana',
        'Anulowano rezerwację.',
        'warning',
        'rezerwacja',
        $reservationId,
        $details
    );
} else {
    log_event(
        (int) $user['id'],
        'rezerwacja_status_zmieniony',
        'Zmieniono status rezerwacji.',
        'info',
        'rezerwacja',
        $reservationId,
        $details
    );
}

json_response(200, [
    'success' => true,
    'id' => $reservationId,
    'status' => $targetStatus,
    'anulowano' => $result['anulowano'],
]);
