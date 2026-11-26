<?php
declare(strict_types=1);

/*
    Testy modułu pracowników w panelu administratora.

    Obejmują tworzenie profilu dla istniejącego konta, kontrolę dostępu, CSRF,
    walidację konta i opisu, niezmienność powiązania, blokadę usunięcia
    profilu z zależnościami, dezaktywację, wymóg zmiany roli przy aktywacji
    profilu konta bez roli pracownik oraz brak wycieku danych technicznych.
*/

test_group('Panel: pracownicy', 'database/database.sql');

function create_test_employee_user(string $email, string $lastName, string $phone): int
{
    $statement = test_db()->prepare(
        'INSERT INTO uzytkownicy (imie, nazwisko, email, telefon, haslo_hash, rola, aktywny)
         VALUES (:imie, :nazwisko, :email, :telefon, :haslo_hash, :rola, TRUE)'
    );
    $statement->execute([
        'imie' => 'Testowy',
        'nazwisko' => $lastName,
        'email' => $email,
        'telefon' => $phone,
        'haslo_hash' => password_hash('haslo123', PASSWORD_DEFAULT),
        'rola' => 'pracownik',
    ]);

    return (int) test_db()->lastInsertId();
}

test('53. administrator tworzy profil pracownika dla konta bez profilu', function (): void {
    $userId = create_test_employee_user('pracownik53@example.com', 'Pracownik53', '+48123456053');

    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/pracownicy.php');
    $response = post_json($session['cookies'], '/api/admin/employees.php', [
        'uzytkownik_id' => $userId,
        'opis' => 'Profil utworzony w teście 53.',
        'aktywny' => true,
    ], $csrf);

    assert_status(201, $response, 'Utworzenie profilu pracownika powinno dać 201');
    $data = json_body($response);
    $profileId = $data['id'] ?? null;
    assert_true(is_int($profileId) && $profileId > 0, 'Odpowiedź powinna zawierać identyfikator profilu');

    $statement = test_db()->prepare('SELECT uzytkownik_id FROM pracownicy WHERE id = :id');
    $statement->execute(['id' => $profileId]);
    assert_same($userId, (int) $statement->fetchColumn(), 'Profil powinien być powiązany z właściwym kontem');
});

test('54. klient i pracownik dostają 403, a konto klienta daje 422', function (): void {
    $accounts = [
        ['email' => 'jan.kowalski@example.com', 'label' => 'klienta'],
        ['email' => 'tomasz.serwisant@wefixit.pl', 'label' => 'pracownika'],
    ];

    foreach ($accounts as $account) {
        $session = login_as($account['email']);
        assert_status(200, $session['response'], 'Logowanie ' . $account['label'] . ' powinno się udać');

        $page = http_request($session['cookies'], 'GET', '/pracownicy.php');
        assert_status(403, $page, 'Konto ' . $account['label'] . ' powinno dostać 403 na /pracownicy.php');

        $csrf = csrf_from_page($session['cookies'], '/profil.php');
        $response = post_json($session['cookies'], '/api/admin/employees.php', [
            'uzytkownik_id' => 2,
            'opis' => '',
            'aktywny' => true,
        ], $csrf);

        assert_status(403, $response, 'Konto ' . $account['label'] . ' powinno dostać 403 na POST pracowników');
    }

    $admin = login_as('admin@wefixit.pl');
    assert_status(200, $admin['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($admin['cookies'], '/pracownicy.php');
    $clientRole = post_json($admin['cookies'], '/api/admin/employees.php', [
        'uzytkownik_id' => 4,
        'opis' => '',
        'aktywny' => true,
    ], $csrf);

    assert_status(422, $clientRole, 'Konto klienta powinno dać 422');

    $data = json_body($clientRole);
    assert_true(
        isset($data['errors']['uzytkownik_id']) && is_string($data['errors']['uzytkownik_id']),
        'Odpowiedź powinna zawierać errors.uzytkownik_id'
    );
});

test('55. POST bez tokenu CSRF daje 403 i nie tworzy profilu', function (): void {
    $userId = create_test_employee_user('pracownik55@example.com', 'Pracownik55', '+48123456055');

    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    csrf_from_page($session['cookies'], '/pracownicy.php'); // Sesja istnieje, ale tokenu nie wysyłamy.

    $response = post_json($session['cookies'], '/api/admin/employees.php', [
        'uzytkownik_id' => $userId,
        'opis' => '',
        'aktywny' => true,
    ]);

    assert_status(403, $response, 'Brak tokenu CSRF powinien dać 403');

    $statement = test_db()->prepare('SELECT COUNT(*) FROM pracownicy WHERE uzytkownik_id = :id');
    $statement->execute(['id' => $userId]);
    assert_same(0, (int) $statement->fetchColumn(), 'Profil bez CSRF nie może powstać');
});

test('56. walidacja konta i opisu zwraca 422 przy właściwym polu', function (): void {
    $userId = create_test_employee_user('pracownik56@example.com', 'Pracownik56', '+48123456056');

    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/pracownicy.php');
    $cases = [
        [
            'payload' => ['opis' => '', 'aktywny' => true],
            'field' => 'uzytkownik_id',
            'label' => 'brak uzytkownik_id',
        ],
        [
            'payload' => ['uzytkownik_id' => 999999, 'opis' => '', 'aktywny' => true],
            'field' => 'uzytkownik_id',
            'label' => 'konto nieistniejące',
        ],
        [
            'payload' => ['uzytkownik_id' => 2, 'opis' => '', 'aktywny' => true],
            'field' => 'uzytkownik_id',
            'label' => 'konto z istniejącym profilem',
        ],
        [
            'payload' => [
                'uzytkownik_id' => $userId,
                'opis' => str_repeat('a', 2001),
                'aktywny' => true,
            ],
            'field' => 'opis',
            'label' => 'opis dłuższy niż 2000 znaków',
        ],
    ];

    foreach ($cases as $case) {
        $response = post_json($session['cookies'], '/api/admin/employees.php', $case['payload'], $csrf);
        assert_status(422, $response, 'Przypadek "' . $case['label'] . '" powinien dać 422');

        $data = json_body($response);
        assert_true(
            isset($data['errors'][$case['field']]) && is_string($data['errors'][$case['field']]),
            'Odpowiedź dla przypadku "' . $case['label'] . '" powinna zawierać errors.' . $case['field']
        );
    }

    $statement = test_db()->prepare('SELECT COUNT(*) FROM pracownicy WHERE uzytkownik_id = :id');
    $statement->execute(['id' => $userId]);
    assert_same(0, (int) $statement->fetchColumn(), 'Niepoprawne dane nie mogą utworzyć profilu');
});

test('57. PATCH nie pozwala zmienić powiązanego konta', function (): void {
    $statement = test_db()->prepare(
        'SELECT p.id, p.uzytkownik_id
         FROM pracownicy p
         JOIN uzytkownicy u ON u.id = p.uzytkownik_id
         WHERE u.email = :email'
    );
    $statement->execute(['email' => 'pracownik53@example.com']);
    $profile = $statement->fetch();
    assert_true(is_array($profile), 'Profil z testu 53 powinien istnieć');

    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/pracownicy.php');
    $response = patch_json($session['cookies'], '/api/admin/employees.php', [
        'id' => (int) $profile['id'],
        'uzytkownik_id' => 3,
    ], $csrf);

    assert_status(422, $response, 'Zmiana powiązanego konta powinna dać 422');

    $data = json_body($response);
    assert_true(
        isset($data['errors']['uzytkownik_id']) && is_string($data['errors']['uzytkownik_id']),
        'Odpowiedź powinna zawierać errors.uzytkownik_id'
    );

    $check = test_db()->prepare('SELECT uzytkownik_id FROM pracownicy WHERE id = :id');
    $check->execute(['id' => (int) $profile['id']]);
    assert_same(
        (int) $profile['uzytkownik_id'],
        (int) $check->fetchColumn(),
        'Powiązanie konta nie może się zmienić'
    );
});

test('58. DELETE profilu z powiązaniami daje 409', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/pracownicy.php');
    $response = delete_json($session['cookies'], '/api/admin/employees.php', ['id' => 1], $csrf);

    assert_status(409, $response, 'Usunięcie profilu z powiązaniami powinno dać 409');

    $statement = test_db()->prepare('SELECT COUNT(*) FROM pracownicy WHERE id = 1');
    $statement->execute();
    assert_same(1, (int) $statement->fetchColumn(), 'Profil z powiązaniami nadal powinien istnieć');
});

test('59. dezaktywacja zmienia flagę i nie usuwa profilu', function (): void {
    $statement = test_db()->prepare(
        'SELECT p.id
         FROM pracownicy p
         JOIN uzytkownicy u ON u.id = p.uzytkownik_id
         WHERE u.email = :email'
    );
    $statement->execute(['email' => 'pracownik53@example.com']);
    $profileId = (int) $statement->fetchColumn();
    assert_true($profileId > 0, 'Profil z testu 53 powinien istnieć');

    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/pracownicy.php');
    $response = patch_json($session['cookies'], '/api/admin/employees.php', [
        'id' => $profileId,
        'aktywny' => false,
    ], $csrf);

    assert_status(200, $response, 'Dezaktywacja pracownika powinna dać 200');

    $pdo = test_db();
    $check = $pdo->prepare('SELECT aktywny FROM pracownicy WHERE id = :id');
    $check->execute(['id' => $profileId]);
    assert_same(0, (int) $check->fetchColumn(), 'Flaga aktywny powinna zostać wyłączona');

    $exists = $pdo->prepare('SELECT COUNT(*) FROM pracownicy WHERE id = :id');
    $exists->execute(['id' => $profileId]);
    assert_same(1, (int) $exists->fetchColumn(), 'Dezaktywacja nie może usuwać profilu');

    $page = http_request($session['cookies'], 'GET', '/pracownicy.php');
    assert_status(200, $page, 'Strona pracowników powinna nadal zwracać 200');
});

test('60. odpowiedzi JSON i HTML nie ujawniają danych technicznych', function (): void {
    $userId = create_test_employee_user('pracownik60@example.com', 'Pracownik60', '+48123456060');

    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/pracownicy.php');
    $responses = [];

    $created = post_json($session['cookies'], '/api/admin/employees.php', [
        'uzytkownik_id' => $userId,
        'opis' => 'Opis testowy 60.',
        'aktywny' => true,
    ], $csrf);
    assert_status(201, $created, 'Utworzenie profilu do testu 60 powinno dać 201');
    $responses[] = $created;
    $id = json_body($created)['id'] ?? 0;

    $patched = patch_json($session['cookies'], '/api/admin/employees.php', [
        'id' => $id,
        'opis' => 'Zaktualizowany opis 60.',
    ], $csrf);
    assert_status(200, $patched, 'Aktualizacja profilu do testu 60 powinna dać 200');
    $responses[] = $patched;

    $deleted = delete_json($session['cookies'], '/api/admin/employees.php', ['id' => $id], $csrf);
    assert_status(200, $deleted, 'Usunięcie profilu bez zależności powinno dać 200');
    $responses[] = $deleted;

    $page = http_request($session['cookies'], 'GET', '/pracownicy.php');
    assert_status(200, $page, 'Strona pracowników powinna zwrócić 200');
    $responses[] = $page;

    $forbidden = ['haslo_hash', 'SQLSTATE', 'SELECT ', 'UPDATE ', 'DELETE FROM', '/var/www', 'app/pracownicy.php'];

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

test('61. dashboard administratora zawiera link do pracowników', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $dashboard = http_request($session['cookies'], 'GET', '/admin.php');
    assert_status(200, $dashboard, 'Dashboard administratora powinien zwrócić 200');
    assert_true(
        strpos($dashboard['body'], 'href="/pracownicy"') !== false,
        'Dashboard powinien zawierać link do pracowników'
    );
});

test('62. konto testowe bez profilu jest widoczne w select i znika po utworzeniu profilu', function (): void {
    $statement = test_db()->prepare('SELECT id FROM uzytkownicy WHERE email = :email');
    $statement->execute(['email' => 'kamil.testowy@wefixit.pl']);
    $userId = (int) $statement->fetchColumn();
    assert_true($userId > 0, 'Konto testowe bez profilu powinno istnieć w database.sql');

    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $page = http_request($session['cookies'], 'GET', '/pracownicy.php');
    assert_status(200, $page, 'Strona pracowników powinna zwrócić 200');
    assert_true(
        preg_match('/<select id="employee-uzytkownik".*?<\/select>/s', $page['body'], $matches) === 1,
        'Formularz powinien zawierać select konta'
    );
    assert_true(
        strpos($matches[0], 'kamil.testowy@wefixit.pl') !== false,
        'Select powinien zawierać konto testowe bez profilu'
    );

    $csrf = csrf_from_page($session['cookies'], '/pracownicy.php');
    $created = post_json($session['cookies'], '/api/admin/employees.php', [
        'uzytkownik_id' => $userId,
        'opis' => 'Profil utworzony w teście 62.',
        'aktywny' => true,
    ], $csrf);
    assert_status(201, $created, 'Utworzenie profilu dla konta testowego powinno dać 201');

    $after = http_request($session['cookies'], 'GET', '/pracownicy.php');
    assert_status(200, $after, 'Strona pracowników po utworzeniu profilu powinna zwrócić 200');
    assert_true(
        preg_match('/<select id="employee-uzytkownik".*?<\/select>/s', $after['body'], $matchesAfter) === 1,
        'Formularz nadal powinien zawierać select konta'
    );
    assert_true(
        strpos($matchesAfter[0], 'kamil.testowy@wefixit.pl') === false,
        'Konto z utworzonym profilem nie może być proponowane w select'
    );
});

test('63. GET endpointu pracowników daje 401 bez logowania i 200 dla administratora', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/api/admin/employees.php');

    assert_status(401, $response, 'GET bez logowania powinien dać 401');

    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');
    $listResponse = http_request($session['cookies'], 'GET', '/api/admin/employees.php');
    assert_status(200, $listResponse, 'GET zalogowanego administratora powinien dać 200');

    $body = json_body($listResponse);
    assert_array_key($body, 'success', 'Odpowiedź powinna zawierać success');
    assert_same(true, $body['success'], 'GET listy pracowników powinien zakończyć się sukcesem');
    assert_array_key($body, 'pracownicy', 'Odpowiedź powinna zawierać listę pracowników');
    assert_array_key($body, 'konta_dostepne', 'Odpowiedź powinna zawierać konta dostępne do powiązania');
    assert_array_key($body, 'paginacja', 'Odpowiedź powinna zawierać paginację');

    // Konta dostępne muszą zgadzać się z bazą i nie mogą mieć profili.
    $available = test_db()->query(
        "SELECT COUNT(*) FROM uzytkownicy u
         LEFT JOIN pracownicy p ON p.uzytkownik_id = u.id
         WHERE u.rola = 'pracownik' AND u.aktywny = TRUE AND p.id IS NULL"
    )->fetchColumn();
    assert_same((int) $available, count($body['konta_dostepne']), 'Lista kont dostępnych powinna zgadzać się z bazą');

    $profileIds = test_db()->query('SELECT uzytkownik_id FROM pracownicy')->fetchAll(PDO::FETCH_COLUMN);

    foreach ($body['konta_dostepne'] as $account) {
        assert_true(
            !in_array((string) $account['id'], array_map('strval', $profileIds), true),
            'Konto z profilem nie może być proponowane do powiązania'
        );
    }
});

test('63a. GET pracowników obsługuje filtry i paginację', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $activeTotal = (int) test_db()->query(
        'SELECT COUNT(*) FROM pracownicy p JOIN uzytkownicy u ON u.id = p.uzytkownik_id WHERE p.aktywny = TRUE'
    )->fetchColumn();
    $statusFilter = http_request($session['cookies'], 'GET', '/api/admin/employees.php?status=active');
    assert_status(200, $statusFilter, 'Filtr statusu powinien dać 200');
    $statusBody = json_body($statusFilter);
    assert_same($activeTotal, (int) $statusBody['paginacja']['razem'], 'Filtr aktywnych powinien zgadzać się z bazą');

    foreach ($statusBody['pracownicy'] as $employee) {
        assert_same(true, $employee['aktywny'], 'Filtr aktywnych może zwracać wyłącznie aktywnych pracowników');
    }

    $searchTotal = (int) test_db()->query(
        "SELECT COUNT(*) FROM pracownicy p JOIN uzytkownicy u ON u.id = p.uzytkownik_id
         WHERE u.nazwisko LIKE '%Elektronik%' OR u.imie LIKE '%Elektronik%'"
    )->fetchColumn();
    $search = http_request($session['cookies'], 'GET', '/api/admin/employees.php?szukaj=Elektronik');
    assert_status(200, $search, 'Wyszukiwanie powinno dać 200');
    $searchBody = json_body($search);
    assert_same($searchTotal, count($searchBody['pracownicy']), 'Wyszukiwanie powinno zgadzać się z bazą');

    $paged = http_request($session['cookies'], 'GET', '/api/admin/employees.php?size=1&page=2');
    assert_status(200, $paged, 'Paginacja powinna dać 200');
    $pagedBody = json_body($paged);
    assert_same(1, count($pagedBody['pracownicy']), 'Rozmiar strony ogranicza liczbę wyników');
    assert_same(2, $pagedBody['paginacja']['strona'], 'Odpowiedź powinna informować o drugiej stronie');

    $total = (int) test_db()->query('SELECT COUNT(*) FROM pracownicy')->fetchColumn();
    $expectedPages = max(1, (int) ceil($total / 1));
    assert_same($expectedPages, (int) $pagedBody['paginacja']['stron'], 'Liczba stron powinna wynikać z liczby pracowników');
});

test('64. POST bez Content-Type application/json daje 415 i nie tworzy profilu', function (): void {
    $userId = create_test_employee_user('pracownik64@example.com', 'Pracownik64', '+48123456064');

    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/pracownicy.php');
    $response = http_request($session['cookies'], 'POST', '/api/admin/employees.php', [
        'body' => json_encode([
            'uzytkownik_id' => $userId,
            'opis' => '',
            'aktywny' => true,
        ], JSON_UNESCAPED_UNICODE),
        'headers' => ['X-CSRF-Token' => $csrf],
    ]);

    assert_status(415, $response, 'Brak Content-Type application/json powinien dać 415');

    $statement = test_db()->prepare('SELECT COUNT(*) FROM pracownicy WHERE uzytkownik_id = :id');
    $statement->execute(['id' => $userId]);
    assert_same(0, (int) $statement->fetchColumn(), 'Profil bez poprawnego Content-Type nie może powstać');
});

test('65. PATCH z tymi samymi wartościami pracownika daje 200', function (): void {
    $row = test_db()->query('SELECT opis, aktywny FROM pracownicy WHERE id = 1')->fetch();
    assert_true(is_array($row), 'Profil testowy powinien istnieć w bazie');

    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/pracownicy.php');
    $response = patch_json($session['cookies'], '/api/admin/employees.php', [
        'id' => 1,
        'opis' => $row['opis'] ?? '',
        'aktywny' => (int) $row['aktywny'] === 1,
    ], $csrf);

    assert_status(200, $response, 'Zapis bez zmiany wartości powinien dać 200, a nie 404 ani 500');

    $after = test_db()->query('SELECT opis, aktywny FROM pracownicy WHERE id = 1')->fetch();
    assert_same($row['opis'] ?? null, $after['opis'] ?? null, 'Opis po zapisie powinien być identyczny');
    assert_same((int) $row['aktywny'], (int) $after['aktywny'], 'Flaga aktywności po zapisie powinna być identyczna');
});

test('65a. aktywacja profilu konta bez roli pracownik wymaga zmiany roli', function (): void {
    $statement = test_db()->prepare('UPDATE uzytkownicy SET rola = :rola WHERE id = 3');
    $statement->execute(['rola' => 'klient']);
    $statement = test_db()->prepare('UPDATE pracownicy SET aktywny = FALSE WHERE id = 2');
    $statement->execute();

    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');
    $csrf = csrf_from_page($session['cookies'], '/pracownicy.php');

    try {
        $blocked = patch_json($session['cookies'], '/api/admin/employees.php', [
            'id' => 2,
            'aktywny' => true,
        ], $csrf);
        assert_status(422, $blocked, 'Aktywacja profilu konta klienta powinna dać 422');

        $data = json_body($blocked);
        $message = $data['errors']['aktywny'] ?? '';
        assert_true(is_string($message) && $message !== '', 'Odpowiedź powinna zawierać errors.aktywny');
        assert_true(
            strpos($message, 'rol') !== false,
            'Komunikat powinien wskazywać potrzebę zmiany roli konta'
        );

        $row = test_db()->query('SELECT aktywny FROM pracownicy WHERE id = 2')->fetch();
        assert_same(0, (int) $row['aktywny'], 'Profil nie może się aktywować przed zmianą roli');

        // Popup w panelu najpierw zmienia rolę konta — po niej aktywacja przechodzi.
        $roleResponse = patch_json($session['cookies'], '/api/admin/users.php', [
            'id' => 3,
            'rola' => 'pracownik',
        ], $csrf);
        assert_status(200, $roleResponse, 'Zmiana roli konta na pracownik powinna dać 200');

        $activated = patch_json($session['cookies'], '/api/admin/employees.php', [
            'id' => 2,
            'aktywny' => true,
        ], $csrf);
        assert_status(200, $activated, 'Aktywacja po zmianie roli powinna dać 200');

        $row = test_db()->query('SELECT aktywny FROM pracownicy WHERE id = 2')->fetch();
        assert_same(1, (int) $row['aktywny'], 'Profil powinien być aktywny po zmianie roli');
    } finally {
        $statement = test_db()->prepare('UPDATE uzytkownicy SET rola = :rola WHERE id = 3');
        $statement->execute(['rola' => 'pracownik']);
        $statement = test_db()->prepare('UPDATE pracownicy SET aktywny = TRUE WHERE id = 2');
        $statement->execute();
    }
});

test('Dodatkowo: DELETE pracownika nie wymaga Content-Type application/json', function (): void {
    $userId = create_test_employee_user('pracownik66@example.com', 'Pracownik66', '+48123456066');

    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/pracownicy.php');
    $created = post_json($session['cookies'], '/api/admin/employees.php', [
        'uzytkownik_id' => $userId,
        'opis' => '',
        'aktywny' => true,
    ], $csrf);
    assert_status(201, $created, 'Utworzenie profilu do testu Content-Type powinno dać 201');
    $id = json_body($created)['id'] ?? 0;

    // DELETE nie opisuje reprezentacji zasobu, więc wspólna reguła nie wymaga
    // dla niego Content-Type. Body JSON z identyfikatorem jest nadal odczytywane.
    $response = http_request($session['cookies'], 'DELETE', '/api/admin/employees.php', [
        'body' => json_encode(['id' => $id], JSON_UNESCAPED_UNICODE),
        'headers' => ['X-CSRF-Token' => $csrf],
    ]);

    assert_status(200, $response, 'DELETE bez Content-Type powinien usunąć profil');

    $statement = test_db()->prepare('SELECT COUNT(*) FROM pracownicy WHERE id = :id');
    $statement->execute(['id' => $id]);
    assert_same(0, (int) $statement->fetchColumn(), 'Profil powinien zostać usunięty bez nagłówka Content-Type');
});
