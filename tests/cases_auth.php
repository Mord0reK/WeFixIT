<?php
declare(strict_types=1);

/*
    Testy logowania, wylogowania i sesji (punkty 5-11 i 20 zakresu Zadania A)
    oraz sprawdzenie parametrów cookie sesyjnego. Grupa korzysta z kont
    testowych w database/database.sql.
*/

test_group('Logowanie, wylogowanie i sesje', 'database/database.sql');

test('5. poprawne dane dają 200 i rolę w odpowiedzi', function (): void {
    $session = login_as('jan.kowalski@example.com');

    assert_status(200, $session['response'], 'Poprawne logowanie powinno dać 200');
    $body = json_body($session['response']);
    assert_true(($body['success'] ?? null) === true, 'Odpowiedź powinna zawierać success=true');
    assert_same('klient', $body['rola'] ?? null, 'Odpowiedź powinna zawierać rolę klient');
});

test('6. błędne hasło daje 401', function (): void {
    $cookies = [];
    $csrf = csrf_from_page($cookies, '/login.php');

    $response = post_json($cookies, '/api/auth/login.php', [
        'email' => 'jan.kowalski@example.com',
        'password' => 'zle-haslo',
    ], $csrf);

    assert_status(401, $response, 'Błędne hasło powinno dać 401');
});

test('7. nieistniejący e-mail daje ten sam komunikat co błędne hasło', function (): void {
    $wrongPassword = [];
    $responseWrong = post_json(
        $wrongPassword,
        '/api/auth/login.php',
        ['email' => 'jan.kowalski@example.com', 'password' => 'zle-haslo'],
        csrf_from_page($wrongPassword, '/login.php')
    );

    $unknownEmail = [];
    $responseUnknown = post_json(
        $unknownEmail,
        '/api/auth/login.php',
        ['email' => 'nie-istnieje@example.com', 'password' => 'haslo123'],
        csrf_from_page($unknownEmail, '/login.php')
    );

    assert_status(401, $responseUnknown, 'Nieznany e-mail powinien dać 401');
    assert_same(
        json_body($responseWrong)['error'] ?? null,
        json_body($responseUnknown)['error'] ?? null,
        'Komunikat nie powinien ujawniać, czy e-mail istnieje'
    );
});

test('8. identyfikator sesji zmienia się po zalogowaniu', function (): void {
    $cookies = [];
    $csrf = csrf_from_page($cookies, '/login.php');
    $before = $cookies['PHPSESSID'] ?? null;
    assert_true(is_string($before) && $before !== '', 'Przed logowaniem powinno istnieć cookie sesyjne');

    $response = post_json($cookies, '/api/auth/login.php', [
        'email' => 'jan.kowalski@example.com',
        'password' => 'haslo123',
    ], $csrf);
    assert_status(200, $response, 'Logowanie powinno się udać');

    $after = $cookies['PHPSESSID'] ?? null;
    assert_true(is_string($after) && $after !== '', 'Po logowaniu powinno istnieć cookie sesyjne');
    assert_true($before !== $after, 'Identyfikator sesji powinien się zmienić po logowaniu');
});

test('9. konto z aktywny = 0 nie może się zalogować', function (): void {
    test_db()->exec(
        "INSERT INTO uzytkownicy (imie, nazwisko, email, telefon, haslo_hash, rola, aktywny)
         SELECT 'Nieaktywny', 'Testowy', 'nieaktywny@test.local', '+48999000111', haslo_hash, 'klient', 0
         FROM uzytkownicy WHERE email = 'jan.kowalski@example.com'"
    );

    $cookies = [];
    $csrf = csrf_from_page($cookies, '/login.php');
    $response = post_json($cookies, '/api/auth/login.php', [
        'email' => 'nieaktywny@test.local',
        'password' => 'haslo123',
    ], $csrf);

    assert_status(401, $response, 'Nieaktywne konto nie powinno się zalogować');
});

test('10. wylogowanie działa, a sesja przestaje działać', function (): void {
    $session = login_as('jan.kowalski@example.com');
    assert_status(200, $session['response'], 'Logowanie powinno się udać');
    $previousSessionId = (string) ($session['cookies']['PHPSESSID'] ?? '');
    assert_true($previousSessionId !== '', 'Po logowaniu powinno istnieć cookie sesyjne');

    $logoutCsrf = csrf_from_page($session['cookies'], '/panel.php');
    $logout = post_json($session['cookies'], '/api/auth/logout.php', [], $logoutCsrf);

    assert_status(200, $logout, 'Wylogowanie powinno zwrócić 200');
    assert_true((json_body($logout)['success'] ?? null) === true, 'Odpowiedź wylogowania powinna zawierać success=true');

    $me = http_request($session['cookies'], 'GET', '/api/me.php');
    assert_status(401, $me, 'Po wylogowaniu /api/me.php powinno dawać 401');

    // Serwer unieważnia sesję również po stronie bazy sesji, nie tylko cookie.
    $reused = ['PHPSESSID' => $previousSessionId];
    $meOld = http_request($reused, 'GET', '/api/me.php');
    assert_status(401, $meOld, 'Stary identyfikator sesji nie powinien działać po wylogowaniu');
});

test('11. wylogowanie bez CSRF daje 403, a GET daje 405', function (): void {
    $session = login_as('jan.kowalski@example.com');
    assert_status(200, $session['response'], 'Logowanie powinno się udać');

    $withoutCsrf = http_request($session['cookies'], 'POST', '/api/auth/logout.php');
    assert_status(403, $withoutCsrf, 'Wylogowanie bez tokenu CSRF powinno dać 403');

    $get = http_request($session['cookies'], 'GET', '/api/auth/logout.php');
    assert_status(405, $get, 'GET na wylogowaniu powinien dać 405');
    assert_same('POST', header_value($get, 'allow'), 'Nagłówek Allow powinien wskazywać POST');
});

test('20. /api/me.php bez sesji daje 401, z sesją dane bez haslo_hash i aktywny', function (): void {
    $anonymous = [];
    $withoutSession = http_request($anonymous, 'GET', '/api/me.php');
    assert_status(401, $withoutSession, 'Bez sesji /api/me.php powinno dawać 401');

    $session = login_as('jan.kowalski@example.com');
    $withSession = http_request($session['cookies'], 'GET', '/api/me.php');
    assert_status(200, $withSession, 'Z sesją /api/me.php powinno dawać 200');

    $user = json_body($withSession)['user'] ?? [];
    assert_same('jan.kowalski@example.com', $user['email'] ?? null, 'Powinny wrócić dane zalogowanego użytkownika');
    assert_array_has_not_key($user, 'haslo_hash', 'Odpowiedź nie może zawierać haslo_hash');
    assert_array_has_not_key($user, 'aktywny', 'Odpowiedź nie może zawierać flagi aktywny');
});

test('Dodatkowo: cookie sesyjne ma HttpOnly i SameSite=Lax', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/login.php');
    $setCookie = header_value($response, 'set-cookie') ?? '';

    assert_true(stripos($setCookie, 'HttpOnly') !== false, 'Cookie sesyjne powinno mieć flagę HttpOnly');
    assert_true(stripos($setCookie, 'SameSite=Lax') !== false, 'Cookie sesyjne powinno mieć SameSite=Lax');
});
