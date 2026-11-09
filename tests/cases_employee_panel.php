<?php
declare(strict_types=1);

/*
    Testy panelu pracownika i zmiany statusu przypisanych rezerwacji.

    Obejmują ochronę strony, izolację wizyt między pracownikami, kartę
    informacyjną administratora, kontrakt endpointu PATCH, maszynę stanów,
    zapis anulowania oraz rozłączność sekcji dzisiaj, nadchodzące i historia.
*/

test_group('Panel pracownika', 'database/database.sql');

function employee_panel_session(string $email = 'tomasz.serwisant@wefixit.pl'): array
{
    $session = login_as($email);
    assert_status(200, $session['response'], 'Logowanie pracownika powinno się udać');
    $session['csrf'] = csrf_from_page($session['cookies'], '/pracownik.php');

    return $session;
}

function employee_panel_client_session(string $email = 'jan.kowalski@example.com'): array
{
    $session = login_as($email);
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');
    $session['csrf'] = csrf_from_page($session['cookies'], '/panel.php');

    return $session;
}

function employee_panel_find_slot(array &$session, int $employeeId, int $serviceId): ?array
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

function employee_panel_create_reservation(
    int $employeeId = 1,
    int $serviceId = 1,
    ?string $comment = null,
    string $clientEmail = 'jan.kowalski@example.com'
): array {
    $client = employee_panel_client_session($clientEmail);
    $found = employee_panel_find_slot($client, $employeeId, $serviceId);
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
        $client['cookies'],
        '/api/reservations.php',
        $payload,
        $client['csrf']
    );
    assert_status(201, $response, 'Utworzenie rezerwacji testowej powinno dać 201');
    $data = json_body($response);

    return [
        'id' => (int) ($data['id'] ?? 0),
        'start' => $start,
        'comment' => $comment,
    ];
}

function employee_panel_section_html(string $html, string $sectionId): string
{
    $marker = 'id="' . $sectionId . '"';
    $start = strpos($html, $marker);

    if ($start === false) {
        fail('Nie znaleziono sekcji ' . $sectionId . ' w panelu pracownika');
    }

    $nextMarkers = match ($sectionId) {
        'employee-reservations-today' => ['id="employee-reservations-upcoming"'],
        'employee-reservations-upcoming' => ['id="employee-reservations-history"'],
        default => ['</main>'],
    };

    foreach ($nextMarkers as $nextMarker) {
        $end = strpos($html, $nextMarker, $start);

        if ($end !== false) {
            return substr($html, $start, $end - $start);
        }
    }

    return substr($html, $start);
}

test('220. panel pracownika bez logowania przekierowuje na login', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/pracownik.php');

    assert_true(
        in_array($response['status'], [302, 303], true),
        'Wejście do panelu pracownika bez sesji powinno przekierować na login'
    );
    $location = header_value($response, 'location');
    assert_true(
        is_string($location) && str_ends_with($location, '/login'),
        'Nagłówek Location powinien prowadzić do /login'
    );
});

test('221. zalogowany pracownik widzi sekcje wizyt zamiast zaślepki', function (): void {
    $session = employee_panel_session('tomasz.serwisant@wefixit.pl');
    $response = http_request($session['cookies'], 'GET', '/pracownik.php');

    assert_status(200, $response, 'Panel pracownika powinien zwrócić 200');
    assert_true(
        strpos($response['body'], 'id="employee-reservations-today"') !== false,
        'Panel powinien zawierać sekcję dzisiejszych wizyt'
    );
    assert_true(
        strpos($response['body'], 'id="employee-reservations-upcoming"') !== false,
        'Panel powinien zawierać sekcję nadchodzących wizyt'
    );
    assert_true(
        strpos($response['body'], 'id="employee-reservations-history"') !== false,
        'Panel powinien zawierać sekcję historii wizyt'
    );
    assert_true(
        strpos($response['body'], 'Ta strona jest dostępna dla pracowników i administratorów.') === false,
        'Panel nie może już wyświetlać dawnej zaślepki'
    );
});

test('222. pracownik widzi wyłącznie własne wizyty', function (): void {
    $markerOne = 'Wizyta pracownika 1 ' . uniqid();
    $markerTwo = 'Wizyta pracownika 2 ' . uniqid();
    employee_panel_create_reservation(1, 1, $markerOne, 'piotr.wisniewski@example.com');
    employee_panel_create_reservation(2, 3, $markerTwo, 'anna.nowak@example.com');

    $employeeOne = employee_panel_session('tomasz.serwisant@wefixit.pl');
    $pageOne = http_request($employeeOne['cookies'], 'GET', '/pracownik.php');
    assert_status(200, $pageOne, 'Panel pracownika 1 powinien zwrócić 200');

    $employeeTwo = employee_panel_session('marcin.elektronik@wefixit.pl');
    $pageTwo = http_request($employeeTwo['cookies'], 'GET', '/pracownik.php');
    assert_status(200, $pageTwo, 'Panel pracownika 2 powinien zwrócić 200');

    assert_true(
        strpos($pageOne['body'], $markerOne) !== false,
        'Pracownik 1 powinien widzieć własną rezerwację'
    );
    assert_true(
        strpos($pageOne['body'], 'Piotr Wisniewski') !== false
        && strpos($pageOne['body'], '+48987654323') !== false,
        'Pracownik 1 powinien widzieć dane klienta swojej rezerwacji'
    );
    assert_true(
        strpos($pageOne['body'], $markerTwo) === false,
        'Pracownik 1 nie może widzieć rezerwacji pracownika 2'
    );
    assert_true(
        strpos($pageOne['body'], 'Płyta nie reaguje na włącznik po zalaniu herbatą.') === false,
        'Pracownik 1 nie może widzieć wizyty pracownika 2 z danych seed'
    );

    assert_true(
        strpos($pageTwo['body'], $markerTwo) !== false,
        'Pracownik 2 powinien widzieć własną rezerwację'
    );
    assert_true(
        strpos($pageTwo['body'], $markerOne) === false,
        'Pracownik 2 nie może widzieć rezerwacji pracownika 1'
    );
    assert_true(
        strpos($pageTwo['body'], 'Piotr Wisniewski') === false
        && strpos($pageTwo['body'], '+48987654323') === false,
        'Pracownik 2 nie może widzieć danych klienta z rezerwacji pracownika 1'
    );
});

test('223. administrator widzi informację o braku profilu pracownika i link do rezerwacji', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $response = http_request($session['cookies'], 'GET', '/pracownik.php');
    assert_status(200, $response, 'Podgląd panelu pracownika dla administratora powinien dać 200');
    assert_true(
        strpos($response['body'], 'Konto administratora nie ma profilu pracownika') !== false,
        'Administrator powinien zobaczyć informację o braku profilu pracownika'
    );
    assert_true(
        strpos($response['body'], 'href="/rezerwacje"') !== false,
        'Administrator powinien mieć link do /rezerwacje'
    );
    assert_true(
        strpos($response['body'], 'id="employee-reservations-today"') === false,
        'Administrator nie powinien widzieć sekcji wizyt pracownika'
    );
});

test('224. PATCH zmiany statusu pracownika bez CSRF daje 403', function (): void {
    $session = employee_panel_session();
    $response = patch_json($session['cookies'], '/api/employee-reservations.php', [
        'id' => 1,
        'status' => 'potwierdzona',
    ]);

    assert_status(403, $response, 'Brak tokenu CSRF powinien dać 403');
});

test('225. PATCH zmiany statusu pracownika bez logowania daje 401', function (): void {
    $cookies = [];
    $response = patch_json($cookies, '/api/employee-reservations.php', [
        'id' => 1,
        'status' => 'potwierdzona',
    ]);

    assert_status(401, $response, 'Brak sesji powinien dać 401');
});

test('226. POST na endpoint rezerwacji pracownika daje 405 z Allow: PATCH', function (): void {
    $session = employee_panel_session();
    $response = post_json(
        $session['cookies'],
        '/api/employee-reservations.php',
        ['id' => 1, 'status' => 'potwierdzona'],
        $session['csrf']
    );

    assert_status(405, $response, 'Metoda POST powinna dać 405');
    assert_same('PATCH', header_value($response, 'allow'), 'Nagłówek Allow powinien dopuszczać tylko PATCH');
});

test('227. pracownik zmienia status własnej rezerwacji z oczekującej na potwierdzoną', function (): void {
    $reservation = employee_panel_create_reservation(1, 1, 'Test potwierdzenia 227');
    $session = employee_panel_session('tomasz.serwisant@wefixit.pl');

    $response = patch_json(
        $session['cookies'],
        '/api/employee-reservations.php',
        ['id' => $reservation['id'], 'status' => 'potwierdzona'],
        $session['csrf']
    );

    assert_status(200, $response, 'Zmiana statusu własnej rezerwacji powinna dać 200');
    $data = json_body($response);
    assert_same(true, $data['success'] ?? null, 'Odpowiedź powinna zawierać success = true');
    assert_same($reservation['id'], $data['id'] ?? null, 'Odpowiedź powinna zwrócić id rezerwacji');
    assert_same('potwierdzona', $data['status'] ?? null, 'Odpowiedź powinna zwrócić nowy status');

    $statement = test_db()->prepare(
        'SELECT status_rezerwacji, anulowano FROM rezerwacje WHERE id = :id'
    );
    $statement->execute(['id' => $reservation['id']]);
    $row = $statement->fetch();

    assert_true(is_array($row), 'Rezerwacja powinna istnieć w bazie');
    assert_same('potwierdzona', (string) $row['status_rezerwacji'], 'Status w bazie powinien być potwierdzona');
    assert_same(null, $row['anulowano'], 'Kolumna anulowano powinna pozostać NULL');
});

test('228. próba zmiany statusu rezerwacji innego pracownika daje 404', function (): void {
    $reservation = employee_panel_create_reservation(2, 3, 'Wizyta pracownika 2 dla testu 228');
    $session = employee_panel_session('tomasz.serwisant@wefixit.pl');

    $response = patch_json(
        $session['cookies'],
        '/api/employee-reservations.php',
        ['id' => $reservation['id'], 'status' => 'potwierdzona'],
        $session['csrf']
    );

    assert_status(404, $response, 'Cudza rezerwacja pracownika powinna dać 404');

    $statement = test_db()->prepare(
        'SELECT status_rezerwacji FROM rezerwacje WHERE id = :id'
    );
    $statement->execute(['id' => $reservation['id']]);

    assert_same(
        'oczekujaca',
        (string) $statement->fetchColumn(),
        'Status rezerwacji innego pracownika nie może się zmienić'
    );
});

test('229. niedozwolone przejście statusu w panelu pracownika daje 422', function (): void {
    $reservation = employee_panel_create_reservation(1, 1, 'Test niedozwolonego przejścia 229');
    $statement = test_db()->prepare(
        "UPDATE rezerwacje SET status_rezerwacji = 'zrealizowana' WHERE id = :id"
    );
    $statement->execute(['id' => $reservation['id']]);

    $session = employee_panel_session('tomasz.serwisant@wefixit.pl');
    $response = patch_json(
        $session['cookies'],
        '/api/employee-reservations.php',
        ['id' => $reservation['id'], 'status' => 'potwierdzona'],
        $session['csrf']
    );

    assert_status(422, $response, 'Przejście zrealizowana -> potwierdzona powinno dać 422');
});

test('230. anulowanie przez pracownika ustawia status i datę anulowania', function (): void {
    $reservation = employee_panel_create_reservation(1, 1, 'Test anulowania przez pracownika 230');
    $session = employee_panel_session('tomasz.serwisant@wefixit.pl');

    $response = patch_json(
        $session['cookies'],
        '/api/employee-reservations.php',
        ['id' => $reservation['id'], 'status' => 'anulowana'],
        $session['csrf']
    );

    assert_status(200, $response, 'Anulowanie własnej rezerwacji przez pracownika powinno dać 200');

    $statement = test_db()->prepare(
        'SELECT status_rezerwacji, anulowano FROM rezerwacje WHERE id = :id'
    );
    $statement->execute(['id' => $reservation['id']]);
    $row = $statement->fetch();

    assert_true(is_array($row), 'Rezerwacja powinna istnieć w bazie');
    assert_same('anulowana', (string) $row['status_rezerwacji'], 'Status w bazie powinien być anulowana');
    assert_true($row['anulowano'] !== null, 'Kolumna anulowano powinna być ustawiona');
});

test('231. klient na endpointcie rezerwacji pracownika dostaje 403', function (): void {
    $reservation = employee_panel_create_reservation(1, 1, 'Test roli klienta 231');
    $client = employee_panel_client_session('jan.kowalski@example.com');

    $response = patch_json(
        $client['cookies'],
        '/api/employee-reservations.php',
        ['id' => $reservation['id'], 'status' => 'potwierdzona'],
        $client['csrf']
    );

    assert_status(403, $response, 'Klient nie może korzystać z endpointu pracownika');
});

test('232. sekcje dzisiaj, nadchodzące i historia są rozłączne', function (): void {
    $insert = test_db()->prepare(
        "INSERT INTO rezerwacje (
            uzytkownik_id, pracownik_id, usluga_id, czas_rozpoczecia, czas_zakonczenia,
            cena_historyczna, status_rezerwacji, komentarz_klienta, anulowano
         ) VALUES (
            4, 1, 1, :start, :end, 150.00, :status, :komentarz, :anulowano
         )"
    );

    $todayDate = (new DateTimeImmutable('today'))->format('Y-m-d');
    $insert->execute([
        'start' => $todayDate . ' 12:00:00',
        'end' => $todayDate . ' 13:00:00',
        'status' => 'zrealizowana',
        'komentarz' => 'Dzisiejsza zrealizowana 232',
        'anulowano' => null,
    ]);

    $session = employee_panel_session('tomasz.serwisant@wefixit.pl');
    $response = http_request($session['cookies'], 'GET', '/pracownik.php');
    assert_status(200, $response, 'Panel pracownika powinien zwrócić 200');

    $todayHtml = employee_panel_section_html($response['body'], 'employee-reservations-today');
    $upcomingHtml = employee_panel_section_html($response['body'], 'employee-reservations-upcoming');
    $historyHtml = employee_panel_section_html($response['body'], 'employee-reservations-history');

    assert_true(
        strpos($todayHtml, 'Dzisiejsza zrealizowana 232') !== false,
        'Dzisiejsza zrealizowana wizyta powinna trafić do sekcji Dzisiaj'
    );
    assert_true(
        strpos($historyHtml, 'Dzisiejsza zrealizowana 232') === false,
        'Dzisiejsza zrealizowana wizyta nie może pojawić się jednocześnie w Historii'
    );

    $ids = [];

    foreach ([$todayHtml, $upcomingHtml, $historyHtml] as $sectionHtml) {
        preg_match_all('/data-employee-reservation-row[^>]*data-reservation-id="(\d+)"/', $sectionHtml, $matches);

        foreach ($matches[1] as $id) {
            $ids[] = (int) $id;
        }
    }

    $countStatement = test_db()->prepare('SELECT COUNT(*) FROM rezerwacje WHERE pracownik_id = 1');
    $countStatement->execute();
    $expectedTotal = (int) $countStatement->fetchColumn();

    assert_same(
        $expectedTotal,
        count($ids),
        'Suma rekordów w trzech sekcjach musi równać się liczbie rezerwacji pracownika w bazie'
    );
    assert_same(
        count($ids),
        count(array_unique($ids)),
        'Żadna rezerwacja nie może wystąpić w więcej niż jednej sekcji'
    );
});
