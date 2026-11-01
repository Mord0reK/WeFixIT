<?php
declare(strict_types=1);

/*
    Testy dziennika zdarzeń i eksportu CSV.

    Obejmują dostęp administratora, filtry, wpisy bez obiektu i z uszkodzonymi
    szczegółami, brak danych kontaktowych aktora, BOM i nagłówki CSV, filtry
    eksportu, neutralizację formuł oraz brak wycieku danych technicznych.
    Każdy test przygotowuje własny stan.
*/

test_group('Panel: dziennik i eksport', 'database/database.sql');

function log_export_clear_logs(): void
{
    test_db()->exec('DELETE FROM log_zdarzen');
}

function log_export_insert_log(
    int $actorId,
    string $action,
    string $level,
    ?string $objectType,
    ?int $objectId,
    string $description,
    ?string $details = null
): int {
    $statement = test_db()->prepare(
        'INSERT INTO log_zdarzen
            (aktor_id, akcja, obiekt_typ, obiekt_id, poziom, opis, szczegoly)
         VALUES
            (:aktor_id, :akcja, :obiekt_typ, :obiekt_id, :poziom, :opis, :szczegoly)'
    );
    $statement->execute([
        'aktor_id' => $actorId,
        'akcja' => $action,
        'obiekt_typ' => $objectType,
        'obiekt_id' => $objectId,
        'poziom' => $level,
        'opis' => $description,
        'szczegoly' => $details,
    ]);

    return (int) test_db()->lastInsertId();
}

function log_export_set_reservation(
    int $id,
    string $status,
    ?string $cancelledAt,
    ?string $comment
): void {
    $statement = test_db()->prepare(
        'UPDATE rezerwacje
         SET status_rezerwacji = :status, anulowano = :anulowano, komentarz_klienta = :komentarz
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $id,
        'status' => $status,
        'anulowano' => $cancelledAt,
        'komentarz' => $comment,
    ]);
}

function log_export_reset_reservations(): void
{
    log_export_set_reservation(1, 'zrealizowana', null, null);
    log_export_set_reservation(2, 'w_trakcie', null, null);
    log_export_set_reservation(3, 'potwierdzona', null, null);
    log_export_set_reservation(4, 'oczekujaca', null, null);
    log_export_set_reservation(5, 'anulowana', '2026-01-05 12:00:00', null);
}

function log_export_admin_session(): array
{
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');

    return $session;
}

function log_export_csv_rows(string $body): array
{
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
    $lines = preg_split('/\r\n|\n/', trim((string) $body));
    $rows = [];

    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }

        $rows[] = str_getcsv($line, ';');
    }

    return $rows;
}

test('129. administrator widzi dziennik, klient i pracownik dostają 403', function (): void {
    log_export_clear_logs();
    log_export_insert_log(1, 'usluga_utworzona', 'info', 'usluga', 1, 'Wpis widoczny w dzienniku');
    $session = log_export_admin_session();
    $page = http_request($session['cookies'], 'GET', '/log.php');

    assert_status(200, $page, 'Strona dziennika powinna dać 200');
    assert_true(strpos($page['body'], 'id="log-table"') !== false, 'Strona powinna zawierać tabelę dziennika');
    assert_true(strpos($page['body'], 'Wpis widoczny w dzienniku') !== false, 'Strona powinna zawierać wpis');

    foreach ([
        ['email' => 'jan.kowalski@example.com', 'label' => 'klienta'],
        ['email' => 'tomasz.serwisant@wefixit.pl', 'label' => 'pracownika'],
    ] as $account) {
        $other = login_as($account['email']);
        assert_status(200, $other['response'], 'Logowanie ' . $account['label'] . ' powinno się udać');
        $forbidden = http_request($other['cookies'], 'GET', '/log.php');
        assert_status(403, $forbidden, 'Konto ' . $account['label'] . ' powinno dostać 403');
    }
});

test('130. filtry akcji i poziomu zawężają dziennik', function (): void {
    log_export_clear_logs();
    log_export_insert_log(1, 'usluga_utworzona', 'info', 'usluga', 1, 'ALFA');
    log_export_insert_log(1, 'rezerwacja_anulowana', 'warning', 'rezerwacja', 1, 'BETA');
    log_export_insert_log(1, 'pracownik_usuniety', 'critical', 'pracownik', 1, 'GAMMA');
    $session = log_export_admin_session();

    $byAction = http_request($session['cookies'], 'GET', '/log.php?akcja=rezerwacja_anulowana');
    assert_status(200, $byAction, 'Filtr akcji powinien dać 200');
    assert_true(strpos($byAction['body'], 'BETA') !== false, 'Filtr akcji powinien pokazać pasujący wpis');
    assert_true(strpos($byAction['body'], 'ALFA') === false, 'Filtr akcji nie powinien pokazać innego wpisu');
    assert_true(strpos($byAction['body'], 'GAMMA') === false, 'Filtr akcji nie powinien pokazać innego wpisu');

    $byLevel = http_request($session['cookies'], 'GET', '/log.php?poziom=critical');
    assert_status(200, $byLevel, 'Filtr poziomu powinien dać 200');
    assert_true(strpos($byLevel['body'], 'GAMMA') !== false, 'Filtr poziomu powinien pokazać pasujący wpis');
    assert_true(strpos($byLevel['body'], 'ALFA') === false, 'Filtr poziomu nie powinien pokazać innego wpisu');
    assert_true(strpos($byLevel['body'], 'BETA') === false, 'Filtr poziomu nie powinien pokazać innego wpisu');

    $invalid = http_request($session['cookies'], 'GET', '/log.php?akcja=nieznana&poziom=zly&obiekt_typ=zly');
    assert_status(200, $invalid, 'Nieprawidłowe filtry powinny dać 200');
    assert_true(strpos($invalid['body'], 'ALFA') !== false, 'Nieprawidłowy filtr powinien wrócić do pełnej listy');
});

test('131. wpis bez obiektu i zły JSON szczegółów nie wywołują błędu', function (): void {
    log_export_clear_logs();
    log_export_insert_log(1, 'usluga_utworzona', 'info', null, null, 'BEZ OBIEKTU', null);
    log_export_insert_log(1, 'usluga_utworzona', 'info', 'usluga', 1, 'ZLY JSON', '"nie-obiekt"');
    log_export_insert_log(1, 'usluga_utworzona', 'info', 'usluga', 1, 'DOBRE SZCZEGOLY', '{"pole":"wartość"}');
    $session = log_export_admin_session();
    $page = http_request($session['cookies'], 'GET', '/log.php');

    assert_status(200, $page, 'Dziennik z nietypowymi szczegółami powinien dać 200');
    assert_true(strpos($page['body'], 'BEZ OBIEKTU') !== false, 'Wpis bez obiektu powinien być widoczny');
    assert_true(strpos($page['body'], 'ZLY JSON') !== false, 'Wpis z nieobiektowym JSON-em powinien być widoczny');
    assert_true(strpos($page['body'], 'DOBRE SZCZEGOLY') !== false, 'Wpis z poprawnymi szczegółami powinien być widoczny');
    assert_true(strpos($page['body'], 'data-log-details') !== false, 'Poprawne szczegóły powinny mieć rozwijany wiersz');
    assert_true(strpos($page['body'], 'Fatal error') === false, 'Widok nie może ujawniać błędu PHP');
});

test('132. dziennik nie pokazuje e-maila ani telefonu aktora', function (): void {
    log_export_clear_logs();
    log_export_insert_log(2, 'pracownik_zaktualizowany', 'info', 'pracownik', 1, 'Aktor bez kontaktu');
    $session = log_export_admin_session();
    $page = http_request($session['cookies'], 'GET', '/log.php');

    assert_status(200, $page, 'Dziennik powinien dać 200');
    assert_true(strpos($page['body'], 'Tomasz Serwisant') !== false, 'Aktor powinien być widoczny jako imię i nazwisko');
    assert_true(strpos($page['body'], 'tomasz.serwisant@wefixit.pl') === false, 'Dziennik nie może pokazywać e-maila aktora');
    assert_true(strpos($page['body'], '+48123456781') === false, 'Dziennik nie może pokazywać telefonu aktora');
});

test('133. eksport rezerwacji ma nagłówki CSV i BOM', function (): void {
    log_export_reset_reservations();
    $session = log_export_admin_session();
    $response = http_request($session['cookies'], 'GET', '/eksport.php?typ=rezerwacje');

    assert_status(200, $response, 'Eksport rezerwacji powinien dać 200');
    $contentType = header_value($response, 'content-type');
    assert_true(
        is_string($contentType) && str_starts_with(strtolower($contentType), 'text/csv'),
        'Eksport powinien mieć Content-Type text/csv'
    );
    $disposition = header_value($response, 'content-disposition');
    assert_true(
        is_string($disposition)
        && preg_match('/attachment;\s*filename="rezerwacje-\d{4}-\d{2}-\d{2}\.csv"/', $disposition) === 1,
        'Eksport powinien mieć nazwę pliku rezerwacje-RRRR-MM-DD.csv'
    );
    assert_same("\xEF\xBB\xBF", substr($response['body'], 0, 3), 'CSV powinien zaczynać się od BOM');

    $rows = log_export_csv_rows($response['body']);
    assert_true(count($rows) >= 6, 'Eksport powinien zawierać nagłówek i wszystkie rezerwacje');
    assert_true(in_array('Komentarz klienta', $rows[0], true), 'Nagłówki powinny być po polsku');
});

test('134. eksport rezerwacji respektuje filtr statusu i obejmuje wszystkie wyniki', function (): void {
    log_export_reset_reservations();
    $session = log_export_admin_session();
    $response = http_request($session['cookies'], 'GET', '/eksport.php?typ=rezerwacje&status=anulowana');

    assert_status(200, $response, 'Eksport z filtrem powinien dać 200');
    $rows = log_export_csv_rows($response['body']);
    assert_same(2, count($rows), 'Filtr anulowana powinien dać nagłówek i jeden wiersz');
    assert_same('Anulowana', $rows[1][9] ?? null, 'Wiersz powinien mieć status anulowana');
});

test('135. eksport użytkowników nie zawiera hasła ani telefonu', function (): void {
    $session = log_export_admin_session();

    foreach ([
        '/eksport.php?typ=uzytkownicy',
        '/eksport.php?typ=uzytkownicy&rola=klient&status=active',
    ] as $path) {
        $response = http_request($session['cookies'], 'GET', $path);
        assert_status(200, $response, 'Eksport użytkowników powinien dać 200');
        assert_true(strpos($response['body'], 'haslo_hash') === false, 'Eksport nie może zawierać haslo_hash');
        assert_true(strpos($response['body'], 'telefon') === false, 'Eksport nie może zawierać telefonu');
        assert_true(strpos($response['body'], '+48') === false, 'Eksport nie może zawierać numerów telefonów');
    }
});

test('136. eksport wymaga administratora i prawidłowego typu', function (): void {
    $anonymousCookies = [];
    $anonymous = http_request($anonymousCookies, 'GET', '/eksport.php?typ=rezerwacje');
    assert_status(302, $anonymous, 'Eksport bez sesji powinien przekierować');
    assert_same('/login', header_value($anonymous, 'location'), 'Przekierowanie powinno prowadzić na /login');

    $session = log_export_admin_session();
    $invalid = http_request($session['cookies'], 'GET', '/eksport.php?typ=nieznany');
    assert_status(404, $invalid, 'Nieznany typ eksportu powinien dać 404');

    foreach ([
        ['email' => 'jan.kowalski@example.com', 'label' => 'klienta'],
        ['email' => 'tomasz.serwisant@wefixit.pl', 'label' => 'pracownika'],
    ] as $account) {
        $other = login_as($account['email']);
        assert_status(200, $other['response'], 'Logowanie ' . $account['label'] . ' powinno się udać');
        $forbidden = http_request($other['cookies'], 'GET', '/eksport.php?typ=rezerwacje');
        assert_status(403, $forbidden, 'Konto ' . $account['label'] . ' powinno dostać 403');
    }
});

test('137. eksport neutralizuje formuły CSV w komentarzu klienta', function (): void {
    log_export_reset_reservations();
    log_export_set_reservation(4, 'oczekujaca', null, '=SUM(1,1)');
    log_export_set_reservation(3, 'potwierdzona', null, '+cmd|test');
    $session = log_export_admin_session();
    $response = http_request($session['cookies'], 'GET', '/eksport.php?typ=rezerwacje&kierunek=asc');

    assert_status(200, $response, 'Eksport do testu formuł powinien dać 200');
    $comments = [];

    foreach (log_export_csv_rows($response['body']) as $index => $row) {
        if ($index === 0) {
            continue;
        }

        $comments[] = $row[10] ?? '';
    }

    assert_true(in_array("'=SUM(1,1)", $comments, true), 'Formuła zaczynająca się od = powinna mieć apostrof');
    assert_true(in_array("'+cmd|test", $comments, true), 'Formuła zaczynająca się od + powinna mieć apostrof');
});

test('138. odpowiedzi nie ujawniają SQL ani ścieżek, a log nie ma błędów PHP', function (): void {
    log_export_clear_logs();
    log_export_reset_reservations();
    log_export_insert_log(2, 'pracownik_zaktualizowany', 'info', 'pracownik', 1, 'Wpis kontrolny');
    $session = log_export_admin_session();
    $responses = [
        http_request($session['cookies'], 'GET', '/log.php'),
        http_request($session['cookies'], 'GET', '/eksport.php?typ=rezerwacje'),
        http_request($session['cookies'], 'GET', '/eksport.php?typ=uzytkownicy'),
    ];

    foreach ($responses as $response) {
        assert_status(200, $response, 'Każda odpowiedź w teście 138 powinna mieć 200');
    }

    $forbidden = [
        'haslo_hash',
        'telefon',
        'SQLSTATE',
        'SELECT ',
        'UPDATE ',
        'DELETE FROM',
        '/var/www',
        'app/log_view.php',
        'app/rezerwacje.php',
        'public/config.php',
        'PDOException',
    ];

    foreach ($responses as $response) {
        foreach ($forbidden as $needle) {
            assert_true(
                strpos($response['body'], $needle) === false,
                'Odpowiedź nie może zawierać: ' . $needle
            );
        }
    }

    $logFile = $GLOBALS['__test_server_log'] ?? '';
    assert_true(is_string($logFile) && $logFile !== '', 'Brak ścieżki logu serwera testowego');
    assert_same([], server_log_errors($logFile), 'Log serwera nie powinien zawierać błędów PHP');
});
