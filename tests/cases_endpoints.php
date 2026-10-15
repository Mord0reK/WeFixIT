<?php
declare(strict_types=1);

/*
    Testy kontraktu endpointów JSON: CSRF, metoda HTTP, Content-Type
    i uszkodzone body (punkty 16-19 zakresu Zadania A).
*/

test_group('Kontrakt endpointów JSON', 'database/database.sql');

test('16. POST logowania bez tokenu CSRF daje 403', function (): void {
    $cookies = [];
    csrf_from_page($cookies, '/login.php'); // Tworzymy sesję, ale tokenu nie wysyłamy.

    $response = http_request($cookies, 'POST', '/api/auth/login.php', [
        'json' => ['email' => 'jan.kowalski@example.com', 'password' => 'haslo123'],
    ]);

    assert_status(403, $response, 'Brak tokenu CSRF powinien dać 403');
});

test('17. nieprawidłowa metoda na logowaniu daje 405 z nagłówkiem Allow', function (): void {
    $cookies = [];
    $response = http_request($cookies, 'GET', '/api/auth/login.php');

    assert_status(405, $response, 'GET na endpoint logowania powinien dać 405');
    assert_same('POST', header_value($response, 'allow'), 'Nagłówek Allow powinien wskazywać POST');
});

test('18. nieobsługiwany Content-Type daje 415', function (): void {
    $cookies = [];
    $csrf = csrf_from_page($cookies, '/login.php');

    $response = http_request($cookies, 'POST', '/api/auth/login.php', [
        'body' => '{"email":"jan.kowalski@example.com","password":"haslo123"}',
        'content_type' => 'text/plain',
        'headers' => ['X-CSRF-Token' => $csrf],
    ]);

    assert_status(415, $response, 'Content-Type text/plain powinien dać 415');
});

test('19. uszkodzone body JSON daje 400', function (): void {
    $cookies = [];
    $csrf = csrf_from_page($cookies, '/login.php');

    $response = http_request($cookies, 'POST', '/api/auth/login.php', [
        'body' => '{"email": ',
        'headers' => ['Content-Type' => 'application/json', 'X-CSRF-Token' => $csrf],
    ]);

    assert_status(400, $response, 'Uszkodzony JSON powinien dać 400');
});
