<?php
declare(strict_types=1);

/*
    Testy administracji kontami użytkowników.

    Obejmują zmianę roli i aktywności, kontrolę dostępu, CSRF, reguły własnego
    konta, profilu pracownika i ostatniego administratora, kontrakt HTTP,
    filtry listy oraz brak wycieku danych technicznych. Każdy test przygotowuje
    własny stan początkowy.
*/

test_group('Panel: użytkownicy', 'database/database.sql');

function admin_users_set_user(int $id, string $role, bool $active): void
{
    $statement = test_db()->prepare(
        'UPDATE uzytkownicy
         SET rola = :rola, aktywny = :aktywny
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $id,
        'rola' => $role,
        'aktywny' => $active ? 1 : 0,
    ]);
}

function admin_users_set_profile_active(int $userId, bool $active): void
{
    $statement = test_db()->prepare(
        'UPDATE pracownicy
         SET aktywny = :aktywny
         WHERE uzytkownik_id = :uzytkownik_id'
    );
    $statement->execute([
        'uzytkownik_id' => $userId,
        'aktywny' => $active ? 1 : 0,
    ]);
}

function admin_users_reset_base(): void
{
    $users = [
        1 => ['admin', true],
        2 => ['pracownik', true],
        3 => ['pracownik', true],
        4 => ['klient', true],
        5 => ['klient', true],
        6 => ['klient', true],
        7 => ['pracownik', true],
    ];

    foreach ($users as $id => $state) {
        admin_users_set_user($id, $state[0], $state[1]);
    }

    admin_users_set_profile_active(2, true);
    admin_users_set_profile_active(3, true);
}

function admin_users_admin_session(): array
{
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');
    $session['csrf'] = csrf_from_page($session['cookies'], '/uzytkownicy.php');

    return $session;
}

function admin_users_patch(array &$session, int $id, array $fields): array
{
    return patch_json(
        $session['cookies'],
        '/api/admin/users.php',
        array_merge(['id' => $id], $fields),
        $session['csrf']
    );
}

test('95. administrator zmienia rolę klienta na pracownik i zapisuje log', function (): void {
    admin_users_reset_base();
    $session = admin_users_admin_session();

    $response = admin_users_patch($session, 4, ['rola' => 'pracownik']);
    assert_status(200, $response, 'Zmiana roli powinna dać 200');

    $data = json_body($response);
    assert_same(true, $data['success'] ?? null, 'Odpowiedź powinna zawierać success = true');
    assert_same(4, $data['id'] ?? null, 'Odpowiedź powinna zawierać id konta');
    assert_same('pracownik', $data['rola'] ?? null, 'Odpowiedź powinna zawierać nową rolę');
    assert_same(true, $data['aktywny'] ?? null, 'Odpowiedź powinna zawierać aktywność konta');

    $row = test_db()->query('SELECT rola, aktywny FROM uzytkownicy WHERE id = 4')->fetch();
    assert_true(is_array($row), 'Konto 4 powinno istnieć');
    assert_same('pracownik', (string) $row['rola'], 'Rola w bazie powinna się zmienić');
    assert_same(1, (int) $row['aktywny'], 'Konto powinno pozostać aktywne');

    $log = test_db()->query(
        "SELECT akcja, obiekt_typ, obiekt_id, poziom, szczegoly
         FROM log_zdarzen
         WHERE akcja = 'uzytkownik_rola_zmieniona' AND obiekt_id = 4
         ORDER BY id DESC
         LIMIT 1"
    )->fetch();
    assert_true(is_array($log), 'Zmiana roli powinna trafić do logu');
    assert_same('uzytkownik', (string) $log['obiekt_typ'], 'Log powinien wskazywać typ uzytkownik');
    assert_same(4, (int) $log['obiekt_id'], 'Log powinien wskazywać konto 4');
    assert_same('info', (string) $log['poziom'], 'Zmiana roli powinna mieć poziom info');

    $details = json_decode((string) $log['szczegoly'], true);
    assert_same('klient', $details['rola']['przed'] ?? null, 'Log powinien zawierać rolę przed zmianą');
    assert_same('pracownik', $details['rola']['po'] ?? null, 'Log powinien zawierać rolę po zmianie');
    assert_true(
        is_array($details['zmienione_pola'] ?? null)
        && in_array('rola', $details['zmienione_pola'], true),
        'Log powinien zawierać nazwę zmienionego pola'
    );
});

test('96. klient i pracownik dostają 403 na stronie i endpointcie', function (): void {
    admin_users_reset_base();

    foreach ([
        ['email' => 'jan.kowalski@example.com', 'label' => 'klienta'],
        ['email' => 'tomasz.serwisant@wefixit.pl', 'label' => 'pracownika'],
    ] as $account) {
        $session = login_as($account['email']);
        assert_status(200, $session['response'], 'Logowanie ' . $account['label'] . ' powinno się udać');

        $page = http_request($session['cookies'], 'GET', '/uzytkownicy.php');
        assert_status(403, $page, 'Konto ' . $account['label'] . ' powinno dostać 403 na stronie');

        $csrf = csrf_from_page($session['cookies'], '/profil.php');
        $response = patch_json($session['cookies'], '/api/admin/users.php', [
            'id' => 4,
            'rola' => 'pracownik',
        ], $csrf);
        assert_status(403, $response, 'Konto ' . $account['label'] . ' powinno dostać 403 na endpointcie');
    }

    $row = test_db()->query('SELECT rola FROM uzytkownicy WHERE id = 4')->fetch();
    assert_same('klient', (string) $row['rola'], 'Brak uprawnień nie może zmienić roli');
});

test('97. PATCH bez tokenu CSRF daje 403 i nie zmienia roli', function (): void {
    admin_users_reset_base();
    $session = admin_users_admin_session();

    $response = patch_json($session['cookies'], '/api/admin/users.php', [
        'id' => 4,
        'rola' => 'pracownik',
    ]);
    assert_status(403, $response, 'PATCH bez CSRF powinien dać 403');

    $row = test_db()->query('SELECT rola FROM uzytkownicy WHERE id = 4')->fetch();
    assert_same('klient', (string) $row['rola'], 'Żądanie bez CSRF nie może zmienić roli');
});

test('98. administrator nie może odebrać sobie roli ani dezaktywować konta', function (): void {
    admin_users_reset_base();
    $session = admin_users_admin_session();

    $roleResponse = admin_users_patch($session, 1, ['rola' => 'klient']);
    assert_status(422, $roleResponse, 'Odebranie sobie roli admin powinno dać 422');
    $roleData = json_body($roleResponse);
    assert_true(
        isset($roleData['errors']['rola']) && is_string($roleData['errors']['rola']),
        'Odpowiedź powinna zawierać errors.rola'
    );

    $activeResponse = admin_users_patch($session, 1, ['aktywny' => false]);
    assert_status(422, $activeResponse, 'Dezaktywacja własnego konta powinna dać 422');
    $activeData = json_body($activeResponse);
    assert_true(
        isset($activeData['errors']['aktywny']) && is_string($activeData['errors']['aktywny']),
        'Odpowiedź powinna zawierać errors.aktywny'
    );

    $row = test_db()->query('SELECT rola, aktywny FROM uzytkownicy WHERE id = 1')->fetch();
    assert_same('admin', (string) $row['rola'], 'Rola administratora nie może się zmienić');
    assert_same(1, (int) $row['aktywny'], 'Konto administratora nie może zostać dezaktywowane');
});

test('99. zmiana roli na klient przy profilu pracownika jest blokowana', function (): void {
    admin_users_reset_base();
    $session = admin_users_admin_session();

    try {
        foreach ([true, false] as $profileActive) {
            admin_users_set_profile_active(2, $profileActive);

            $response = admin_users_patch($session, 2, ['rola' => 'klient']);
            assert_status(422, $response, 'Zmiana roli przy profilu pracownika powinna dać 422');

            $data = json_body($response);
            $message = $data['errors']['rola'] ?? '';
            assert_true(is_string($message) && $message !== '', 'Odpowiedź powinna zawierać errors.rola');
            assert_true(
                strpos($message, 'dezaktyw') !== false,
                'Komunikat powinien wskazywać potrzebę dezaktywacji profilu pracownika'
            );

            $row = test_db()->query('SELECT rola FROM uzytkownicy WHERE id = 2')->fetch();
            assert_same('pracownik', (string) $row['rola'], 'Rola pracownika nie może się zmienić');
        }
    } finally {
        admin_users_set_profile_active(2, true);
    }
});

test('100. ostatni administrator jest chroniony, a drugi admin pozwala na zmianę', function (): void {
    admin_users_reset_base();
    $session = admin_users_admin_session();

    $selfRole = admin_users_patch($session, 1, ['rola' => 'klient']);
    assert_status(422, $selfRole, 'Odebranie roli ostatniemu adminowi powinno dać 422');

    admin_users_set_user(7, 'admin', true);

    try {
        $response = admin_users_patch($session, 7, ['rola' => 'klient']);
        assert_status(200, $response, 'Zmiana roli przy drugim aktywnym adminie powinna dać 200');

        $first = test_db()->query('SELECT rola FROM uzytkownicy WHERE id = 1')->fetch();
        $second = test_db()->query('SELECT rola FROM uzytkownicy WHERE id = 7')->fetch();
        assert_same('admin', (string) $first['rola'], 'Pierwszy administrator powinien pozostać adminem');
        assert_same('klient', (string) $second['rola'], 'Drugie konto powinno mieć nową rolę');
    } finally {
        admin_users_set_user(1, 'admin', true);
        admin_users_set_user(7, 'pracownik', true);
    }
});

test('101. endpoint przyjmuje tylko PATCH i wymaga Content-Type', function (): void {
    $cookies = [];

    foreach (['GET', 'POST', 'DELETE'] as $method) {
        $response = http_request($cookies, $method, '/api/admin/users.php');
        assert_status(405, $response, $method . ' na endpoint użytkowników powinien dać 405');

        $allow = header_value($response, 'allow');
        assert_true(is_string($allow) && $allow !== '', $method . ' powinien zwrócić nagłówek Allow');

        $methods = array_values(array_filter(array_map('trim', explode(',', $allow))));
        assert_same(['PATCH'], $methods, 'Allow powinno zawierać wyłącznie PATCH');
    }

    admin_users_reset_base();
    $session = admin_users_admin_session();
    $response = http_request($session['cookies'], 'PATCH', '/api/admin/users.php', [
        'body' => json_encode(['id' => 4, 'rola' => 'pracownik'], JSON_UNESCAPED_UNICODE),
        'headers' => ['X-CSRF-Token' => $session['csrf']],
    ]);
    assert_status(415, $response, 'PATCH bez Content-Type application/json powinien dać 415');

    $row = test_db()->query('SELECT rola FROM uzytkownicy WHERE id = 4')->fetch();
    assert_same('klient', (string) $row['rola'], 'PATCH bez Content-Type nie może zmienić roli');
});

test('102. walidacja identyfikatora, roli, aktywności i pustego body', function (): void {
    admin_users_reset_base();
    $session = admin_users_admin_session();
    $cases = [
        [
            'payload' => ['rola' => 'pracownik'],
            'status' => 422,
            'field' => 'id',
            'label' => 'brak id',
        ],
        [
            'payload' => ['id' => 999999, 'rola' => 'pracownik'],
            'status' => 404,
            'field' => null,
            'label' => 'nieistniejące konto',
        ],
        [
            'payload' => ['id' => 4, 'rola' => 'właściciel'],
            'status' => 422,
            'field' => 'rola',
            'label' => 'rola spoza allowlisty',
        ],
        [
            'payload' => ['id' => 4, 'aktywny' => 'tak'],
            'status' => 422,
            'field' => 'aktywny',
            'label' => 'aktywny nie jest bool',
        ],
        [
            'payload' => ['id' => 4],
            'status' => 422,
            'field' => null,
            'label' => 'brak pól do zmiany',
        ],
    ];

    foreach ($cases as $case) {
        $response = patch_json(
            $session['cookies'],
            '/api/admin/users.php',
            $case['payload'],
            $session['csrf']
        );
        assert_status($case['status'], $response, 'Przypadek "' . $case['label'] . '" powinien dać ' . $case['status']);

        if ($case['field'] !== null) {
            $data = json_body($response);
            assert_true(
                isset($data['errors'][$case['field']]) && is_string($data['errors'][$case['field']]),
                'Odpowiedź dla "' . $case['label'] . '" powinna zawierać errors.' . $case['field']
            );
        }
    }

    $empty = http_request($session['cookies'], 'PATCH', '/api/admin/users.php', [
        'body' => '{}',
        'content_type' => 'application/json',
        'headers' => ['X-CSRF-Token' => $session['csrf']],
    ]);
    assert_status(422, $empty, 'Puste body powinno dać 422');

    $row = test_db()->query('SELECT rola, aktywny FROM uzytkownicy WHERE id = 4')->fetch();
    assert_same('klient', (string) $row['rola'], 'Walidacja nie może zmienić roli');
    assert_same(1, (int) $row['aktywny'], 'Walidacja nie może zmienić aktywności');
});

test('103. dezaktywacja blokuje logowanie i nie usuwa konta', function (): void {
    admin_users_reset_base();
    $session = admin_users_admin_session();

    try {
        $response = admin_users_patch($session, 4, ['aktywny' => false]);
        assert_status(200, $response, 'Dezaktywacja konta powinna dać 200');
        assert_same(false, json_body($response)['aktywny'] ?? null, 'Odpowiedź powinna zawierać aktywny = false');

        $row = test_db()->query('SELECT aktywny FROM uzytkownicy WHERE id = 4')->fetch();
        assert_same(0, (int) $row['aktywny'], 'Flaga aktywności powinna zostać wyłączona');

        $count = test_db()->query('SELECT COUNT(*) FROM uzytkownicy WHERE id = 4')->fetchColumn();
        assert_same(1, (int) $count, 'Dezaktywacja nie może usuwać konta');

        $login = login_as('jan.kowalski@example.com');
        assert_status(401, $login['response'], 'Dezaktywowany użytkownik nie może się zalogować');

        $log = test_db()->query(
            "SELECT akcja, obiekt_typ, obiekt_id, poziom, szczegoly
             FROM log_zdarzen
             WHERE akcja = 'uzytkownik_status_zmieniony' AND obiekt_id = 4
             ORDER BY id DESC
             LIMIT 1"
        )->fetch();
        assert_true(is_array($log), 'Dezaktywacja powinna trafić do logu');
        assert_same('uzytkownik', (string) $log['obiekt_typ'], 'Log powinien wskazywać typ uzytkownik');
        assert_same('warning', (string) $log['poziom'], 'Dezaktywacja powinna mieć poziom warning');

        $details = json_decode((string) $log['szczegoly'], true);
        assert_same(true, $details['aktywny']['przed'] ?? null, 'Log powinien zawierać stan przed dezaktywacją');
        assert_same(false, $details['aktywny']['po'] ?? null, 'Log powinien zawierać stan po dezaktywacji');
    } finally {
        admin_users_set_user(4, 'klient', true);
    }
});

test('104. lista filtruje role i status, a błędny filtr nie powoduje błędu', function (): void {
    admin_users_reset_base();
    admin_users_set_user(5, 'klient', false);
    $session = admin_users_admin_session();

    $activeClients = http_request($session['cookies'], 'GET', '/uzytkownicy.php?rola=klient&status=active');
    assert_status(200, $activeClients, 'Filtr aktywnych klientów powinien dać 200');
    assert_true(
        strpos($activeClients['body'], 'jan.kowalski@example.com') !== false,
        'Lista powinna zawierać aktywnego klienta'
    );
    assert_true(
        strpos($activeClients['body'], 'anna.nowak@example.com') === false,
        'Lista nie powinna zawierać nieaktywnego klienta'
    );
    assert_true(
        preg_match('/<option value="klient"[^>]*selected/', $activeClients['body']) === 1,
        'Filtr roli powinien zachować wybraną wartość'
    );
    assert_true(
        strpos($activeClients['body'], 'href="/uzytkownicy"') !== false,
        'Menu panelu powinno zawierać link do użytkowników'
    );

    $inactiveClients = http_request($session['cookies'], 'GET', '/uzytkownicy.php?rola=klient&status=inactive');
    assert_status(200, $inactiveClients, 'Filtr nieaktywnych klientów powinien dać 200');
    assert_true(
        strpos($inactiveClients['body'], 'anna.nowak@example.com') !== false,
        'Lista powinna zawierać nieaktywnego klienta'
    );
    assert_true(
        strpos($inactiveClients['body'], 'jan.kowalski@example.com') === false,
        'Filtr nieaktywnych nie powinien pokazywać aktywnego klienta'
    );

    $admins = http_request($session['cookies'], 'GET', '/uzytkownicy.php?rola=admin&status=active');
    assert_status(200, $admins, 'Filtr administratorów powinien dać 200');
    assert_true(
        strpos($admins['body'], 'admin@wefixit.pl') !== false,
        'Lista powinna zawierać administratora'
    );

    $invalid = http_request($session['cookies'], 'GET', '/uzytkownicy.php?rola=nieznana&status=zly');
    assert_status(200, $invalid, 'Nieprawidłowe filtry powinny dać 200');
    assert_true(
        strpos($invalid['body'], 'admin@wefixit.pl') !== false,
        'Nieprawidłowe filtry powinny wrócić do pokazania wszystkich kont'
    );
    assert_true(strpos($invalid['body'], 'SQLSTATE') === false, 'Nieprawidłowy filtr nie może ujawniać SQL');

    $dashboard = http_request($session['cookies'], 'GET', '/admin.php');
    assert_status(200, $dashboard, 'Dashboard administratora powinien zwrócić 200');
    assert_true(
        strpos($dashboard['body'], '/uzytkownicy') !== false,
        'Dashboard powinien zawierać link do użytkowników'
    );
});

test('105. odpowiedzi nie ujawniają hashy, SQL ani ścieżek', function (): void {
    admin_users_reset_base();
    $session = admin_users_admin_session();
    $responses = [];

    $changed = admin_users_patch($session, 4, ['rola' => 'pracownik']);
    assert_status(200, $changed, 'Zmiana roli do testu 105 powinna dać 200');
    $responses[] = $changed;

    $invalid = admin_users_patch($session, 4, ['rola' => 'nieprawidłowa']);
    assert_status(422, $invalid, 'Nieprawidłowa rola do testu 105 powinna dać 422');
    $responses[] = $invalid;

    $page = http_request($session['cookies'], 'GET', '/uzytkownicy.php');
    assert_status(200, $page, 'Strona użytkowników do testu 105 powinna zwrócić 200');
    $responses[] = $page;

    $forbidden = [
        'haslo_hash',
        '$2y$',
        'SQLSTATE',
        'SELECT ',
        'UPDATE ',
        'DELETE FROM',
        '/var/www',
        'app/uzytkownicy.php',
        'public/config.php',
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
