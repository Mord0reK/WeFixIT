<?php
declare(strict_types=1);

/*
    Testy modułu pracowników w panelu administratora.

    Obejmują tworzenie profilu dla istniejącego konta, kontrolę dostępu, CSRF,
    walidację konta i opisu, niezmienność powiązania, blokadę usunięcia
    profilu z zależnościami, dezaktywację oraz brak wycieku danych technicznych.
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
