<?php
declare(strict_types=1);

/*
    Endpoint administracyjny kont użytkowników.

    Obsługuje wyłącznie PATCH: zmianę roli i/lub flagi aktywności po id.
    Nie tworzy, nie usuwa kont i nie zmienia haseł. Tożsamość aktora pochodzi
    wyłącznie z sesji, a reguły spójności sprawdza warstwa danych.
*/
require_once __DIR__ . '/../../../app/json.php';
require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/uzytkownicy.php';
require_once __DIR__ . '/../../../app/log.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
json_require_method(['PATCH']);

if ($method === 'PATCH') {
    json_require_content_type();
}

$user = require_api_login();

if (($user['rola'] ?? '') !== 'admin') {
    json_response(403, ['error' => 'Brak uprawnień do zarządzania użytkownikami.']);
}

if (!csrf_verify(csrf_token_from_request())) {
    json_response(403, ['error' => 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.']);
}

$data = json_read_body();
$errors = uzytkownik_change_errors($data);

if ($errors !== []) {
    json_response(422, ['error' => 'Nieprawidłowe dane użytkownika.', 'errors' => $errors]);
}

$targetId = (int) $data['id'];

if (uzytkownik_find_by_id($targetId) === null) {
    json_response(404, ['error' => 'Nie znaleziono użytkownika.']);
}

$result = uzytkownik_update($targetId, $data, (int) $user['id']);

if ($result['status'] === 'not_found') {
    json_response(404, ['error' => 'Nie znaleziono użytkownika.']);
}

if ($result['status'] === 'invalid') {
    json_response(422, [
        'error' => 'Nieprawidłowe dane użytkownika.',
        'errors' => $result['errors'] ?? [],
    ]);
}

if ($result['status'] !== 'ok') {
    json_response(500, ['error' => 'Nie udało się zapisać konta użytkownika. Wystąpił problem z serwerem.']);
}

$changedFields = $result['zmienione_pola'];
$details = [
    'zmienione_pola' => $changedFields,
    'rola' => [
        'przed' => $result['rola_przed'],
        'po' => $result['rola_po'],
    ],
    'aktywny' => [
        'przed' => $result['aktywny_przed'],
        'po' => $result['aktywny_po'],
    ],
];

if (in_array('rola', $changedFields, true)) {
    log_event(
        (int) $user['id'],
        'uzytkownik_rola_zmieniona',
        'Zmieniono rolę użytkownika.',
        'info',
        'uzytkownik',
        $targetId,
        $details
    );
}

if (in_array('aktywny', $changedFields, true)) {
    log_event(
        (int) $user['id'],
        'uzytkownik_status_zmieniony',
        $result['aktywny_po'] ? 'Aktywowano konto użytkownika.' : 'Dezaktywowano konto użytkownika.',
        $result['aktywny_po'] ? 'info' : 'warning',
        'uzytkownik',
        $targetId,
        $details
    );
}

json_response(200, [
    'success' => true,
    'id' => $targetId,
    'rola' => $result['rola'],
    'aktywny' => $result['aktywny'],
]);
