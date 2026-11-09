<?php
declare(strict_types=1);

/*
    Testy formularza rezerwacji klienta.

    Obejmują ochronę sesji, dostępność formularza, filtrowanie oferty,
    wstępny wybór usługi, widoczność pozycji menu oraz warunkowy link CTA
    na stronie szczegółów usługi.
*/

test_group('Formularz rezerwacji', 'database/database.sql');

test('196. formularz rezerwacji bez logowania przekierowuje na login', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/rezerwacja.php');

    assert_true(
        in_array($response['status'], [302, 303], true),
        'Wejście bez sesji powinno przekierować na login'
    );
    $location = header_value($response, 'location');
    assert_true(
        is_string($location) && str_ends_with($location, '/login'),
        'Nagłówek Location powinien prowadzić do /login'
    );
});

test('197. zalogowany klient widzi formularz i select usług', function (): void {
    $session = login_as('jan.kowalski@example.com');
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');

    $response = http_request($session['cookies'], 'GET', '/rezerwacja.php');
    assert_status(200, $response, 'Formularz rezerwacji powinien zwrócić 200');

    assert_true(
        strpos($response['body'], 'id="reservation-form"') !== false,
        'Strona powinna zawierać formularz rezerwacji'
    );
    assert_true(
        strpos($response['body'], 'id="reservation-usluga"') !== false,
        'Formularz powinien zawierać select usług'
    );
});

test('198. formularz nie zawiera usług bez aktywnego pracownika', function (): void {
    $session = login_as('jan.kowalski@example.com');
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');

    $response = http_request($session['cookies'], 'GET', '/rezerwacja.php');
    assert_status(200, $response, 'Formularz rezerwacji powinien zwrócić 200');

    assert_true(
        strpos($response['body'], 'Wycofana usługa naprawy napędów DVD') === false,
        'Nieaktywna usługa nie może pojawić się w formularzu'
    );
});

test('199. parametr usluga ustawia wstępny wybór usługi', function (): void {
    $session = login_as('jan.kowalski@example.com');
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');

    $response = http_request($session['cookies'], 'GET', '/rezerwacja.php?usluga=2');
    assert_status(200, $response, 'Formularz z parametrem usługi powinien zwrócić 200');

    assert_true(
        strpos($response['body'], 'data-usluga-preselect="2"') !== false,
        'Formularz powinien mieć ustawiony wstępny identyfikator usługi'
    );
});

test('200. nieistniejąca usługa jest ignorowana w parametrze formularza', function (): void {
    $session = login_as('jan.kowalski@example.com');
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');

    $response = http_request($session['cookies'], 'GET', '/rezerwacja.php?usluga=999999');
    assert_status(200, $response, 'Nieistniejąca usługa nie może powodować błędu');

    assert_true(
        strpos($response['body'], 'data-usluga-preselect=""') !== false,
        'Nieistniejąca usługa nie może zostać wybrana wstępnie'
    );
});

test('201. pozycja menu nowej rezerwacji jest widoczna tylko dla klienta', function (): void {
    $client = login_as('jan.kowalski@example.com');
    assert_status(200, $client['response'], 'Logowanie klienta powinno się udać');
    $clientPage = http_request($client['cookies'], 'GET', '/rezerwacja.php');
    assert_status(200, $clientPage, 'Strona klienta powinna zwrócić 200');
    assert_true(
        strpos($clientPage['body'], 'href="/rezerwacja"') !== false,
        'Menu klienta powinno zawierać link do nowej rezerwacji'
    );

    foreach ([
        ['email' => 'tomasz.serwisant@wefixit.pl', 'label' => 'pracownika'],
        ['email' => 'admin@wefixit.pl', 'label' => 'administratora'],
    ] as $account) {
        $session = login_as($account['email']);
        assert_status(200, $session['response'], 'Logowanie ' . $account['label'] . ' powinno się udać');
        $page = http_request($session['cookies'], 'GET', '/rezerwacja.php');
        assert_status(403, $page, 'Strona dla ' . $account['label'] . ' powinna zwrócić 403');
        assert_true(
            strpos($page['body'], 'Nie masz uprawnień do wyświetlenia tej strony.') !== false,
            $account['label'] . ' powinien zobaczyć komunikat o braku uprawnień'
        );
    }
});

test('202. strona formularza dołącza skrypt reservation-form.js', function (): void {
    $session = login_as('jan.kowalski@example.com');
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');

    $response = http_request($session['cookies'], 'GET', '/rezerwacja.php');
    assert_status(200, $response, 'Formularz rezerwacji powinien zwrócić 200');
    assert_true(
        strpos($response['body'], 'assets/js/reservation-form.js') !== false,
        'Strona powinna dołączać skrypt formularza rezerwacji'
    );
});

test('203. CTA na stronie usługi zależy od roli użytkownika', function (): void {
    $client = login_as('jan.kowalski@example.com');
    assert_status(200, $client['response'], 'Logowanie klienta powinno się udać');
    $clientPage = http_request($client['cookies'], 'GET', '/usluga.php?id=2');
    assert_status(200, $clientPage, 'Szczegóły usługi dla klienta powinny zwrócić 200');
    assert_true(
        strpos($clientPage['body'], '/rezerwacja?usluga=2') !== false,
        'Klient powinien dostać link do formularza z wybraną usługą'
    );

    $cookies = [];
    $anonymousPage = http_request($cookies, 'GET', '/usluga.php?id=2');
    assert_status(200, $anonymousPage, 'Szczegóły usługi bez logowania powinny zwrócić 200');
    assert_true(
        strpos($anonymousPage['body'], '/login') !== false,
        'Niezalogowany użytkownik powinien dostać link do logowania'
    );
    assert_true(
        strpos($anonymousPage['body'], '/rezerwacja') === false,
        'Niezalogowany użytkownik nie może dostać linku do rezerwacji'
    );

    $worker = login_as('tomasz.serwisant@wefixit.pl');
    assert_status(200, $worker['response'], 'Logowanie pracownika powinno się udać');
    $workerPage = http_request($worker['cookies'], 'GET', '/usluga.php?id=2');
    assert_status(200, $workerPage, 'Szczegóły usługi dla pracownika powinny zwrócić 200');
    assert_true(
        strpos($workerPage['body'], '/pracownik') !== false,
        'Pracownik powinien dostać link do swojego panelu'
    );
    assert_true(
        strpos($workerPage['body'], '/login') === false,
        'Pracownik nie powinien dostać linku do logowania'
    );
});
