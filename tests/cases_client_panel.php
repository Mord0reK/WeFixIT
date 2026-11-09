<?php
declare(strict_types=1);

/*
    Testy panelu klienta i anulowania własnych rezerwacji.

    Obejmują ochronę strony, widoczność wyłącznie własnych danych, kontrakt
    endpointu anulowania, ownership, statusy i przenoszenie anulowanej
    rezerwacji z sekcji nadchodzących do historii.
*/

test_group('Panel klienta', 'database/database.sql');

function client_panel_session(string $email = 'jan.kowalski@example.com'): array
{
    $session = login_as($email);
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');
    $session['csrf'] = csrf_from_page($session['cookies'], '/panel.php');

    return $session;
}

function client_panel_find_slot(array &$session, int $employeeId, int $serviceId): ?array
{
    for ($offset = 0; $offset < 14; $offset++) {
        $date = date('Y-m-d', strtotime('+' . $offset . ' days'));
        $response = http_request(
            $session['cookies'],
            'GET',
            '/api/availability.php?pracownik_id=' . $employeeId
            . '&usluga_id=' . $serviceId
            . '&data=' . urlencode($date)
        );

        if ($response['status'] !== 200) {
            continue;
        }

        $data = json_body($response);
        $slots = is_array($data['sloty'] ?? null) ? $data['sloty'] : [];

        if ($slots !== []) {
            return ['date' => $date, 'slot' => $slots[0]];
        }
    }

    return null;
}

function client_panel_create_reservation(
    array &$session,
    int $employeeId = 1,
    int $serviceId = 1,
    ?string $comment = null
): array {
    $found = client_panel_find_slot($session, $employeeId, $serviceId);
    assert_true($found !== null, 'Powinien istnieć wolny termin do utworzenia rezerwacji');

    $start = (string) $found['slot']['start'];
    $payload = [
        'pracownik_id' => $employeeId,
        'usluga_id' => $serviceId,
        'data' => substr($start, 0, 10),
        'godzina' => substr($start, 11, 8),
    ];

    if ($comment !== null) {
        $payload['komentarz'] = $comment;
    }

    $response = post_json(
        $session['cookies'],
        '/api/reservations.php',
        $payload,
        $session['csrf']
    );
    assert_status(201, $response, 'Utworzenie rezerwacji testowej powinno dać 201');
    $data = json_body($response);

    return [
        'id' => (int) ($data['id'] ?? 0),
        'start' => $start,
        'comment' => $comment,
    ];
}

// Wycinek HTML sekcji panelu; granicę nadchodzących wyznacza początek historii.
function client_panel_section(string $html, string $sectionId): string
{
    $marker = 'id="' . $sectionId . '"';
    $start = strpos($html, $marker);

    if ($start === false) {
        fail('Nie znaleziono sekcji ' . $sectionId . ' w panelu klienta');
    }

    if ($sectionId === 'client-reservations-upcoming') {
        $historyStart = strpos($html, 'id="client-reservations-history"');

        if ($historyStart !== false) {
            return substr($html, $start, $historyStart - $start);
        }
    }

    return substr($html, $start);
}

test('207. panel klienta bez logowania przekierowuje na login', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/panel.php');

    assert_true(
        in_array($response['status'], [302, 303], true),
        'Wejście do panelu bez sesji powinno przekierować na login'
    );
    $location = header_value($response, 'location');
    assert_true(
        is_string($location) && str_ends_with($location, '/login'),
        'Nagłówek Location powinien prowadzić do /login'
    );
});

test('208. zalogowany klient widzi nagłówek i tabelę rezerwacji', function (): void {
    $session = client_panel_session();
    $response = http_request($session['cookies'], 'GET', '/panel.php');

    assert_status(200, $response, 'Panel klienta powinien zwrócić 200');
    assert_true(
        strpos($response['body'], 'Moje rezerwacje') !== false,
        'Panel powinien zawierać nagłówek „Moje rezerwacje”'
    );
    assert_true(
        strpos($response['body'], '<table') !== false,
        'Panel powinien zawierać tabelę rezerwacji'
    );
});

test('209. klient widzi wyłącznie własne rezerwacje', function (): void {
    $session = client_panel_session();
    $response = http_request($session['cookies'], 'GET', '/panel.php');

    assert_status(200, $response, 'Panel klienta powinien zwrócić 200');
    assert_true(
        strpos($response['body'], 'Płyta nie reaguje na włącznik po zalaniu herbatą.') === false,
        'Panel nie może pokazywać komentarza rezerwacji innego klienta'
    );
    assert_true(
        strpos($response['body'], '11:00') === false,
        'Panel nie może pokazywać godziny rezerwacji innego klienta'
    );
});

test('210. PATCH anulowania bez CSRF daje 403', function (): void {
    $session = client_panel_session();
    $response = patch_json($session['cookies'], '/api/client-reservations.php', ['id' => 1]);

    assert_status(403, $response, 'Brak tokenu CSRF powinien dać 403');
});

test('211. PATCH anulowania bez logowania daje 401', function (): void {
    $cookies = [];
    $response = patch_json($cookies, '/api/client-reservations.php', ['id' => 1]);

    assert_status(401, $response, 'Brak sesji powinien dać 401');
});

test('212. GET na endpoint anulowania daje 405 z Allow: PATCH', function (): void {
    $session = client_panel_session();
    $response = http_request($session['cookies'], 'GET', '/api/client-reservations.php');

    assert_status(405, $response, 'Nieprawidłowa metoda powinna dać 405');
    assert_same('PATCH', header_value($response, 'allow'), 'Nagłówek Allow powinien dopuszczać tylko PATCH');
});

test('213. klient anuluje własną przyszłą rezerwację oczekującą', function (): void {
    $session = client_panel_session();
    $reservation = client_panel_create_reservation($session);
    assert_true($reservation['id'] > 0, 'Rezerwacja testowa powinna mieć identyfikator');
    assert_true(
        new DateTimeImmutable($reservation['start']) > new DateTimeImmutable('now'),
        'Rezerwacja testowa powinna być przyszła'
    );

    $response = patch_json(
        $session['cookies'],
        '/api/client-reservations.php',
        ['id' => $reservation['id']],
        $session['csrf']
    );

    assert_status(200, $response, 'Anulowanie własnej rezerwacji powinno dać 200');
    $data = json_body($response);
    assert_same(true, $data['success'] ?? null, 'Odpowiedź powinna zawierać success = true');
    assert_same('anulowana', $data['status'] ?? null, 'Odpowiedź powinna zwrócić status anulowana');
    assert_true(
        is_string($data['anulowano'] ?? null) && $data['anulowano'] !== '',
        'Odpowiedź powinna zawierać datę anulowania'
    );

    $statement = test_db()->prepare(
        'SELECT status_rezerwacji, anulowano FROM rezerwacje WHERE id = :id'
    );
    $statement->execute(['id' => $reservation['id']]);
    $row = $statement->fetch();

    assert_true(is_array($row), 'Rezerwacja powinna istnieć w bazie');
    assert_same('anulowana', (string) $row['status_rezerwacji'], 'Status w bazie powinien być anulowana');
    assert_true($row['anulowano'] !== null, 'Kolumna anulowano powinna być ustawiona');
    assert_same(
        (string) $data['anulowano'],
        (string) $row['anulowano'],
        'Data w odpowiedzi powinna pochodzić z bazy'
    );
});

test('214. powtórne anulowanie tej samej rezerwacji daje 409', function (): void {
    $session = client_panel_session();
    $reservation = client_panel_create_reservation($session);
    $first = patch_json(
        $session['cookies'],
        '/api/client-reservations.php',
        ['id' => $reservation['id']],
        $session['csrf']
    );
    assert_status(200, $first, 'Pierwsze anulowanie powinno dać 200');

    $second = patch_json(
        $session['cookies'],
        '/api/client-reservations.php',
        ['id' => $reservation['id']],
        $session['csrf']
    );
    assert_status(409, $second, 'Powtórne anulowanie powinno dać 409');
});

test('215. klient nie może anulować rezerwacji innego klienta', function (): void {
    $owner = client_panel_session('anna.nowak@example.com');
    $reservation = client_panel_create_reservation($owner, 1, 1, 'Rezerwacja innego klienta');
    assert_true($reservation['id'] > 0, 'Rezerwacja innego klienta powinna mieć identyfikator');

    $session = client_panel_session();
    $response = patch_json(
        $session['cookies'],
        '/api/client-reservations.php',
        ['id' => $reservation['id']],
        $session['csrf']
    );

    assert_status(403, $response, 'Anulowanie cudzej rezerwacji powinno dać 403');

    $statement = test_db()->prepare(
        'SELECT status_rezerwacji FROM rezerwacje WHERE id = :id'
    );
    $statement->execute(['id' => $reservation['id']]);

    assert_same(
        'oczekujaca',
        (string) $statement->fetchColumn(),
        'Cudza rezerwacja nie może zmienić statusu'
    );
});

test('216. anulowanie rezerwacji zrealizowanej daje 409', function (): void {
    $session = client_panel_session();
    $reservation = client_panel_create_reservation($session);
    $statement = test_db()->prepare(
        "UPDATE rezerwacje SET status_rezerwacji = 'zrealizowana' WHERE id = :id"
    );
    $statement->execute(['id' => $reservation['id']]);

    $response = patch_json(
        $session['cookies'],
        '/api/client-reservations.php',
        ['id' => $reservation['id']],
        $session['csrf']
    );

    assert_status(409, $response, 'Anulowanie rezerwacji zrealizowanej powinno dać 409');
});

test('217. anulowanie nieistniejącej rezerwacji daje 404', function (): void {
    $session = client_panel_session();
    $response = patch_json(
        $session['cookies'],
        '/api/client-reservations.php',
        ['id' => 999999],
        $session['csrf']
    );

    assert_status(404, $response, 'Anulowanie nieistniejącej rezerwacji powinno dać 404');
});

test('218. anulowana rezerwacja znika z nadchodzących i trafia do historii', function (): void {
    $session = client_panel_session();
    $marker = 'Test panelu klienta 218 ' . uniqid();
    $reservation = client_panel_create_reservation($session, 1, 1, $marker);

    $before = http_request($session['cookies'], 'GET', '/panel.php');
    assert_status(200, $before, 'Panel przed anulowaniem powinien zwrócić 200');
    $beforeUpcoming = client_panel_section($before['body'], 'client-reservations-upcoming');
    assert_true(
        strpos($beforeUpcoming, $marker) !== false,
        'Rezerwacja musi być widoczna w nadchodzących przed anulowaniem'
    );

    $response = patch_json(
        $session['cookies'],
        '/api/client-reservations.php',
        ['id' => $reservation['id']],
        $session['csrf']
    );
    assert_status(200, $response, 'Anulowanie rezerwacji powinno dać 200');

    $after = http_request($session['cookies'], 'GET', '/panel.php');
    assert_status(200, $after, 'Panel po anulowaniu powinien zwrócić 200');
    $afterUpcoming = client_panel_section($after['body'], 'client-reservations-upcoming');
    $afterHistory = client_panel_section($after['body'], 'client-reservations-history');

    assert_true(
        strpos($afterUpcoming, $marker) === false,
        'Anulowana rezerwacja nie może pozostać w nadchodzących'
    );
    assert_true(
        strpos($afterHistory, $marker) !== false,
        'Anulowana rezerwacja powinna pojawić się w historii'
    );
});

test('219. przyszła rezerwacja przed anulowaniem jest tylko w nadchodzących', function (): void {
    $session = client_panel_session();
    $marker = 'Test panelu klienta 219 ' . uniqid();
    $pastMarker = 'Test panelu klienta 219 przeszła ' . uniqid();
    $reservation = client_panel_create_reservation($session, 1, 1, $marker);

    $statement = test_db()->prepare(
        "INSERT INTO rezerwacje (
            uzytkownik_id, pracownik_id, usluga_id, czas_rozpoczecia, czas_zakonczenia,
            cena_historyczna, status_rezerwacji, komentarz_klienta
         ) VALUES (
            4, 1, 1, DATE_SUB(NOW(), INTERVAL 2 DAY),
            DATE_ADD(DATE_SUB(NOW(), INTERVAL 2 DAY), INTERVAL 1 HOUR),
            150.00, 'oczekujaca', :komentarz
         )"
    );
    $statement->execute(['komentarz' => $pastMarker]);

    $response = http_request($session['cookies'], 'GET', '/panel.php');
    assert_status(200, $response, 'Panel przed anulowaniem powinien zwrócić 200');
    $upcoming = client_panel_section($response['body'], 'client-reservations-upcoming');
    $history = client_panel_section($response['body'], 'client-reservations-history');

    assert_true(
        strpos($upcoming, $marker) !== false,
        'Przyszła rezerwacja musi być widoczna w sekcji nadchodzących'
    );
    assert_true(
        strpos($history, $marker) === false,
        'Przyszła rezerwacja nie może być widoczna w historii przed anulowaniem'
    );
    assert_true(
        strpos($history, $pastMarker) !== false,
        'Przeszła rezerwacja musi być widoczna w historii'
    );
    assert_true(
        strpos($upcoming, $pastMarker) === false,
        'Przeszła rezerwacja nie może być widoczna w nadchodzących'
    );

    $cancel = patch_json(
        $session['cookies'],
        '/api/client-reservations.php',
        ['id' => $reservation['id']],
        $session['csrf']
    );
    assert_status(200, $cancel, 'Sprzątające anulowanie rezerwacji testowej powinno dać 200');
});
