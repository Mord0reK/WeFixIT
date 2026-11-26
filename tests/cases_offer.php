<?php
declare(strict_types=1);

/*
    Testy oferty usług: publicznej listy i panelowego filtra z wyszukiwaniem.

    Obejmują dostęp bez logowania, widoczność tylko aktywnych usług i kategorii,
    filtr kategorii, wyszukiwanie po nazwie (strona i endpoint), szczegóły
    usługi, ukrywanie nieaktywnych rekordów, escapowanie danych oraz brak
    zmian stanu przez żądania GET.
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

test('172. zalogowany klient widzi ofertę wewnątrz panelu', function (): void {
    $session = login_as('jan.kowalski@example.com');
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');

    $response = http_request($session['cookies'], 'GET', '/oferta.php');
    assert_status(200, $response, 'Oferta w panelu powinna zwrócić 200');
    assert_true(
        strpos($response['body'], 'id="panel-shell"') !== false,
        'Oferta zalogowanego klienta powinna renderować się wewnątrz panelu'
    );
    assert_true(
        strpos($response['body'], 'Oferta usług') !== false,
        'Oferta w panelu powinna zawierać nagłówek „Oferta usług”'
    );
    assert_true(
        strpos($response['body'], 'Nowa rezerwacja') !== false,
        'Oferta w panelu klienta powinna prowadzić do nowej rezerwacji'
    );
    assert_true(
        strpos($response['body'], 'id="offer-filter-form"') !== false,
        'Oferta w panelu powinna mieć filtr kategorii obsługiwany przez JavaScript'
    );
    assert_true(
        strpos($response['body'], 'data-filter-dropdown') !== false,
        'Filtr kategorii oferty powinien być dropdownem jak w tabelach panelu'
    );
    assert_true(
        strpos($response['body'], 'name="szukaj"') !== false,
        'Oferta w panelu powinna mieć pole wyszukiwania'
    );

    $cookies = [];
    $guest = http_request($cookies, 'GET', '/oferta.php');
    assert_status(200, $guest, 'Oferta gościa powinna nadal zwracać 200');
    assert_true(
        strpos($guest['body'], 'id="panel-shell"') === false,
        'Gość nie może widzieć panelu zalogowanego użytkownika'
    );
    assert_true(
        strpos($guest['body'], 'id="offer-filter-form"') === false,
        'Strona publiczna dla gościa działa bez JavaScriptu i bez formularza filtra'
    );
    assert_true(
        strpos($guest['body'], 'name="szukaj"') === false,
        'Pole wyszukiwania należy do panelu, nie do strony publicznej'
    );
});

test('173. endpoint oferty zwraca aktywne kategorie i usługi bez logowania', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/api/oferta.php');

    assert_status(200, $response, 'Endpoint oferty powinien być publiczny');
    assert_same(
        'application/json; charset=utf-8',
        header_value($response, 'content-type'),
        'Endpoint oferty powinien zwracać JSON'
    );

    $data = json_body($response);
    assert_same(true, $data['success'] ?? null, 'Odpowiedź powinna zawierać success: true');
    assert_true(is_array($data['kategorie'] ?? null), 'Odpowiedź powinna zawierać listę kategorii');
    assert_true(is_array($data['uslugi'] ?? null), 'Odpowiedź powinna zawierać listę usług');
    assert_true(
        strpos($response['body'], 'Kompleksowa diagnostyka sprzętowa') !== false,
        'Endpoint powinien zwracać aktywną usługę diagnostyki'
    );
    assert_true(
        strpos($response['body'], 'Wycofana usługa naprawy napędów DVD') === false,
        'Endpoint nie może zwracać nieaktywnej usługi'
    );
});

test('174. endpoint oferty zawęża listę do wybranej kategorii', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/api/oferta.php?kategoria=1');

    assert_status(200, $response, 'Filtrowany endpoint oferty powinien zwrócić 200');

    $data = json_body($response);
    $services = $data['uslugi'] ?? [];
    assert_true(is_array($services), 'Odpowiedź powinna zawierać listę usług');

    foreach ($services as $service) {
        assert_same(1, $service['kategoria_id'] ?? null, 'Filtr kategorii może zwracać wyłącznie usługi z kategorii 1');
    }

    assert_true(
        strpos($response['body'], 'Konserwacja układu chłodzenia laptopa') !== false,
        'Filtr kategorii 1 powinien zwrócić usługę z tej kategorii'
    );
    assert_true(
        strpos($response['body'], 'Wymiana matrycy w laptopie') === false,
        'Filtr kategorii 1 nie może zwracać usług z innych kategorii'
    );
});

test('175. nieprawidłowy filtr endpointu oferty oznacza brak filtra', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/api/oferta.php?kategoria=abc');

    assert_status(200, $response, 'Nieprawidłowy filtr nie może powodować błędu');
    assert_true(
        strpos($response['body'], 'Kompleksowa diagnostyka sprzętowa') !== false,
        'Brak filtra powinien zwrócić usługę z kategorii 1'
    );
    assert_true(
        strpos($response['body'], 'Wymiana matrycy w laptopie') !== false,
        'Brak filtra powinien zwrócić usługę z kategorii 2'
    );
});

test('176. endpoint oferty odrzuca metodę POST nagłówkiem Allow', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'POST', '/api/oferta.php');

    assert_status(405, $response, 'Endpoint oferty przyjmuje wyłącznie GET');
    assert_same('GET', header_value($response, 'allow'), 'Nagłówek Allow powinien wskazywać GET');
});

test('177. endpoint oferty zawęża listę frazą wyszukiwania', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/api/oferta.php?szukaj=matryc');

    assert_status(200, $response, 'Wyszukiwanie w ofercie powinno zwrócić 200');

    $data = json_body($response);
    assert_same(true, $data['success'] ?? null, 'Odpowiedź powinna zawierać success: true');

    $services = $data['uslugi'] ?? [];
    assert_true(is_array($services), 'Odpowiedź powinna zawierać listę usług');
    assert_same(1, count($services), 'Fraza „matryc” powinna dopasować dokładnie jedną usługę');
    assert_same('Wymiana matrycy w laptopie', $services[0]['nazwa'] ?? null, 'Fraza powinna dopasować usługę wymiany matrycy');
    assert_true(
        strpos($response['body'], 'Kompleksowa diagnostyka sprzętowa') === false,
        'Wyszukiwanie nie może zwracać niedopasowanych usług'
    );
});

test('178. endpoint oferty łączy kategorię z frazą wyszukiwania', function (): void {
    $cookies = [];

    $matched = http_request($cookies, 'GET', '/api/oferta.php?kategoria=2&szukaj=matryc');
    assert_status(200, $matched, 'Połączone filtry powinny zwrócić 200');

    $matchedData = json_body($matched);
    $services = $matchedData['uslugi'] ?? [];
    assert_true(is_array($services), 'Odpowiedź powinna zawierać listę usług');
    assert_same(1, count($services), 'Kategoria 2 i fraza „matryc” powinny dopasować jedną usługę');

    foreach ($services as $service) {
        assert_same(2, $service['kategoria_id'] ?? null, 'Połączone filtry mogą zwracać wyłącznie usługi z kategorii 2');
    }

    $empty = http_request($cookies, 'GET', '/api/oferta.php?kategoria=1&szukaj=matryc');
    assert_status(200, $empty, 'Brak dopasowań nie może powodować błędu');

    $emptyData = json_body($empty);
    assert_same([], $emptyData['uslugi'] ?? null, 'Fraza bez dopasowania w kategorii 1 powinna zwrócić pustą listę');
});

test('179. strona oferty w panelu szuka po frazie i pokazuje stan pusty filtrów', function (): void {
    $session = login_as('jan.kowalski@example.com');
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');

    $response = http_request($session['cookies'], 'GET', '/oferta.php?szukaj=matryc');
    assert_status(200, $response, 'Oferta z frazą wyszukiwania powinna zwrócić 200');
    assert_true(
        strpos($response['body'], 'Wymiana matrycy w laptopie') !== false,
        'Pierwszy render powinien zawierać dopasowaną usługę'
    );
    assert_true(
        strpos($response['body'], 'Kompleksowa diagnostyka sprzętowa') === false,
        'Pierwszy render nie może zawierać niedopasowanych usług'
    );
    assert_true(
        strpos($response['body'], 'value="matryc"') !== false,
        'Pole wyszukiwania powinno zachować wpisaną frazę po odświeżeniu'
    );

    $noMatch = http_request($session['cookies'], 'GET', '/oferta.php?kategoria=1&szukaj=matryc');
    assert_status(200, $noMatch, 'Oferta bez dopasowań powinna zwrócić 200');
    assert_true(
        strpos($noMatch['body'], 'Brak usług dla wybranych filtrów') !== false,
        'Brak dopasowań przy aktywnych filtrach powinien pokazać odpowiedni stan pusty'
    );
});

test('180. szczegóły usługi w panelu mają jawny przycisk rezerwacji', function (): void {
    $session = login_as('jan.kowalski@example.com');
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');

    $response = http_request($session['cookies'], 'GET', '/usluga.php?id=2');
    assert_status(200, $response, 'Szczegóły usługi w panelu powinny zwrócić 200');
    assert_true(
        strpos($response['body'], 'id="panel-shell"') !== false,
        'Szczegóły usługi zalogowanego klienta powinny renderować się wewnątrz panelu'
    );
    assert_true(
        strpos($response['body'], 'Zarezerwuj termin') !== false,
        'Szczegóły usługi powinny mieć jawny przycisk rezerwacji'
    );
    assert_true(
        strpos($response['body'], 'rezerwacja?usluga=2') !== false,
        'Przycisk rezerwacji powinien preselekcjonować tę usługę w formularzu'
    );
    assert_true(
        strpos($response['body'], 'Kompleksowa diagnostyka sprzętowa') !== false,
        'Szczegóły powinny zawierać nazwę usługi'
    );
});

test('181. lista oferty w panelu ma przycisk rezerwacji przy każdej usłudze', function (): void {
    $session = login_as('jan.kowalski@example.com');
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');

    $response = http_request($session['cookies'], 'GET', '/oferta.php');
    assert_status(200, $response, 'Oferta w panelu powinna zwrócić 200');
    assert_true(
        strpos($response['body'], 'data-reservation-url') !== false,
        'Lista oferty powinna przekazywać adres rezerwacji do JavaScriptu'
    );
    assert_true(
        strpos($response['body'], 'rezerwacja?usluga=1') !== false,
        'Pierwsza usługa powinna mieć przycisk rezerwacji w pierwszym renderze'
    );
    assert_true(
        strpos($response['body'], 'rezerwacja?usluga=6') !== false,
        'Ostatnia usługa powinna mieć przycisk rezerwacji w pierwszym renderze'
    );
    // Liczba przycisków porównana z bazą, bo wcześniejsze testy grupy mogą
    // dodawać aktywne usługi (np. test escapowania tworzy własną).
    $activeServices = (int) test_db()
        ->query('SELECT COUNT(*) FROM uslugi WHERE aktywna = TRUE')
        ->fetchColumn();
    assert_same(
        $activeServices,
        substr_count($response['body'], 'rezerwacja?usluga='),
        'Każda aktywna usługa powinna mieć własny przycisk rezerwacji'
    );
    // Tekst kotwicy jest w osobnej linii w kodzie widoku, więc porównujemy
    // po znormalizowaniu białych znaków.
    $flat = preg_replace('/\s+/', ' ', $response['body']);
    assert_true(
        strpos($flat, ' Zarezerwuj </a>') !== false,
        'Przyciski rezerwacji powinny mieć czytelny tekst „Zarezerwuj”'
    );
});

test('182. pracownik nie dostaje przycisków rezerwacji', function (): void {
    $session = login_as('tomasz.serwisant@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie pracownika powinno się udać');

    $offer = http_request($session['cookies'], 'GET', '/oferta.php');
    assert_status(200, $offer, 'Oferta w panelu pracownika powinna zwrócić 200');
    assert_true(
        strpos($offer['body'], 'data-reservation-url') === false,
        'Pracownik nie może dostawać adresu rezerwacji, bo formularz wymaga roli klient lub admin'
    );
    assert_true(
        strpos($offer['body'], 'rezerwacja?usluga=') === false,
        'Lista oferty pracownika nie może zawierać przycisków rezerwacji'
    );

    $details = http_request($session['cookies'], 'GET', '/usluga.php?id=2');
    assert_status(200, $details, 'Szczegóły usługi w panelu pracownika powinny zwrócić 200');
    assert_true(
        strpos($details['body'], 'id="panel-shell"') !== false,
        'Szczegóły usługi pracownika powinny renderować się wewnątrz panelu'
    );
    assert_true(
        strpos($details['body'], 'Zarezerwuj termin') === false,
        'Pracownik nie może widzieć przycisku rezerwacji na szczegółach usługi'
    );
    assert_true(
        strpos($details['body'], 'Wróć do oferty') !== false,
        'Szczegóły usługi pracownika powinny prowadzić z powrotem do oferty'
    );
});

test('183. nieistniejąca usługa w panelu zwraca 404 wewnątrz panelu', function (): void {
    $session = login_as('jan.kowalski@example.com');
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');

    $response = http_request($session['cookies'], 'GET', '/usluga.php?id=999999');
    assert_status(404, $response, 'Nieistniejąca usługa powinna zwrócić 404 także w panelu');
    assert_true(
        strpos($response['body'], 'id="panel-shell"') !== false,
        'Strona 404 zalogowanego użytkownika powinna pozostać wewnątrz panelu'
    );
    assert_true(
        strpos($response['body'], 'Nie znaleziono usługi') !== false,
        'Strona 404 w panelu powinna zawierać przyjazny komunikat'
    );
    assert_true(
        strpos($response['body'], 'Wróć do oferty') !== false,
        'Strona 404 w panelu powinna prowadzić z powrotem do oferty'
    );
});

test_group('Oferta — dane nieaktywne', 'database/database.sql');

test('169. usługa w nieaktywnej kategorii nie pojawia się w ofercie', function (): void {
    $statement = test_db()->prepare(
        'UPDATE uslugi SET kategoria_id = :kategoria_id WHERE id = :id'
    );
    $statement->execute([
        'kategoria_id' => 4,
        'id' => 2,
    ]);

    $cookies = [];
    $response = http_request($cookies, 'GET', '/oferta.php');

    assert_status(200, $response, 'Oferta powinna zwrócić 200 także po zmianie kategorii usługi');
    assert_true(
        strpos($response['body'], 'Kompleksowa diagnostyka sprzętowa') === false,
        'Aktywna usługa w nieaktywnej kategorii nie może pojawić się w ofercie'
    );
});

test('170. usługa w nieaktywnej kategorii na szczegółach zwraca 404', function (): void {
    $statement = test_db()->prepare(
        'UPDATE uslugi SET kategoria_id = :kategoria_id WHERE id = :id'
    );
    $statement->execute([
        'kategoria_id' => 4,
        'id' => 2,
    ]);

    $cookies = [];
    $response = http_request($cookies, 'GET', '/usluga.php?id=2');

    assert_status(404, $response, 'Usługa w nieaktywnej kategorii powinna być nierozróżnialna od nieistniejącej');
});

test('171. nieaktywne konto pracownika oznacza brak dostępności usługi', function (): void {
    $cookies = [];
    $before = http_request($cookies, 'GET', '/usluga.php?id=4');
    assert_status(200, $before, 'Usługa 4 powinna być widoczna przed dezaktywacją konta pracownika');
    assert_true(
        strpos($before['body'], 'Elektronik') !== false,
        'Stan z seed danych powinien zawierać pracownika Elektronik'
    );

    $statement = test_db()->prepare('UPDATE uzytkownicy SET aktywny = FALSE WHERE id = :id');
    $statement->execute(['id' => 3]);

    $after = http_request($cookies, 'GET', '/usluga.php?id=4');
    assert_status(200, $after, 'Usługa 4 powinna nadal zwracać 200 po dezaktywacji konta pracownika');

    // Widok pokazuje dedykowany komunikat dopiero przy zerowej liczbie
    // pracowników, więc jego obecność dowodzi spadku z 1 do 0.
    assert_true(
        strpos($after['body'], 'Elektronik') === false,
        'Nieaktywne konto pracownika nie może pojawiać się na szczegółach usługi'
    );
    assert_true(
        strpos($after['body'], 'Usługa jest chwilowo niedostępna') !== false,
        'Brak aktywnego pracownika powinien pokazać komunikat o chwilowej niedostępności'
    );

    $offer = http_request($cookies, 'GET', '/oferta.php');
    assert_status(200, $offer, 'Oferta powinna zwrócić 200 po dezaktywacji konta pracownika');
    assert_true(
        strpos($offer['body'], 'Elektronik') === false,
        'Nazwisko nieaktywnego pracownika nie może pojawić się w ofercie'
    );
});
