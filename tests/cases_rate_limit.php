<?php
declare(strict_types=1);

/*
    Testy limitu nieudanych prób logowania.

    Sprawdzają blokadę po 10 próbach dla e-maila, niezależność od sesji,
    brak blokady innego e-maila, czyszczenie po udanym logowaniu oraz limit
    20 prób dla adresu IP.
*/
require_once __DIR__ . '/../app/rate_limit.php';

test_group('Limit prób logowania', 'database/database.sql');

function rate_limit_test_session(): array
{
    $cookies = [];
    $csrf = csrf_from_page($cookies, '/login.php');

    return ['cookies' => $cookies, 'csrf' => $csrf];
}

function rate_limit_test_attempt(array &$session, string $email, string $password): array
{
    return post_json(
        $session['cookies'],
        '/api/auth/login.php',
        ['email' => $email, 'password' => $password],
        $session['csrf']
    );
}

test('241. dziesięć nieudanych prób blokuje kolejne logowanie', function (): void {
    $session = rate_limit_test_session();
    $email = 'jan.kowalski@example.com';

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $response = rate_limit_test_attempt($session, $email, 'zle-haslo-' . $attempt);
        assert_status(401, $response, 'Próba ' . $attempt . ' powinna dać 401');
    }

    $blocked = rate_limit_test_attempt($session, $email, 'haslo123');
    assert_status(429, $blocked, 'Po dziesięciu nieudanych próbach logowanie powinno dać 429');
    assert_true(
        header_value($blocked, 'retry-after') !== null,
        'Odpowiedź 429 powinna zawierać nagłówek Retry-After'
    );
    assert_same('no-store', header_value($blocked, 'cache-control'), 'Odpowiedź limitu powinna mieć Cache-Control: no-store');

    $statement = test_db()->prepare(
        'SELECT email_hash, ip_hash FROM proby_logowania LIMIT 1'
    );
    $statement->execute();
    $row = $statement->fetch();

    assert_true(is_array($row), 'Nieudana próba powinna zostać zapisana w proby_logowania');
    assert_same(64, strlen((string) $row['email_hash']), 'email_hash powinien mieć 64 znaki');
    assert_same(64, strlen((string) $row['ip_hash']), 'ip_hash powinien mieć 64 znaki');
    assert_true(
        (string) $row['email_hash'] !== strtolower($email),
        'Tabela nie może przechowywać jawnego adresu e-mail'
    );
    assert_true(
        (string) $row['ip_hash'] !== '127.0.0.1',
        'Tabela nie może przechowywać jawnego adresu IP'
    );

    $emailHash = rate_limit_email_hash($email);
    $statement = test_db()->prepare(
        'SELECT COUNT(*) FROM proby_logowania WHERE email_hash = :email_hash'
    );
    $statement->execute(['email_hash' => $emailHash]);
    assert_same(10, (int) $statement->fetchColumn(), 'Po dziesięciu próbach powinno być dziesięć wpisów');
});

test('242. poprawne dane po przekroczeniu limitu nadal dają 429', function (): void {
    $session = rate_limit_test_session();
    $response = rate_limit_test_attempt($session, 'jan.kowalski@example.com', 'haslo123');

    assert_status(429, $response, 'Poprawne dane nie mogą obejść aktywnego limitu');
});

test('243. inny e-mail nie jest blokowany przez próby na pierwszym', function (): void {
    $session = rate_limit_test_session();
    $response = rate_limit_test_attempt($session, 'anna.nowak@example.com', 'haslo123');

    assert_status(200, $response, 'Inny e-mail nie powinien być blokowany przez próby na pierwszym koncie');
    $data = json_body($response);
    assert_same(true, $data['success'] ?? null, 'Logowanie innego konta powinno się udać');
});

test('244. udane logowanie czyści próby dla e-maila i stare wpisy', function (): void {
    $session = rate_limit_test_session();
    $email = 'piotr.wisniewski@example.com';

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $response = rate_limit_test_attempt($session, $email, 'zle-haslo-' . $attempt);
        assert_status(401, $response, 'Próba ' . $attempt . ' powinna dać 401');
    }

    $success = rate_limit_test_attempt($session, $email, 'haslo123');
    assert_status(200, $success, 'Poprawne logowanie powinno wyczyścić próby dla e-maila');

    $emailHash = rate_limit_email_hash($email);
    $statement = test_db()->prepare(
        'SELECT COUNT(*) FROM proby_logowania WHERE email_hash = :email_hash'
    );
    $statement->execute(['email_hash' => $emailHash]);
    assert_same(0, (int) $statement->fetchColumn(), 'Po udanym logowaniu wpisy e-maila powinny zostać usunięte');

    $oldHash = str_repeat('a', 64);
    $statement = test_db()->prepare(
        'INSERT INTO proby_logowania (email_hash, ip_hash, utworzono)
         VALUES (:email_hash, :ip_hash, DATE_SUB(NOW(), INTERVAL 16 MINUTE))'
    );
    $statement->execute([
        'email_hash' => $oldHash,
        'ip_hash' => str_repeat('b', 64),
    ]);
    rate_limit_cleanup();

    $statement = test_db()->prepare(
        'SELECT COUNT(*) FROM proby_logowania WHERE email_hash = :email_hash'
    );
    $statement->execute(['email_hash' => $oldHash]);
    assert_same(0, (int) $statement->fetchColumn(), 'Czyszczenie powinno usunąć wpisy starsze niż okno');
});

test('245. dwadzieścia nieudanych prób blokuje adres IP', function (): void {
    $session = rate_limit_test_session();

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $response = rate_limit_test_attempt(
            $session,
            'ip-limit-' . $attempt . '@example.com',
            'zle-haslo'
        );
        assert_status(401, $response, 'Próba IP ' . $attempt . ' powinna dać 401');
    }

    $blocked = rate_limit_test_attempt($session, 'anna.nowak@example.com', 'haslo123');
    assert_status(429, $blocked, 'Po dwudziestu próbach z jednego IP logowanie powinno dać 429');
});
