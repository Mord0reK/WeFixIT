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
