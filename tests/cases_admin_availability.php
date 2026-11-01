<?php
declare(strict_types=1);

/*
    Testy dostępności pracownika w panelu administratora.

    Obejmują grafik tygodniowy (dodawanie, konflikty, walidację, ownership),
    nieobecności (oczekujący wniosek, maszynę stanów, ostrzeżenie o
    rezerwacjach), kontrolę dostępu, CSRF, metody HTTP oraz brak wycieku
    danych technicznych. Każdy test przygotowuje własny stan początkowy.
*/

test_group('Panel: dostępność pracownika', 'database/database.sql');

function availability_clean_schedule(int $employeeId, ?int $day = null): void
{
    $sql = 'DELETE FROM godziny_pracy WHERE pracownik_id = :pracownik_id';
    $params = ['pracownik_id' => $employeeId];

    if ($day !== null) {
        $sql .= ' AND dzien_tygodnia = :dzien_tygodnia';
        $params['dzien_tygodnia'] = $day;
    }

    $statement = test_db()->prepare($sql);
    $statement->execute($params);
}

function availability_clean_absences(int $employeeId): void
{
    $statement = test_db()->prepare('DELETE FROM nieobecnosci WHERE pracownik_id = :pracownik_id');
    $statement->execute(['pracownik_id' => $employeeId]);
}

function availability_schedule_count(int $employeeId, ?int $day = null): int
{
    $sql = 'SELECT COUNT(*) FROM godziny_pracy WHERE pracownik_id = :pracownik_id';
    $params = ['pracownik_id' => $employeeId];

    if ($day !== null) {
        $sql .= ' AND dzien_tygodnia = :dzien_tygodnia';
        $params['dzien_tygodnia'] = $day;
    }

    $statement = test_db()->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn();
}

function availability_active_schedule_count(int $employeeId, int $day): int
{
    $statement = test_db()->prepare(
        'SELECT COUNT(*)
         FROM godziny_pracy
         WHERE pracownik_id = :pracownik_id
           AND dzien_tygodnia = :dzien_tygodnia
           AND aktywne = TRUE'
    );
    $statement->execute([
        'pracownik_id' => $employeeId,
        'dzien_tygodnia' => $day,
    ]);

    return (int) $statement->fetchColumn();
}

function availability_schedule_row(int $id): ?array
{
    $statement = test_db()->prepare(
        'SELECT id, pracownik_id, dzien_tygodnia, czas_rozpoczecia, czas_zakonczenia, aktywne
         FROM godziny_pracy
         WHERE id = :id'
    );
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function availability_absence_row(int $id): ?array
{
    $statement = test_db()->prepare(
        'SELECT id, pracownik_id, data_od, data_do, rodzaj, status, powod,
                rozpatrzyl_id, rozpatrzono
         FROM nieobecnosci
         WHERE id = :id'
    );
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function availability_insert_absence(
    int $employeeId,
    string $from,
    string $to,
    string $type,
    string $status,
    ?int $reviewerId,
    ?string $reviewedAt
): int
{
    $statement = test_db()->prepare(
        'INSERT INTO nieobecnosci
            (pracownik_id, data_od, data_do, rodzaj, status, powod, rozpatrzyl_id, rozpatrzono)
         VALUES
            (:pracownik_id, :data_od, :data_do, :rodzaj, :status, NULL, :rozpatrzyl_id, :rozpatrzono)'
    );
    $statement->execute([
        'pracownik_id' => $employeeId,
        'data_od' => $from,
        'data_do' => $to,
        'rodzaj' => $type,
        'status' => $status,
        'rozpatrzyl_id' => $reviewerId,
        'rozpatrzono' => $reviewedAt,
    ]);

    return (int) test_db()->lastInsertId();
}

function availability_admin_session(int $employeeId = 1): array
{
    $session = login_as('admin@wefixit.pl');
    assert_status(200, $session['response'], 'Logowanie administratora powinno się udać');
    $session['csrf'] = csrf_from_page(
        $session['cookies'],
        '/pracownicy-dostepnosc.php?id=' . $employeeId
    );

    return $session;
}

function availability_add_schedule(
    array &$session,
    int $employeeId,
    int $day,
    string $start,
    string $end
): array
{
    return post_json($session['cookies'], '/api/admin/employee-schedule.php', [
        'pracownik_id' => $employeeId,
        'dzien_tygodnia' => $day,
        'czas_rozpoczecia' => $start,
        'czas_zakonczenia' => $end,
    ], $session['csrf']);
}

function availability_add_absence(
    array &$session,
    int $employeeId,
    string $from,
    string $to,
    string $type = 'urlop'
): array
{
    return post_json($session['cookies'], '/api/admin/employee-absences.php', [
        'pracownik_id' => $employeeId,
        'data_od' => $from,
        'data_do' => $to,
        'rodzaj' => $type,
    ], $session['csrf']);
}

test('79. dodanie przedziału grafiku daje 201 i wpis istnieje w bazie', function (): void {
    availability_clean_schedule(1, 6);
    $session = availability_admin_session();

    $response = availability_add_schedule($session, 1, 6, '09:15', '11:45');
    assert_status(201, $response, 'Dodanie przedziału powinno dać 201');

    $data = json_body($response);
    assert_same(true, $data['success'] ?? null, 'Odpowiedź powinna zawierać success = true');
    $id = $data['id'] ?? null;
    assert_true(is_int($id) && $id > 0, 'Odpowiedź powinna zawierać identyfikator wpisu');

    $row = availability_schedule_row($id);
    assert_true(is_array($row), 'Wpis grafiku powinien istnieć w bazie');
    assert_same(1, (int) $row['pracownik_id'], 'Wpis powinien należeć do pracownika 1');
    assert_same(6, (int) $row['dzien_tygodnia'], 'Wpis powinien mieć właściwy dzień tygodnia');
    assert_same('09:15:00', (string) $row['czas_rozpoczecia'], 'Godzina rozpoczęcia powinna być zapisana jako TIME');
    assert_same('11:45:00', (string) $row['czas_zakonczenia'], 'Godzina zakończenia powinna być zapisana jako TIME');
    assert_same(1, (int) $row['aktywne'], 'Nowy przedział powinien być aktywny');
});

test('80. nakładanie jest blokowane, a stykające się granice są dozwolone', function (): void {
    availability_clean_schedule(1, 3);
    $session = availability_admin_session();

    $first = availability_add_schedule($session, 1, 3, '08:00', '12:00');
    assert_status(201, $first, 'Pierwszy przedział 08:00-12:00 powinien dać 201');
    $firstId = (int) (json_body($first)['id'] ?? 0);
    $countBefore = availability_schedule_count(1, 3);

    $overlap = availability_add_schedule($session, 1, 3, '11:00', '15:00');
    assert_status(422, $overlap, 'Nakładający się przedział powinien dać 422');
    $overlapData = json_body($overlap);
    assert_true(
        isset($overlapData['errors']['czas_rozpoczecia'])
        && is_string($overlapData['errors']['czas_rozpoczecia']),
        'Konflikt powinien być przypisany do pola czas_rozpoczecia'
    );
    assert_same($countBefore, availability_schedule_count(1, 3), 'Odrzucony konflikt nie może zmienić bazy');

    $adjacent = availability_add_schedule($session, 1, 3, '12:00', '16:00');
    assert_status(201, $adjacent, 'Przedział stykający się granicami powinien dać 201');

    $duplicate = availability_add_schedule($session, 1, 3, '08:00', '12:00');
    assert_status(422, $duplicate, 'Dokładny duplikat przedziału powinien dać 422');
    $duplicateMessage = json_body($duplicate)['errors']['czas_rozpoczecia'] ?? '';
    assert_true(is_string($duplicateMessage) && $duplicateMessage !== '', 'Duplikat powinien mieć komunikat pola');
    assert_true(
        $duplicateMessage !== (string) ($overlapData['errors']['czas_rozpoczecia'] ?? ''),
        'Komunikat duplikatu powinien różnić się od komunikatu nakładania'
    );

    // Nieaktywny przedział nie blokuje nowego, nachodzącego na niego wpisu.
    $disable = patch_json($session['cookies'], '/api/admin/employee-schedule.php', [
        'id' => $firstId,
        'pracownik_id' => 1,
        'aktywne' => false,
    ], $session['csrf']);
    assert_status(200, $disable, 'Dezaktywacja przedziału powinna dać 200');

    // Ta reguła dotyczy wyłącznie tworzenia nowego wpisu. Reaktywacja
    // nieaktywnego, kolidującego wpisu jest odrzucana — patrz test 94.
    $overInactive = availability_add_schedule($session, 1, 3, '09:00', '10:00');
    assert_status(201, $overInactive, 'Przedział nachodzący na nieaktywny wpis powinien być dozwolony');
});

test('81. walidacja godzin i dnia tygodnia zwraca 422', function (): void {
    availability_clean_schedule(1, 4);
    $session = availability_admin_session();
    $cases = [
        [
            'payload' => ['pracownik_id' => 1, 'dzien_tygodnia' => 4, 'czas_rozpoczecia' => '10:00', 'czas_zakonczenia' => '10:00'],
            'field' => 'czas_zakonczenia',
            'label' => 'zakończenie równe rozpoczęciu',
        ],
        [
            'payload' => ['pracownik_id' => 1, 'dzien_tygodnia' => 4, 'czas_rozpoczecia' => '11:00', 'czas_zakonczenia' => '10:00'],
            'field' => 'czas_zakonczenia',
            'label' => 'zakończenie wcześniejsze od rozpoczęcia',
        ],
        [
            'payload' => ['pracownik_id' => 1, 'dzien_tygodnia' => 0, 'czas_rozpoczecia' => '08:00', 'czas_zakonczenia' => '09:00'],
            'field' => 'dzien_tygodnia',
            'label' => 'dzień 0',
        ],
        [
            'payload' => ['pracownik_id' => 1, 'dzien_tygodnia' => 8, 'czas_rozpoczecia' => '08:00', 'czas_zakonczenia' => '09:00'],
            'field' => 'dzien_tygodnia',
            'label' => 'dzień 8',
        ],
        [
            'payload' => ['pracownik_id' => 1, 'dzien_tygodnia' => 4, 'czas_rozpoczecia' => '25:00', 'czas_zakonczenia' => '26:00'],
            'field' => 'czas_rozpoczecia',
            'label' => 'niepoprawny format godziny',
        ],
    ];

    foreach ($cases as $case) {
        $response = post_json(
            $session['cookies'],
            '/api/admin/employee-schedule.php',
            $case['payload'],
            $session['csrf']
        );
        assert_status(422, $response, 'Przypadek "' . $case['label'] . '" powinien dać 422');

        $data = json_body($response);
        assert_true(
            isset($data['errors'][$case['field']]) && is_string($data['errors'][$case['field']]),
            'Odpowiedź dla "' . $case['label'] . '" powinna zawierać errors.' . $case['field']
        );
    }

    assert_same(0, availability_schedule_count(1, 4), 'Błędne dane nie mogą utworzyć wpisu grafiku');
});

test('82. klient i pracownik nie mają dostępu do strony ani endpointów', function (): void {
    availability_clean_schedule(1, 6);
    availability_clean_absences(1);
    $scheduleBefore = availability_schedule_count(1);
    $absencesBefore = (int) test_db()->query('SELECT COUNT(*) FROM nieobecnosci WHERE pracownik_id = 1')->fetchColumn();

    foreach ([
        ['email' => 'jan.kowalski@example.com', 'label' => 'klienta'],
        ['email' => 'tomasz.serwisant@wefixit.pl', 'label' => 'pracownika'],
    ] as $account) {
        $session = login_as($account['email']);
        assert_status(200, $session['response'], 'Logowanie ' . $account['label'] . ' powinno się udać');

        $page = http_request($session['cookies'], 'GET', '/pracownicy-dostepnosc.php?id=1');
        assert_status(403, $page, 'Konto ' . $account['label'] . ' powinno dostać 403 na stronie dostępności');

        $csrf = csrf_from_page($session['cookies'], '/profil.php');
        $schedule = post_json($session['cookies'], '/api/admin/employee-schedule.php', [
            'pracownik_id' => 1,
            'dzien_tygodnia' => 6,
            'czas_rozpoczecia' => '08:00',
            'czas_zakonczenia' => '09:00',
        ], $csrf);
        assert_status(403, $schedule, 'Konto ' . $account['label'] . ' powinno dostać 403 na POST grafiku');

        $absence = post_json($session['cookies'], '/api/admin/employee-absences.php', [
            'pracownik_id' => 1,
            'data_od' => '2035-01-01',
            'data_do' => '2035-01-02',
            'rodzaj' => 'urlop',
        ], $csrf);
        assert_status(403, $absence, 'Konto ' . $account['label'] . ' powinno dostać 403 na POST nieobecności');
    }

    assert_same($scheduleBefore, availability_schedule_count(1), 'Brak uprawnień nie może zmienić grafiku');
    assert_same(
        $absencesBefore,
        (int) test_db()->query('SELECT COUNT(*) FROM nieobecnosci WHERE pracownik_id = 1')->fetchColumn(),
        'Brak uprawnień nie może utworzyć nieobecności'
    );
});

test('83. POST bez tokenu CSRF daje 403 dla obu endpointów', function (): void {
    availability_clean_schedule(1, 6);
    availability_clean_absences(1);
    $session = availability_admin_session();

    $schedule = post_json($session['cookies'], '/api/admin/employee-schedule.php', [
        'pracownik_id' => 1,
        'dzien_tygodnia' => 6,
        'czas_rozpoczecia' => '08:00',
        'czas_zakonczenia' => '09:00',
    ]);
    assert_status(403, $schedule, 'POST grafiku bez CSRF powinien dać 403');

    $absence = post_json($session['cookies'], '/api/admin/employee-absences.php', [
        'pracownik_id' => 1,
        'data_od' => '2035-02-01',
        'data_do' => '2035-02-02',
        'rodzaj' => 'urlop',
    ]);
    assert_status(403, $absence, 'POST nieobecności bez CSRF powinien dać 403');

    assert_same(0, availability_schedule_count(1, 6), 'Żądanie bez CSRF nie może dodać wpisu grafiku');
    assert_same(0, (int) test_db()->query('SELECT COUNT(*) FROM nieobecnosci WHERE pracownik_id = 1')->fetchColumn(), 'Żądanie bez CSRF nie może dodać nieobecności');
});

test('84. nowa nieobecność zawsze jest oczekująca i bez pól rozpatrzenia', function (): void {
    availability_clean_absences(1);
    $session = availability_admin_session();

    $response = post_json($session['cookies'], '/api/admin/employee-absences.php', [
        'pracownik_id' => 1,
        'data_od' => '2035-03-01',
        'data_do' => '2035-03-05',
        'rodzaj' => 'urlop',
        'powod' => 'Wniosek z klienta, który nie może sam zatwierdzić urlopu.',
        'status' => 'zatwierdzona',
        'rozpatrzyl_id' => 1,
        'rozpatrzono' => '2035-03-06 10:00:00',
    ], $session['csrf']);

    assert_status(201, $response, 'Utworzenie nieobecności powinno dać 201');
    $id = (int) (json_body($response)['id'] ?? 0);
    assert_true($id > 0, 'Odpowiedź powinna zawierać identyfikator nieobecności');

    $row = availability_absence_row($id);
    assert_true(is_array($row), 'Nieobecność powinna istnieć w bazie');
    assert_same('oczekujaca', (string) $row['status'], 'Nowa nieobecność musi mieć status oczekujaca');
    assert_same(null, $row['rozpatrzyl_id'], 'Nowa nieobecność nie może mieć rozpatrującego');
    assert_same(null, $row['rozpatrzono'], 'Nowa nieobecność nie może mieć daty rozpatrzenia');
    assert_same('urlop', (string) $row['rodzaj'], 'Rodzaj powinien zostać zapisany');
});

test('85. zatwierdzenie i odrzucenie zapisują rozpatrzenie, anulowanie je zeruje', function (): void {
    availability_clean_absences(1);
    $session = availability_admin_session();
    $approved = availability_add_absence($session, 1, '2035-04-01', '2035-04-02');
    $rejected = availability_add_absence($session, 1, '2035-04-03', '2035-04-04');
    $cancelled = availability_add_absence($session, 1, '2035-04-05', '2035-04-06');
    assert_status(201, $approved, 'Wniosek do zatwierdzenia powinien dać 201');
    assert_status(201, $rejected, 'Wniosek do odrzucenia powinien dać 201');
    assert_status(201, $cancelled, 'Wniosek do anulowania powinien dać 201');

    $approvedId = (int) (json_body($approved)['id'] ?? 0);
    $rejectedId = (int) (json_body($rejected)['id'] ?? 0);
    $cancelledId = (int) (json_body($cancelled)['id'] ?? 0);

    $approve = patch_json($session['cookies'], '/api/admin/employee-absences.php', [
        'id' => $approvedId,
        'status' => 'zatwierdzona',
    ], $session['csrf']);
    assert_status(200, $approve, 'Zatwierdzenie powinno dać 200');
    $approvedRow = availability_absence_row($approvedId);
    assert_same('zatwierdzona', (string) $approvedRow['status'], 'Status powinien być zatwierdzona');
    assert_same(1, (int) $approvedRow['rozpatrzyl_id'], 'Zatwierdzenie powinno zapisać administratora');
    assert_true($approvedRow['rozpatrzono'] !== null, 'Zatwierdzenie powinno zapisać czas rozpatrzenia');

    $reject = patch_json($session['cookies'], '/api/admin/employee-absences.php', [
        'id' => $rejectedId,
        'status' => 'odrzucona',
    ], $session['csrf']);
    assert_status(200, $reject, 'Odrzucenie powinno dać 200');
    $rejectedRow = availability_absence_row($rejectedId);
    assert_same('odrzucona', (string) $rejectedRow['status'], 'Status powinien być odrzucona');
    assert_same(1, (int) $rejectedRow['rozpatrzyl_id'], 'Odrzucenie powinno zapisać administratora');
    assert_true($rejectedRow['rozpatrzono'] !== null, 'Odrzucenie powinno zapisać czas rozpatrzenia');

    $cancel = patch_json($session['cookies'], '/api/admin/employee-absences.php', [
        'id' => $approvedId,
        'status' => 'anulowana',
    ], $session['csrf']);
    assert_status(200, $cancel, 'Anulowanie zatwierdzonej nieobecności powinno dać 200');
    $cancelledRow = availability_absence_row($approvedId);
    assert_same('anulowana', (string) $cancelledRow['status'], 'Status powinien być anulowana');
    assert_same(null, $cancelledRow['rozpatrzyl_id'], 'Anulowanie powinno wyzerować rozpatrującego');
    assert_same(null, $cancelledRow['rozpatrzono'], 'Anulowanie powinno wyzerować czas rozpatrzenia');

    // Oczekujący wniosek również można anulować; oba pola rozpatrzenia pozostają puste.
    $cancelPending = patch_json($session['cookies'], '/api/admin/employee-absences.php', [
        'id' => $cancelledId,
        'status' => 'anulowana',
    ], $session['csrf']);
    assert_status(200, $cancelPending, 'Anulowanie oczekującego wniosku powinno dać 200');
    $pendingRow = availability_absence_row($cancelledId);
    assert_same('anulowana', (string) $pendingRow['status'], 'Status oczekującego wniosku powinien być anulowana');
    assert_same(null, $pendingRow['rozpatrzyl_id'], 'Anulowany wniosek nie może mieć rozpatrującego');
    assert_same(null, $pendingRow['rozpatrzono'], 'Anulowany wniosek nie może mieć daty rozpatrzenia');
});

test('86. nielegalne przejścia statusu dają 422 i nie zmieniają rekordu', function (): void {
    availability_clean_absences(1);
    $rejectedId = availability_insert_absence(
        1,
        '2035-05-01',
        '2035-05-02',
        'urlop',
        'odrzucona',
        1,
        '2035-05-03 09:00:00'
    );
    $cancelledId = availability_insert_absence(
        1,
        '2035-05-04',
        '2035-05-05',
        'choroba',
        'anulowana',
        null,
        null
    );
    $session = availability_admin_session();

    foreach ([$rejectedId, $cancelledId] as $id) {
        $before = availability_absence_row($id);
        $response = patch_json($session['cookies'], '/api/admin/employee-absences.php', [
            'id' => $id,
            'status' => 'zatwierdzona',
        ], $session['csrf']);

        assert_status(422, $response, 'Nielegalne przejście do zatwierdzona powinno dać 422');
        $data = json_body($response);
        assert_true(
            isset($data['errors']['status']) && is_string($data['errors']['status']),
            'Odpowiedź powinna zawierać errors.status'
        );
        assert_same($before, availability_absence_row($id), 'Odrzucone przejście nie może zmienić rekordu');
    }
});

test('87. DELETE nieobecności daje 405 z Allow tylko POST i PATCH', function (): void {
    $cookies = [];
    $response = delete_json($cookies, '/api/admin/employee-absences.php', ['id' => 1]);

    assert_status(405, $response, 'DELETE nieobecności powinien dać 405');
    $allow = header_value($response, 'allow');
    assert_true(is_string($allow) && $allow !== '', 'Odpowiedź powinna zawierać nagłówek Allow');

    $methods = array_values(array_filter(array_map('trim', explode(',', $allow))));
    sort($methods);
    assert_same(['PATCH', 'POST'], $methods, 'Allow powinno zawierać wyłącznie POST i PATCH');
});

test('88. walidacja dat, rodzaju i nakładania nieobecności zwraca 422', function (): void {
    availability_clean_absences(1);
    $session = availability_admin_session();
    $cases = [
        [
            'payload' => ['pracownik_id' => 1, 'data_od' => '2035-06-10', 'data_do' => '2035-06-09', 'rodzaj' => 'urlop'],
            'field' => 'data_do',
            'label' => 'data_od późniejsza niż data_do',
        ],
        [
            'payload' => ['pracownik_id' => 1, 'data_od' => '2035-02-30', 'data_do' => '2035-03-01', 'rodzaj' => 'urlop'],
            'field' => 'data_od',
            'label' => 'nieistniejąca data',
        ],
        [
            'payload' => ['pracownik_id' => 1, 'data_od' => '10.06.2035', 'data_do' => '2035-06-11', 'rodzaj' => 'urlop'],
            'field' => 'data_od',
            'label' => 'niepoprawny format daty',
        ],
        [
            'payload' => ['pracownik_id' => 1, 'data_od' => '2035-06-10', 'data_do' => '2035-06-11', 'rodzaj' => 'szkolenie'],
            'field' => 'rodzaj',
            'label' => 'rodzaj spoza allowlisty',
        ],
        [
            'payload' => [
                'pracownik_id' => 1,
                'data_od' => '2035-06-10',
                'data_do' => '2035-06-11',
                'rodzaj' => 'urlop',
                'powod' => str_repeat('a', 501),
            ],
            'field' => 'powod',
            'label' => 'powód dłuższy niż 500 znaków',
        ],
    ];

    foreach ($cases as $case) {
        $response = post_json(
            $session['cookies'],
            '/api/admin/employee-absences.php',
            $case['payload'],
            $session['csrf']
        );
        assert_status(422, $response, 'Przypadek "' . $case['label'] . '" powinien dać 422');

        $data = json_body($response);
        assert_true(
            isset($data['errors'][$case['field']]) && is_string($data['errors'][$case['field']]),
            'Odpowiedź dla "' . $case['label'] . '" powinna zawierać errors.' . $case['field']
        );
    }

    $valid = availability_add_absence($session, 1, '2035-07-01', '2035-07-05');
    assert_status(201, $valid, 'Poprawna nieobecność powinna dać 201');
    $countBefore = (int) test_db()->query('SELECT COUNT(*) FROM nieobecnosci WHERE pracownik_id = 1')->fetchColumn();

    $overlap = availability_add_absence($session, 1, '2035-07-04', '2035-07-07', 'choroba');
    assert_status(422, $overlap, 'Nakładająca się nieobecność powinna dać 422');
    assert_true(
        isset(json_body($overlap)['errors']['data_od']),
        'Konflikt nieobecności powinien być przypisany do pola data_od'
    );
    assert_same(
        $countBefore,
        (int) test_db()->query('SELECT COUNT(*) FROM nieobecnosci WHERE pracownik_id = 1')->fetchColumn(),
        'Odrzucona nieobecność nie może zmienić bazy'
    );
});

test('89. zatwierdzenie urlopu zwraca ostrzeżenie o rezerwacjach', function (): void {
    availability_clean_absences(1);
    $session = availability_admin_session();
    $from = date('Y-m-d', strtotime('-30 days'));
    $to = date('Y-m-d', strtotime('+30 days'));
    $created = availability_add_absence($session, 1, $from, $to);
    assert_status(201, $created, 'Szeroki wniosek urlopowy powinien dać 201');
    $id = (int) (json_body($created)['id'] ?? 0);

    $page = http_request($session['cookies'], 'GET', '/pracownicy-dostepnosc.php?id=1');
    assert_status(200, $page, 'Strona z oczekującym urlopem powinna zwrócić 200');
    assert_true(
        strpos($page['body'], 'W okresie nieobecności zaplanowano') !== false,
        'Widok powinien pokazać ostrzeżenie o rezerwacjach przed potwierdzeniem'
    );

    $response = patch_json($session['cookies'], '/api/admin/employee-absences.php', [
        'id' => $id,
        'status' => 'zatwierdzona',
    ], $session['csrf']);

    assert_status(200, $response, 'Zatwierdzenie urlopu powinno dać 200');
    $data = json_body($response);
    assert_array_key($data, 'ostrzezenie', 'Odpowiedź powinna zawierać ostrzeżenie');
    assert_true(
        is_array($data['ostrzezenie'])
        && (int) ($data['ostrzezenie']['liczba_rezerwacji'] ?? 0) > 0,
        'Ostrzeżenie powinno zawierać liczbę rezerwacji większą od zera'
    );
    assert_true(
        isset($data['ostrzezenie']['komunikat']) && is_string($data['ostrzezenie']['komunikat']),
        'Ostrzeżenie powinno zawierać komunikat'
    );

    $log = test_db()->query(
        "SELECT akcja, obiekt_typ, obiekt_id, poziom
         FROM log_zdarzen
         WHERE akcja = 'nieobecnosc_zatwierdzona'
         ORDER BY id DESC
         LIMIT 1"
    )->fetch();
    assert_true(is_array($log), 'Zatwierdzenie powinno trafić do logu');
    assert_same('pracownik', (string) $log['obiekt_typ'], 'Log powinien wskazywać pracownika');
    assert_same(1, (int) $log['obiekt_id'], 'Log powinien wskazywać pracownika 1');
    assert_same('warning', (string) $log['poziom'], 'Konflikt z rezerwacjami powinien dać poziom warning');
});

test('90. PATCH i DELETE grafiku wymagają zgodności właściciela wpisu', function (): void {
    availability_clean_schedule(1, 6);
    availability_clean_schedule(2, 6);
    $session = availability_admin_session();

    $own = availability_add_schedule($session, 1, 6, '08:00', '10:00');
    $foreign = availability_add_schedule($session, 2, 6, '10:00', '12:00');
    assert_status(201, $own, 'Wpis pracownika 1 powinien dać 201');
    assert_status(201, $foreign, 'Wpis pracownika 2 powinien dać 201');
    $ownId = (int) (json_body($own)['id'] ?? 0);
    $foreignId = (int) (json_body($foreign)['id'] ?? 0);
    $foreignBefore = availability_schedule_row($foreignId);

    $patchForeign = patch_json($session['cookies'], '/api/admin/employee-schedule.php', [
        'id' => $foreignId,
        'pracownik_id' => 1,
        'aktywne' => false,
    ], $session['csrf']);
    assert_status(404, $patchForeign, 'PATCH cudzego wpisu powinien dać 404');
    assert_same($foreignBefore, availability_schedule_row($foreignId), 'PATCH cudzego wpisu nie może zmienić stanu');

    $deleteForeign = delete_json($session['cookies'], '/api/admin/employee-schedule.php', [
        'id' => $foreignId,
        'pracownik_id' => 1,
    ], $session['csrf']);
    assert_status(404, $deleteForeign, 'DELETE cudzego wpisu powinien dać 404');
    assert_same($foreignBefore, availability_schedule_row($foreignId), 'DELETE cudzego wpisu nie może usunąć rekordu');

    $patchOwn = patch_json($session['cookies'], '/api/admin/employee-schedule.php', [
        'id' => $ownId,
        'pracownik_id' => 1,
        'aktywne' => false,
    ], $session['csrf']);
    assert_status(200, $patchOwn, 'PATCH własnego wpisu powinien dać 200');
    $ownAfter = availability_schedule_row($ownId);
    assert_same(0, (int) $ownAfter['aktywne'], 'Flaga aktywności powinna się zmienić');

    $deleteOwn = delete_json($session['cookies'], '/api/admin/employee-schedule.php', [
        'id' => $ownId,
        'pracownik_id' => 1,
    ], $session['csrf']);
    assert_status(200, $deleteOwn, 'DELETE własnego wpisu powinien dać 200');
    assert_same(null, availability_schedule_row($ownId), 'Wpis po DELETE nie powinien istnieć');
});

test('91. strona dostępności zawiera obie sekcje i osadza 404 w szklelecie panelu', function (): void {
    $session = availability_admin_session();

    $page = http_request($session['cookies'], 'GET', '/pracownicy-dostepnosc.php?id=1');
    assert_status(200, $page, 'Strona dostępności powinna zwrócić 200');
    assert_true(strpos($page['body'], 'Grafik tygodniowy') !== false, 'Strona powinna zawierać sekcję grafiku');
    assert_true(strpos($page['body'], 'Nieobecności') !== false, 'Strona powinna zawierać sekcję nieobecności');
    assert_true(strpos($page['body'], 'Poniedziałek') !== false, 'Grafik powinien zaczynać się od poniedziałku');
    assert_true(strpos($page['body'], 'Niedziela') !== false, 'Grafik powinien zawierać niedzielę');
    assert_true(
        strpos($page['body'], '/api/admin/employee-schedule') !== false,
        'Strona powinna wskazywać endpoint grafiku'
    );
    assert_true(
        strpos($page['body'], '/api/admin/employee-absences') !== false,
        'Strona powinna wskazywać endpoint nieobecności'
    );
    assert_true(
        strpos($page['body'], 'brak godzin pracy') !== false,
        'Puste dni grafiku powinny wprost pokazywać brak godzin pracy'
    );

    $employeesPage = http_request($session['cookies'], 'GET', '/pracownicy.php');
    assert_status(200, $employeesPage, 'Lista pracowników powinna zwrócić 200');
    assert_true(
        strpos($employeesPage['body'], '/pracownicy-dostepnosc?id=1') !== false,
        'Lista pracowników powinna zawierać link do dostępności'
    );
    assert_true(
        strpos($employeesPage['body'], 'Dostępność') !== false,
        'Akcja na liście pracowników powinna nazywać się Dostępność'
    );

    foreach (['/pracownicy-dostepnosc.php?id=999999', '/pracownicy-dostepnosc.php'] as $path) {
        $missing = http_request($session['cookies'], 'GET', $path);
        assert_status(404, $missing, 'Adres ' . $path . ' powinien dać 404');
        assert_true(
            strpos($missing['body'], 'Nie znaleziono pracownika') !== false,
            'Strona 404 powinna zawierać komunikat o braku pracownika'
        );
        assert_true(
            strpos($missing['body'], 'id="panel-shell"') !== false,
            'Strona 404 powinna być osadzona w szklelecie panelu'
        );
    }
});

test('92. odpowiedzi nie ujawniają danych technicznych, a log nie ma błędów PHP', function (): void {
    availability_clean_schedule(1, 6);
    availability_clean_absences(1);
    $session = availability_admin_session();
    $responses = [];

    $created = availability_add_schedule($session, 1, 6, '08:00', '09:00');
    assert_status(201, $created, 'Utworzenie wpisu do testu 92 powinno dać 201');
    $responses[] = $created;

    $conflict = availability_add_schedule($session, 1, 6, '08:30', '09:30');
    assert_status(422, $conflict, 'Konflikt do testu 92 powinien dać 422');
    $responses[] = $conflict;

    $absence = availability_add_absence($session, 1, '2035-08-01', '2035-08-02');
    assert_status(201, $absence, 'Utworzenie nieobecności do testu 92 powinno dać 201');
    $responses[] = $absence;

    $invalid = post_json($session['cookies'], '/api/admin/employee-absences.php', [
        'pracownik_id' => 1,
        'data_od' => 'zła-data',
        'data_do' => '2035-08-03',
        'rodzaj' => 'urlop',
    ], $session['csrf']);
    assert_status(422, $invalid, 'Niepoprawna data do testu 92 powinna dać 422');
    $responses[] = $invalid;

    $page = http_request($session['cookies'], 'GET', '/pracownicy-dostepnosc.php?id=1');
    assert_status(200, $page, 'Strona dostępności do testu 92 powinna zwrócić 200');
    $responses[] = $page;

    $forbidden = [
        'haslo_hash',
        'SQLSTATE',
        'SELECT ',
        'INSERT INTO',
        'UPDATE ',
        'DELETE FROM',
        '/var/www',
        'app/pracownicy_dostepnosc.php',
        'public/config.php',
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

test('93. kontrakt metod HTTP i Content-Type endpointów dostępności', function (): void {
    availability_clean_schedule(1, 6);
    availability_clean_absences(1);
    $session = availability_admin_session();

    $scheduleGet = http_request($session['cookies'], 'GET', '/api/admin/employee-schedule.php');
    assert_status(405, $scheduleGet, 'GET grafiku powinien dać 405');
    $scheduleAllow = header_value($scheduleGet, 'allow');
    assert_true(is_string($scheduleAllow) && $scheduleAllow !== '', 'GET grafiku powinien zwrócić Allow');
    $scheduleMethods = array_values(array_filter(array_map('trim', explode(',', $scheduleAllow))));
    sort($scheduleMethods);
    assert_same(
        ['DELETE', 'PATCH', 'POST'],
        $scheduleMethods,
        'Allow grafiku powinno zawierać POST, PATCH i DELETE'
    );

    foreach (['GET', 'DELETE'] as $method) {
        $response = http_request($session['cookies'], $method, '/api/admin/employee-absences.php');
        assert_status(405, $response, $method . ' nieobecności powinien dać 405');
        $allow = header_value($response, 'allow');
        assert_true(is_string($allow) && $allow !== '', $method . ' nieobecności powinien zwrócić Allow');
        $methods = array_values(array_filter(array_map('trim', explode(',', $allow))));
        sort($methods);
        assert_same(['PATCH', 'POST'], $methods, 'Allow nieobecności powinno zawierać wyłącznie POST i PATCH');
    }

    $postPayload = [
        'pracownik_id' => 1,
        'dzien_tygodnia' => 6,
        'czas_rozpoczecia' => '08:00',
        'czas_zakonczenia' => '09:00',
    ];
    $postWithoutType = http_request($session['cookies'], 'POST', '/api/admin/employee-schedule.php', [
        'body' => json_encode($postPayload, JSON_UNESCAPED_UNICODE),
        'headers' => ['X-CSRF-Token' => $session['csrf']],
    ]);
    assert_status(415, $postWithoutType, 'POST grafiku bez Content-Type powinien dać 415');
    assert_same(0, availability_schedule_count(1, 6), 'POST bez Content-Type nie może utworzyć wpisu');

    $created = availability_add_schedule($session, 1, 6, '10:00', '11:00');
    assert_status(201, $created, 'Utworzenie wpisu do testu PATCH powinno dać 201');
    $id = (int) (json_body($created)['id'] ?? 0);
    $rowBefore = availability_schedule_row($id);
    assert_true(is_array($rowBefore), 'Wpis do testu PATCH powinien istnieć');

    $patchWithoutType = http_request($session['cookies'], 'PATCH', '/api/admin/employee-schedule.php', [
        'body' => json_encode([
            'id' => $id,
            'pracownik_id' => 1,
            'aktywne' => false,
        ], JSON_UNESCAPED_UNICODE),
        'headers' => ['X-CSRF-Token' => $session['csrf']],
    ]);
    assert_status(415, $patchWithoutType, 'PATCH grafiku bez Content-Type powinien dać 415');
    assert_same($rowBefore, availability_schedule_row($id), 'PATCH bez Content-Type nie może zmienić wpisu');

    availability_clean_schedule(1, 6);
});

test('94. reaktywacja wpisu grafiku odrzuca kolizję z aktywnym przedziałem', function (): void {
    availability_clean_schedule(1, 3);
    $session = availability_admin_session();

    $first = availability_add_schedule($session, 1, 3, '08:00', '12:00');
    assert_status(201, $first, 'Pierwszy przedział powinien dać 201');
    $firstId = (int) (json_body($first)['id'] ?? 0);

    $disableFirst = patch_json($session['cookies'], '/api/admin/employee-schedule.php', [
        'id' => $firstId,
        'pracownik_id' => 1,
        'aktywne' => false,
    ], $session['csrf']);
    assert_status(200, $disableFirst, 'Dezaktywacja pierwszego przedziału powinna dać 200');

    $second = availability_add_schedule($session, 1, 3, '09:00', '10:00');
    assert_status(201, $second, 'Drugi, nachodzący przedział powinien dać 201 po dezaktywacji pierwszego');
    $secondId = (int) (json_body($second)['id'] ?? 0);
    assert_same(1, availability_active_schedule_count(1, 3), 'Aktywny powinien być tylko drugi wpis');

    $reactivate = patch_json($session['cookies'], '/api/admin/employee-schedule.php', [
        'id' => $firstId,
        'pracownik_id' => 1,
        'aktywne' => true,
    ], $session['csrf']);
    assert_status(422, $reactivate, 'Reaktywacja kolidującego wpisu powinna dać 422');
    $reactivateData = json_body($reactivate);
    assert_true(
        isset($reactivateData['errors']['aktywne']) && is_string($reactivateData['errors']['aktywne']),
        'Konflikt reaktywacji powinien być przypisany do pola aktywne'
    );

    $firstRow = availability_schedule_row($firstId);
    assert_same(0, (int) $firstRow['aktywne'], 'Pierwszy wpis powinien pozostać nieaktywny');
    assert_same(1, availability_active_schedule_count(1, 3), 'Nadal tylko drugi wpis powinien być aktywny');

    $disableSecond = patch_json($session['cookies'], '/api/admin/employee-schedule.php', [
        'id' => $secondId,
        'pracownik_id' => 1,
        'aktywne' => false,
    ], $session['csrf']);
    assert_status(200, $disableSecond, 'Dezaktywacja drugiego przedziału powinna dać 200');

    $reactivateAgain = patch_json($session['cookies'], '/api/admin/employee-schedule.php', [
        'id' => $firstId,
        'pracownik_id' => 1,
        'aktywne' => true,
    ], $session['csrf']);
    assert_status(200, $reactivateAgain, 'Reaktywacja bez kolizji powinna dać 200');

    $firstRow = availability_schedule_row($firstId);
    $secondRow = availability_schedule_row($secondId);
    assert_same(1, (int) $firstRow['aktywne'], 'Pierwszy wpis powinien być ponownie aktywny');
    assert_same(0, (int) $secondRow['aktywne'], 'Drugi wpis powinien pozostać nieaktywny');
    assert_same(1, availability_active_schedule_count(1, 3), 'Dokładnie jeden wpis powinien być aktywny');
});
