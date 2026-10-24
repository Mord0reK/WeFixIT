<?php
declare(strict_types=1);

/*
    Testy dashboardu administratora.

    Sprawdzają kontrolę dostępu, obecność statystyk policzonych z bazy oraz
    brak wycieku hashy haseł i błędów PHP. Grupa korzysta z database.sql,
    więc liczniki powinny być niezerowe.
*/

test_group('Dashboard administratora', 'database/database.sql');

test('26. administrator widzi dashboard z wartościami z bazy', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $response = http_request($session['cookies'], 'GET', '/admin.php');
    assert_status(200, $response, 'Administrator powinien zobaczyć dashboard');
    assert_true(
        strpos($response['body'], 'id="dashboard-stats"') !== false,
        'Dashboard powinien zawierać sekcję statystyk'
    );

    $pdo = test_db();
    $expected = [
        'clients_total' => (int) $pdo->query("SELECT COUNT(*) FROM uzytkownicy WHERE rola = 'klient'")->fetchColumn(),
        'employees_active' => (int) $pdo->query('SELECT COUNT(*) FROM pracownicy WHERE aktywny = TRUE')->fetchColumn(),
        'services_total' => (int) $pdo->query('SELECT COUNT(*) FROM uslugi')->fetchColumn(),
        'reservations_total' => (int) $pdo->query('SELECT COUNT(*) FROM rezerwacje')->fetchColumn(),
        'reservations_today' => (int) $pdo->query(
            'SELECT COUNT(*) FROM rezerwacje
             WHERE czas_rozpoczecia >= CURDATE()
               AND czas_rozpoczecia < CURDATE() + INTERVAL 1 DAY'
        )->fetchColumn(),
    ];

    assert_true($expected['reservations_total'] > 0, 'database.sql powinien zawierać rezerwacje');

    foreach ($expected as $key => $value) {
        $pattern = '/data-stat="' . preg_quote($key, '/') . '">.*?<p class="mt-2 text-3xl font-semibold">(\d+)<\/p>/s';
        assert_true(preg_match($pattern, $response['body'], $matches) === 1, 'Brak kafelka statystyki ' . $key);
        assert_same((string) $value, $matches[1], 'Statystyka ' . $key . ' powinna pochodzić z bazy');
    }
});

test('27. klient i pracownik dostają 403 na /admin.php', function (): void {
    $accounts = [
        ['email' => 'jan.kowalski@example.com', 'label' => 'klienta'],
        ['email' => 'tomasz.serwisant@wefixit.pl', 'label' => 'pracownika'],
    ];

    foreach ($accounts as $account) {
        $session = login_as($account['email']);
        assert_status(200, $session['response'], 'Logowanie ' . $account['label'] . ' powinno się udać');

        $response = http_request($session['cookies'], 'GET', '/admin.php');
        assert_status(403, $response, 'Konto ' . $account['label'] . ' powinno dostać 403 na /admin.php');
    }
});

test('28. dashboard nie ujawnia haslo_hash i nie generuje błędów PHP', function (): void {
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    $response = http_request($session['cookies'], 'GET', '/admin.php');
    assert_status(200, $response, 'Dashboard powinien zwrócić 200');

    assert_true(
        strpos($response['body'], 'haslo_hash') === false,
        'HTML dashboardu nie może zawierać nazwy kolumny haslo_hash'
    );
    assert_true(
        preg_match('/\$2y\$\d{2}\$/', $response['body']) !== 1,
        'HTML dashboardu nie może zawierać hashy haseł'
    );

    $logFile = $GLOBALS['__test_server_log'] ?? '';
    assert_true(is_string($logFile) && $logFile !== '', 'Brak ścieżki logu serwera testowego');
    assert_same([], server_log_errors($logFile), 'Log serwera nie powinien zawierać błędów PHP');
});
