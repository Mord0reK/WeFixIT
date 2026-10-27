<?php
declare(strict_types=1);

/*
    Endpoint administracyjny usług.

    Obsługuje POST (utworzenie), PATCH (edycja lub zmiana aktywności)
    i DELETE (usunięcie). Operacje rozdziela metoda HTTP, a identyfikator
    pochodzi z body. Uprawnienia sprawdzamy wyłącznie po stronie serwera.
*/
require_once __DIR__ . '/../../../app/json.php';
require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/uslugi.php';
require_once __DIR__ . '/../../../app/log.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
json_require_method(['POST', 'PATCH', 'DELETE']);

if ($method === 'POST' || $method === 'PATCH') {
    json_require_content_type();
}

$user = require_api_login();

if (($user['rola'] ?? '') !== 'admin') {
    json_response(403, ['error' => 'Brak uprawnień do zarządzania usługami.']);
}

if (!csrf_verify(csrf_token_from_request())) {
    json_response(403, ['error' => 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.']);
}

$data = json_read_body();
$actorId = (int) $user['id'];

if ($method === 'POST') {
    $errors = usluga_errors($data);

    if ($errors !== []) {
        if (($errors['nazwa'] ?? '') === USLUGA_NAME_TAKEN_ERROR) {
            json_response(409, [
                'error' => USLUGA_NAME_TAKEN_ERROR,
                'errors' => ['nazwa' => USLUGA_NAME_TAKEN_ERROR],
            ]);
        }

        json_response(422, ['error' => 'Nieprawidłowe dane usługi.', 'errors' => $errors]);
    }

    try {
        $serviceId = usluga_create($data);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_response(409, [
                'error' => USLUGA_NAME_TAKEN_ERROR,
                'errors' => ['nazwa' => USLUGA_NAME_TAKEN_ERROR],
            ]);
        }

        error_log('Services endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się zapisać usługi. Wystąpił problem z serwerem.']);
    } catch (Throwable $e) {
        error_log('Services endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się zapisać usługi. Wystąpił problem z serwerem.']);
    }

    log_event(
        $actorId,
        'usluga_utworzona',
        'Utworzono usługę.',
        'info',
        'usluga',
        $serviceId,
        ['nazwa' => usluga_field($data, 'nazwa')]
    );

    json_response(201, ['success' => true, 'id' => $serviceId]);
}

$serviceId = $data['id'] ?? null;

if (!is_int($serviceId) || $serviceId <= 0) {
    json_response(422, [
        'error' => 'Nieprawidłowe dane usługi.',
        'errors' => ['id' => 'Podaj identyfikator usługi.'],
    ]);
}

if ($method === 'DELETE') {
    $current = usluga_find_by_id($serviceId);

    if ($current === null) {
        json_response(404, ['error' => 'Nie znaleziono usługi.']);
    }

    $hasDependencies = usluga_has_dependencies($serviceId);

    if ($hasDependencies === null) {
        json_response(500, ['error' => 'Nie udało się usunąć usługi. Wystąpił problem z serwerem.']);
    }

    if ($hasDependencies === true) {
        json_response(409, ['error' => USLUGA_HAS_DEPENDENCIES_ERROR]);
    }

    try {
        $deleted = usluga_delete($serviceId);
    } catch (PDOException $e) {
        // Klucz obcy rozstrzyga wyścig między sprawdzeniem a DELETE.
        if ($e->getCode() === '23000') {
            json_response(409, ['error' => USLUGA_HAS_DEPENDENCIES_ERROR]);
        }

        error_log('Services endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się usunąć usługi. Wystąpił problem z serwerem.']);
    } catch (Throwable $e) {
        error_log('Services endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się usunąć usługi. Wystąpił problem z serwerem.']);
    }

    if (!$deleted) {
        json_response(404, ['error' => 'Nie znaleziono usługi.']);
    }

    log_event(
        $actorId,
        'usluga_usunieta',
        'Usunięto usługę.',
        'warning',
        'usluga',
        $serviceId,
        ['nazwa' => $current['nazwa']]
    );

    json_response(200, ['success' => true]);
}

// PATCH: brak pól do zmiany jest błędem walidacji, a nie pustym sukcesem.
$hasName = array_key_exists('nazwa', $data);
$hasDescription = array_key_exists('opis', $data);
$hasCategory = array_key_exists('kategoria_id', $data);
$hasPrice = array_key_exists('cena', $data);
$hasDuration = array_key_exists('czas_trwania_minuty', $data);
$hasActive = array_key_exists('aktywna', $data);

if (!$hasName && !$hasDescription && !$hasCategory && !$hasPrice && !$hasDuration && !$hasActive) {
    json_response(422, ['error' => 'Brak danych do zapisania.']);
}

if ($hasActive && !is_bool($data['aktywna'])) {
    json_response(422, [
        'error' => 'Nieprawidłowe dane usługi.',
        'errors' => ['aktywna' => 'Pole aktywna musi być wartością logiczną.'],
    ]);
}

$partialErrors = [];

if ($hasName) {
    $partialErrors += usluga_name_basic_errors($data['nazwa']);
}

if ($hasDescription) {
    $partialErrors += usluga_description_errors($data['opis']);
}

if ($hasCategory) {
    $partialErrors += usluga_category_errors($data['kategoria_id']);
}

if ($hasPrice) {
    $partialErrors += usluga_price_errors($data['cena']);
}

if ($hasDuration) {
    $partialErrors += usluga_duration_errors($data['czas_trwania_minuty']);
}

if ($partialErrors !== []) {
    json_response(422, ['error' => 'Nieprawidłowe dane usługi.', 'errors' => $partialErrors]);
}

$current = usluga_find_by_id($serviceId);

if ($current === null) {
    json_response(404, ['error' => 'Nie znaleziono usługi.']);
}

$activeAfter = $hasActive ? $data['aktywna'] : $current['aktywna'];

if (!$hasName && !$hasDescription && !$hasCategory && !$hasPrice && !$hasDuration) {
    $updated = usluga_set_active($serviceId, $activeAfter);

    if (!$updated) {
        json_response(500, ['error' => 'Nie udało się zapisać usługi. Wystąpił problem z serwerem.']);
    }
} else {
    $payload = [
        'nazwa' => $hasName ? $data['nazwa'] : $current['nazwa'],
        'opis' => $hasDescription ? $data['opis'] : ($current['opis'] ?? ''),
        'kategoria_id' => $hasCategory ? $data['kategoria_id'] : $current['kategoria_id'],
        'cena' => $hasPrice ? $data['cena'] : $current['cena'],
        'czas_trwania_minuty' => $hasDuration ? $data['czas_trwania_minuty'] : $current['czas_trwania_minuty'],
        'aktywna' => $activeAfter,
    ];

    $errors = usluga_errors($payload, $serviceId);

    if ($errors !== []) {
        if (($errors['nazwa'] ?? '') === USLUGA_NAME_TAKEN_ERROR) {
            json_response(409, [
                'error' => USLUGA_NAME_TAKEN_ERROR,
                'errors' => ['nazwa' => USLUGA_NAME_TAKEN_ERROR],
            ]);
        }

        json_response(422, ['error' => 'Nieprawidłowe dane usługi.', 'errors' => $errors]);
    }

    try {
        $updated = usluga_update($serviceId, $payload);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_response(409, [
                'error' => USLUGA_NAME_TAKEN_ERROR,
                'errors' => ['nazwa' => USLUGA_NAME_TAKEN_ERROR],
            ]);
        }

        error_log('Services endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się zapisać usługi. Wystąpił problem z serwerem.']);
    } catch (Throwable $e) {
        error_log('Services endpoint: ' . $e->getMessage());
        json_response(500, ['error' => 'Nie udało się zapisać usługi. Wystąpił problem z serwerem.']);
    }

    if (!$updated) {
        json_response(404, ['error' => 'Nie znaleziono usługi.']);
    }
}

$action = 'usluga_zaktualizowana';
$description = 'Zaktualizowano usługę.';

if ($hasActive && $activeAfter !== $current['aktywna']) {
    $action = $activeAfter ? 'usluga_aktywowana' : 'usluga_dezaktywowana';
    $description = $activeAfter ? 'Aktywowano usługę.' : 'Dezaktywowano usługę.';
}

log_event(
    $actorId,
    $action,
    $description,
    'info',
    'usluga',
    $serviceId,
    ['aktywna' => $activeAfter]
);

json_response(200, ['success' => true, 'id' => $serviceId]);
