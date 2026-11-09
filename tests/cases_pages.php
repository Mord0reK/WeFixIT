<?php
declare(strict_types=1);

/*
    Testy ochrony stron i podziału na role (punkty 12-15 zakresu Zadania A).

    Strony bez sesji przekierowują na /login (302), a brak uprawnień daje
    odpowiedź 403. Grupa korzysta z kont testowych w database.sql.
*/

test_group('Ochrona stron i role', 'database/database.sql');

test('12. strony chronione bez sesji przekierowują na /login', function (): void {
    foreach (['/panel.php', '/pracownik.php', '/admin.php'] as $path) {
        $cookies = [];
        $response = http_request($cookies, 'GET', $path);

        assert_same(302, $response['status'], $path . ' bez sesji powinno przekierować (302)');
        assert_same('/login', header_value($response, 'location'), $path . ' powinno przekierować na /login');
    }
});

test('13. klient na /pracownik.php i /admin.php dostaje 403', function (): void {
    $session = login_as('jan.kowalski@example.com');
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');

    $pracownik = http_request($session['cookies'], 'GET', '/pracownik.php');
    assert_status(403, $pracownik, 'Klient na /pracownik.php powinien dostać 403');

    $admin = http_request($session['cookies'], 'GET', '/admin.php');
    assert_status(403, $admin, 'Klient na /admin.php powinien dostać 403');
});

test('14. pracownik ma 200 na /pracownik.php, a 403 na /admin.php', function (): void {
    $session = login_as('tomasz.serwisant@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie pracownika powinno się udać');

    $pracownik = http_request($session['cookies'], 'GET', '/pracownik.php');
    assert_status(200, $pracownik, 'Pracownik powinien mieć dostęp do /pracownik.php');

    $admin = http_request($session['cookies'], 'GET', '/admin.php');
    assert_status(403, $admin, 'Pracownik na /admin.php powinien dostać 403');
});

test('15. administrator ma dostęp do /admin.php', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $admin = http_request($session['cookies'], 'GET', '/admin.php');
    assert_status(200, $admin, 'Administrator powinien mieć dostęp do /admin.php');
});

test('Dodatkowo: menu klienta i pracownika zawiera link do właściwego panelu', function (): void {
    $client = login_as('jan.kowalski@example.com');
    assert_status(200, $client['response'], 'Logowanie klienta powinno się udać');

    $clientProfile = http_request($client['cookies'], 'GET', '/profil.php');
    assert_status(200, $clientProfile, 'Klient powinien mieć dostęp do profilu');
    assert_true(
        strpos($clientProfile['body'], 'href="/panel"') !== false,
        'Menu klienta powinno zawierać link do /panel'
    );
    assert_true(
        strpos($clientProfile['body'], 'href="/admin"') === false,
        'Menu klienta nie powinno zawierać linku do /admin'
    );

    $employee = login_as('tomasz.serwisant@wefixit.pl');
    assert_status(200, $employee['response'], 'Logowanie pracownika powinno się udać');

    $employeeProfile = http_request($employee['cookies'], 'GET', '/profil.php');
    assert_status(200, $employeeProfile, 'Pracownik powinien mieć dostęp do profilu');
    assert_true(
        strpos($employeeProfile['body'], 'href="/pracownik"') !== false,
        'Menu pracownika powinno zawierać link do /pracownik'
    );
    assert_true(
        strpos($employeeProfile['body'], 'href="/admin"') === false,
        'Menu pracownika nie powinno zawierać linku do /admin'
    );
});

test('Dodatkowo: administrator widzi w menu oba panele i profil', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $dashboard = http_request($session['cookies'], 'GET', '/admin.php');
    assert_status(200, $dashboard, 'Administrator powinien zobaczyć dashboard');

    foreach (['/panel', '/pracownik', '/profil'] as $path) {
        assert_true(
            strpos($dashboard['body'], 'href="' . $path . '"') !== false,
            'Menu administratora powinno zawierać link do ' . $path
        );
    }
});

test('Dodatkowo: karta użytkownika w sidebarze prowadzi do profilu', function (): void {
    $session = login_as('jan.kowalski@example.com');
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');

    $panel = http_request($session['cookies'], 'GET', '/panel.php');
    assert_status(200, $panel, 'Klient powinien zobaczyć panel');
    assert_true(
        preg_match('/<a href="\/profil"[^>]*title="Przejdź do Mojego profilu"/s', $panel['body']) === 1,
        'Karta użytkownika w sidebarze powinna linkować do /profil'
    );

    $profile = http_request($session['cookies'], 'GET', '/profil.php');
    assert_status(200, $profile, 'Klient powinien zobaczyć profil');
    assert_true(
        preg_match('/<a href="\/profil"[^>]*title="Przejdź do Mojego profilu"[^>]*aria-current="page"/s', $profile['body']) === 1,
        'Na profilu karta użytkownika powinna być oznaczona jako aktywna'
    );
});

test('Dodatkowo: formularze administracyjne są osadzone w panelach bocznych', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $pages = [
        '/uslugi.php' => ['service-drawer', 'service-form'],
        '/kategorie.php' => ['category-drawer', 'category-form'],
        '/pracownicy.php' => ['employee-drawer', 'employee-form'],
    ];

    foreach ($pages as $path => [$drawerId, $formId]) {
        $page = http_request($session['cookies'], 'GET', $path);
        assert_status(200, $page, $path . ' powinno zwrócić 200');

        assert_true(
            strpos($page['body'], 'id="' . $drawerId . '"') !== false,
            $path . ' powinno zawierać panel boczny formularza'
        );
        assert_true(
            strpos($page['body'], 'data-drawer aria-hidden="true"') !== false,
            $path . ' powinno ukrywać panel do czasu otwarcia'
        );
        assert_true(
            strpos($page['body'], 'id="' . $formId . '"') !== false,
            $path . ' powinno zawierać formularz wewnątrz panelu'
        );
        assert_true(
            strpos($page['body'], 'data-drawer-open="' . $drawerId . '"') !== false,
            $path . ' powinno mieć akcję otwierającą panel'
        );
    }
});

test('Dodatkowo: strony HTML wysyłają nagłówki bezpieczeństwa', function (): void {
    $cookies = [];
    $page = http_request($cookies, 'GET', '/login.php');
    assert_status(200, $page, 'Strona logowania powinna zwrócić 200');

    $csp = header_value($page, 'content-security-policy');
    assert_true(is_string($csp) && $csp !== '', 'Strona HTML powinna zawierać nagłówek Content-Security-Policy');
    assert_true(
        strpos($csp, "default-src 'self'") !== false
        && strpos($csp, "script-src 'self'") !== false
        && strpos($csp, "connect-src 'self'") !== false
        && strpos($csp, "frame-ancestors 'none'") !== false,
        'CSP powinno zawierać wymagane dyrektywy'
    );
    assert_same('nosniff', header_value($page, 'x-content-type-options'), 'Powinien być nagłówek X-Content-Type-Options');
    assert_same('same-origin', header_value($page, 'referrer-policy'), 'Powinien być nagłówek Referrer-Policy');

    $apiCookies = [];
    $api = http_request($apiCookies, 'GET', '/api/me.php');
    assert_status(401, $api, 'API bez sesji powinno zwrócić 401');
    assert_same(
        'application/json; charset=utf-8',
        header_value($api, 'content-type'),
        'Endpoint JSON powinien zachować kontrakt Content-Type'
    );
    assert_same(
        null,
        header_value($api, 'content-security-policy'),
        'Endpoint JSON nie może otrzymywać nagłówka CSP'
    );
});

test('Dodatkowo: administrator widzi właściwą aktywną pozycję menu na podglądach', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $panel = http_request($session['cookies'], 'GET', '/panel.php');
    assert_status(200, $panel, 'Administrator powinien zobaczyć podgląd panelu klienta');
    assert_true(
        preg_match('/<a href="\/panel"[^>]*aria-current="page"/s', $panel['body']) === 1,
        'Na /panel.php aktywna powinna być pozycja Panel klienta'
    );
    assert_true(
        preg_match('/<a href="\/admin"[^>]*aria-current="page"/s', $panel['body']) !== 1,
        'Na /panel.php Dashboard nie powinien być aktywny'
    );

    $employee = http_request($session['cookies'], 'GET', '/pracownik.php');
    assert_status(200, $employee, 'Administrator powinien zobaczyć podgląd panelu pracownika');
    assert_true(
        preg_match('/<a href="\/pracownik"[^>]*aria-current="page"/s', $employee['body']) === 1,
        'Na /pracownik.php aktywna powinna być pozycja Panel pracownika'
    );
    assert_true(
        preg_match('/<a href="\/admin"[^>]*aria-current="page"/s', $employee['body']) !== 1,
        'Na /pracownik.php Dashboard nie powinien być aktywny'
    );
});
