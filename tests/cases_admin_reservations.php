<?php
declare(strict_types=1);

/*
    Testy administracji rezerwacjami.

    Obejmują listę z filtrami, zmianę statusu według maszyny stanów,
    anulowanie i cofnięcie anulowania, kontrolę dostępu, CSRF, kontrakt HTTP,
    walidację oraz brak wycieku danych technicznych. Każdy test przygotowuje
    własny stan początkowy.
*/

test_group('Panel: rezerwacje', 'database/database.sql');

function admin_reservations_set_status(int $id, string $status, ?string $cancelledAt): void
{
    $statement = test_db()->prepare(
        'UPDATE rezerwacje
         SET status_rezerwacji = :status, anulowano = :anulowano
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $id,
        'status' => $status,
        'anulowano' => $cancelledAt,
    ]);
}

function admin_reservations_reset_base(): void
{
    admin_reservations_set_status(1, 'zrealizowana', null);
    admin_reservations_set_status(2, 'w_trakcie', null);
    admin_reservations_set_status(3, 'potwierdzona', null);
    admin_reservations_set_status(4, 'oczekujaca', null);
    admin_reservations_set_status(5, 'anulowana', '2026-01-05 12:00:00');
}

function admin_reservations_row(int $id): ?array
{
    $statement = test_db()->prepare(
        'SELECT id, status_rezerwacji, anulowano
         FROM rezerwacje
         WHERE id = :id'
    );
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function admin_reservations_clear_logs(int $id): void
{
    $statement = test_db()->prepare(
        "DELETE FROM log_zdarzen
         WHERE obiekt_typ = 'rezerwacja' AND obiekt_id = :id"
    );
    $statement->execute(['id' => $id]);
}

function admin_reservations_admin_session(): array
{
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');
    $session['csrf'] = csrf_from_page($session['cookies'], '/rezerwacje.php');

    return $session;
}

function admin_reservations_patch(array &$session, array $payload): array
{
    return patch_json(
        $session['cookies'],
        '/api/admin/reservations.php',
        $payload,
        $session['csrf']
    );
}

function admin_reservations_list(array &$session, string $query = ''): array
{
    return http_request(
        $session['cookies'],
        'GET',
        '/api/admin/reservations.php' . $query
    );
}

test('107. administrator pobiera listę rezerwacji z pełnymi danymi', function (): void {
    admin_reservations_reset_base();
    $session = admin_reservations_admin_session();
    $response = admin_reservations_list($session);

    assert_status(200, $response, 'GET listy rezerwacji powinien dać 200');
    $data = json_body($response);
    assert_same(true, $data['success'] ?? null, 'Odpowiedź powinna zawierać success = true');
    assert_true(is_array($data['rezerwacje'] ?? null), 'Odpowiedź powinna zawierać listę rezerwacji');

    $reservation = null;

    foreach ($data['rezerwacje'] as $item) {
        if (is_array($item) && (int) ($item['id'] ?? 0) === 4) {
            $reservation = $item;
            break;
        }
    }

    assert_true(is_array($reservation), 'Lista powinna zawierać rezerwację 4');
    assert_same('oczekujaca', $reservation['status_rezerwacji'] ?? null, 'Status rezerwacji powinien być oczekujaca');
    assert_same('Jan', $reservation['klient_imie'] ?? null, 'Lista powinna zawierać imię klienta');
    assert_same('Kowalski', $reservation['klient_nazwisko'] ?? null, 'Lista powinna zawierać nazwisko klienta');
    assert_same('jan.kowalski@example.com', $reservation['klient_email'] ?? null, 'Lista powinna zawierać e-mail klienta');
    assert_same('Marcin', $reservation['pracownik_imie'] ?? null, 'Lista powinna zawierać imię pracownika');
    assert_same('Elektronik', $reservation['pracownik_nazwisko'] ?? null, 'Lista powinna zawierać nazwisko pracownika');
    assert_same('Wymiana matrycy w laptopie', $reservation['usluga_nazwa'] ?? null, 'Lista powinna zawierać nazwę usługi');
    assert_same('Naprawy Sprzętowe PC i Laptopów', $reservation['usluga_kategoria'] ?? null, 'Lista powinna zawierać kategorię usługi');

    foreach ([
        'czas_rozpoczecia',
        'czas_zakonczenia',
        'cena_historyczna',
        'komentarz_klienta',
        'anulowano',
        'utworzono',
        'zaktualizowano',
    ] as $field) {
        assert_array_key($reservation, $field, 'Rezerwacja powinna zawierać pole ' . $field);
    }

    assert_array_has_not_key($reservation, 'haslo_hash', 'Lista nie może ujawniać hasła');
});

test('108. klient i pracownik dostają 403 na stronie oraz endpointcie', function (): void {
    admin_reservations_reset_base();

    foreach ([
        ['email' => 'jan.kowalski@example.com', 'label' => 'klienta'],
        ['email' => 'tomasz.serwisant@wefixit.pl', 'label' => 'pracownika'],
    ] as $account) {
        $session = login_as($account['email']);
        assert_status(200, $session['response'], 'Logowanie ' . $account['label'] . ' powinno się udać');

        $page = http_request($session['cookies'], 'GET', '/rezerwacje.php');
        assert_status(403, $page, 'Konto ' . $account['label'] . ' powinno dostać 403 na stronie');

        $list = http_request($session['cookies'], 'GET', '/api/admin/reservations.php');
        assert_status(403, $list, 'Konto ' . $account['label'] . ' powinno dostać 403 na GET');

        $csrf = csrf_from_page($session['cookies'], '/profil.php');
        $change = patch_json($session['cookies'], '/api/admin/reservations.php', [
            'id' => 4,
            'status' => 'potwierdzona',
        ], $csrf);
        assert_status(403, $change, 'Konto ' . $account['label'] . ' powinno dostać 403 na PATCH');
    }

    $row = admin_reservations_row(4);
    assert_same('oczekujaca', (string) $row['status_rezerwacji'], 'Brak uprawnień nie może zmienić statusu');
});

test('109. PATCH bez tokenu CSRF daje 403 i nie zmienia statusu', function (): void {
    admin_reservations_reset_base();
    $session = admin_reservations_admin_session();

    $response = patch_json($session['cookies'], '/api/admin/reservations.php', [
        'id' => 4,
        'status' => 'potwierdzona',
    ]);
    assert_status(403, $response, 'PATCH bez CSRF powinien dać 403');

    $row = admin_reservations_row(4);
    assert_same('oczekujaca', (string) $row['status_rezerwacji'], 'Żądanie bez CSRF nie może zmienić statusu');
});

test('110. administrator zmienia status oczekujaca na potwierdzona i zapisuje log', function (): void {
    admin_reservations_reset_base();
    admin_reservations_clear_logs(4);
    $session = admin_reservations_admin_session();

    $response = admin_reservations_patch($session, [
        'id' => 4,
        'status' => 'potwierdzona',
    ]);
    assert_status(200, $response, 'Zmiana statusu powinna dać 200');

    $data = json_body($response);
    assert_same(true, $data['success'] ?? null, 'Odpowiedź powinna zawierać success = true');
    assert_same(4, $data['id'] ?? null, 'Odpowiedź powinna zawierać id rezerwacji');
    assert_same('potwierdzona', $data['status'] ?? null, 'Odpowiedź powinna zawierać nowy status');
    assert_array_key($data, 'anulowano', 'Odpowiedź powinna zawierać pole anulowano');
    assert_same(null, $data['anulowano'], 'Przy potwierdzonej anulowano musi być puste');

    $row = admin_reservations_row(4);
    assert_same('potwierdzona', (string) $row['status_rezerwacji'], 'Status w bazie powinien się zmienić');
    assert_same(null, $row['anulowano'], 'Pole anulowano powinno pozostać puste');

    $log = test_db()->query(
        "SELECT akcja, obiekt_typ, obiekt_id, poziom, szczegoly
         FROM log_zdarzen
         WHERE akcja = 'rezerwacja_status_zmieniony' AND obiekt_id = 4
         ORDER BY id DESC
         LIMIT 1"
    )->fetch();
    assert_true(is_array($log), 'Zmiana statusu powinna trafić do logu');
    assert_same('rezerwacja', (string) $log['obiekt_typ'], 'Log powinien wskazywać typ rezerwacja');
    assert_same(4, (int) $log['obiekt_id'], 'Log powinien wskazywać rezerwację 4');
    assert_same('info', (string) $log['poziom'], 'Zmiana statusu powinna mieć poziom info');

    $details = json_decode((string) $log['szczegoly'], true);
    assert_same('oczekujaca', $details['status']['przed'] ?? null, 'Log powinien zawierać status przed zmianą');
    assert_same('potwierdzona', $details['status']['po'] ?? null, 'Log powinien zawierać status po zmianie');
});

test('111. anulowanie ustawia status i anulowano w jednej operacji', function (): void {
    admin_reservations_reset_base();
    admin_reservations_clear_logs(3);
    $session = admin_reservations_admin_session();

    $response = admin_reservations_patch($session, [
        'id' => 3,
        'status' => 'anulowana',
    ]);
    assert_status(200, $response, 'Anulowanie powinno dać 200');

    $data = json_body($response);
    assert_same('anulowana', $data['status'] ?? null, 'Odpowiedź powinna zawierać status anulowana');
    assert_true(
        isset($data['anulowano']) && is_string($data['anulowano']) && $data['anulowano'] !== '',
        'Odpowiedź powinna zawierać datę anulowania'
    );

    $row = admin_reservations_row(3);
    assert_same('anulowana', (string) $row['status_rezerwacji'], 'Status w bazie powinien być anulowana');
    assert_true($row['anulowano'] !== null, 'Anulowana rezerwacja musi mieć ustawione anulowano');

    $log = test_db()->query(
        "SELECT akcja, obiekt_typ, obiekt_id, poziom
         FROM log_zdarzen
         WHERE akcja = 'rezerwacja_anulowana' AND obiekt_id = 3
         ORDER BY id DESC
         LIMIT 1"
    )->fetch();
    assert_true(is_array($log), 'Anulowanie powinno trafić do logu');
    assert_same('rezerwacja', (string) $log['obiekt_typ'], 'Log powinien wskazywać typ rezerwacja');
    assert_same('warning', (string) $log['poziom'], 'Anulowanie powinno mieć poziom warning');
});

test('112. cofnięcie anulowania ustawia status i zeruje anulowano', function (): void {
    admin_reservations_reset_base();
    admin_reservations_clear_logs(5);
    $session = admin_reservations_admin_session();

    $response = admin_reservations_patch($session, [
        'id' => 5,
        'status' => 'oczekujaca',
    ]);
    assert_status(200, $response, 'Cofnięcie anulowania powinno dać 200');

    $data = json_body($response);
    assert_same('oczekujaca', $data['status'] ?? null, 'Odpowiedź powinna zawierać status oczekujaca');
    assert_array_key($data, 'anulowano', 'Odpowiedź powinna zawierać pole anulowano');
    assert_same(null, $data['anulowano'], 'Po cofnięciu anulowania pole musi być puste');

    $row = admin_reservations_row(5);
    assert_same('oczekujaca', (string) $row['status_rezerwacji'], 'Status w bazie powinien być oczekujaca');
    assert_same(null, $row['anulowano'], 'Pole anulowano powinno zostać wyzerowane');

    $log = test_db()->query(
        "SELECT akcja, poziom
         FROM log_zdarzen
         WHERE akcja = 'rezerwacja_status_zmieniony' AND obiekt_id = 5
         ORDER BY id DESC
         LIMIT 1"
    )->fetch();
    assert_true(is_array($log), 'Cofnięcie anulowania powinno trafić do logu');
    assert_same('info', (string) $log['poziom'], 'Cofnięcie anulowania powinno mieć poziom info');
});

test('113. nielegalne przejścia dają 422 i nie zmieniają rekordu', function (): void {
    admin_reservations_reset_base();
    $session = admin_reservations_admin_session();
    $cases = [
        ['id' => 1, 'status' => 'oczekujaca', 'label' => 'zrealizowana do oczekujaca'],
        ['id' => 5, 'status' => 'potwierdzona', 'label' => 'anulowana do potwierdzona'],
        ['id' => 3, 'status' => 'oczekujaca', 'label' => 'potwierdzona do oczekujaca'],
    ];

    foreach ($cases as $case) {
        $before = admin_reservations_row($case['id']);
        $response = admin_reservations_patch($session, [
            'id' => $case['id'],
            'status' => $case['status'],
        ]);

        assert_status(422, $response, 'Przejście "' . $case['label'] . '" powinno dać 422');
        $data = json_body($response);
        assert_true(
            isset($data['errors']['status']) && is_string($data['errors']['status']),
            'Odpowiedź powinna zawierać errors.status'
        );
        assert_same($before, admin_reservations_row($case['id']), 'Nielegalne przejście nie może zmienić rekordu');
    }
});

test('114. endpoint przyjmuje GET i PATCH, a POST i DELETE dają 405', function (): void {
    $cookies = [];

    foreach (['POST', 'DELETE'] as $method) {
        $response = http_request($cookies, $method, '/api/admin/reservations.php');
        assert_status(405, $response, $method . ' na endpoint rezerwacji powinien dać 405');

        $allow = header_value($response, 'allow');
        assert_true(is_string($allow) && $allow !== '', $method . ' powinien zwrócić nagłówek Allow');

        $methods = array_values(array_filter(array_map('trim', explode(',', $allow))));
        sort($methods);
        assert_same(['GET', 'PATCH'], $methods, 'Allow powinno zawierać wyłącznie GET i PATCH');
    }

    admin_reservations_reset_base();
    $session = admin_reservations_admin_session();
    $list = admin_reservations_list($session);
    assert_status(200, $list, 'GET listy rezerwacji powinien dać 200');

    $response = http_request($session['cookies'], 'PATCH', '/api/admin/reservations.php', [
        'body' => json_encode(['id' => 4, 'status' => 'potwierdzona'], JSON_UNESCAPED_UNICODE),
        'headers' => ['X-CSRF-Token' => $session['csrf']],
    ]);
    assert_status(415, $response, 'PATCH bez Content-Type application/json powinien dać 415');

    $row = admin_reservations_row(4);
    assert_same('oczekujaca', (string) $row['status_rezerwacji'], 'PATCH bez Content-Type nie może zmienić statusu');
});

test('115. walidacja identyfikatora i statusu rezerwacji', function (): void {
    admin_reservations_reset_base();
    $session = admin_reservations_admin_session();
    $cases = [
        [
            'payload' => ['status' => 'potwierdzona'],
            'status' => 422,
            'field' => 'id',
            'label' => 'brak id',
        ],
        [
            'payload' => ['id' => 0, 'status' => 'potwierdzona'],
            'status' => 422,
            'field' => 'id',
            'label' => 'id równe zero',
        ],
        [
            'payload' => ['id' => '4', 'status' => 'potwierdzona'],
            'status' => 422,
            'field' => 'id',
            'label' => 'id jako string',
        ],
        [
            'payload' => ['id' => 999999, 'status' => 'potwierdzona'],
            'status' => 404,
            'field' => null,
            'label' => 'nieistniejąca rezerwacja',
        ],
        [
            'payload' => ['id' => 4, 'status' => 'nieznany'],
            'status' => 422,
            'field' => 'status',
            'label' => 'status spoza ENUM',
        ],
        [
            'payload' => ['id' => 4, 'status' => 123],
            'status' => 422,
            'field' => 'status',
            'label' => 'status jako liczba',
        ],
        [
            'payload' => ['id' => 4, 'status' => ['potwierdzona']],
            'status' => 422,
            'field' => 'status',
            'label' => 'status jako tablica',
        ],
    ];

    foreach ($cases as $case) {
        $response = admin_reservations_patch($session, $case['payload']);
        assert_status(
            $case['status'],
            $response,
            'Przypadek "' . $case['label'] . '" powinien dać ' . $case['status']
        );

        if ($case['field'] !== null) {
            $data = json_body($response);
            assert_true(
                isset($data['errors'][$case['field']]) && is_string($data['errors'][$case['field']]),
                'Odpowiedź dla "' . $case['label'] . '" powinna zawierać errors.' . $case['field']
            );
        }
    }

    $row = admin_reservations_row(4);
    assert_same('oczekujaca', (string) $row['status_rezerwacji'], 'Walidacja nie może zmienić statusu');
});

test('116. filtry i sortowanie nie wywołują błędu dla błędnych wartości', function (): void {
    admin_reservations_reset_base();
    $session = admin_reservations_admin_session();
    $filtered = admin_reservations_list($session, '?status=anulowana');

    assert_status(200, $filtered, 'Filtr statusu powinien dać 200');
    $filteredData = json_body($filtered);
    assert_true(is_array($filteredData['rezerwacje'] ?? null), 'Filtr powinien zwrócić listę');
    assert_same(1, count($filteredData['rezerwacje']), 'Filtr anulowana powinien zwrócić jedną rezerwację');
    assert_same(5, (int) $filteredData['rezerwacje'][0]['id'], 'Filtr powinien zwrócić rezerwację 5');
    assert_same('anulowana', $filteredData['rezerwacje'][0]['status_rezerwacji'], 'Status powinien pasować do filtra');

    $byEmployee = admin_reservations_list($session, '?pracownik_id=2');
    assert_status(200, $byEmployee, 'Filtr pracownika powinien dać 200');

    foreach (json_body($byEmployee)['rezerwacje'] as $reservation) {
        assert_same(2, (int) $reservation['pracownik_id'], 'Filtr pracownika powinien zawężać wyniki');
    }

    $invalid = admin_reservations_list(
        $session,
        '?status=nieznany&data_od=2026-02-30&data_do=2026-13-01&pracownik_id=abc&usluga_id=-1&uzytkownik_id%5B%5D=1&kierunek=sideways'
    );
    assert_status(200, $invalid, 'Nieprawidłowe filtry powinny dać 200');
    assert_same(5, count(json_body($invalid)['rezerwacje']), 'Nieprawidłowe filtry powinny wrócić do pełnej listy');
    assert_true(strpos($invalid['body'], 'SQLSTATE') === false, 'Nieprawidłowy filtr nie może ujawniać SQL');

    $ascending = admin_reservations_list($session, '?kierunek=asc');
    assert_status(200, $ascending, 'Sortowanie rosnące powinno dać 200');
    $ascendingRows = json_body($ascending)['rezerwacje'];
    $previous = null;

    foreach ($ascendingRows as $reservation) {
        $current = (string) $reservation['czas_rozpoczecia'];

        if ($previous !== null) {
            assert_true($previous <= $current, 'Sortowanie rosnące powinno zachować kolejność terminów');
        }

        $previous = $current;
    }

    $descending = admin_reservations_list($session, '?kierunek=desc');
    assert_status(200, $descending, 'Sortowanie malejące powinno dać 200');
    $descendingRows = json_body($descending)['rezerwacje'];
    $previous = null;

    foreach ($descendingRows as $reservation) {
        $current = (string) $reservation['czas_rozpoczecia'];

        if ($previous !== null) {
            assert_true($previous >= $current, 'Sortowanie malejące powinno zachować kolejność terminów');
        }

        $previous = $current;
    }
});

test('117. strona rezerwacji zawiera tabelę i filtry oraz respektuje filtr', function (): void {
    admin_reservations_reset_base();
    $session = admin_reservations_admin_session();
    $page = http_request($session['cookies'], 'GET', '/rezerwacje.php');

    assert_status(200, $page, 'Strona rezerwacji powinna dać 200');
    assert_true(strpos($page['body'], 'id="reservations-table"') !== false, 'Strona powinna zawierać tabelę rezerwacji');
    assert_true(strpos($page['body'], 'id="reservations-filter-form"') !== false, 'Strona powinna zawierać formularz filtrów');
    assert_true(strpos($page['body'], 'name="data_od"') !== false, 'Filtr powinien zawierać datę od');
    assert_true(strpos($page['body'], 'name="data_do"') !== false, 'Filtr powinien zawierać datę do');
    assert_true(strpos($page['body'], 'name="status"') !== false, 'Filtr powinien zawierać status');
    assert_true(strpos($page['body'], 'name="pracownik_id"') !== false, 'Filtr powinien zawierać pracownika');
    assert_true(strpos($page['body'], 'name="usluga_id"') !== false, 'Filtr powinien zawierać usługę');
    assert_true(strpos($page['body'], 'name="uzytkownik_id"') !== false, 'Filtr powinien zawierać klienta');
    assert_same(5, substr_count($page['body'], 'data-reservation-row'), 'Tabela powinna mieć pięć wierszy');
    assert_true(strpos($page['body'], 'href="/rezerwacje"') !== false, 'Menu powinno zawierać link do rezerwacji');

    $filtered = http_request($session['cookies'], 'GET', '/rezerwacje.php?status=anulowana');
    assert_status(200, $filtered, 'Strona z filtrem statusu powinna dać 200');
    assert_same(1, substr_count($filtered['body'], 'data-reservation-row'), 'Filtr powinien pozostawić jeden wiersz');
    assert_true(
        preg_match('/<option value="anulowana"[^>]*selected/', $filtered['body']) === 1,
        'Filtr statusu powinien zachować wybraną wartość'
    );
});

test('118. odpowiedzi nie ujawniają danych technicznych, a log nie ma błędów PHP', function (): void {
    admin_reservations_reset_base();
    $session = admin_reservations_admin_session();
    $responses = [];

    $list = admin_reservations_list($session);
    assert_status(200, $list, 'GET listy do testu 118 powinien dać 200');
    $responses[] = $list;

    $changed = admin_reservations_patch($session, ['id' => 4, 'status' => 'potwierdzona']);
    assert_status(200, $changed, 'Zmiana statusu do testu 118 powinna dać 200');
    $responses[] = $changed;

    $invalid = admin_reservations_patch($session, ['id' => 4, 'status' => 'nieprawidłowy']);
    assert_status(422, $invalid, 'Nieprawidłowy status do testu 118 powinien dać 422');
    $responses[] = $invalid;

    $page = http_request($session['cookies'], 'GET', '/rezerwacje.php');
    assert_status(200, $page, 'Strona rezerwacji do testu 118 powinna dać 200');
    $responses[] = $page;

    $forbidden = [
        'haslo_hash',
        '$2y$',
        'SQLSTATE',
        'SELECT ',
        'UPDATE ',
        'DELETE FROM',
        '/var/www',
        'app/rezerwacje.php',
        'public/config.php',
        'PDOException',
    ];

    foreach ($responses as $response) {
        foreach ($forbidden as $needle) {
            assert_true(
                strpos($response['body'], $needle) === false,
                'Odpowiedź nie może zawierać: ' . $needle
            );
        }
    }

    $logFile = $GLOBALS['__test_server_log'] ?? '';
    assert_true(is_string($logFile) && $logFile !== '', 'Brak ścieżki logu serwera testowego');
    assert_same([], server_log_errors($logFile), 'Log serwera nie powinien zawierać błędów PHP');
});
