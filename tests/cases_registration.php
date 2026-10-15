<?php
declare(strict_types=1);

/*
    Testy rejestracji konta (punkty 1-4 zakresu Zadania A).

    Grupa działa na czystej bazie z database-czysta.sql, więc zakłada brak
    jakichkolwiek kont na starcie.
*/

test_group('Rejestracja konta', 'database/database-czysta.sql');

test('1. poprawne dane tworzą konto i ustawiają sesję', function (): void {
    $cookies = [];
    $csrf = csrf_from_page($cookies, '/register.php');

    $response = post_json($cookies, '/api/auth/register.php', [
        'imie' => 'Nowy',
        'nazwisko' => 'Klient',
        'email' => 'nowy.klient@example.com',
        'telefon' => '+48111222333',
        'password' => 'haslo12345',
        'password_powtorzenie' => 'haslo12345',
    ], $csrf);

    assert_status(201, $response, 'Rejestracja powinna zwrócić 201');
    $body = json_body($response);
    assert_true(($body['success'] ?? null) === true, 'Odpowiedź powinna zawierać success=true');
    assert_true(isset($body['user_id']) && (int) $body['user_id'] > 0, 'Odpowiedź powinna zawierać user_id');

    // Nowy użytkownik jest od razu zalogowany — /api/me.php widzi jego sesję.
    $me = http_request($cookies, 'GET', '/api/me.php');
    assert_status(200, $me, 'Po rejestracji sesja powinna być aktywna');
    $meBody = json_body($me);
    assert_same('nowy.klient@example.com', $meBody['user']['email'] ?? null, 'Sesja powinna wskazywać nowe konto');

    $row = test_db()->query("SELECT rola FROM uzytkownicy WHERE email = 'nowy.klient@example.com'")->fetch();
    assert_same('klient', $row['rola'] ?? null, 'Nowe konto powinno mieć rolę klient');
});

test('2. duplikat e-maila daje 409', function (): void {
    $payload = [
        'imie' => 'Duplikat',
        'nazwisko' => 'Testowy',
        'email' => 'duplikat@example.com',
        'telefon' => '+48222333444',
        'password' => 'haslo12345',
        'password_powtorzenie' => 'haslo12345',
    ];

    $first = [];
    $created = post_json($first, '/api/auth/register.php', $payload, csrf_from_page($first, '/register.php'));
    assert_status(201, $created, 'Pierwsza rejestracja powinna się udać');

    $second = [];
    $duplicate = post_json($second, '/api/auth/register.php', $payload, csrf_from_page($second, '/register.php'));
    assert_status(409, $duplicate, 'Duplikat e-maila powinien zwrócić 409');
});

test('3. błędne pola dają 422 z mapą errors', function (): void {
    $cookies = [];
    $csrf = csrf_from_page($cookies, '/register.php');

    $response = post_json($cookies, '/api/auth/register.php', [
        'imie' => '',
        'nazwisko' => 'X',
        'email' => 'to-nie-jest-email',
        'telefon' => '123',
        'password' => 'krotkie',
        'password_powtorzenie' => 'inne',
    ], $csrf);

    assert_status(422, $response, 'Błędne dane powinny zwrócić 422');
    $body = json_body($response);
    assert_array_key($body, 'errors', 'Odpowiedź powinna zawierać mapę errors');

    foreach (['imie', 'email', 'telefon', 'password'] as $field) {
        assert_array_key($body['errors'], $field, 'Brak informacji o błędzie pola ' . $field);
    }
});

test('4. pole rola w body nie tworzy pracownika ani administratora', function (): void {
    $cookies = [];
    $csrf = csrf_from_page($cookies, '/register.php');

    $response = post_json($cookies, '/api/auth/register.php', [
        'imie' => 'Sprytny',
        'nazwisko' => 'Klient',
        'email' => 'rola.proba@example.com',
        'telefon' => '+48333444555',
        'password' => 'haslo12345',
        'password_powtorzenie' => 'haslo12345',
        'rola' => 'admin',
        'id' => 1,
        'aktywny' => 1,
    ], $csrf);

    assert_status(201, $response, 'Rejestracja powinna się udać mimo dodatkowego pola rola');

    $row = test_db()->query("SELECT rola FROM uzytkownicy WHERE email = 'rola.proba@example.com'")->fetch();
    assert_same('klient', $row['rola'] ?? null, 'Rolę zawsze ustala serwer (klient)');
});
