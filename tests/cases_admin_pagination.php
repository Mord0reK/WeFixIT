<?php
declare(strict_types=1);

/*
    Testy paginacji i wyszukiwania list panelu.

    Obejmują metadane paginacji rezerwacji, przechodzenie przez strony,
    bezpieczne wartości spoza allowlisty, wyszukiwanie w pięciu listach,
    łączenie filtrów z paginacją, linki zachowujące parametry oraz brak
    wycieku danych technicznych. Każdy test przygotowuje własny stan.
*/

test_group('Panel: paginacja i wyszukiwanie', 'database/database.sql');

function pagination_set_reservation_status(int $id, string $status, ?string $cancelledAt): void
{
    $statement = test_db()->prepare(
        'UPDATE rezerwacje
         SET status_rezerwacji = :status, anulowano = :anulowano
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $id,
        'status' => $status,
        'anulowano' => $cancelledAt,
    ]);
}

function pagination_reset_reservations(): void
{
    pagination_set_reservation_status(1, 'zrealizowana', null);
    pagination_set_reservation_status(2, 'w_trakcie', null);
    pagination_set_reservation_status(3, 'potwierdzona', null);
    pagination_set_reservation_status(4, 'oczekujaca', null);
    pagination_set_reservation_status(5, 'anulowana', '2026-01-05 12:00:00');
}

function pagination_admin_session(): array
{
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');
    $session['csrf'] = csrf_from_page($session['cookies'], '/rezerwacje.php');

    return $session;
}

function pagination_reservations_request(array &$session, string $query = ''): array
{
    return http_request(
        $session['cookies'],
        'GET',
        '/api/admin/reservations.php' . $query
    );
}

function pagination_ids(array $items): array
{
    $ids = [];

    foreach ($items as $item) {
        $ids[] = (int) ($item['id'] ?? 0);
    }

    sort($ids);

    return $ids;
}

test('119. paginacja rezerwacji zwraca dwa rekordy i poprawne metadane', function (): void {
    pagination_reset_reservations();
    $session = pagination_admin_session();
    $response = pagination_reservations_request($session, '?size=2&page=1');

    assert_status(200, $response, 'Paginowana lista rezerwacji powinna dać 200');
    $data = json_body($response);
    assert_same(true, $data['success'] ?? null, 'Odpowiedź powinna zawierać success = true');
    assert_true(is_array($data['rezerwacje'] ?? null), 'Odpowiedź powinna zawierać rezerwacje');
    assert_same(2, count($data['rezerwacje']), 'Strona size=2 powinna mieć dwa rekordy');
    assert_same(1, $data['paginacja']['strona'] ?? null, 'Metadane powinny wskazywać stronę 1');
    assert_same(2, $data['paginacja']['rozmiar'] ?? null, 'Metadane powinny wskazywać rozmiar 2');
    assert_same(3, $data['paginacja']['stron'] ?? null, 'Pięć rekordów po dwa powinno dać trzy strony');
    assert_same(5, $data['paginacja']['razem'] ?? null, 'Metadane powinny wskazywać pięć rekordów');
});

test('120. kolejne strony nie powtarzają rekordów', function (): void {
    pagination_reset_reservations();
    $session = pagination_admin_session();
    $first = json_body(pagination_reservations_request($session, '?size=2&page=1'));
    $second = json_body(pagination_reservations_request($session, '?size=2&page=2'));
    $third = json_body(pagination_reservations_request($session, '?size=2&page=3'));

    assert_same(2, count($first['rezerwacje']), 'Pierwsza strona powinna mieć dwa rekordy');
    assert_same(2, count($second['rezerwacje']), 'Druga strona powinna mieć dwa rekordy');
    assert_same(1, count($third['rezerwacje']), 'Trzecia strona powinna mieć jeden rekord');

    $firstIds = pagination_ids($first['rezerwacje']);
    $secondIds = pagination_ids($second['rezerwacje']);
    $thirdIds = pagination_ids($third['rezerwacje']);
    assert_true($firstIds !== $secondIds, 'Druga strona powinna różnić się od pierwszej');

    $allIds = array_merge($firstIds, $secondIds, $thirdIds);
    sort($allIds);
    assert_same([1, 2, 3, 4, 5], $allIds, 'Wszystkie strony powinny zwrócić każdy rekord dokładnie raz');
});

test('121. niepoprawne page i size wracają do bezpiecznych wartości', function (): void {
    pagination_reset_reservations();
    $session = pagination_admin_session();
    $cases = [
        ['query' => '?page=999&size=2', 'page' => 3, 'size' => 2, 'count' => 1],
        ['query' => '?page=0&size=2', 'page' => 1, 'size' => 2, 'count' => 2],
        ['query' => '?page=abc&size=2', 'page' => 1, 'size' => 2, 'count' => 2],
        ['query' => '?page=1&size=9999', 'page' => 1, 'size' => 25, 'count' => 5],
        ['query' => '?page=1&size=abc', 'page' => 1, 'size' => 25, 'count' => 5],
    ];

    foreach ($cases as $case) {
        $response = pagination_reservations_request($session, $case['query']);
        assert_status(200, $response, 'Niepoprawne parametry "' . $case['query'] . '" powinny dać 200');
        $data = json_body($response);
        assert_same($case['page'], $data['paginacja']['strona'] ?? null, 'Niepoprawny page powinien wrócić do bezpiecznej strony');
        assert_same($case['size'], $data['paginacja']['rozmiar'] ?? null, 'Niepoprawny size powinien wrócić do domyślnego rozmiaru');
        assert_same($case['count'], count($data['rezerwacje']), 'Liczba rekordów powinna odpowiadać bezpiecznym parametrom');
    }
});

test('122. wyszukiwanie rezerwacji po kliencie i pracowniku zawęża wynik', function (): void {
    pagination_reset_reservations();
    $session = pagination_admin_session();
    $clientResponse = pagination_reservations_request($session, '?szukaj=Kowalski&size=25');
    assert_status(200, $clientResponse, 'Wyszukiwanie po kliencie powinno dać 200');
    $clientData = json_body($clientResponse);
    assert_same(2, $clientData['paginacja']['razem'] ?? null, 'Kowalski powinien mieć dwie rezerwacje');

    foreach ($clientData['rezerwacje'] as $reservation) {
        assert_same('Kowalski', $reservation['klient_nazwisko'], 'Każdy wynik powinien dotyczyć klienta Kowalski');
    }

    $employeeResponse = pagination_reservations_request($session, '?szukaj=Elektronik&size=25');
    assert_status(200, $employeeResponse, 'Wyszukiwanie po pracowniku powinno dać 200');
    $employeeData = json_body($employeeResponse);
    assert_same(2, $employeeData['paginacja']['razem'] ?? null, 'Elektronik powinien mieć dwie rezerwacje');

    foreach ($employeeData['rezerwacje'] as $reservation) {
        assert_same('Elektronik', $reservation['pracownik_nazwisko'], 'Każdy wynik powinien dotyczyć pracownika Elektronik');
    }
});

test('123. wyszukiwanie usług i kategorii działa oraz escapuje znaki LIKE', function (): void {
    $session = pagination_admin_session();
    $services = http_request($session['cookies'], 'GET', '/uslugi.php?szukaj=matryc');
    assert_status(200, $services, 'Wyszukiwanie usług powinno dać 200');
    assert_true(strpos($services['body'], 'Wymiana matrycy w laptopie') !== false, 'Wynik powinien zawierać dopasowaną usługę');

    $categories = http_request($session['cookies'], 'GET', '/kategorie.php?szukaj=Naprawy');
    assert_status(200, $categories, 'Wyszukiwanie kategorii powinno dać 200');
    assert_true(strpos($categories['body'], 'Naprawy Sprzętowe PC i Laptopów') !== false, 'Wynik powinien zawierać dopasowaną kategorię');

    $percent = http_request($session['cookies'], 'GET', '/uslugi.php?szukaj=%25');
    assert_status(200, $percent, 'Fraza z procentem nie może wywołać błędu');
    assert_true(
        strpos($percent['body'], 'Wymiana matrycy w laptopie') === false,
        'Procent nie może być traktowany jak wildcard'
    );
    assert_true(strpos($percent['body'], 'Brak usług dla wybranych filtrów') !== false, 'Brak wyników powinien pokazać pusty stan');

    $underscore = http_request($session['cookies'], 'GET', '/kategorie.php?szukaj=_');
    assert_status(200, $underscore, 'Fraza z podkreśleniem nie może wywołać błędu');
    assert_true(
        strpos($underscore['body'], 'Naprawy Sprzętowe PC i Laptopów') === false,
        'Podkreślenie nie może być traktowane jak wildcard'
    );
    assert_true(strpos($underscore['body'], 'Brak kategorii dla wybranej frazy') !== false, 'Brak wyników powinien pokazać pusty stan');
});

test('141. filtry statusu kategorii i pracowników działają z nagłówków tabel', function (): void {
    $session = pagination_admin_session();

    $inactiveCategories = http_request($session['cookies'], 'GET', '/kategorie.php?status=inactive');
    assert_status(200, $inactiveCategories, 'Filtr nieaktywnych kategorii powinien dać 200');
    assert_true(strpos($inactiveCategories['body'], 'Archiwalna Kategoria') !== false, 'Filtr powinien pokazać nieaktywną kategorię');
    assert_true(strpos($inactiveCategories['body'], 'Diagnostyka i Konserwacja') === false, 'Filtr nie powinien pokazać aktywnej kategorii');
    assert_true(strpos($inactiveCategories['body'], 'name="status"') !== false, 'Filtr statusu powinien być w nagłówku tabeli');

    $activeEmployees = http_request($session['cookies'], 'GET', '/pracownicy.php?status=active');
    assert_status(200, $activeEmployees, 'Filtr aktywnych pracowników powinien dać 200');
    assert_true(strpos($activeEmployees['body'], 'Tomasz Serwisant') !== false, 'Filtr powinien pokazać aktywnego pracownika');
    assert_true(strpos($activeEmployees['body'], 'name="status"') !== false, 'Filtr statusu powinien być w nagłówku tabeli');

    $inactiveEmployees = http_request($session['cookies'], 'GET', '/pracownicy.php?status=inactive');
    assert_status(200, $inactiveEmployees, 'Filtr nieaktywnych pracowników powinien dać 200');
    assert_true(strpos($inactiveEmployees['body'], 'Brak pracowników dla wybranej frazy') !== false, 'Brak nieaktywnych pracowników powinien pokazać pusty stan');
    assert_true(strpos($inactiveEmployees['body'], 'Tomasz Serwisant') === false, 'Filtr nie powinien pokazać aktywnego pracownika');
});

test('124. filtr statusu i paginacja liczą strony po zawężeniu', function (): void {
    pagination_reset_reservations();
    pagination_set_reservation_status(1, 'potwierdzona', null);
    $session = pagination_admin_session();
    $first = pagination_reservations_request($session, '?status=potwierdzona&size=1&page=1');

    assert_status(200, $first, 'Filtr statusu z paginacją powinien dać 200');
    $firstData = json_body($first);
    assert_same(2, $firstData['paginacja']['razem'] ?? null, 'Filtr powinien znaleźć dwa rekordy');
    assert_same(2, $firstData['paginacja']['stron'] ?? null, 'Dwa rekordy po jednym powinny dać dwie strony');
    assert_same(1, count($firstData['rezerwacje']), 'Pierwsza strona powinna mieć jeden rekord');

    $second = json_body(pagination_reservations_request($session, '?status=potwierdzona&size=1&page=2'));
    assert_same(1, count($second['rezerwacje']), 'Druga strona powinna mieć jeden rekord');
    assert_true(
        pagination_ids($firstData['rezerwacje']) !== pagination_ids($second['rezerwacje']),
        'Strony filtrowanej listy powinny zawierać różne rekordy'
    );
});

test('125. wyszukiwanie użytkowników po e-mailu i pracowników po nazwisku', function (): void {
    $session = pagination_admin_session();
    $users = http_request($session['cookies'], 'GET', '/uzytkownicy.php?szukaj=jan.kowalski%40example.com');
    assert_status(200, $users, 'Wyszukiwanie użytkowników powinno dać 200');
    assert_true(strpos($users['body'], 'jan.kowalski@example.com') !== false, 'Wynik powinien zawierać dopasowany e-mail');
    assert_true(strpos($users['body'], 'anna.nowak@example.com') === false, 'Wynik nie powinien zawierać innego użytkownika');

    $employees = http_request($session['cookies'], 'GET', '/pracownicy.php?szukaj=Serwisant');
    assert_status(200, $employees, 'Wyszukiwanie pracowników powinno dać 200');
    assert_true(strpos($employees['body'], 'Tomasz Serwisant') !== false, 'Wynik powinien zawierać dopasowanego pracownika');
    assert_true(strpos($employees['body'], 'Marcin Elektronik') === false, 'Wynik nie powinien zawierać innego pracownika');
});

test('126. linki paginacji zachowują filtry i frazę wyszukiwania', function (): void {
    $session = pagination_admin_session();
    $response = http_request(
        $session['cookies'],
        'GET',
        '/uslugi.php?id_kategorii=1&status=active&szukaj=a&size=1&page=1'
    );

    assert_status(200, $response, 'Strona usług z filtrami powinna dać 200');
    assert_true(strpos($response['body'], 'data-pagination') !== false, 'Strona powinna zawierać pasek paginacji');
    assert_true(strpos($response['body'], 'szukaj=a') !== false, 'Linki powinny zachować frazę wyszukiwania');
    assert_true(strpos($response['body'], 'id_kategorii=1') !== false, 'Linki powinny zachować filtr kategorii');
    assert_true(strpos($response['body'], 'status=active') !== false, 'Linki powinny zachować filtr statusu');
    assert_true(strpos($response['body'], 'size=1') !== false, 'Linki powinny zachować rozmiar strony');
    assert_true(strpos($response['body'], 'page=2') !== false, 'Linki powinny prowadzić do kolejnej strony');
});

test('127. strona bez wyników nie renderuje paska paginacji', function (): void {
    $session = pagination_admin_session();
    $response = http_request($session['cookies'], 'GET', '/uslugi.php?szukaj=nie-ma-takiej-uslugi');

    assert_status(200, $response, 'Strona bez wyników powinna dać 200');
    assert_true(strpos($response['body'], 'data-pagination') === false, 'Pusty wynik nie powinien renderować paginacji');
    assert_true(strpos($response['body'], 'Brak usług dla wybranych filtrów') !== false, 'Pusty wynik powinien pokazać komunikat');
});

test('128. odpowiedzi nie ujawniają SQL ani ścieżek, a log nie ma błędów PHP', function (): void {
    pagination_reset_reservations();
    $session = pagination_admin_session();
    $responses = [
        pagination_reservations_request($session, '?size=2'),
        http_request($session['cookies'], 'GET', '/rezerwacje.php?status=anulowana&szukaj=a&size=1'),
        http_request($session['cookies'], 'GET', '/uslugi.php?szukaj=matryc'),
        http_request($session['cookies'], 'GET', '/kategorie.php?szukaj=Naprawy'),
        http_request($session['cookies'], 'GET', '/uzytkownicy.php?szukaj=kowalski'),
        http_request($session['cookies'], 'GET', '/pracownicy.php?szukaj=Serwisant'),
    ];

    foreach ($responses as $response) {
        assert_status(200, $response, 'Każda lista w teście 128 powinna zwrócić 200');
    }

    $forbidden = [
        'haslo_hash',
        '$2y$',
        'SQLSTATE',
        'SELECT ',
        'UPDATE ',
        'DELETE FROM',
        '/var/www',
        'app/pagination.php',
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
