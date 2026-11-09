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

test('198. formularz ukrywa usługę po dezaktywacji ostatniego pracownika', function (): void {
    $session = login_as('jan.kowalski@example.com');
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');

    $before = http_request($session['cookies'], 'GET', '/rezerwacja.php');
    assert_status(200, $before, 'Formularz rezerwacji powinien zwrócić 200');
    assert_true(
        strpos($before['body'], 'Wymiana matrycy w laptopie') !== false,
        'Usługa pracownika 2 powinna być widoczna przed dezaktywacją konta'
    );
    assert_true(
        strpos($before['body'], 'Konserwacja układu chłodzenia laptopa') !== false,
        'Usługa pracownika 1 powinna być widoczna na starcie'
    );
    assert_true(
        strpos($before['body'], 'Wycofana usługa naprawy napędów DVD') === false,
        'Nieaktywna usługa nie może pojawić się w formularzu'
    );

    $statement = test_db()->prepare('UPDATE uzytkownicy SET aktywny = FALSE WHERE id = :id');
    $statement->execute(['id' => 3]);

    $after = http_request($session['cookies'], 'GET', '/rezerwacja.php');
    assert_status(200, $after, 'Formularz po dezaktywacji konta powinien zwrócić 200');
    assert_true(
        strpos($after['body'], 'Wymiana matrycy w laptopie') === false,
        'Usługa bez aktywnego pracownika nie może pozostać w formularzu'
    );
    assert_true(
        strpos($after['body'], 'Konserwacja układu chłodzenia laptopa') !== false,
        'Usługa wykonywana przez aktywnego pracownika 1 nie może zniknąć'
    );
    assert_true(
        strpos($after['body'], 'Wycofana usługa naprawy napędów DVD') === false,
        'Nieaktywna usługa nadal nie może pojawić się w formularzu'
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

test('201. dostęp do formularza i pozycja menu zależą od roli', function (): void {
    $client = login_as('jan.kowalski@example.com');
    assert_status(200, $client['response'], 'Logowanie klienta powinno się udać');
    $clientPage = http_request($client['cookies'], 'GET', '/rezerwacja.php');
    assert_status(200, $clientPage, 'Strona klienta powinna zwrócić 200');
    assert_true(
        strpos($clientPage['body'], 'href="/rezerwacja"') !== false,
        'Menu klienta powinno zawierać link do nowej rezerwacji'
    );

    $employee = login_as('tomasz.serwisant@wefixit.pl');
    assert_status(200, $employee['response'], 'Logowanie pracownika powinno się udać');
    $employeePage = http_request($employee['cookies'], 'GET', '/rezerwacja.php');
    assert_status(403, $employeePage, 'Pracownik nie powinien mieć dostępu do formularza rezerwacji');
    assert_true(
        strpos($employeePage['body'], 'Nie masz uprawnień do wyświetlenia tej strony.') !== false,
        'Pracownik powinien zobaczyć komunikat o braku uprawnień'
    );

    $admin = login_as('admin@wefixit.pl');
    assert_status(200, $admin['response'], 'Logowanie administratora powinno się udać');
    $adminPage = http_request($admin['cookies'], 'GET', '/rezerwacja.php');
    assert_status(200, $adminPage, 'Strona administratora powinna zwrócić 200');
    assert_true(
        strpos($adminPage['body'], 'href="/rezerwacja"') !== false,
        'Menu administratora powinno zawierać link do nowej rezerwacji'
    );
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

test('204. administrator ma dostęp do formularza rezerwacji', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $response = http_request($session['cookies'], 'GET', '/rezerwacja.php');
    assert_status(200, $response, 'Administrator powinien dostać formularz rezerwacji');
    assert_true(
        strpos($response['body'], 'id="reservation-form"') !== false,
        'Strona administratora powinna zawierać formularz rezerwacji'
    );
});

test('205. menu nowej rezerwacji widzi administrator, ale nie pracownik', function (): void {
    $employee = login_as('tomasz.serwisant@wefixit.pl');
    assert_status(200, $employee['response'], 'Logowanie pracownika powinno się udać');
    $employeePage = http_request($employee['cookies'], 'GET', '/pracownik.php');
    assert_status(200, $employeePage, 'Panel pracownika powinien zwrócić 200');
    assert_true(
        strpos($employeePage['body'], 'href="/rezerwacja"') === false,
        'Pracownik nie może widzieć pozycji nowej rezerwacji'
    );

    $admin = login_as('admin@wefixit.pl');
    assert_status(200, $admin['response'], 'Logowanie administratora powinno się udać');
    $adminPage = http_request($admin['cookies'], 'GET', '/admin.php');
    assert_status(200, $adminPage, 'Panel administratora powinien zwrócić 200');
    assert_true(
        strpos($adminPage['body'], 'href="/rezerwacja"') !== false,
        'Administrator powinien widzieć pozycję nowej rezerwacji'
    );
});

test('206. formularz przekazuje pracowników przez data-employees', function (): void {
    $session = login_as('jan.kowalski@example.com');
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');

    $response = http_request($session['cookies'], 'GET', '/rezerwacja.php');
    assert_status(200, $response, 'Formularz rezerwacji powinien zwrócić 200');
    assert_true(
        strpos($response['body'], 'data-employees=') !== false,
        'Formularz powinien zawierać mapę pracowników w data-employees'
    );
    assert_true(
        strpos($response['body'], 'data-usluga-id=') === false,
        'Formularz nie może już renderować opcji z data-usluga-id'
    );
});
