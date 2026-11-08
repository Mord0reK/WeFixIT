<?php
declare(strict_types=1);

/*
    Testy publicznej oferty usług.

    Obejmują dostęp bez logowania, widoczność tylko aktywnych usług i kategorii,
    filtr kategorii, szczegóły usługi, ukrywanie nieaktywnych rekordów,
    escapowanie danych oraz brak zmian stanu przez żądania GET.
*/

test_group('Oferta publiczna', 'database/database.sql');

test('158. publiczna oferta zwraca 200 bez logowania i bez cookies', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/oferta.php');

    assert_status(200, $response, 'Publiczna oferta powinna być dostępna bez logowania');
    assert_true(
        strpos($response['body'], 'Oferta usług') !== false,
        'Strona oferty powinna zawierać nagłówek „Oferta usług”'
    );
});

test('159. lista zawiera aktywne usługi i pomija nieaktywną usługę', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/oferta.php');

    assert_status(200, $response, 'Lista oferty powinna zwrócić 200');
    assert_true(
        strpos($response['body'], 'Kompleksowa diagnostyka sprzętowa') !== false,
        'Oferta powinna zawierać aktywną usługę diagnostyki'
    );
    assert_true(
        strpos($response['body'], 'Wycofana usługa naprawy napędów DVD') === false,
        'Oferta nie może zawierać nieaktywnej usługi'
    );
});

test('160. lista pomija usługi z nieaktywnej kategorii', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/oferta.php');

    assert_status(200, $response, 'Lista oferty powinna zwrócić 200');
    assert_true(
        strpos($response['body'], 'Archiwalna Kategoria') === false,
        'Oferta nie może zawierać nieaktywnej kategorii'
    );
});

test('161. filtr kategorii zawęża listę do wybranej kategorii', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/oferta.php?kategoria=1');

    assert_status(200, $response, 'Filtr kategorii powinien zwrócić 200');
    assert_true(
        strpos($response['body'], 'Konserwacja układu chłodzenia laptopa') !== false,
        'Filtr kategorii 1 powinien zawierać usługę z tej kategorii'
    );
    assert_true(
        strpos($response['body'], 'Kompleksowa diagnostyka sprzętowa') !== false,
        'Filtr kategorii 1 powinien zawierać drugą usługę z tej kategorii'
    );
    assert_true(
        strpos($response['body'], 'Wymiana matrycy w laptopie') === false,
        'Filtr kategorii 1 nie może zawierać usługi z innej kategorii'
    );
});

test('162. nieprawidłowy filtr kategorii oznacza brak filtra', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/oferta.php?kategoria=abc');

    assert_status(200, $response, 'Nieprawidłowy filtr nie może powodować błędu');
    assert_true(
        strpos($response['body'], 'Kompleksowa diagnostyka sprzętowa') !== false,
        'Brak filtra powinien pokazać usługę z kategorii 1'
    );
    assert_true(
        strpos($response['body'], 'Wymiana matrycy w laptopie') !== false,
        'Brak filtra powinien pokazać usługę z kategorii 2'
    );
});

test('163. szczegóły usługi zawierają nazwę i cenę', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/usluga.php?id=2');

    assert_status(200, $response, 'Szczegóły aktywnej usługi powinny zwrócić 200');
    assert_true(
        strpos($response['body'], 'Kompleksowa diagnostyka sprzętowa') !== false,
        'Szczegóły powinny zawierać nazwę usługi'
    );
    assert_true(
        strpos($response['body'], '80,00 zł') !== false,
        'Szczegóły powinny zawierać cenę w formacie polskim'
    );
});

test('164. nieaktywna usługa zwraca 404', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/usluga.php?id=7');

    assert_status(404, $response, 'Nieaktywna usługa powinna zwrócić 404');
    assert_true(
        strpos($response['body'], 'Nie znaleziono usługi') !== false,
        'Strona 404 powinna zawierać przyjazny komunikat'
    );
});

test('165. nieistniejąca usługa zwraca 404', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/usluga.php?id=999999');

    assert_status(404, $response, 'Nieistniejąca usługa powinna zwrócić 404');
});

test('166. brak identyfikatora usługi zwraca 404', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/usluga.php');

    assert_status(404, $response, 'Brak identyfikatora usługi powinien zwrócić 404');
});

test('167. nazwa usługi jest escapowana w ofercie', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $csrf = csrf_from_page($session['cookies'], '/uslugi.php');
    $name = 'Usługa XSS <script>alert(1)</script> 167';
    $created = post_json($session['cookies'], '/api/admin/services.php', [
        'nazwa' => $name,
        'opis' => 'Opis usługi do testu XSS.',
        'kategoria_id' => 1,
        'cena' => '10,00',
        'czas_trwania_minuty' => 30,
    ], $csrf);

    assert_status(201, $created, 'Utworzenie usługi do testu XSS powinno dać 201');

    $cookies = [];
    $response = http_request($cookies, 'GET', '/oferta.php');
    assert_status(200, $response, 'Oferta po dodaniu usługi powinna zwrócić 200');
    assert_true(
        strpos($response['body'], '&lt;script&gt;alert(1)&lt;/script&gt;') !== false,
        'Nazwa usługi powinna być escapowana w HTML'
    );
    assert_true(
        strpos($response['body'], '<script>alert(1)</script>') === false,
        'Surowy znacznik script nie może pojawić się w treści usługi'
    );
});

test('168. żądania GET oferty nie wymagają CSRF i nie zmieniają rezerwacji', function (): void {
    $before = (int) test_db()->query('SELECT COUNT(*) FROM rezerwacje')->fetchColumn();
    $paths = ['/oferta.php', '/usluga.php?id=2', '/oferta.php?kategoria=1'];

    foreach ($paths as $path) {
        $cookies = [];
        $response = http_request($cookies, 'GET', $path);
        assert_status(200, $response, 'Żądanie GET ' . $path . ' powinno zwrócić 200 bez CSRF');
    }

    $after = (int) test_db()->query('SELECT COUNT(*) FROM rezerwacje')->fetchColumn();
    assert_same($before, $after, 'Żądania GET oferty nie mogą zmieniać liczby rezerwacji');
});
