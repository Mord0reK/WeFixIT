<?php
declare(strict_types=1);

/*
    Testy regresyjne domykające Etap 4.

    Sprawdzają zapis zdarzeń rezerwacji w log_zdarzen, odczyt historii przez
    reservation_history() i reservation_history_batch() oraz wyświetlanie
    elementów <details> w panelu klienta i pracownika bez przecieku cudzych
    rekordów.
*/
require_once __DIR__ . '/../app/rezerwacje_historia.php';

test_group('Etap 4 — regresja', 'database/database.sql');

function stage4_client_session(string $email = 'jan.kowalski@example.com'): array
{
    $session = login_as($email);
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');
    $session['csrf'] = csrf_from_page($session['cookies'], '/panel.php');

    return $session;
}

function stage4_employee_session(string $email = 'tomasz.serwisant@wefixit.pl'): array
{
    $session = login_as($email);
    assert_status(200, $session['response'], 'Logowanie pracownika powinno się udać');
    $session['csrf'] = csrf_from_page($session['cookies'], '/pracownik.php');

    return $session;
}

function stage4_find_slot(array &$session, int $employeeId, int $serviceId): ?array
{
    for ($offset = 0; $offset < 14; $offset++) {
        $date = date('Y-m-d', strtotime('+' . $offset . ' days'));
        $response = http_request(
            $session['cookies'],
            'GET',
            '/api/availability.php?pracownik_id=' . $employeeId
            . '&usluga_id=' . $serviceId
            . '&data=' . urlencode($date)
        );

        if ($response['status'] !== 200) {
            continue;
        }

        $data = json_body($response);
        $slots = is_array($data['sloty'] ?? null) ? $data['sloty'] : [];

        if ($slots !== []) {
            return ['date' => $date, 'slot' => $slots[0]];
        }
    }

    return null;
}

function stage4_create_reservation(
    array &$clientSession,
    int $employeeId = 1,
    int $serviceId = 1,
    ?string $comment = null
): array {
    $found = stage4_find_slot($clientSession, $employeeId, $serviceId);
    assert_true($found !== null, 'Powinien istnieć wolny termin do utworzenia rezerwacji');

    $start = (string) $found['slot']['start'];
    $payload = [
        'pracownik_id' => $employeeId,
        'usluga_id' => $serviceId,
        'data' => substr($start, 0, 10),
        'godzina' => substr($start, 11, 8),
    ];

    if ($comment !== null) {
        $payload['komentarz'] = $comment;
    }

    $response = post_json(
        $clientSession['cookies'],
        '/api/reservations.php',
        $payload,
        $clientSession['csrf']
    );
    assert_status(201, $response, 'Utworzenie rezerwacji testowej powinno dać 201');
    $data = json_body($response);

    return [
        'id' => (int) ($data['id'] ?? 0),
        'start' => $start,
    ];
}

test('233. utworzenie rezerwacji zapisuje rezerwacja_utworzona w log_zdarzen', function (): void {
    $client = stage4_client_session('jan.kowalski@example.com');
    $reservation = stage4_create_reservation($client, 1, 1, 'Test logu utworzenia 233');

    $statement = test_db()->prepare(
        "SELECT akcja, obiekt_typ, obiekt_id, aktor_id
         FROM log_zdarzen
         WHERE obiekt_typ = 'rezerwacja'
           AND obiekt_id = :id
           AND akcja = 'rezerwacja_utworzona'
         ORDER BY id DESC
         LIMIT 1"
    );
    $statement->execute(['id' => $reservation['id']]);
    $row = $statement->fetch();

    assert_true(is_array($row), 'W log_zdarzen powinien powstać wpis rezerwacja_utworzona');
    assert_same('rezerwacja', (string) $row['obiekt_typ'], 'Typ obiektu powinien wynosić rezerwacja');
    assert_same($reservation['id'], (int) $row['obiekt_id'], 'Identyfikator obiektu powinien odpowiadać rezerwacji');
    assert_same(4, (int) $row['aktor_id'], 'Aktorem powinien być zalogowany klient');
});

test('234. anulowanie przez klienta zapisuje rezerwacja_anulowana_klient', function (): void {
    $client = stage4_client_session('jan.kowalski@example.com');
    $reservation = stage4_create_reservation($client, 1, 1, 'Test logu anulowania klienta 234');

    $cancel = patch_json(
        $client['cookies'],
        '/api/client-reservations.php',
        ['id' => $reservation['id']],
        $client['csrf']
    );
    assert_status(200, $cancel, 'Anulowanie rezerwacji przez klienta powinno dać 200');

    $statement = test_db()->prepare(
        "SELECT akcja, poziom, szczegoly
         FROM log_zdarzen
         WHERE obiekt_typ = 'rezerwacja'
           AND obiekt_id = :id
           AND akcja = 'rezerwacja_anulowana_klient'
         ORDER BY id DESC
         LIMIT 1"
    );
    $statement->execute(['id' => $reservation['id']]);
    $row = $statement->fetch();

    assert_true(is_array($row), 'W log_zdarzen powinien powstać wpis rezerwacja_anulowana_klient');
    assert_same('warning', (string) $row['poziom'], 'Anulowanie powinno mieć poziom warning');
    $details = json_decode((string) $row['szczegoly'], true);
    assert_same('oczekujaca', $details['status']['przed'] ?? null, 'Status przed powinien wynosić oczekujaca');
    assert_same('anulowana', $details['status']['po'] ?? null, 'Status po powinien wynosić anulowana');
});

test('235. zmiana statusu przez pracownika zapisuje rezerwacja_status_zmieniony ze szczegółami', function (): void {
    $client = stage4_client_session('jan.kowalski@example.com');
    $reservation = stage4_create_reservation($client, 1, 1, 'Test logu pracownika 235');

    $employee = stage4_employee_session('tomasz.serwisant@wefixit.pl');
    $change = patch_json(
        $employee['cookies'],
        '/api/employee-reservations.php',
        ['id' => $reservation['id'], 'status' => 'potwierdzona'],
        $employee['csrf']
    );
    assert_status(200, $change, 'Zmiana statusu przez pracownika powinna dać 200');

    $statement = test_db()->prepare(
        "SELECT akcja, poziom, aktor_id, szczegoly
         FROM log_zdarzen
         WHERE obiekt_typ = 'rezerwacja'
           AND obiekt_id = :id
           AND akcja = 'rezerwacja_status_zmieniony'
         ORDER BY id DESC
         LIMIT 1"
    );
    $statement->execute(['id' => $reservation['id']]);
    $row = $statement->fetch();

    assert_true(is_array($row), 'W log_zdarzen powinien powstać wpis rezerwacja_status_zmieniony');
    assert_same('info', (string) $row['poziom'], 'Zmiana statusu powinna mieć poziom info');
    assert_same(2, (int) $row['aktor_id'], 'Aktorem powinien być pracownik');
    $details = json_decode((string) $row['szczegoly'], true);
    assert_same('oczekujaca', $details['status']['przed'] ?? null, 'Status przed powinien wynosić oczekujaca');
    assert_same('potwierdzona', $details['status']['po'] ?? null, 'Status po powinien wynosić potwierdzona');
});

test('236. historia rezerwacji jest widoczna w panelu klienta jako details po anulowaniu', function (): void {
    $client = stage4_client_session('jan.kowalski@example.com');
    $reservation = stage4_create_reservation($client, 1, 1, 'Test details klienta 236');

    $cancel = patch_json(
        $client['cookies'],
        '/api/client-reservations.php',
        ['id' => $reservation['id']],
        $client['csrf']
    );
    assert_status(200, $cancel, 'Anulowanie rezerwacji powinno dać 200');

    $panel = http_request($client['cookies'], 'GET', '/panel.php');
    assert_status(200, $panel, 'Panel klienta powinien zwrócić 200');
    assert_true(
        strpos($panel['body'], '<details>') !== false,
        'Panel klienta powinien renderować element <details>'
    );
    assert_true(
        strpos($panel['body'], 'Historia zmian (2)') !== false,
        'Podsumowanie powinno pokazywać 2 zdarzenia (utworzenie i anulowanie)'
    );
    assert_true(
        strpos($panel['body'], 'oczekujaca → anulowana') !== false,
        'Historia powinna pokazywać przejście statusu oczekujaca → anulowana'
    );
});

test('237. historia rezerwacji jest widoczna w panelu pracownika dla jego wizyty', function (): void {
    $client = stage4_client_session('jan.kowalski@example.com');
    $reservation = stage4_create_reservation($client, 1, 1, 'Test details pracownika 237');

    $employee = stage4_employee_session('tomasz.serwisant@wefixit.pl');
    $change = patch_json(
        $employee['cookies'],
        '/api/employee-reservations.php',
        ['id' => $reservation['id'], 'status' => 'potwierdzona'],
        $employee['csrf']
    );
    assert_status(200, $change, 'Potwierdzenie wizyty przez pracownika powinno dać 200');

    $panel = http_request($employee['cookies'], 'GET', '/pracownik.php');
    assert_status(200, $panel, 'Panel pracownika powinien zwrócić 200');
    assert_true(
        strpos($panel['body'], '<details>') !== false,
        'Panel pracownika powinien renderować element <details>'
    );
    assert_true(
        strpos($panel['body'], 'oczekujaca → potwierdzona') !== false,
        'Historia w panelu pracownika powinna pokazywać przejście oczekujaca → potwierdzona'
    );
});

test('238. klient nie widzi historii cudzej rezerwacji', function (): void {
    test_db()->exec("DELETE FROM log_zdarzen WHERE obiekt_typ = 'rezerwacja'");

    $otherClient = stage4_client_session('anna.nowak@example.com');
    $otherReservation = stage4_create_reservation($otherClient, 1, 1, 'Cudza rezerwacja 238');
    $cancelOther = patch_json(
        $otherClient['cookies'],
        '/api/client-reservations.php',
        ['id' => $otherReservation['id']],
        $otherClient['csrf']
    );
    assert_status(200, $cancelOther, 'Anulowanie rezerwacji drugiego klienta powinno dać 200');

    $client = stage4_client_session('jan.kowalski@example.com');
    $ownReservation = stage4_create_reservation($client, 1, 1, 'Własna rezerwacja 238');

    $GLOBALS['pdo'] = test_db();
    $batch = reservation_history_batch([$ownReservation['id']]);
    assert_true(is_array($batch), 'Batch historii powinien zwrócić tablicę');
    assert_same(
        [$ownReservation['id']],
        array_keys($batch),
        'reservation_history_batch() może zwrócić wyłącznie historię przekazanych rezerwacji klienta'
    );
    assert_true(
        !array_key_exists($otherReservation['id'], $batch),
        'reservation_history_batch() nie może zwracać historii cudzej rezerwacji'
    );

    $panel = http_request($client['cookies'], 'GET', '/panel.php');
    assert_status(200, $panel, 'Panel klienta powinien zwrócić 200');
    assert_same(
        1,
        substr_count($panel['body'], '<details>'),
        'Liczba elementów <details> w panelu klienta musi odpowiadać wyłącznie jego rezerwacjom z historią'
    );
    assert_true(
        strpos($panel['body'], 'Anna Nowak') === false,
        'Panel klienta nie może zawierać historii zmian należącej do innego klienta'
    );
});

test('239. reservation_history_batch dla pustej listy nie wykonuje zapytania i zwraca pustą tablicę', function (): void {
    $previousPdo = $GLOBALS['pdo'] ?? null;
    $GLOBALS['pdo'] = null;

    try {
        $result = reservation_history_batch([]);
    } finally {
        $GLOBALS['pdo'] = $previousPdo;
    }

    assert_same(
        [],
        $result,
        'Dla pustej listy funkcja powinna zwrócić [] bez sięgania do połączenia PDO'
    );
});

test('240. zdarzenia dla tej samej rezerwacji są zwracane w kolejności chronologicznej rosnącej', function (): void {
    $client = stage4_client_session('jan.kowalski@example.com');
    $reservation = stage4_create_reservation($client, 1, 1, 'Test kolejności historii 240');

    $employee = stage4_employee_session('tomasz.serwisant@wefixit.pl');
    $confirm = patch_json(
        $employee['cookies'],
        '/api/employee-reservations.php',
        ['id' => $reservation['id'], 'status' => 'potwierdzona'],
        $employee['csrf']
    );
    assert_status(200, $confirm, 'Potwierdzenie przez pracownika powinno dać 200');

    $cancel = patch_json(
        $client['cookies'],
        '/api/client-reservations.php',
        ['id' => $reservation['id']],
        $client['csrf']
    );
    assert_status(200, $cancel, 'Anulowanie przez klienta powinno dać 200');

    $GLOBALS['pdo'] = test_db();
    $events = reservation_history($reservation['id']);

    assert_true(is_array($events), 'Historia rezerwacji powinna być tablicą');
    assert_same(3, count($events), 'Rezerwacja powinna mieć 3 zdarzenia w historii');
    assert_same(
        [
            'rezerwacja_utworzona',
            'rezerwacja_status_zmieniony',
            'rezerwacja_anulowana_klient',
        ],
        array_column($events, 'akcja'),
        'Zdarzenia powinny być ułożone chronologicznie rosnąco'
    );
    assert_true(
        $events[0]['id'] < $events[1]['id'] && $events[1]['id'] < $events[2]['id'],
        'Identyfikatory kolejnych zdarzeń powinny rosnąć'
    );
});
