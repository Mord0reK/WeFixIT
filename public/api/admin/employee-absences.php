<?php
declare(strict_types=1);

/*
    Endpoint administracyjny nieobecności pracownika.

    Obsługuje wyłącznie POST (utworzenie oczekującego wniosku) i PATCH (zmiana
    statusu po id). Nieobecności nie usuwamy — anulowanie jest zmianą statusu,
    dlatego DELETE celowo nie znajduje się na liście dozwolonych metod.
    Przy zatwierdzaniu zwracamy ostrzeżenie o rezerwacjach w okresie.
*/
require_once __DIR__ . '/../../../app/json.php';
require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/pracownicy.php';
require_once __DIR__ . '/../../../app/pracownicy_dostepnosc.php';
require_once __DIR__ . '/../../../app/log.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
json_require_method(['POST', 'PATCH']);
json_require_content_type();

$user = require_api_login();

if (($user['rola'] ?? '') !== 'admin') {
    json_response(403, ['error' => 'Brak uprawnień do zarządzania nieobecnościami pracownika.']);
}

if (!csrf_verify(csrf_token_from_request())) {
    json_response(403, ['error' => 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.']);
}

$data = json_read_body();
$actorId = (int) $user['id'];

if ($method === 'POST') {
    $employeeId = $data['pracownik_id'] ?? null;
    $errors = pracownik_absence_errors(
        $data,
        is_int($employeeId) && $employeeId > 0 ? $employeeId : 0
    );

    if (!is_int($employeeId) || $employeeId <= 0) {
        $errors['pracownik_id'] = PRACOWNIK_NIEOBECNOSC_EMPLOYEE_ERROR;
    }

    if ($errors !== []) {
        json_response(422, ['error' => 'Nieprawidłowe dane nieobecności.', 'errors' => $errors]);
    }

    if (pracownik_find_by_id($employeeId) === null) {
        json_response(404, ['error' => 'Nie znaleziono pracownika.']);
    }

    $result = pracownik_absence_create($employeeId, $data);

    if ($result['status'] === 'not_found') {
        json_response(404, ['error' => 'Nie znaleziono pracownika.']);
    }

    if ($result['status'] === 'overlap') {
        json_response(422, [
            'error' => 'Nieprawidłowe dane nieobecności.',
            'errors' => $result['errors'] ?? ['data_od' => PRACOWNIK_NIEOBECNOSC_OVERLAP_ERROR],
        ]);
    }

    if ($result['status'] === 'invalid') {
        json_response(422, [
            'error' => 'Nieprawidłowe dane nieobecności.',
            'errors' => $result['errors'] ?? [],
        ]);
    }

    if ($result['status'] !== 'ok') {
        json_response(500, ['error' => 'Nie udało się utworzyć nieobecności. Wystąpił problem z serwerem.']);
    }

    log_event(
        $actorId,
        'nieobecnosc_utworzona',
        'Utworzono wniosek o nieobecność pracownika.',
        'info',
        'pracownik',
        $employeeId,
        [
            'nieobecnosc_id' => $result['id'],
            'rodzaj' => $data['rodzaj'],
        ]
    );

    json_response(201, [
        'success' => true,
        'id' => $result['id'],
        'pracownik_id' => $employeeId,
        'status' => 'oczekujaca',
    ]);
}

$absenceId = $data['id'] ?? null;
$targetStatus = pracownik_absence_status_value($data['status'] ?? null);
$errors = [];

if (!is_int($absenceId) || $absenceId <= 0) {
    $errors['id'] = PRACOWNIK_NIEOBECNOSC_ID_ERROR;
}

if ($targetStatus === null) {
    $errors['status'] = PRACOWNIK_NIEOBECNOSC_STATUS_ERROR;
}

if ($errors !== []) {
    json_response(422, ['error' => 'Nieprawidłowe dane nieobecności.', 'errors' => $errors]);
}

$absence = pracownik_absence_find($absenceId);

if ($absence === null) {
    json_response(404, ['error' => 'Nie znaleziono nieobecności.']);
}

if (!pracownik_absence_transition_allowed($absence['status'], $targetStatus)) {
    json_response(422, [
        'error' => 'Nieprawidłowe dane nieobecności.',
        'errors' => ['status' => PRACOWNIK_NIEOBECNOSC_TRANSITION_ERROR],
    ]);
}

// Liczymy przed zapisem, żeby ostrzeżenie i poziom logu odpowiadały tej samej operacji.
$reservationCount = null;

if ($targetStatus === 'zatwierdzona') {
    $reservationCount = pracownik_absence_reservation_count(
        $absence['pracownik_id'],
        $absence['data_od'],
        $absence['data_do']
    );
}

$result = pracownik_absence_set_status($absenceId, $targetStatus, $actorId);

if ($result['status'] === 'not_found') {
    json_response(404, ['error' => 'Nie znaleziono nieobecności.']);
}

if ($result['status'] === 'invalid_transition') {
    json_response(422, [
        'error' => 'Nieprawidłowe dane nieobecności.',
        'errors' => ['status' => PRACOWNIK_NIEOBECNOSC_TRANSITION_ERROR],
    ]);
}

if ($result['status'] !== 'ok') {
    json_response(500, ['error' => 'Nie udało się zmienić statusu nieobecności. Wystąpił problem z serwerem.']);
}

$warning = $targetStatus === 'zatwierdzona' && $reservationCount !== null
    ? pracownik_absence_warning($reservationCount)
    : null;

$action = match ($targetStatus) {
    'zatwierdzona' => 'nieobecnosc_zatwierdzona',
    'odrzucona' => 'nieobecnosc_odrzucona',
    default => 'nieobecnosc_anulowana',
};
$description = match ($targetStatus) {
    'zatwierdzona' => 'Zatwierdzono nieobecność pracownika.',
    'odrzucona' => 'Odrzucono nieobecność pracownika.',
    default => 'Anulowano nieobecność pracownika.',
};
$level = $warning !== null ? 'warning' : 'info';

log_event(
    $actorId,
    $action,
    $description,
    $level,
    'pracownik',
    $absence['pracownik_id'],
    [
        'nieobecnosc_id' => $absenceId,
        'status' => $targetStatus,
        'liczba_rezerwacji' => $reservationCount ?? 0,
    ]
);

$body = [
    'success' => true,
    'id' => $absenceId,
    'pracownik_id' => $absence['pracownik_id'],
    'status' => $targetStatus,
];

if ($warning !== null) {
    $body['ostrzezenie'] = $warning;
}

json_response(200, $body);
