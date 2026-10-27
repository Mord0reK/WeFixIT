<?php
declare(strict_types=1);

/*
    Endpoint administracyjny kategorii usług.

    Obsługuje POST (utworzenie), PATCH (edycja lub zmiana aktywności)
    i DELETE (usunięcie). Operacje rozdziela metoda HTTP, a identyfikator
    pochodzi z body. Uprawnienia sprawdzamy wyłącznie po stronie serwera.
*/
require_once __DIR__ . '/../../../app/json.php';
require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/kategorie.php';
require_once __DIR__ . '/../../../app/log.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
json_require_method(['POST', 'PATCH', 'DELETE']);

if ($method === 'POST' || $method === 'PATCH') {
    json_require_content_type();
}

$user = require_api_login();

if (($user['rola'] ?? '') !== 'admin') {
    json_response(403, ['error' => 'Brak uprawnień do zarządzania kategoriami.']);
}

if (!csrf_verify(csrf_token_from_request())) {
    json_response(403, ['error' => 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.']);
}

$data = json_read_body();
$actorId = (int) $user['id'];

if ($method === 'POST') {
    $errors = kategoria_errors($data);

    if ($errors !== []) {
        json_response(422, ['error' => 'Nieprawidłowe dane kategorii.', 'errors' => $errors]);
    }

    try {
        $categoryId = kategoria_create($data);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_response(409, [
                'error' => KATEGORIA_NAME_TAKEN_ERROR,
                'errors' => ['nazwa' => KATEGORIA_NAME_TAKEN_ERROR],
            ]);
        }

        error_log('Categories endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się zapisać kategorii. Wystąpił problem z serwerem.']);
    } catch (Throwable $e) {
        error_log('Categories endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się zapisać kategorii. Wystąpił problem z serwerem.']);
    }

    log_event(
        $actorId,
        'kategoria_utworzona',
        'Utworzono kategorię usług.',
        'info',
        'kategoria_uslug',
        $categoryId,
        ['nazwa' => kategoria_field($data, 'nazwa')]
    );

    json_response(201, ['success' => true, 'id' => $categoryId]);
}

$categoryId = $data['id'] ?? null;

if (!is_int($categoryId) || $categoryId <= 0) {
    json_response(422, [
        'error' => 'Nieprawidłowe dane kategorii.',
        'errors' => ['id' => 'Podaj identyfikator kategorii.'],
    ]);
}

if ($method === 'DELETE') {
    $current = kategoria_find_by_id($categoryId);

    if ($current === null) {
        json_response(404, ['error' => 'Nie znaleziono kategorii.']);
    }

    $hasServices = kategoria_has_services($categoryId);

    if ($hasServices === null) {
        json_response(500, ['error' => 'Nie udało się usunąć kategorii. Wystąpił problem z serwerem.']);
    }

    if ($hasServices === true) {
        json_response(409, ['error' => KATEGORIA_HAS_SERVICES_ERROR]);
    }

    try {
        $deleted = kategoria_delete($categoryId);
    } catch (PDOException $e) {
        // Klucz obcy rozstrzyga wyścig między sprawdzeniem a DELETE.
        if ($e->getCode() === '23000') {
            json_response(409, ['error' => KATEGORIA_HAS_SERVICES_ERROR]);
        }

        error_log('Categories endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się usunąć kategorii. Wystąpił problem z serwerem.']);
    } catch (Throwable $e) {
        error_log('Categories endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się usunąć kategorii. Wystąpił problem z serwerem.']);
    }

    if (!$deleted) {
        json_response(404, ['error' => 'Nie znaleziono kategorii.']);
    }

    log_event(
        $actorId,
        'kategoria_usunieta',
        'Usunięto kategorię usług.',
        'warning',
        'kategoria_uslug',
        $categoryId,
        ['nazwa' => $current['nazwa']]
    );

    json_response(200, ['success' => true]);
}

// PATCH: brak pól do zmiany jest błędem walidacji, a nie pustym sukcesem.
$hasName = array_key_exists('nazwa', $data);
$hasDescription = array_key_exists('opis', $data);
$hasActive = array_key_exists('aktywna', $data);

if (!$hasName && !$hasDescription && !$hasActive) {
    json_response(422, ['error' => 'Brak danych do zapisania.']);
}

if ($hasActive && !is_bool($data['aktywna'])) {
    json_response(422, [
        'error' => 'Nieprawidłowe dane kategorii.',
        'errors' => ['aktywna' => 'Pole aktywna musi być wartością logiczną.'],
    ]);
}

$errors = [];

if ($hasName) {
    $errors += kategoria_name_errors($data['nazwa'], $categoryId);
}

if ($hasDescription) {
    $errors += kategoria_description_errors($data['opis']);
}

if ($errors !== []) {
    if (($errors['nazwa'] ?? '') === KATEGORIA_NAME_TAKEN_ERROR) {
        json_response(409, [
            'error' => KATEGORIA_NAME_TAKEN_ERROR,
            'errors' => ['nazwa' => KATEGORIA_NAME_TAKEN_ERROR],
        ]);
    }

    json_response(422, ['error' => 'Nieprawidłowe dane kategorii.', 'errors' => $errors]);
}

$current = kategoria_find_by_id($categoryId);

if ($current === null) {
    json_response(404, ['error' => 'Nie znaleziono kategorii.']);
}

$activeAfter = $hasActive ? $data['aktywna'] : $current['aktywna'];

$changedFields = [];

if ($hasName && kategoria_field($data, 'nazwa') !== $current['nazwa']) {
    $changedFields[] = 'nazwa';
}

if ($hasDescription) {
    $newDescription = kategoria_field($data, 'opis');
    $currentDescription = $current['opis'] ?? '';

    if ($newDescription !== $currentDescription) {
        $changedFields[] = 'opis';
    }
}

if ($hasActive && $activeAfter !== $current['aktywna']) {
    $changedFields[] = 'aktywna';
}

if ($hasName || $hasDescription) {
    $payload = [
        'nazwa' => $hasName ? $data['nazwa'] : $current['nazwa'],
        'opis' => $hasDescription ? $data['opis'] : ($current['opis'] ?? ''),
        'aktywna' => $activeAfter,
    ];

    try {
        $updated = kategoria_update($categoryId, $payload);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_response(409, [
                'error' => KATEGORIA_NAME_TAKEN_ERROR,
                'errors' => ['nazwa' => KATEGORIA_NAME_TAKEN_ERROR],
            ]);
        }

        error_log('Categories endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się zapisać kategorii. Wystąpił problem z serwerem.']);
    } catch (Throwable $e) {
        error_log('Categories endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się zapisać kategorii. Wystąpił problem z serwerem.']);
    }

    if (!$updated) {
        json_response(500, ['error' => 'Nie udało się zapisać kategorii. Wystąpił problem z serwerem.']);
    }
} else {
    $updated = kategoria_set_active($categoryId, $activeAfter);

    if (!$updated) {
        json_response(500, ['error' => 'Nie udało się zapisać kategorii. Wystąpił problem z serwerem.']);
    }
}

$action = 'kategoria_zaktualizowana';
$description = 'Zaktualizowano kategorię usług.';

if ($hasActive && $activeAfter !== $current['aktywna']) {
    $action = $activeAfter ? 'kategoria_aktywowana' : 'kategoria_dezaktywowana';
    $description = $activeAfter ? 'Aktywowano kategorię usług.' : 'Dezaktywowano kategorię usług.';
}

log_event(
    $actorId,
    $action,
    $description,
    'info',
    'kategoria_uslug',
    $categoryId,
    ['zmienione_pola' => $changedFields, 'aktywna' => $activeAfter]
);

json_response(200, ['success' => true, 'id' => $categoryId]);
