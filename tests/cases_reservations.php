<?php
declare(strict_types=1);

/*
    Testy API rezerwacji klienta.

    Obejmują dostęp do terminów, sprawdzanie pary pracownik + usługa, walidację,
    CSRF, tworzenie rezerwacji, konflikty terminów oraz brak zaufania do ceny
    i statusu przysłanych w body.
*/

test_group('Rezerwacje klienta', 'database/database.sql');

function reservations_client_session(): array
{
    $session = login_as('jan.kowalski@example.com');
    assert_status(200, $session['response'], 'Logowanie klienta powinno się udać');
    $session['csrf'] = csrf_from_page($session['cookies'], '/profil.php');

    return $session;
}

function reservations_availability_request(
    array &$cookies,
    int $employeeId,
    int $serviceId,
    string $date
): array {
    return http_request(
        $cookies,
        'GET',
        '/api/availability.php?pracownik_id=' . $employeeId
        . '&usluga_id=' . $serviceId
        . '&data=' . urlencode($date)
    );
}

function reservations_find_slots(
    array &$session,
    int $employeeId,
    int $serviceId
): ?array {
    for ($offset = 0; $offset < 14; $offset++) {
        $date = date('Y-m-d', strtotime('+' . $offset . ' days'));
        $response = reservations_availability_request(
            $session['cookies'],
            $employeeId,
            $serviceId,
            $date
        );

        if ($response['status'] !== 200) {
            continue;
        }

        $data = json_body($response);
        $slots = is_array($data['sloty'] ?? null) ? $data['sloty'] : [];

        if ($slots !== []) {
            return [
                'date' => $date,
                'slots' => $slots,
                'response' => $response,
            ];
        }
    }

    return null;
}

function reservations_find_adjacent_slots(
    array &$session,
    int $employeeId,
    int $serviceId
): ?array {
    for ($offset = 0; $offset < 14; $offset++) {
        $date = date('Y-m-d', strtotime('+' . $offset . ' days'));
        $response = reservations_availability_request(
            $session['cookies'],
            $employeeId,
            $serviceId,
            $date
        );

        if ($response['status'] !== 200) {
            continue;
        }

        $data = json_body($response);
        $slots = is_array($data['sloty'] ?? null) ? $data['sloty'] : [];
        $count = count($slots);

        for ($index = 0; $index + 1 < $count; $index++) {
            if (($slots[$index]['end'] ?? null) === ($slots[$index + 1]['start'] ?? null)) {
                return [
                    'date' => $date,
                    'slots' => [$slots[$index], $slots[$index + 1]],
                ];
            }
        }
    }

    return null;
}

function reservations_find_overlap_pair(
    array &$session,
    int $employeeId,
    int $serviceA,
    int $serviceB,
    int $gapMinutes
): ?array {
    for ($offset = 0; $offset < 14; $offset++) {
        $date = date('Y-m-d', strtotime('+' . $offset . ' days'));
        $responseA = reservations_availability_request(
            $session['cookies'],
            $employeeId,
            $serviceA,
            $date
        );
        $responseB = reservations_availability_request(
            $session['cookies'],
            $employeeId,
            $serviceB,
            $date
        );

        if ($responseA['status'] !== 200 || $responseB['status'] !== 200) {
            continue;
        }

        $dataA = json_body($responseA);
        $dataB = json_body($responseB);
        $slotsA = is_array($dataA['sloty'] ?? null) ? $dataA['sloty'] : [];
        $slotsB = is_array($dataB['sloty'] ?? null) ? $dataB['sloty'] : [];
        $startsB = [];

        foreach ($slotsB as $slotB) {
            $start = $slotB['start'] ?? null;

            if (is_string($start)) {
                $startsB[$start] = $slotB;
            }
        }

        foreach ($slotsA as $slotA) {
            $start = $slotA['start'] ?? null;

            if (!is_string($start)) {
                continue;
            }

            $expectedStart = (new DateTimeImmutable($start))
                ->modify('+' . $gapMinutes . ' minutes')
                ->format('Y-m-d H:i:s');

            if (isset($startsB[$expectedStart])) {
                return [
                    'date' => $date,
                    'slot_a' => $slotA,
                    'slot_b' => $startsB[$expectedStart],
                ];
            }
        }
    }

    return null;
}

function reservations_slot_parts(array $slot): array
{
    $start = (string) ($slot['start'] ?? '');

    return [
        'date' => substr($start, 0, 10),
        'time' => substr($start, 11, 8),
        'start' => $start,
        'end' => (string) ($slot['end'] ?? ''),
    ];
}

function reservations_payload(
    int $employeeId,
    int $serviceId,
    string $date,
    string $time,
    ?string $comment = null,
    array $extra = []
): array {
    $data = [
        'pracownik_id' => $employeeId,
        'usluga_id' => $serviceId,
        'data' => $date,
        'godzina' => $time,
    ];

    if ($comment !== null) {
        $data['komentarz'] = $comment;
    }

    return array_merge($data, $extra);
}

function reservations_parallel_posts(array $requests): array
{
    $multi = curl_multi_init();
    $handles = [];

    foreach ($requests as $index => $request) {
        $cookies = is_array($request['cookies'] ?? null) ? $request['cookies'] : [];
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];
        $csrf = (string) ($request['csrf'] ?? '');
        $headers = [
            'Content-Type: application/json',
            'X-CSRF-Token: ' . $csrf,
        ];
        $cookiePairs = [];

        foreach ($cookies as $name => $value) {
            $cookiePairs[] = $name . '=' . $value;
        }

        if ($cookiePairs !== []) {
            $headers[] = 'Cookie: ' . implode('; ', $cookiePairs);
        }

        $handle = curl_init(test_base_url() . '/api/reservations.php');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[$index] = $handle;
    }

    do {
        $multiStatus = curl_multi_exec($multi, $active);

        if ($active) {
            if (curl_multi_select($multi, 1.0) === -1) {
                usleep(10000);
            }
        }
    } while ($active && $multiStatus === CURLM_OK);

    $responses = [];

    foreach ($handles as $index => $handle) {
        $responses[$index] = [
            'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'body' => (string) curl_multi_getcontent($handle),
        ];
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }

    curl_multi_close($multi);

    return $responses;
}

test('175. GET /api/availability bez logowania daje 401', function (): void {
    $cookies = [];
    $response = http_request(
        $cookies,
        'GET',
        '/api/availability.php?pracownik_id=1&usluga_id=1&data=' . date('Y-m-d')
    );

    assert_status(401, $response, 'Pobranie terminów bez logowania powinno dać 401');
});

test('176. GET /api/availability zwraca niepuste sloty dla istniejącej pary', function (): void {
    $session = reservations_client_session();
    $found = reservations_find_slots($session, 1, 1);

    assert_true($found !== null, 'Powinien istnieć co najmniej jeden wolny termin');
    assert_status(200, $found['response'], 'Pobranie terminów powinno dać 200');

    $data = json_body($found['response']);
    assert_same(true, $data['success'] ?? null, 'Odpowiedź powinna zawierać success = true');
    assert_same($found['date'], $data['data'] ?? null, 'Odpowiedź powinna zwrócić tę samą datę');
    assert_true(is_array($data['sloty'] ?? null) && $data['sloty'] !== [], 'Lista slotów nie może być pusta');

    foreach ($data['sloty'] as $slot) {
        assert_true(is_array($slot), 'Każdy slot powinien być tablicą');
        assert_true(is_string($slot['start'] ?? null), 'Slot powinien mieć start');
        assert_true(is_string($slot['end'] ?? null), 'Slot powinien mieć end');
    }
});

test('177. GET /api/availability dla nieistniejącej usługi daje 404', function (): void {
    $session = reservations_client_session();
    $response = reservations_availability_request(
        $session['cookies'],
        1,
        999999,
        date('Y-m-d')
    );

    assert_status(404, $response, 'Nieistniejąca usługa powinna dać 404');
});

test('178. GET /api/availability dla nieprzypisanej pary daje 409', function (): void {
    $session = reservations_client_session();
    $response = reservations_availability_request(
        $session['cookies'],
        1,
        4,
        date('Y-m-d')
    );

    assert_status(409, $response, 'Para pracownik 1 i usługa 4 nie jest przypisana i powinna dać 409');
});

test('179. GET /api/availability z nieprawidłową datą daje 422', function (): void {
    $session = reservations_client_session();
    $response = reservations_availability_request(
        $session['cookies'],
        1,
        1,
        '2026-02-30'
    );

    assert_status(422, $response, 'Nieistniejąca data powinna dać 422');
    $data = json_body($response);
    assert_true(isset($data['errors']['data']), 'Odpowiedź powinna zawierać błąd pola data');
});

test('180. POST /api/reservations bez tokenu CSRF daje 403', function (): void {
    $session = reservations_client_session();
    $response = post_json($session['cookies'], '/api/reservations.php', reservations_payload(
        1,
        1,
        date('Y-m-d'),
        '09:00:00'
    ));

    assert_status(403, $response, 'Brak tokenu CSRF powinien dać 403');
});

test('181. POST /api/reservations bez logowania daje 401', function (): void {
    $cookies = [];
    $response = post_json($cookies, '/api/reservations.php', reservations_payload(
        1,
        1,
        date('Y-m-d'),
        '09:00:00'
    ));

    assert_status(401, $response, 'Brak sesji powinien dać 401');
});

test('182. POST /api/reservations ze złym Content-Type daje 415', function (): void {
    $session = reservations_client_session();
    $response = http_request($session['cookies'], 'POST', '/api/reservations.php', [
        'body' => '{}',
        'content_type' => 'text/plain',
        'headers' => ['X-CSRF-Token' => $session['csrf']],
    ]);

    assert_status(415, $response, 'Nieobsługiwany Content-Type powinien dać 415');
});

test('183. POST /api/reservations z nieprawidłowym body daje 422', function (): void {
    $session = reservations_client_session();
    $response = post_json($session['cookies'], '/api/reservations.php', [
        'pracownik_id' => 0,
        'usluga_id' => 'abc',
        'data' => '2026-02-30',
        'godzina' => '25:00',
        'komentarz' => [],
    ], $session['csrf']);

    assert_status(422, $response, 'Nieprawidłowe body powinno dać 422');
    $data = json_body($response);
    assert_true(isset($data['errors']['pracownik_id']), 'Powinien być błąd pracownik_id');
    assert_true(isset($data['errors']['usluga_id']), 'Powinien być błąd usluga_id');
    assert_true(isset($data['errors']['data']), 'Powinien być błąd data');
    assert_true(isset($data['errors']['godzina']), 'Powinien być błąd godzina');
    assert_true(isset($data['errors']['komentarz']), 'Powinien być błąd komentarz');
});

test('184. POST tworzy rezerwację z ceną i statusem z serwera', function (): void {
    $session = reservations_client_session();
    $found = reservations_find_slots($session, 1, 1);
    assert_true($found !== null, 'Powinien istnieć wolny termin do rezerwacji');

    $parts = reservations_slot_parts($found['slots'][0]);
    $response = post_json(
        $session['cookies'],
        '/api/reservations.php',
        reservations_payload(1, 1, $parts['date'], $parts['time']),
        $session['csrf']
    );

    assert_status(201, $response, 'Utworzenie rezerwacji na wolnym terminie powinno dać 201');
    $data = json_body($response);
    assert_same(true, $data['success'] ?? null, 'Odpowiedź powinna zawierać success = true');
    $reservationId = (int) ($data['id'] ?? 0);
    assert_true($reservationId > 0, 'Odpowiedź powinna zawierać identyfikator rezerwacji');

    $statement = test_db()->prepare(
        'SELECT cena_historyczna, status_rezerwacji, anulowano
         FROM rezerwacje
         WHERE id = :id'
    );
    $statement->execute(['id' => $reservationId]);
    $row = $statement->fetch();

    assert_true(is_array($row), 'Rezerwacja powinna istnieć w bazie');
    $priceStatement = test_db()->prepare('SELECT cena FROM uslugi WHERE id = :id');
    $priceStatement->execute(['id' => 1]);
    assert_same(
        (string) $priceStatement->fetchColumn(),
        (string) $row['cena_historyczna'],
        'Cena historyczna powinna pochodzić z tabeli usług'
    );
    assert_same('oczekujaca', (string) $row['status_rezerwacji'], 'Nowa rezerwacja powinna być oczekująca');
    assert_same(null, $row['anulowano'], 'Nowa rezerwacja nie może mieć ustawionego anulowania');
});

test('185. POST na ten sam termin drugi raz daje 409', function (): void {
    $session = reservations_client_session();
    $found = reservations_find_slots($session, 1, 1);
    assert_true($found !== null, 'Powinien istnieć wolny termin do rezerwacji');

    $parts = reservations_slot_parts($found['slots'][0]);
    $payload = reservations_payload(1, 1, $parts['date'], $parts['time']);
    $first = post_json($session['cookies'], '/api/reservations.php', $payload, $session['csrf']);
    assert_status(201, $first, 'Pierwsza rezerwacja powinna dać 201');

    $second = post_json($session['cookies'], '/api/reservations.php', $payload, $session['csrf']);
    assert_status(409, $second, 'Druga rezerwacja tego samego terminu powinna dać 409');
});

test('186. POST na termin stykający się granicą daje 201', function (): void {
    $session = reservations_client_session();
    $found = reservations_find_adjacent_slots($session, 1, 1);
    assert_true($found !== null, 'Powinny istnieć dwa sąsiadujące sloty');

    $firstParts = reservations_slot_parts($found['slots'][0]);
    $secondParts = reservations_slot_parts($found['slots'][1]);
    $first = post_json(
        $session['cookies'],
        '/api/reservations.php',
        reservations_payload(1, 1, $firstParts['date'], $firstParts['time']),
        $session['csrf']
    );
    assert_status(201, $first, 'Pierwsza rezerwacja powinna dać 201');

    $second = post_json(
        $session['cookies'],
        '/api/reservations.php',
        reservations_payload(1, 1, $secondParts['date'], $secondParts['time']),
        $session['csrf']
    );
    assert_status(201, $second, 'Rezerwacja stykająca się granicą powinna dać 201');
});

/*
    Usługa 1 trwa 60 minut, a usługa 6 45 minut. Start usługi 6 przesunięty
    o 45 minut od startu usługi 1 należy do siatki 15-minutowej, ale nakłada
    się na rezerwację usługi 1. Sprawdzamy slot_b przed i po rezerwacji, żeby
    dowieść, że 409 wynika z konfliktu, a nie z godziny spoza siatki.
*/
test('187. POST na termin częściowo kolidujący daje 409', function (): void {
    $session = reservations_client_session();
    $pair = reservations_find_overlap_pair($session, 1, 1, 6, 45);
    assert_true(
        $pair !== null,
        'Dane seed powinny zawierać slot usługi 6 nakładający się na usługę 1'
    );

    if ($pair === null) {
        return;
    }

    $partsA = reservations_slot_parts($pair['slot_a']);
    $partsB = reservations_slot_parts($pair['slot_b']);
    $slotBStart = $partsB['start'];

    $before = reservations_availability_request($session['cookies'], 1, 6, $pair['date']);
    assert_status(200, $before, 'Dostępność usługi 6 przed rezerwacją powinna zwrócić 200');
    $beforeData = json_body($before);
    $beforeStarts = array_column($beforeData['sloty'] ?? [], 'start');
    assert_true(
        in_array($slotBStart, $beforeStarts, true),
        'Slot_b musi być wolny przed utworzeniem rezerwacji usługi 1'
    );

    $first = post_json(
        $session['cookies'],
        '/api/reservations.php',
        reservations_payload(1, 1, $partsA['date'], $partsA['time']),
        $session['csrf']
    );
    assert_status(201, $first, 'Rezerwacja bazowa usługi 1 powinna dać 201');

    $after = reservations_availability_request($session['cookies'], 1, 6, $pair['date']);
    assert_status(200, $after, 'Dostępność usługi 6 po rezerwacji powinna zwrócić 200');
    $afterData = json_body($after);
    $afterStarts = array_column($afterData['sloty'] ?? [], 'start');
    assert_true(
        !in_array($slotBStart, $afterStarts, true),
        'Slot_b powinien zniknąć z dostępności po rezerwacji usługi 1'
    );

    $partial = post_json(
        $session['cookies'],
        '/api/reservations.php',
        reservations_payload(1, 6, $partsB['date'], $partsB['time']),
        $session['csrf']
    );

    assert_status(409, $partial, 'Rezerwacja częściowo kolidująca powinna dać 409');
});

test('188. POST poza godzinami pracy daje 409', function (): void {
    $session = reservations_client_session();
    $found = reservations_find_slots($session, 1, 1);
    assert_true($found !== null, 'Powinien istnieć dzień z wolnymi terminami');

    $response = post_json(
        $session['cookies'],
        '/api/reservations.php',
        reservations_payload(1, 1, $found['date'], '03:00:00'),
        $session['csrf']
    );

    assert_status(409, $response, 'Termin poza grafikiem pracownika powinien dać 409');
});

test('189. POST z komentarzem dłuższym niż 1000 znaków daje 422', function (): void {
    $session = reservations_client_session();
    $response = post_json(
        $session['cookies'],
        '/api/reservations.php',
        reservations_payload(1, 1, date('Y-m-d'), '09:00:00', str_repeat('a', 1001)),
        $session['csrf']
    );

    assert_status(422, $response, 'Zbyt długi komentarz powinien dać 422');
    $data = json_body($response);
    assert_true(isset($data['errors']['komentarz']), 'Odpowiedź powinna zawierać błąd komentarza');
});

test('190. POST ignoruje cenę i status przysłane w body', function (): void {
    $session = reservations_client_session();
    $found = reservations_find_slots($session, 1, 1);
    assert_true($found !== null, 'Powinien istnieć wolny termin do rezerwacji');

    $parts = reservations_slot_parts($found['slots'][0]);
    $response = post_json(
        $session['cookies'],
        '/api/reservations.php',
        reservations_payload(
            1,
            1,
            $parts['date'],
            $parts['time'],
            null,
            [
                'cena_historyczna' => '0.01',
                'status_rezerwacji' => 'anulowana',
            ]
        ),
        $session['csrf']
    );

    assert_status(201, $response, 'Dodatkowe pola nie mogą blokować utworzenia rezerwacji');
    $data = json_body($response);
    $reservationId = (int) ($data['id'] ?? 0);
    assert_true($reservationId > 0, 'Odpowiedź powinna zawierać identyfikator rezerwacji');

    $statement = test_db()->prepare(
        'SELECT cena_historyczna, status_rezerwacji FROM rezerwacje WHERE id = :id'
    );
    $statement->execute(['id' => $reservationId]);
    $row = $statement->fetch();

    $priceStatement = test_db()->prepare('SELECT cena FROM uslugi WHERE id = :id');
    $priceStatement->execute(['id' => 1]);

    assert_same(
        (string) $priceStatement->fetchColumn(),
        (string) ($row['cena_historyczna'] ?? ''),
        'Cena musi pochodzić z tabeli usług, a nie z body'
    );
    assert_same(
        'oczekujaca',
        (string) ($row['status_rezerwacji'] ?? ''),
        'Status musi być ustawiony przez serwer na oczekująca'
    );
});

test('192. POST przyjmuje wolny termin poza przesuniętą listą slotów', function (): void {
    $session = reservations_client_session();
    $pair = reservations_find_overlap_pair($session, 1, 6, 1, 60);
    assert_true(
        $pair !== null,
        'Dane seed powinny zawierać parę slotów usług 6 i 1 przesuniętych o 60 minut'
    );

    if ($pair === null) {
        return;
    }

    $partsA = reservations_slot_parts($pair['slot_a']);
    $partsB = reservations_slot_parts($pair['slot_b']);
    $first = post_json(
        $session['cookies'],
        '/api/reservations.php',
        reservations_payload(1, 6, $partsA['date'], $partsA['time']),
        $session['csrf']
    );
    assert_status(201, $first, 'Rezerwacja usługi 6 powinna dać 201');

    $after = reservations_availability_request($session['cookies'], 1, 1, $pair['date']);
    assert_status(200, $after, 'Dostępność usługi 1 powinna zwrócić 200');
    $afterData = json_body($after);
    $afterStarts = array_column($afterData['sloty'] ?? [], 'start');
    assert_true(
        !in_array($partsB['start'], $afterStarts, true),
        'Żądany termin powinien wypaść z przesuniętej listy slotów'
    );

    $second = post_json(
        $session['cookies'],
        '/api/reservations.php',
        reservations_payload(1, 1, $partsB['date'], $partsB['time']),
        $session['csrf']
    );
    assert_status(201, $second, 'Wolny termin poza listą nadal powinien dać 201');
});

test('193. administrator i pracownik nie mogą tworzyć rezerwacji klienta', function (): void {
    $accounts = [
        ['email' => 'admin@wefixit.pl', 'label' => 'administratora'],
        ['email' => 'tomasz.serwisant@wefixit.pl', 'label' => 'pracownika'],
    ];

    foreach ($accounts as $account) {
        $session = login_as($account['email']);
        assert_status(200, $session['response'], 'Logowanie ' . $account['label'] . ' powinno się udać');
        $csrf = csrf_from_page($session['cookies'], '/profil.php');
        $response = post_json(
            $session['cookies'],
            '/api/reservations.php',
            reservations_payload(1, 1, date('Y-m-d'), '09:00:00'),
            $csrf
        );

        assert_status(403, $response, 'Konto ' . $account['label'] . ' nie może tworzyć rezerwacji');
    }
});

test('194. równoległe POST tworzą dokładnie jedną rezerwację', function (): void {
    $sessionA = reservations_client_session();
    $sessionB = reservations_client_session();
    $found = reservations_find_slots($sessionA, 1, 1);
    assert_true($found !== null, 'Powinien istnieć wolny termin do testu równoległego');

    $parts = reservations_slot_parts($found['slots'][0]);
    $payload = reservations_payload(1, 1, $parts['date'], $parts['time']);
    $responses = reservations_parallel_posts([
        [
            'cookies' => $sessionA['cookies'],
            'csrf' => $sessionA['csrf'],
            'payload' => $payload,
        ],
        [
            'cookies' => $sessionB['cookies'],
            'csrf' => $sessionB['csrf'],
            'payload' => $payload,
        ],
    ]);

    $statuses = [
        (int) ($responses[0]['status'] ?? 0),
        (int) ($responses[1]['status'] ?? 0),
    ];
    sort($statuses);
    assert_same([201, 409], $statuses, 'Równoległe żądania powinny dać jedną rezerwację i jeden konflikt');

    $statement = test_db()->prepare(
        "SELECT COUNT(*)
         FROM rezerwacje
         WHERE pracownik_id = 1
           AND usluga_id = 1
           AND czas_rozpoczecia = :czas_rozpoczecia
           AND status_rezerwacji <> 'anulowana'"
    );
    $statement->execute(['czas_rozpoczecia' => $parts['start']]);
    assert_same(1, (int) $statement->fetchColumn(), 'W bazie powinna powstać dokładnie jedna rezerwacja');
});
