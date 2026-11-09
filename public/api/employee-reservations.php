<?php
declare(strict_types=1);

/*
    Endpoint pracownika do zmiany statusu przypisanych rezerwacji.

    Przyjmuje wyłącznie PATCH z identyfikatorem rezerwacji i docelowym statusem.
    Identyfikator pracownika pochodzi wyłącznie z profilu powiązanego z sesją,
    a przynależność rezerwacji sprawdza moduł rezerwacji pracownika.
*/
require_once __DIR__ . '/../../app/json.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/rezerwacje_pracownika.php';
require_once __DIR__ . '/../../app/log.php';

json_require_method(['PATCH']);
json_require_content_type();

$user = require_api_login();

if (!in_array($user['rola'] ?? '', ['pracownik', 'admin'], true)) {
    json_response(403, ['error' => 'Brak uprawnień do zarządzania wizytami pracownika.']);
}

if (!csrf_verify(csrf_token_from_request())) {
    json_response(403, ['error' => 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.']);
}

$employeeId = employee_profile_id((int) $user['id']);

if ($employeeId === null) {
    json_response(403, ['error' => 'Konto nie ma przypisanego profilu pracownika.']);
}

$data = json_read_body();
$errors = rezerwacja_status_change_errors($data);

if ($errors !== []) {
    json_response(422, [
        'error' => 'Nieprawidłowe dane rezerwacji.',
        'errors' => $errors,
    ]);
}

$reservationId = (int) $data['id'];
$targetStatus = (string) $data['status'];
$result = employee_reservation_set_status($employeeId, $reservationId, $targetStatus);

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
]);
