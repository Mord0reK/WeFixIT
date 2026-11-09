<?php
declare(strict_types=1);

/*
    Endpoint GET /api/availability.

    Zwraca wolne terminy dla wybranej pary pracownik + usługa w jednym dniu.
    Wymaga zalogowania, ale nie wymaga CSRF, bo jest to odczyt.
*/
require_once __DIR__ . '/../../app/json.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/rezerwacje_klienta.php';

json_require_method(['GET']);

$user = require_api_login();

$employeeId = reservation_id_value($_GET['pracownik_id'] ?? null);
$serviceId = reservation_id_value($_GET['usluga_id'] ?? null);
$date = reservation_date_value($_GET['data'] ?? null);
$errors = [];

if ($employeeId === null) {
    $errors['pracownik_id'] = 'Podaj identyfikator pracownika.';
}

if ($serviceId === null) {
    $errors['usluga_id'] = 'Podaj identyfikator usługi.';
}

if ($date === null) {
    $errors['data'] = 'Podaj poprawną datę w formacie RRRR-MM-DD.';
}

if ($errors !== []) {
    json_response(422, [
        'error' => 'Nieprawidłowe parametry zapytania.',
        'errors' => $errors,
    ]);
}

$pair = reservation_pair_check($employeeId, $serviceId);

if ($pair['status'] === 'error') {
    json_response(500, ['error' => 'Nie udało się pobrać terminów. Wystąpił problem z serwerem.']);
}

if ($pair['status'] === 'service_not_found') {
    json_response(404, ['error' => 'Nie znaleziono usługi.']);
}

if ($pair['status'] === 'employee_not_found') {
    json_response(404, ['error' => 'Nie znaleziono pracownika.']);
}

if ($pair['status'] !== 'ok') {
    json_response(409, ['error' => 'Wybrany termin nie jest dostępny.']);
}

$result = availability_day($employeeId, $serviceId, $date);

if ($result['status'] === 'not_found') {
    json_response(404, ['error' => 'Nie znaleziono usługi.']);
}

if ($result['status'] === 'error') {
    json_response(500, ['error' => 'Nie udało się pobrać terminów. Wystąpił problem z serwerem.']);
}

json_response(200, [
    'success' => true,
    'data' => $result['data'],
    'czas_trwania_minuty' => $result['czas_trwania_minuty'],
    'sloty' => $result['sloty'],
]);
