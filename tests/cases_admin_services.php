<?php
declare(strict_types=1);

/*
    Testy modułu usług w panelu administratora.

    Obejmują tworzenie, kontrolę dostępu, CSRF, walidację ceny i czasu,
    unikalność nazwy w obrębie kategorii, blokadę usunięcia zależnej usługi,
    dezaktywację, filtry listy oraz brak wycieku danych technicznych.
*/

test_group('Panel: usługi', 'database/database.sql');

test('40. administrator tworzy usługę z ceną z przecinkiem', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/uslugi.php');
    $response = post_json($session['cookies'], '/api/admin/services.php', [
        'nazwa' => 'Usługa testowa 40',
        'opis' => 'Opis utworzony w teście 40.',
        'kategoria_id' => 1,
        'cena' => '199,99',
        'czas_trwania_minuty' => 75,
    ], $csrf);

    assert_status(201, $response, 'Utworzenie usługi powinno dać 201');
    $data = json_body($response);
    assert_true($data['success'] ?? false, 'Odpowiedź powinna zawierać success = true');

    $id = $data['id'] ?? null;
    assert_true(is_int($id) && $id > 0, 'Odpowiedź powinna zawierać identyfikator usługi');

    $statement = test_db()->prepare(
        'SELECT kategoria_id, czas_trwania_minuty, cena FROM uslugi WHERE id = :id'
    );
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    assert_same(1, (int) ($row['kategoria_id'] ?? 0), 'Kategoria powinna być zapisana');
    assert_same(75, (int) ($row['czas_trwania_minuty'] ?? 0), 'Czas trwania powinien być zapisany');
    assert_same('199.99', (string) ($row['cena'] ?? ''), 'Cena powinna być zapisana jako DECIMAL');
});

test('41. klient i pracownik dostają 403 na stronie i endpointcie usług', function (): void {
    $accounts = [
        ['email' => 'jan.kowalski@example.com', 'label' => 'klienta', 'nazwa' => 'Usługa klienta 41'],
        ['email' => 'tomasz.serwisant@wefixit.pl', 'label' => 'pracownika', 'nazwa' => 'Usługa pracownika 41'],
    ];

    foreach ($accounts as $account) {
        $session = login_as($account['email']);
        assert_status(200, $session['response'], 'Logowanie ' . $account['label'] . ' powinno się udać');

        $page = http_request($session['cookies'], 'GET', '/uslugi.php');
        assert_status(403, $page, 'Konto ' . $account['label'] . ' powinno dostać 403 na /uslugi.php');

        $csrf = csrf_from_page($session['cookies'], '/profil.php');
        $response = post_json($session['cookies'], '/api/admin/services.php', [
            'nazwa' => $account['nazwa'],
            'opis' => '',
            'kategoria_id' => 1,
            'cena' => '10,00',
            'czas_trwania_minuty' => 30,
        ], $csrf);

        assert_status(403, $response, 'Konto ' . $account['label'] . ' powinno dostać 403 na POST usług');

        $statement = test_db()->prepare('SELECT COUNT(*) FROM uslugi WHERE nazwa = :nazwa');
        $statement->execute(['nazwa' => $account['nazwa']]);
        assert_same(0, (int) $statement->fetchColumn(), 'Usługa nie może powstać bez roli admin');
    }
});

test('42. POST bez tokenu CSRF daje 403 i nie tworzy rekordu', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    csrf_from_page($session['cookies'], '/uslugi.php'); // Sesja istnieje, ale tokenu nie wysyłamy.

    $response = post_json($session['cookies'], '/api/admin/services.php', [
        'nazwa' => 'Usługa bez csrf 42',
        'opis' => '',
        'kategoria_id' => 1,
        'cena' => '10,00',
        'czas_trwania_minuty' => 30,
    ]);

    assert_status(403, $response, 'Brak tokenu CSRF powinien dać 403');

    $statement = test_db()->prepare('SELECT COUNT(*) FROM uslugi WHERE nazwa = :nazwa');
    $statement->execute(['nazwa' => 'Usługa bez csrf 42']);
    assert_same(0, (int) $statement->fetchColumn(), 'Usługa bez CSRF nie może zostać utworzona');
});

test('43. walidacja zwraca 422 z błędem przy właściwym polu', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/uslugi.php');
    $base = [
        'nazwa' => 'Usługa walidacyjna 43',
        'opis' => '',
        'kategoria_id' => 1,
        'cena' => '10,00',
        'czas_trwania_minuty' => 30,
    ];

    $cases = [
        [
            'payload' => array_diff_key($base, ['nazwa' => true]),
            'field' => 'nazwa',
            'label' => 'brak nazwy',
        ],
        [
            'payload' => array_merge($base, ['nazwa' => 'Usługa ujemna 43', 'cena' => '-10,00']),
            'field' => 'cena',
            'label' => 'cena ujemna',
        ],
        [
            'payload' => array_merge($base, ['nazwa' => 'Usługa precyzja 43', 'cena' => '10,999']),
            'field' => 'cena',
            'label' => 'cena z trzema miejscami po kropce',
        ],
        [
            'payload' => array_merge($base, ['nazwa' => 'Usługa czas 43', 'czas_trwania_minuty' => 0]),
            'field' => 'czas_trwania_minuty',
            'label' => 'czas trwania równy zero',
        ],
        [
            'payload' => array_merge($base, ['nazwa' => 'Usługa kategoria 43', 'kategoria_id' => 999999]),
            'field' => 'kategoria_id',
            'label' => 'kategoria nieistniejąca',
        ],
        [
            'payload' => array_merge($base, ['nazwa' => 'Usługa archiwalna 43', 'kategoria_id' => 4]),
            'field' => 'kategoria_id',
            'label' => 'kategoria nieaktywna',
        ],
    ];

    foreach ($cases as $case) {
        $response = post_json($session['cookies'], '/api/admin/services.php', $case['payload'], $csrf);
        assert_status(422, $response, 'Przypadek "' . $case['label'] . '" powinien dać 422');

        $data = json_body($response);
        assert_true(
            isset($data['errors'][$case['field']]) && is_string($data['errors'][$case['field']]),
            'Odpowiedź dla przypadku "' . $case['label'] . '" powinna zawierać errors.' . $case['field']
        );
    }
});

test('44. duplikat nazwy w kategorii daje 409, a w innej kategorii jest dozwolony', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/uslugi.php');
    $payload = [
        'nazwa' => 'Usługa testowa 44',
        'opis' => '',
        'kategoria_id' => 1,
        'cena' => '50,00',
        'czas_trwania_minuty' => 30,
    ];

    $created = post_json($session['cookies'], '/api/admin/services.php', $payload, $csrf);
    assert_status(201, $created, 'Pierwsze utworzenie usługi powinno dać 201');

    $duplicate = post_json($session['cookies'], '/api/admin/services.php', $payload, $csrf);
    assert_status(409, $duplicate, 'Duplikat nazwy w tej samej kategorii powinien dać 409');

    $data = json_body($duplicate);
    assert_true(
        isset($data['errors']['nazwa']) && is_string($data['errors']['nazwa']),
        'Odpowiedź 409 powinna zawierać errors.nazwa'
    );

    $otherCategory = $payload;
    $otherCategory['kategoria_id'] = 2;
    $accepted = post_json($session['cookies'], '/api/admin/services.php', $otherCategory, $csrf);
    assert_status(201, $accepted, 'Ta sama nazwa w innej kategorii powinna być dozwolona');
});

test('45. DELETE usługi z przypisaniem pracownika daje 409', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/uslugi.php');
    $response = delete_json($session['cookies'], '/api/admin/services.php', ['id' => 1], $csrf);

    assert_status(409, $response, 'Usunięcie usługi z przypisaniem powinno dać 409');

    $statement = test_db()->prepare('SELECT COUNT(*) FROM uslugi WHERE id = 1');
    $statement->execute();
    assert_same(1, (int) $statement->fetchColumn(), 'Usługa z przypisaniem nadal powinna istnieć w bazie');
});

test('46. dezaktywacja zmienia flagę i usuwa usługę z listy aktywnych', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/uslugi.php');
    $response = patch_json($session['cookies'], '/api/admin/services.php', [
        'id' => 2,
        'aktywna' => false,
    ], $csrf);

    assert_status(200, $response, 'Dezaktywacja usługi powinna dać 200');

    $pdo = test_db();
    $statement = $pdo->prepare('SELECT aktywna FROM uslugi WHERE id = 2');
    $statement->execute();
    assert_same(0, (int) $statement->fetchColumn(), 'Flaga aktywna powinna zostać wyłączona');

    $active = $pdo->query('SELECT COUNT(*) FROM uslugi WHERE id = 2 AND aktywna = TRUE')->fetchColumn();
    assert_same(0, (int) $active, 'Usługa nie powinna należeć do aktywnych');

    $exists = $pdo->query('SELECT COUNT(*) FROM uslugi WHERE id = 2')->fetchColumn();
    assert_same(1, (int) $exists, 'Dezaktywacja nie może usuwać rekordu z bazy');

    $page = http_request($session['cookies'], 'GET', '/uslugi.php?status=active');
    assert_status(200, $page, 'Lista aktywnych usług powinna zwrócić 200');
    assert_true(
        strpos($page['body'], 'Kompleksowa diagnostyka sprzętowa') === false,
        'Nieaktywna usługa nie powinna pojawiać się na liście aktywnych'
    );
});

test('47. filtry kategorii działają i błędna wartość nie wywołuje błędu', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $filtered = http_request($session['cookies'], 'GET', '/uslugi.php?id_kategorii=1');
    assert_status(200, $filtered, 'Filtr kategorii powinien zwrócić 200');
    assert_true(
        strpos($filtered['body'], 'Konserwacja układu chłodzenia laptopa') !== false,
        'Filtr powinien zawierać usługę z kategorii 1'
    );
    assert_true(
        strpos($filtered['body'], 'Wymiana matrycy w laptopie') === false,
        'Filtr nie powinien zawierać usługi z innej kategorii'
    );

    $invalid = http_request($session['cookies'], 'GET', '/uslugi.php?id_kategorii=abc');
    assert_status(200, $invalid, 'Nieprawidłowy filtr powinien spaść do wartości domyślnej');

    foreach (['SQLSTATE', 'SELECT ', 'FROM uslugi'] as $needle) {
        assert_true(
            strpos($invalid['body'], $needle) === false,
            'Nieprawidłowy filtr nie może ujawniać SQL: ' . $needle
        );
    }

    assert_true(
        strpos($invalid['body'], 'id="services-table"') !== false,
        'Nieprawidłowy filtr powinien pokazać domyślną listę usług'
    );
});

test('48. odpowiedzi JSON i HTML nie ujawniają danych technicznych', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/uslugi.php');
    $responses = [];

    $created = post_json($session['cookies'], '/api/admin/services.php', [
        'nazwa' => 'Usługa testowa 48',
        'opis' => 'Opis testowy.',
        'kategoria_id' => 1,
        'cena' => '99,99',
        'czas_trwania_minuty' => 45,
    ], $csrf);
    assert_status(201, $created, 'Utworzenie usługi do testu 48 powinno dać 201');
    $responses[] = $created;
    $id = json_body($created)['id'] ?? 0;

    $patched = patch_json($session['cookies'], '/api/admin/services.php', [
        'id' => $id,
        'nazwa' => 'Usługa testowa 48 zaktualizowana',
        'cena' => '100,01',
    ], $csrf);
    assert_status(200, $patched, 'Aktualizacja usługi do testu 48 powinna dać 200');
    $responses[] = $patched;

    $duplicate = post_json($session['cookies'], '/api/admin/services.php', [
        'nazwa' => 'Konserwacja układu chłodzenia laptopa',
        'opis' => '',
        'kategoria_id' => 1,
        'cena' => '10,00',
        'czas_trwania_minuty' => 30,
    ], $csrf);
    assert_status(409, $duplicate, 'Duplikat nazwy powinien dać 409');
    $responses[] = $duplicate;

    $deleted = delete_json($session['cookies'], '/api/admin/services.php', ['id' => $id], $csrf);
    assert_status(200, $deleted, 'Usunięcie usługi bez zależności powinno dać 200');
    $responses[] = $deleted;

    $page = http_request($session['cookies'], 'GET', '/uslugi.php');
    assert_status(200, $page, 'Strona usług powinna zwrócić 200');
    $responses[] = $page;

    $dashboard = http_request($session['cookies'], 'GET', '/admin.php');
    assert_status(200, $dashboard, 'Dashboard administratora powinien zwrócić 200');
    assert_true(
        strpos($dashboard['body'], 'href="/uslugi"') !== false,
        'Dashboard powinien zawierać link do usług'
    );
    $responses[] = $dashboard;

    $forbidden = ['haslo_hash', 'SQLSTATE', 'SELECT ', 'UPDATE ', 'DELETE FROM', '/var/www', 'app/uslugi.php'];

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

test('50. PATCH z tymi samymi wartościami usługi daje 200', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $row = test_db()->query(
        'SELECT kategoria_id, nazwa, opis, czas_trwania_minuty, cena, aktywna
         FROM uslugi WHERE id = 1'
    )->fetch();
    assert_true(is_array($row), 'Usługa testowa powinna istnieć w bazie');

    $csrf = csrf_from_page($session['cookies'], '/uslugi.php');
    $response = patch_json($session['cookies'], '/api/admin/services.php', [
        'id' => 1,
        'kategoria_id' => (int) $row['kategoria_id'],
        'nazwa' => $row['nazwa'],
        'opis' => $row['opis'] ?? '',
        'czas_trwania_minuty' => (int) $row['czas_trwania_minuty'],
        'cena' => $row['cena'],
        'aktywna' => (int) $row['aktywna'] === 1,
    ], $csrf);

    assert_status(200, $response, 'Zapis bez zmiany wartości powinien dać 200, a nie 404');
});

test('51. GET endpointu usług daje 405 z nagłówkiem Allow', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/api/admin/services.php');

    assert_status(405, $response, 'GET na endpoint usług powinien dać 405');

    $allow = header_value($response, 'allow');
    assert_true(is_string($allow) && $allow !== '', 'Odpowiedź 405 powinna zawierać nagłówek Allow');

    foreach (['POST', 'PATCH', 'DELETE'] as $method) {
        assert_true(
            strpos($allow, $method) !== false,
            'Nagłówek Allow powinien zawierać metodę ' . $method
        );
    }
});

test('52. POST bez Content-Type application/json daje 415 i nie tworzy rekordu', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/uslugi.php');
    $response = http_request($session['cookies'], 'POST', '/api/admin/services.php', [
        'body' => json_encode([
            'nazwa' => 'Usługa bez content type 52',
            'opis' => '',
            'kategoria_id' => 1,
            'cena' => '10,00',
            'czas_trwania_minuty' => 30,
        ], JSON_UNESCAPED_UNICODE),
        'headers' => ['X-CSRF-Token' => $csrf],
    ]);

    assert_status(415, $response, 'Brak Content-Type application/json powinien dać 415');

    $statement = test_db()->prepare('SELECT COUNT(*) FROM uslugi WHERE nazwa = :nazwa');
    $statement->execute(['nazwa' => 'Usługa bez content type 52']);
    assert_same(0, (int) $statement->fetchColumn(), 'Usługa bez poprawnego Content-Type nie może powstać');
});

test('Dodatkowo: edycja usługi w nieaktywnej kategorii nie wymaga zmiany kategorii', function (): void {
    $pdo = test_db();
    $statement = $pdo->prepare(
        'INSERT INTO uslugi (kategoria_id, nazwa, opis, czas_trwania_minuty, cena, aktywna)
         VALUES (4, :nazwa, :opis, 30, 50.00, TRUE)'
    );
    $statement->execute([
        'nazwa' => 'Usługa archiwalna test',
        'opis' => 'Usługa w nieaktywnej kategorii.',
    ]);
    $serviceId = (int) $pdo->lastInsertId();

    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/uslugi.php');
    $priceUpdate = patch_json($session['cookies'], '/api/admin/services.php', [
        'id' => $serviceId,
        'cena' => '60,00',
    ], $csrf);
    assert_status(200, $priceUpdate, 'Edycja ceny bez zmiany kategorii powinna dać 200');

    $statement = $pdo->prepare('SELECT kategoria_id, cena FROM uslugi WHERE id = :id');
    $statement->execute(['id' => $serviceId]);
    $row = $statement->fetch();
    assert_same(4, (int) ($row['kategoria_id'] ?? 0), 'Usługa powinna pozostać w nieaktywnej kategorii');
    assert_same('60.00', (string) ($row['cena'] ?? ''), 'Nowa cena powinna zostać zapisana');

    $sameCategoryUpdate = patch_json($session['cookies'], '/api/admin/services.php', [
        'id' => $serviceId,
        'kategoria_id' => 4,
        'opis' => 'Zaktualizowany opis archiwalnej usługi.',
    ], $csrf);
    assert_status(200, $sameCategoryUpdate, 'Zapis z tą samą nieaktywną kategorią powinien dać 200');

    $page = http_request($session['cookies'], 'GET', '/uslugi.php');
    assert_status(200, $page, 'Strona usług powinna zwrócić 200');
    assert_true(
        preg_match('/<select id="service-kategoria".*?<\/select>/s', $page['body'], $matches) === 1,
        'Formularz powinien zawierać select kategorii'
    );
    assert_true(
        strpos($matches[0], 'data-inactive="1"') !== false,
        'Select powinien zawierać nieaktywną kategorię oznaczoną dla JS'
    );
    assert_true(
        strpos($matches[0], '(nieaktywna)') !== false,
        'Nieaktywna kategoria powinna mieć czytelne oznaczenie'
    );
});

test('Dodatkowo: DELETE usługi wymaga Content-Type application/json', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/uslugi.php');
    $created = post_json($session['cookies'], '/api/admin/services.php', [
        'nazwa' => 'Usługa content type DELETE',
        'opis' => '',
        'kategoria_id' => 1,
        'cena' => '10,00',
        'czas_trwania_minuty' => 30,
    ], $csrf);
    assert_status(201, $created, 'Utworzenie usługi do testu Content-Type powinno dać 201');
    $id = json_body($created)['id'] ?? 0;

    $response = http_request($session['cookies'], 'DELETE', '/api/admin/services.php', [
        'body' => json_encode(['id' => $id], JSON_UNESCAPED_UNICODE),
        'content_type' => 'text/plain',
        'headers' => ['X-CSRF-Token' => $csrf],
    ]);

    assert_status(415, $response, 'DELETE z text/plain powinien dać 415');

    $statement = test_db()->prepare('SELECT COUNT(*) FROM uslugi WHERE id = :id');
    $statement->execute(['id' => $id]);
    assert_same(1, (int) $statement->fetchColumn(), 'Usługa nie może zostać usunięta bez JSON Content-Type');
});
