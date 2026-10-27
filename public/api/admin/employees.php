<?php
declare(strict_types=1);

/*
    Endpoint administracyjny pracowników.

    Obsługuje POST (utworzenie profilu), PATCH (opis i aktywność) oraz DELETE.
    Panel nie tworzy kont użytkowników — profil można utworzyć wyłącznie dla
    istniejącego, aktywnego konta z rolą pracownik. Uprawnienia sprawdzamy
    wyłącznie po stronie serwera.
*/
require_once __DIR__ . '/../../../app/json.php';
require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/pracownicy.php';
require_once __DIR__ . '/../../../app/log.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
json_require_method(['POST', 'PATCH', 'DELETE']);

if ($method === 'POST' || $method === 'PATCH') {
    json_require_content_type();
}

$user = require_api_login();

if (($user['rola'] ?? '') !== 'admin') {
    json_response(403, ['error' => 'Brak uprawnień do zarządzania pracownikami.']);
}

if (!csrf_verify(csrf_token_from_request())) {
    json_response(403, ['error' => 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.']);
}

$data = json_read_body();
$actorId = (int) $user['id'];

if ($method === 'POST') {
    $errors = pracownik_errors($data);

    if ($errors !== []) {
        json_response(422, ['error' => 'Nieprawidłowe dane pracownika.', 'errors' => $errors]);
    }

    try {
        $employeeId = pracownik_create($data);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_response(409, [
                'error' => PRACOWNIK_USER_TAKEN_ERROR,
                'errors' => ['uzytkownik_id' => PRACOWNIK_USER_TAKEN_ERROR],
            ]);
        }

        error_log('Employees endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się zapisać pracownika. Wystąpił problem z serwerem.']);
    } catch (Throwable $e) {
        error_log('Employees endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się zapisać pracownika. Wystąpił problem z serwerem.']);
    }

    log_event(
        $actorId,
        'pracownik_utworzony',
        'Utworzono profil pracownika.',
        'info',
        'pracownik',
        $employeeId,
        ['uzytkownik_id' => pracownik_user_id_value($data['uzytkownik_id'] ?? null)]
    );

    json_response(201, ['success' => true, 'id' => $employeeId]);
}

$employeeId = $data['id'] ?? null;

if (!is_int($employeeId) || $employeeId <= 0) {
    json_response(422, [
        'error' => 'Nieprawidłowe dane pracownika.',
        'errors' => ['id' => 'Podaj identyfikator pracownika.'],
    ]);
}

if ($method === 'DELETE') {
    $current = pracownik_find_by_id($employeeId);

    if ($current === null) {
        json_response(404, ['error' => 'Nie znaleziono pracownika.']);
    }

    $hasDependencies = pracownik_has_dependencies($employeeId);

    if ($hasDependencies === null) {
        json_response(500, ['error' => 'Nie udało się usunąć pracownika. Wystąpił problem z serwerem.']);
    }

    if ($hasDependencies === true) {
        json_response(409, ['error' => PRACOWNIK_HAS_DEPENDENCIES_ERROR]);
    }

    try {
        $deleted = pracownik_delete($employeeId);
    } catch (PDOException $e) {
        // Klucz obcy rozstrzyga wyścig między sprawdzeniem a DELETE.
        if ($e->getCode() === '23000') {
            json_response(409, ['error' => PRACOWNIK_HAS_DEPENDENCIES_ERROR]);
        }

        error_log('Employees endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się usunąć pracownika. Wystąpił problem z serwerem.']);
    } catch (Throwable $e) {
        error_log('Employees endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się usunąć pracownika. Wystąpił problem z serwerem.']);
    }

    if (!$deleted) {
        json_response(404, ['error' => 'Nie znaleziono pracownika.']);
    }

    log_event(
        $actorId,
        'pracownik_usuniety',
        'Usunięto profil pracownika.',
        'warning',
        'pracownik',
        $employeeId,
        ['uzytkownik_id' => $current['uzytkownik_id']]
    );

    json_response(200, ['success' => true]);
}

// PATCH: brak pól do zmiany jest błędem walidacji, a nie pustym sukcesem.
$hasDescription = array_key_exists('opis', $data);
$hasUserId = array_key_exists('uzytkownik_id', $data);
$hasActive = array_key_exists('aktywny', $data);

if (!$hasDescription && !$hasUserId && !$hasActive) {
    json_response(422, ['error' => 'Brak danych do zapisania.']);
}

if ($hasActive && !is_bool($data['aktywny'])) {
    json_response(422, [
        'error' => 'Nieprawidłowe dane pracownika.',
        'errors' => ['aktywny' => 'Pole aktywny musi być wartością logiczną.'],
    ]);
}

$partialErrors = [];

if ($hasDescription) {
    $partialErrors += pracownik_description_errors($data['opis']);
}

$newUserId = null;

if ($hasUserId) {
    $newUserId = pracownik_user_id_value($data['uzytkownik_id']);

    if ($newUserId === null) {
        $partialErrors += ['uzytkownik_id' => 'Wybierz konto pracownika.'];
    }
}

if ($partialErrors !== []) {
    json_response(422, ['error' => 'Nieprawidłowe dane pracownika.', 'errors' => $partialErrors]);
}

$current = pracownik_find_by_id($employeeId);

if ($current === null) {
    json_response(404, ['error' => 'Nie znaleziono pracownika.']);
}

if ($hasUserId && $newUserId !== $current['uzytkownik_id']) {
    json_response(422, [
        'error' => 'Nieprawidłowe dane pracownika.',
        'errors' => ['uzytkownik_id' => PRACOWNIK_USER_CHANGE_ERROR],
    ]);
}

$activeAfter = $hasActive ? $data['aktywny'] : $current['aktywny'];

if ($hasDescription) {
    try {
        $updated = pracownik_update($employeeId, [
            'opis' => $data['opis'],
            'aktywny' => $activeAfter,
        ]);
    } catch (PDOException $e) {
        error_log('Employees endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się zapisać pracownika. Wystąpił problem z serwerem.']);
    } catch (Throwable $e) {
        error_log('Employees endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się zapisać pracownika. Wystąpił problem z serwerem.']);
    }

    if (!$updated) {
        json_response(500, ['error' => 'Nie udało się zapisać pracownika. Wystąpił problem z serwerem.']);
    }
} else {
    $updated = pracownik_set_active($employeeId, $activeAfter);

    if (!$updated) {
        json_response(500, ['error' => 'Nie udało się zapisać pracownika. Wystąpił problem z serwerem.']);
    }
}

$changedFields = [];

if ($hasDescription) {
    $newDescription = pracownik_field($data, 'opis');
    $currentDescription = $current['opis'] ?? '';

    if ($newDescription !== $currentDescription) {
        $changedFields[] = 'opis';
    }
}

if ($hasActive && $activeAfter !== $current['aktywny']) {
    $changedFields[] = 'aktywny';
}

$action = 'pracownik_zaktualizowany';
$description = 'Zaktualizowano profil pracownika.';

if ($hasActive && $activeAfter !== $current['aktywny']) {
    $action = $activeAfter ? 'pracownik_aktywowany' : 'pracownik_dezaktywowany';
    $description = $activeAfter ? 'Aktywowano profil pracownika.' : 'Dezaktywowano profil pracownika.';
}

log_event(
    $actorId,
    $action,
    $description,
    'info',
    'pracownik',
    $employeeId,
    ['zmienione_pola' => $changedFields, 'aktywny' => $activeAfter]
);

json_response(200, ['success' => true, 'id' => $employeeId]);
