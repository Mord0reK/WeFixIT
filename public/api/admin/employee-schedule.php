<?php
declare(strict_types=1);

/*
    Endpoint administracyjny grafiku pracownika.

    Obsługuje POST (dodanie przedziału), PATCH (zmiana flagi aktywności) i
    DELETE (fizyczne usunięcie wpisu). Dla PATCH i DELETE weryfikujemy, że
    wpis należy do wskazanego pracownika, żeby samo id nie pozwalało edytować
    cudzego grafiku. Konflikt przedziałów jest błędem danych i zwraca 422.
*/
require_once __DIR__ . '/../../../app/json.php';
require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/pracownicy.php';
require_once __DIR__ . '/../../../app/pracownicy_dostepnosc.php';
require_once __DIR__ . '/../../../app/log.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
json_require_method(['POST', 'PATCH', 'DELETE']);
json_require_content_type();

$user = require_api_login();

if (($user['rola'] ?? '') !== 'admin') {
    json_response(403, ['error' => 'Brak uprawnień do zarządzania grafikiem pracownika.']);
}

if (!csrf_verify(csrf_token_from_request())) {
    json_response(403, ['error' => 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.']);
}

$data = json_read_body();
$actorId = (int) $user['id'];

if ($method === 'POST') {
    $employeeId = $data['pracownik_id'] ?? null;
    $errors = pracownik_schedule_errors($data);

    if (!is_int($employeeId) || $employeeId <= 0) {
        $errors['pracownik_id'] = PRACOWNIK_GRAFIK_EMPLOYEE_ERROR;
    }

    if ($errors !== []) {
        json_response(422, ['error' => 'Nieprawidłowe dane grafiku.', 'errors' => $errors]);
    }

    if (pracownik_find_by_id($employeeId) === null) {
        json_response(404, ['error' => 'Nie znaleziono pracownika.']);
    }

    $result = pracownik_schedule_create($employeeId, $data);

    if ($result['status'] === 'not_found') {
        json_response(404, ['error' => 'Nie znaleziono pracownika.']);
    }

    if ($result['status'] === 'invalid') {
        json_response(422, [
            'error' => 'Nieprawidłowe dane grafiku.',
            'errors' => $result['errors'] ?? [],
        ]);
    }

    if ($result['status'] !== 'ok') {
        json_response(500, ['error' => 'Nie udało się dodać przedziału grafiku. Wystąpił problem z serwerem.']);
    }

    log_event(
        $actorId,
        'grafik_dodany',
        'Dodano przedział grafiku pracownika.',
        'info',
        'pracownik',
        $employeeId,
        [
            'dzien_tygodnia' => $data['dzien_tygodnia'],
            'czas_rozpoczecia' => pracownik_schedule_time_value($data['czas_rozpoczecia'] ?? null),
            'czas_zakonczenia' => pracownik_schedule_time_value($data['czas_zakonczenia'] ?? null),
        ]
    );

    json_response(201, [
        'success' => true,
        'id' => $result['id'],
        'pracownik_id' => $employeeId,
    ]);
}

$scheduleId = $data['id'] ?? null;
$employeeId = $data['pracownik_id'] ?? null;
$errors = [];

if (!is_int($scheduleId) || $scheduleId <= 0) {
    $errors['id'] = PRACOWNIK_GRAFIK_ID_ERROR;
}

if (!is_int($employeeId) || $employeeId <= 0) {
    $errors['pracownik_id'] = PRACOWNIK_GRAFIK_EMPLOYEE_ERROR;
}

if ($method === 'PATCH' && (!array_key_exists('aktywne', $data) || !is_bool($data['aktywne']))) {
    $errors['aktywne'] = PRACOWNIK_GRAFIK_ACTIVE_ERROR;
}

if ($errors !== []) {
    json_response(422, ['error' => 'Nieprawidłowe dane grafiku.', 'errors' => $errors]);
}

$entry = pracownik_schedule_find($scheduleId);

// Samo id wpisu nie wystarcza: wpis musi należeć do wskazanego pracownika.
if ($entry === null || $entry['pracownik_id'] !== $employeeId) {
    json_response(404, ['error' => 'Nie znaleziono wpisu grafiku.']);
}

if ($method === 'PATCH') {
    $result = pracownik_schedule_set_active($employeeId, $scheduleId, $data['aktywne']);

    if ($result['status'] === 'not_found') {
        json_response(404, ['error' => 'Nie znaleziono wpisu grafiku.']);
    }

    if ($result['status'] !== 'ok') {
        json_response(500, ['error' => 'Nie udało się zapisać grafiku. Wystąpił problem z serwerem.']);
    }

    log_event(
        $actorId,
        'grafik_zaktualizowany',
        $data['aktywne'] ? 'Aktywowano przedział grafiku.' : 'Dezaktywowano przedział grafiku.',
        'info',
        'pracownik',
        $employeeId,
        [
            'wpis_id' => $scheduleId,
            'dzien_tygodnia' => $entry['dzien_tygodnia'],
            'aktywne' => $data['aktywne'],
        ]
    );

    json_response(200, [
        'success' => true,
        'id' => $scheduleId,
        'pracownik_id' => $employeeId,
        'aktywne' => $data['aktywne'],
    ]);
}

$result = pracownik_schedule_delete($employeeId, $scheduleId);

if ($result['status'] === 'not_found') {
    json_response(404, ['error' => 'Nie znaleziono wpisu grafiku.']);
}

if ($result['status'] !== 'ok') {
    json_response(500, ['error' => 'Nie udało się usunąć wpisu grafiku. Wystąpił problem z serwerem.']);
}

log_event(
    $actorId,
    'grafik_usuniety',
    'Usunięto przedział grafiku pracownika.',
    'info',
    'pracownik',
    $employeeId,
    [
        'wpis_id' => $scheduleId,
        'dzien_tygodnia' => $entry['dzien_tygodnia'],
    ]
);

json_response(200, ['success' => true]);
