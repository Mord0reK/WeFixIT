<?php
declare(strict_types=1);

/*
    Testy przypisań usług do pracowników w panelu administratora.

    Obejmują zapis stanu docelowego, odłączanie bez usuwania wierszy,
    idempotencję, kontrolę dostępu, CSRF, walidację, metody HTTP,
    usługi nieaktywne, spójność rezerwacji historycznych i brak wycieku
    danych technicznych. Każdy test przygotowuje własny stan początkowy.
*/

test_group('Panel: przypisania usług', 'database/database.sql');

function employee_services_rows(int $employeeId): array
{
    $statement = test_db()->prepare(
        'SELECT usluga_id, aktywne
         FROM uslugi_pracownikow
         WHERE pracownik_id = :pracownik_id
         ORDER BY usluga_id ASC'
    );
    $statement->execute(['pracownik_id' => $employeeId]);
    $rows = [];

    foreach ($statement->fetchAll() as $row) {
        $rows[(int) $row['usluga_id']] = (int) $row['aktywne'];
    }

    return $rows;
}

function employee_services_total(): int
{
    return (int) test_db()->query('SELECT COUNT(*) FROM uslugi_pracownikow')->fetchColumn();
}

function employee_services_set_service_active(int $serviceId, bool $active): void
{
    $statement = test_db()->prepare('UPDATE uslugi SET aktywna = :aktywna WHERE id = :id');
    $statement->execute([
        'id' => $serviceId,
        'aktywna' => $active ? 1 : 0,
    ]);
}

function employee_services_set_services_active(array $serviceIds): void
{
    foreach ($serviceIds as $serviceId) {
        employee_services_set_service_active((int) $serviceId, true);
    }
}

function employee_services_admin_session(int $employeeId = 1): array
{
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');
    $session['csrf'] = csrf_from_page($session['cookies'], '/pracownicy-uslugi.php?id=' . $employeeId);

    return $session;
}

function employee_services_save_with(array &$session, array $serviceIds, int $employeeId = 1): array
{
    return post_json($session['cookies'], '/api/admin/employee-services.php', [
        'pracownik_id' => $employeeId,
        'usluga_ids' => $serviceIds,
    ], $session['csrf']);
}

test('66. administrator zapisuje zbiór usług i odłącza brakujące bez usuwania wierszy', function (): void {
    employee_services_set_services_active([1, 2, 3, 5, 6]);
    $session = employee_services_admin_session();
    $setup = employee_services_save_with($session, [1, 2, 5, 6]);
    assert_status(200, $setup, 'Przygotowanie przypisań powinno dać 200');

    $totalBefore = employee_services_total();
    $response = employee_services_save_with($session, [1, 3, 3]);

    assert_status(200, $response, 'Zapis przypisań powinien dać 200');
    $data = json_body($response);
    assert_same(true, $data['success'] ?? null, 'Odpowiedź powinna zawierać success = true');
    assert_same(1, $data['pracownik_id'] ?? null, 'Odpowiedź powinna zawierać pracownik_id');
    assert_same([1, 3], $data['usluga_ids'] ?? null, 'Duplikaty powinny zostać zredukowane');

    $rows = employee_services_rows(1);
    assert_same(1, $rows[1] ?? null, 'Usługa 1 powinna być przypisana');
    assert_same(1, $rows[3] ?? null, 'Usługa 3 powinna być przypisana');

    foreach ([2, 5, 6] as $serviceId) {
        assert_true(array_key_exists($serviceId, $rows), 'Wiersz usługi ' . $serviceId . ' nie może zostać usunięty');
        assert_same(0, $rows[$serviceId], 'Usługa ' . $serviceId . ' powinna być odłączona');
    }

    assert_true(
        employee_services_total() >= $totalBefore,
        'Zapis może dodać nowy wiersz, ale nie może usuwać istniejących'
    );

    $log = test_db()->query(
        "SELECT akcja, obiekt_typ, obiekt_id, poziom, szczegoly
         FROM log_zdarzen
         WHERE akcja = 'pracownik_uslugi_zaktualizowane'
         ORDER BY id DESC
         LIMIT 1"
    )->fetch();
    assert_true(is_array($log), 'Zapis przypisań powinien trafić do logu');
    assert_same('pracownik', (string) ($log['obiekt_typ'] ?? ''), 'Log powinien wskazywać typ obiektu pracownik');
    assert_same(1, (int) ($log['obiekt_id'] ?? 0), 'Log powinien wskazywać pracownika 1');
    assert_same('info', (string) ($log['poziom'] ?? ''), 'Poziom logu powinien być info');

    $details = json_decode((string) ($log['szczegoly'] ?? ''), true);
    assert_same([3], $details['dodane_usluga_ids'] ?? null, 'Log powinien zawierać dodane usługi');
    assert_same([2, 5, 6], $details['odlaczone_usluga_ids'] ?? null, 'Log powinien zawierać odłączone usługi');
});

test('67. pusta lista odłącza wszystkie przypisania bez usuwania wierszy', function (): void {
    employee_services_set_services_active([1, 2, 3, 4, 5, 6]);
    $session = employee_services_admin_session();
    $setup = employee_services_save_with($session, [1, 3]);
    assert_status(200, $setup, 'Przygotowanie przypisań powinno dać 200');

    $rowsBefore = employee_services_rows(1);
    assert_true($rowsBefore !== [], 'Pracownik 1 powinien mieć przypisania przed testem');

    $response = employee_services_save_with($session, []);

    assert_status(200, $response, 'Zapis pustej listy powinien dać 200');
    assert_same([], json_body($response)['usluga_ids'] ?? null, 'Odpowiedź powinna zawierać pustą listę');

    $rowsAfter = employee_services_rows(1);
    assert_same(count($rowsBefore), count($rowsAfter), 'Liczba wierszy przypisań nie może się zmienić');

    foreach ($rowsAfter as $serviceId => $active) {
        assert_same(0, $active, 'Przypisanie usługi ' . $serviceId . ' powinno być odłączone');
    }
});

test('68. ponowne wysłanie tego samego zbioru jest idempotentne', function (): void {
    employee_services_set_services_active([1, 3]);
    $session = employee_services_admin_session();
    $first = employee_services_save_with($session, [1, 3]);
    assert_status(200, $first, 'Pierwszy zapis zbioru powinien dać 200');

    $rowsAfterFirst = employee_services_rows(1);
    $second = employee_services_save_with($session, [1, 3]);

    assert_status(200, $second, 'Drugi zapis tego samego zbioru powinien dać 200');
    assert_same([1, 3], json_body($second)['usluga_ids'] ?? null, 'Odpowiedź powinna zawierać ten sam zbiór');
    assert_same($rowsAfterFirst, employee_services_rows(1), 'Idempotentny zapis nie może zmienić stanu');
    assert_same(1, $rowsAfterFirst[1] ?? null, 'Usługa 1 powinna pozostać przypisana');
    assert_same(1, $rowsAfterFirst[3] ?? null, 'Usługa 3 powinna pozostać przypisana');
});

test('69. klient i pracownik dostają 403 na stronie i endpointcie przypisań', function (): void {
    $before = employee_services_rows(1);
    $accounts = [
        ['email' => 'jan.kowalski@example.com', 'label' => 'klienta'],
        ['email' => 'tomasz.serwisant@wefixit.pl', 'label' => 'pracownika'],
    ];

    foreach ($accounts as $account) {
        $session = login_as($account['email']);
        assert_status(200, $session['response'], 'Logowanie ' . $account['label'] . ' powinno się udać');

        $page = http_request($session['cookies'], 'GET', '/pracownicy-uslugi.php?id=1');
        assert_status(403, $page, 'Konto ' . $account['label'] . ' powinno dostać 403 na stronie przypisań');

        $csrf = csrf_from_page($session['cookies'], '/profil.php');
        $response = post_json($session['cookies'], '/api/admin/employee-services.php', [
            'pracownik_id' => 1,
            'usluga_ids' => [],
        ], $csrf);

        assert_status(403, $response, 'Konto ' . $account['label'] . ' powinno dostać 403 na POST przypisań');
    }

    assert_same($before, employee_services_rows(1), 'Brak uprawnień nie może zmienić przypisań');
});

test('70. POST bez tokenu CSRF daje 403 i nie zmienia przypisań', function (): void {
    $session = employee_services_admin_session();
    $before = employee_services_rows(1);

    $response = post_json($session['cookies'], '/api/admin/employee-services.php', [
        'pracownik_id' => 1,
        'usluga_ids' => [],
    ]);

    assert_status(403, $response, 'Brak tokenu CSRF powinien dać 403');
    assert_same($before, employee_services_rows(1), 'Żądanie bez CSRF nie może zmienić przypisań');
});

test('71. walidacja zwraca 422 lub 404 i nie zostawia połowicznego stanu', function (): void {
    employee_services_set_service_active(7, false);
    $session = employee_services_admin_session();
    $cases = [
        [
            'payload' => ['usluga_ids' => [1]],
            'status' => 422,
            'field' => 'pracownik_id',
            'label' => 'brak pracownik_id',
        ],
        [
            'payload' => ['pracownik_id' => 1, 'usluga_ids' => '1'],
            'status' => 422,
            'field' => 'usluga_ids',
            'label' => 'usluga_ids nie jest tablicą',
        ],
        [
            'payload' => ['pracownik_id' => 1, 'usluga_ids' => [1, '3']],
            'status' => 422,
            'field' => 'usluga_ids',
            'label' => 'usluga_ids zawiera wartość niecałkowitą',
        ],
        [
            'payload' => ['pracownik_id' => 1, 'usluga_ids' => [999999]],
            'status' => 422,
            'field' => 'usluga_ids',
            'label' => 'usługa nieistniejąca',
        ],
        [
            'payload' => ['pracownik_id' => 1, 'usluga_ids' => [7]],
            'status' => 422,
            'field' => 'usluga_ids',
            'label' => 'usługa nieaktywna',
        ],
    ];

    foreach ($cases as $case) {
        $response = post_json($session['cookies'], '/api/admin/employee-services.php', $case['payload'], $session['csrf']);

        assert_status($case['status'], $response, 'Przypadek "' . $case['label'] . '" powinien dać ' . $case['status']);

        $data = json_body($response);
        assert_true(
            isset($data['errors'][$case['field']]) && is_string($data['errors'][$case['field']]),
            'Odpowiedź dla przypadku "' . $case['label'] . '" powinna zawierać errors.' . $case['field']
        );
    }

    $missing = post_json($session['cookies'], '/api/admin/employee-services.php', [
        'pracownik_id' => 999999,
        'usluga_ids' => [1],
    ], $session['csrf']);
    assert_status(404, $missing, 'Nieistniejący pracownik powinien dać 404');

    $before = employee_services_rows(1);
    $atomic = post_json($session['cookies'], '/api/admin/employee-services.php', [
        'pracownik_id' => 1,
        'usluga_ids' => [1, 7],
    ], $session['csrf']);
    assert_status(422, $atomic, 'Mieszanka poprawnej i nieaktywnej usługi powinna dać 422');
    assert_same($before, employee_services_rows(1), 'Błąd walidacji nie może zostawić częściowego stanu');
});

test('72. GET daje 405 z Allow, a POST bez JSON daje 415', function (): void {
    $cookies = [];
    $method = http_request($cookies, 'GET', '/api/admin/employee-services.php');
    assert_status(405, $method, 'GET na endpoint przypisań powinien dać 405');

    $allow = header_value($method, 'allow');
    assert_true(is_string($allow) && $allow !== '', 'Odpowiedź 405 powinna zawierać nagłówek Allow');
    assert_true(strpos($allow, 'POST') !== false, 'Nagłówek Allow powinien zawierać POST');

    $session = employee_services_admin_session();
    $before = employee_services_rows(1);
    $response = http_request($session['cookies'], 'POST', '/api/admin/employee-services.php', [
        'body' => json_encode(['pracownik_id' => 1, 'usluga_ids' => []], JSON_UNESCAPED_UNICODE),
        'content_type' => 'text/plain',
        'headers' => ['X-CSRF-Token' => $session['csrf']],
    ]);

    assert_status(415, $response, 'POST bez Content-Type application/json powinien dać 415');
    assert_same($before, employee_services_rows(1), 'Żądanie 415 nie może zmienić przypisań');
});

test('73. odłączenie przypisania użytego przez rezerwację zachowuje wiersz i rezerwację', function (): void {
    employee_services_set_services_active([1, 3]);
    $session = employee_services_admin_session();
    $setup = employee_services_save_with($session, [1, 3]);
    assert_status(200, $setup, 'Przygotowanie przypisania 1/1 powinno dać 200');

    $reservationBefore = test_db()->query('SELECT * FROM rezerwacje WHERE id = 1')->fetch();
    assert_true(is_array($reservationBefore), 'Rezerwacja testowa powinna istnieć');

    $response = employee_services_save_with($session, [3]);
    assert_status(200, $response, 'Odłączenie usługi z rezerwacją powinno dać 200');
    assert_same([3], json_body($response)['usluga_ids'] ?? null, 'Odpowiedź powinna zawierać nowy zbiór');

    $statement = test_db()->prepare(
        'SELECT aktywne FROM uslugi_pracownikow WHERE pracownik_id = :pracownik_id AND usluga_id = :usluga_id'
    );
    $statement->execute(['pracownik_id' => 1, 'usluga_id' => 1]);
    $active = $statement->fetchColumn();
    assert_true($active !== false, 'Wiersz przypisania 1/1 nadal powinien istnieć');
    assert_same(0, (int) $active, 'Przypisanie 1/1 powinno być odłączone');

    $reservationAfter = test_db()->query('SELECT * FROM rezerwacje WHERE id = 1')->fetch();
    assert_same($reservationBefore, $reservationAfter, 'Rezerwacja historyczna nie może się zmienić');
});

test('74. strona przypisań zwraca 200 dla pracownika i 404 dla braku lub złego id', function (): void {
    employee_services_set_services_active([1, 3]);
    $session = employee_services_admin_session();
    $first = employee_services_save_with($session, [1, 3]);
    assert_status(200, $first, 'Przypisanie usług 1 i 3 powinno dać 200');

    $detached = employee_services_save_with($session, [3]);
    assert_status(200, $detached, 'Odłączenie usługi 1 powinno dać 200');

    $page = http_request($session['cookies'], 'GET', '/pracownicy-uslugi.php?id=1');
    assert_status(200, $page, 'Strona przypisań powinna zwrócić 200');
    assert_true(strpos($page['body'], 'name="usluga_ids[]"') !== false, 'Strona powinna zawierać checkboxy usług');
    assert_true(
        strpos($page['body'], 'Konserwacja układu chłodzenia laptopa') !== false,
        'Strona powinna zawierać nazwę aktywnej usługi'
    );
    assert_true(
        strpos($page['body'], 'wcześniej odłączona') !== false,
        'Odłączona usługa powinna być oznaczona jako wcześniej odłączona'
    );
    assert_true(
        strpos($page['body'], '/api/admin/employee-services') !== false,
        'Formularz powinien wskazywać właściwy endpoint'
    );

    $missing = http_request($session['cookies'], 'GET', '/pracownicy-uslugi.php?id=999999');
    assert_status(404, $missing, 'Nieistniejący pracownik powinien dać 404');
    assert_true(strpos($missing['body'], 'Nie znaleziono pracownika') !== false, 'Strona 404 powinna zawierać komunikat');
    assert_true(
        strpos($missing['body'], 'id="panel-shell"') !== false,
        'Strona 404 powinna być osadzona w szkielecie panelu'
    );

    $withoutId = http_request($session['cookies'], 'GET', '/pracownicy-uslugi.php');
    assert_status(404, $withoutId, 'Brak parametru id powinien dać 404');
    assert_true(strpos($withoutId['body'], 'Nie znaleziono pracownika') !== false, 'Brak id powinien pokazać komunikat');
});

test('75. odpowiedzi nie ujawniają danych technicznych i nie usuwają przypisań', function (): void {
    employee_services_set_services_active([1, 3]);
    employee_services_set_service_active(7, false);
    $session = employee_services_admin_session();
    $setup = employee_services_save_with($session, [1, 3]);
    assert_status(200, $setup, 'Przygotowanie przypisań do testu 75 powinno dać 200');

    $totalBefore = employee_services_total();
    $responses = [];

    $saved = employee_services_save_with($session, [1, 3]);
    assert_status(200, $saved, 'Zapis przypisań do testu 75 powinien dać 200');
    $responses[] = $saved;

    $invalid = employee_services_save_with($session, [7]);
    assert_status(422, $invalid, 'Nieaktywna usługa powinna dać 422');
    $responses[] = $invalid;

    $missing = http_request($session['cookies'], 'GET', '/pracownicy-uslugi.php?id=999999');
    assert_status(404, $missing, 'Nieistniejący pracownik powinien dać 404');
    $responses[] = $missing;

    $page = http_request($session['cookies'], 'GET', '/pracownicy-uslugi.php?id=1');
    assert_status(200, $page, 'Strona przypisań powinna zwrócić 200');
    $responses[] = $page;

    $forbidden = ['haslo_hash', 'SQLSTATE', 'SELECT ', 'UPDATE ', 'DELETE FROM', '/var/www', 'app/pracownicy_uslugi.php'];

    foreach ($responses as $response) {
        foreach ($forbidden as $needle) {
            assert_true(
                strpos($response['body'], $needle) === false,
                'Odpowiedź nie może zawierać: ' . $needle
            );
        }
    }

    assert_same($totalBefore, employee_services_total(), 'Operacje nie mogą usuwać wierszy przypisań');

    $logFile = $GLOBALS['__test_server_log'] ?? '';
    assert_true(is_string($logFile) && $logFile !== '', 'Brak ścieżki logu serwera testowego');
    assert_same([], server_log_errors($logFile), 'Log serwera nie powinien zawierać błędów PHP');
});

test('76. nieaktywna usługa pozostaje widoczna i nie jest odłączana przez zapis bez zmian', function (): void {
    employee_services_set_services_active([1, 3]);
    $session = employee_services_admin_session();
    $setup = employee_services_save_with($session, [1, 3]);
    assert_status(200, $setup, 'Przygotowanie przypisania 1/1 powinno dać 200');

    employee_services_set_service_active(1, false);

    try {
        $page = http_request($session['cookies'], 'GET', '/pracownicy-uslugi.php?id=1');
        assert_status(200, $page, 'Strona z nieaktywną usługą powinna zwrócić 200');
        assert_true(
            strpos($page['body'], 'Konserwacja układu chłodzenia laptopa') !== false,
            'Nieaktywna usługa nadal przypisana powinna być widoczna'
        );
        assert_true(strpos($page['body'], 'Usługa nieaktywna') !== false, 'Strona powinna zawierać badge usługi nieaktywnej');
        assert_true(
            strpos($page['body'], 'Usługi nieaktywne, nadal przypisane') !== false,
            'Strona powinna zawierać osobną sekcję usług nieaktywnych'
        );
        assert_true(
            strpos($page['body'], 'id="usluga-nieaktywna-1"') !== false,
            'Nieaktywna usługa powinna mieć własny checkbox'
        );

        $response = employee_services_save_with($session, [3]);
        assert_status(200, $response, 'Zapis aktywnej usługi powinien dać 200');

        $rows = employee_services_rows(1);
        assert_same(1, $rows[1] ?? null, 'Przypisanie do nieaktywnej usługi 1 musi pozostać aktywne');
        assert_same(1, $rows[3] ?? null, 'Usługa 3 powinna pozostać przypisana');
    } finally {
        employee_services_set_service_active(1, true);
    }
});

test('77. próba przypisania usługi nieaktywnej daje 422 i nie zmienia stanu', function (): void {
    employee_services_set_services_active([1, 3]);
    employee_services_set_service_active(7, false);
    $session = employee_services_admin_session();
    $setup = employee_services_save_with($session, [1, 3]);
    assert_status(200, $setup, 'Przygotowanie przypisania 1/1 powinno dać 200');

    employee_services_set_service_active(1, false);

    try {
        $before = employee_services_rows(1);

        $neverAssigned = employee_services_save_with($session, [7]);
        assert_status(422, $neverAssigned, 'Nieaktywna usługa bez historii powinna dać 422');
        assert_true(
            isset(json_body($neverAssigned)['errors']['usluga_ids']),
            'Odpowiedź powinna zawierać errors.usluga_ids dla usługi bez historii'
        );
        assert_same($before, employee_services_rows(1), 'Nieudane przypisanie nie może zmienić stanu');

        $alreadyAssigned = employee_services_save_with($session, [1]);
        assert_status(422, $alreadyAssigned, 'Nieaktywna usługa z historią również powinna dać 422');
        assert_true(
            isset(json_body($alreadyAssigned)['errors']['usluga_ids']),
            'Odpowiedź powinna zawierać errors.usluga_ids dla usługi z historią'
        );
        assert_same($before, employee_services_rows(1), 'Odrzucony zapis nie może odłączyć przypisania');
    } finally {
        employee_services_set_service_active(1, true);
    }
});

test('78. zapis bez nieaktywnej usługi zachowuje przypisanie i nie loguje odłączenia', function (): void {
    employee_services_set_services_active([1, 3]);
    $session = employee_services_admin_session();
    $setup = employee_services_save_with($session, [1, 3]);
    assert_status(200, $setup, 'Przygotowanie przypisania 1/1 powinno dać 200');

    employee_services_set_service_active(1, false);

    try {
        $response = employee_services_save_with($session, [3]);
        assert_status(200, $response, 'Zapis bez nieaktywnej usługi powinien dać 200');
        assert_same([1, 3], json_body($response)['usluga_ids'] ?? null, 'Odpowiedź powinna zawierać zachowane przypisanie');

        $rows = employee_services_rows(1);
        assert_same(1, $rows[1] ?? null, 'Przypisanie do nieaktywnej usługi 1 musi pozostać aktywne');
        assert_same(1, $rows[3] ?? null, 'Usługa 3 powinna pozostać przypisana');

        $log = test_db()->query(
            "SELECT szczegoly
             FROM log_zdarzen
             WHERE akcja = 'pracownik_uslugi_zaktualizowane'
             ORDER BY id DESC
             LIMIT 1"
        )->fetch();
        assert_true(is_array($log), 'Zapis przypisań powinien trafić do logu');

        $details = json_decode((string) ($log['szczegoly'] ?? ''), true);
        assert_same([], $details['odlaczone_usluga_ids'] ?? null, 'Log nie może zgłaszać odłączenia niewidocznej usługi');
    } finally {
        employee_services_set_service_active(1, true);
    }
});
