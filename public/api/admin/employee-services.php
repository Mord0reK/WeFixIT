<?php
declare(strict_types=1);

/*
    Endpoint administracyjny przypisań usług do pracownika.

    Obsługuje wyłącznie POST, który zastępuje cały zbiór przypisań wskazanego
    pracownika. Operacja jest idempotentna: podana lista jest stanem docelowym,
    a brakujące przypisania są odłączane przez aktywne = FALSE. Uprawnienia
    sprawdzamy wyłącznie po stronie serwera.
*/
require_once __DIR__ . '/../../../app/json.php';
require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/pracownicy.php';
require_once __DIR__ . '/../../../app/pracownicy_uslugi.php';
require_once __DIR__ . '/../../../app/log.php';

json_require_method(['POST']);
json_require_content_type();

$user = require_api_login();

if (($user['rola'] ?? '') !== 'admin') {
    json_response(403, ['error' => 'Brak uprawnień do zarządzania przypisaniami usług.']);
}

if (!csrf_verify(csrf_token_from_request())) {
    json_response(403, ['error' => 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.']);
}

$data = json_read_body();
$actorId = (int) $user['id'];
$employeeId = $data['pracownik_id'] ?? null;
$serviceIds = pracownik_uslugi_ids_value($data['usluga_ids'] ?? null);
$errors = [];

if (!is_int($employeeId) || $employeeId <= 0) {
    $errors['pracownik_id'] = 'Podaj identyfikator pracownika.';
}

if ($serviceIds === null) {
    $errors['usluga_ids'] = PRACOWNIK_USLUGI_IDS_ERROR;
}

if ($errors !== []) {
    json_response(422, ['error' => 'Nieprawidłowe dane przypisań usług.', 'errors' => $errors]);
}

if (pracownik_find_by_id($employeeId) === null) {
    json_response(404, ['error' => 'Nie znaleziono pracownika.']);
}

$beforeState = pracownik_uslugi_state($employeeId);

if ($beforeState === null) {
    json_response(500, ['error' => 'Nie udało się zapisać przypisań usług. Wystąpił problem z serwerem.']);
}

$beforeIds = [];

foreach ($beforeState as $service) {
    if ($service['przypisana']) {
        $beforeIds[] = $service['id'];
    }
}

$result = pracownik_uslugi_save($employeeId, $serviceIds);

if ($result['status'] === 'not_found') {
    json_response(404, ['error' => 'Nie znaleziono pracownika.']);
}

if ($result['status'] === 'invalid') {
    json_response(422, [
        'error' => 'Nieprawidłowe dane przypisań usług.',
        'errors' => ['usluga_ids' => PRACOWNIK_USLUGI_UNAVAILABLE_ERROR],
    ]);
}

if ($result['status'] !== 'ok') {
    json_response(500, ['error' => 'Nie udało się zapisać przypisań usług. Wystąpił problem z serwerem.']);
}

$afterIds = $result['usluga_ids'];
sort($beforeIds, SORT_NUMERIC);
$addedIds = array_values(array_diff($afterIds, $beforeIds));
$removedIds = array_values(array_diff($beforeIds, $afterIds));

log_event(
    $actorId,
    'pracownik_uslugi_zaktualizowane',
    'Zaktualizowano przypisania usług pracownika.',
    'info',
    'pracownik',
    $employeeId,
    [
        'dodane_usluga_ids' => $addedIds,
        'odlaczone_usluga_ids' => $removedIds,
    ]
);

json_response(200, [
    'success' => true,
    'pracownik_id' => $employeeId,
    'usluga_ids' => $afterIds,
]);
