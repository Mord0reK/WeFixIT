<?php
declare(strict_types=1);

/*
    Testy modułu kategorii w panelu administratora.

    Obejmują tworzenie, kontrolę dostępu, CSRF, walidację, blokadę usunięcia
    kategorii z usługami, dezaktywację, brak wycieku danych technicznych oraz
    widok strony i link w dashboardzie.
*/

test_group('Panel: kategorie', 'database/database.sql');

test('29. administrator tworzy kategorię przez POST', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/kategorie.php');
    $response = post_json($session['cookies'], '/api/admin/categories.php', [
        'nazwa' => 'Kategoria testowa 29',
        'opis' => 'Opis utworzony w teście 29.',
    ], $csrf);

    assert_status(201, $response, 'Utworzenie kategorii powinno dać 201');
    $data = json_body($response);
    assert_true($data['success'] ?? false, 'Odpowiedź powinna zawierać success = true');

    $id = $data['id'] ?? null;
    assert_true(is_int($id) && $id > 0, 'Odpowiedź powinna zawierać identyfikator kategorii');

    $statement = test_db()->prepare('SELECT nazwa, opis FROM kategorie_uslug WHERE id = :id');
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    assert_same('Kategoria testowa 29', $row['nazwa'] ?? null, 'Kategoria powinna istnieć w bazie');
    assert_same('Opis utworzony w teście 29.', $row['opis'] ?? null, 'Opis powinien zostać zapisany');
});

test('30. klient i pracownik dostają 403 na stronie i endpointcie kategorii', function (): void {
    $accounts = [
        ['email' => 'jan.kowalski@example.com', 'label' => 'klienta', 'nazwa' => 'Kategoria klienta 30'],
        ['email' => 'tomasz.serwisant@wefixit.pl', 'label' => 'pracownika', 'nazwa' => 'Kategoria pracownika 30'],
    ];

    foreach ($accounts as $account) {
        $session = login_as($account['email']);
        assert_status(200, $session['response'], 'Logowanie ' . $account['label'] . ' powinno się udać');

        $page = http_request($session['cookies'], 'GET', '/kategorie.php');
        assert_status(403, $page, 'Konto ' . $account['label'] . ' powinno dostać 403 na /kategorie.php');

        $csrf = csrf_from_page($session['cookies'], '/profil.php');
        $response = post_json($session['cookies'], '/api/admin/categories.php', [
            'nazwa' => $account['nazwa'],
            'opis' => '',
        ], $csrf);

        assert_status(403, $response, 'Konto ' . $account['label'] . ' powinno dostać 403 na POST kategorii');

        $statement = test_db()->prepare('SELECT COUNT(*) FROM kategorie_uslug WHERE nazwa = :nazwa');
        $statement->execute(['nazwa' => $account['nazwa']]);
        assert_same(0, (int) $statement->fetchColumn(), 'Kategoria nie może powstać bez roli admin');
    }
});

test('31. POST bez tokenu CSRF daje 403 i nie tworzy rekordu', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    csrf_from_page($session['cookies'], '/kategorie.php'); // Sesja istnieje, ale tokenu nie wysyłamy.

    $response = post_json($session['cookies'], '/api/admin/categories.php', [
        'nazwa' => 'Kategoria bez csrf 31',
        'opis' => '',
    ]);

    assert_status(403, $response, 'Brak tokenu CSRF powinien dać 403');

    $statement = test_db()->prepare('SELECT COUNT(*) FROM kategorie_uslug WHERE nazwa = :nazwa');
    $statement->execute(['nazwa' => 'Kategoria bez csrf 31']);
    assert_same(0, (int) $statement->fetchColumn(), 'Kategoria bez CSRF nie może zostać utworzona');
});

test('32. walidacja nazwy zwraca 422 z błędem przy polu nazwa', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/kategorie.php');

    $cases = [
        ['payload' => ['opis' => 'Brak nazwy.'], 'label' => 'brak nazwy'],
        ['payload' => ['nazwa' => str_repeat('a', 101), 'opis' => ''], 'label' => 'nazwa dłuższa niż 100 znaków'],
        ['payload' => ['nazwa' => 'Diagnostyka i Konserwacja', 'opis' => ''], 'label' => 'duplikat nazwy'],
    ];

    foreach ($cases as $case) {
        $response = post_json($session['cookies'], '/api/admin/categories.php', $case['payload'], $csrf);
        assert_status(422, $response, 'Przypadek "' . $case['label'] . '" powinien dać 422');

        $data = json_body($response);
        assert_true(
            isset($data['errors']['nazwa']) && is_string($data['errors']['nazwa']),
            'Odpowiedź dla przypadku "' . $case['label'] . '" powinna zawierać errors.nazwa'
        );
    }

    $statement = test_db()->prepare('SELECT COUNT(*) FROM kategorie_uslug WHERE nazwa = :nazwa');
    $statement->execute(['nazwa' => str_repeat('a', 101)]);
    assert_same(0, (int) $statement->fetchColumn(), 'Niepoprawna nazwa nie może trafić do bazy');
});

test('33. DELETE kategorii z usługami daje 409 i nie usuwa rekordu', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/kategorie.php');
    $response = delete_json($session['cookies'], '/api/admin/categories.php', ['id' => 1], $csrf);

    assert_status(409, $response, 'Usunięcie kategorii z usługami powinno dać 409');

    $statement = test_db()->prepare('SELECT COUNT(*) FROM kategorie_uslug WHERE id = 1');
    $statement->execute();
    assert_same(1, (int) $statement->fetchColumn(), 'Kategoria z usługami nadal powinna istnieć w bazie');
});

test('34. dezaktywacja zmienia flagę i usuwa kategorię z listy aktywnych', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/kategorie.php');
    $response = patch_json($session['cookies'], '/api/admin/categories.php', [
        'id' => 3,
        'aktywna' => false,
    ], $csrf);

    assert_status(200, $response, 'Dezaktywacja kategorii powinna dać 200');

    $pdo = test_db();
    $statement = $pdo->prepare('SELECT aktywna FROM kategorie_uslug WHERE id = 3');
    $statement->execute();
    assert_same(0, (int) $statement->fetchColumn(), 'Flaga aktywna powinna zostać wyłączona');

    $active = $pdo->query('SELECT COUNT(*) FROM kategorie_uslug WHERE id = 3 AND aktywna = TRUE')->fetchColumn();
    assert_same(0, (int) $active, 'Kategoria nie powinna należeć do aktywnych');

    $exists = $pdo->query('SELECT COUNT(*) FROM kategorie_uslug WHERE id = 3')->fetchColumn();
    assert_same(1, (int) $exists, 'Dezaktywacja nie może usuwać rekordu z bazy');
});

test('35. odpowiedzi JSON nie ujawniają hashy, SQL ani ścieżek', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/kategorie.php');
    $responses = [];

    $created = post_json($session['cookies'], '/api/admin/categories.php', [
        'nazwa' => 'Kategoria testowa 35',
        'opis' => 'Opis testowy.',
    ], $csrf);
    assert_status(201, $created, 'Utworzenie kategorii do testu 35 powinno dać 201');
    $responses[] = $created;
    $id = json_body($created)['id'] ?? 0;

    $patched = patch_json($session['cookies'], '/api/admin/categories.php', [
        'id' => $id,
        'nazwa' => 'Kategoria testowa 35 zaktualizowana',
        'opis' => 'Nowy opis.',
    ], $csrf);
    assert_status(200, $patched, 'Aktualizacja kategorii do testu 35 powinna dać 200');
    $responses[] = $patched;

    $duplicate = post_json($session['cookies'], '/api/admin/categories.php', [
        'nazwa' => 'Diagnostyka i Konserwacja',
        'opis' => '',
    ], $csrf);
    assert_status(422, $duplicate, 'Duplikat nazwy powinien dać 422');
    $responses[] = $duplicate;

    $deleted = delete_json($session['cookies'], '/api/admin/categories.php', ['id' => $id], $csrf);
    assert_status(200, $deleted, 'Usunięcie kategorii bez usług powinno dać 200');
    $responses[] = $deleted;

    $forbidden = ['haslo_hash', 'SQLSTATE', 'SELECT ', 'UPDATE ', 'DELETE FROM', '/var/www', 'app/kategorie.php'];

    foreach ($responses as $response) {
        foreach ($forbidden as $needle) {
            assert_true(
                strpos($response['body'], $needle) === false,
                'Odpowiedź JSON nie może zawierać: ' . $needle
            );
        }
    }

    $logFile = $GLOBALS['__test_server_log'] ?? '';
    assert_true(is_string($logFile) && $logFile !== '', 'Brak ścieżki logu serwera testowego');
    assert_same([], server_log_errors($logFile), 'Log serwera nie powinien zawierać błędów PHP');
});

test('36. strona kategorii zawiera tabelę i akcję, a dashboard link do kategorii', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $page = http_request($session['cookies'], 'GET', '/kategorie.php');
    assert_status(200, $page, 'Administrator powinien zobaczyć stronę kategorii');
    assert_true(
        strpos($page['body'], 'id="categories-table"') !== false,
        'Strona kategorii powinna zawierać tabelę'
    );
    assert_true(
        strpos($page['body'], 'id="category-submit"') !== false,
        'Strona kategorii powinna zawierać przycisk dodawania'
    );

    $dashboard = http_request($session['cookies'], 'GET', '/admin.php');
    assert_status(200, $dashboard, 'Administrator powinien zobaczyć dashboard');
    assert_true(
        strpos($dashboard['body'], 'href="/kategorie"') !== false,
        'Dashboard powinien zawierać link do kategorii'
    );
});

test('37. GET endpointu kategorii daje 405 z nagłówkiem Allow', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/api/admin/categories.php');

    assert_status(405, $response, 'GET na endpoint kategorii powinien dać 405');

    $allow = header_value($response, 'allow');
    assert_true(is_string($allow) && $allow !== '', 'Odpowiedź 405 powinna zawierać nagłówek Allow');

    foreach (['POST', 'PATCH', 'DELETE'] as $method) {
        assert_true(
            strpos($allow, $method) !== false,
            'Nagłówek Allow powinien zawierać metodę ' . $method
        );
    }
});

test('38. POST bez Content-Type application/json daje 415 i nie tworzy rekordu', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/kategorie.php');
    $response = http_request($session['cookies'], 'POST', '/api/admin/categories.php', [
        'body' => json_encode(['nazwa' => 'Kategoria bez content type 38', 'opis' => ''], JSON_UNESCAPED_UNICODE),
        'headers' => ['X-CSRF-Token' => $csrf],
    ]);

    assert_status(415, $response, 'Brak Content-Type application/json powinien dać 415');

    $statement = test_db()->prepare('SELECT COUNT(*) FROM kategorie_uslug WHERE nazwa = :nazwa');
    $statement->execute(['nazwa' => 'Kategoria bez content type 38']);
    assert_same(0, (int) $statement->fetchColumn(), 'Kategoria bez poprawnego Content-Type nie może powstać');
});

test('39. PATCH z nazwą innej kategorii daje 409 z błędem nazwy', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/kategorie.php');
    $response = patch_json($session['cookies'], '/api/admin/categories.php', [
        'id' => 2,
        'nazwa' => 'Diagnostyka i Konserwacja',
    ], $csrf);

    assert_status(409, $response, 'Konflikt unikalności nazwy przy PATCH powinien dać 409');

    $data = json_body($response);
    assert_true(
        isset($data['errors']['nazwa']) && is_string($data['errors']['nazwa']),
        'Odpowiedź 409 powinna zawierać errors.nazwa'
    );
});

test('49. PATCH z tymi samymi wartościami kategorii daje 200', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $row = test_db()->query('SELECT nazwa, opis FROM kategorie_uslug WHERE id = 1')->fetch();
    assert_true(is_array($row), 'Kategoria testowa powinna istnieć w bazie');

    $csrf = csrf_from_page($session['cookies'], '/kategorie.php');
    $response = patch_json($session['cookies'], '/api/admin/categories.php', [
        'id' => 1,
        'nazwa' => $row['nazwa'],
        'opis' => $row['opis'] ?? '',
    ], $csrf);

    assert_status(200, $response, 'Zapis bez zmiany wartości powinien dać 200, a nie 404');
});
