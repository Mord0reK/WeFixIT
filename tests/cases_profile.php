<?php
declare(strict_types=1);

/*
    Testy edycji profilu (punkty 21-25 zakresu Zadania A).

    Sprawdzają kontrolę dostępu, zapis w bazie oraz to, że żadne pole body ani
    query string nie pozwalają zmienić cudzego konta lub własnej roli.
*/

test_group('Profil użytkownika', 'database/database.sql');

test('21. brak sesji daje 401, brak CSRF daje 403', function (): void {
    $payload = [
        'imie' => 'Jan',
        'nazwisko' => 'Kowalski',
        'email' => 'jan.kowalski@example.com',
        'telefon' => '+48987654321',
    ];

    $anonymous = [];
    $withoutSession = patch_json($anonymous, '/api/profile.php', $payload);
    assert_status(401, $withoutSession, 'PATCH bez sesji powinien dać 401');

    $session = login_as('jan.kowalski@example.com');
    $withoutCsrf = patch_json($session['cookies'], '/api/profile.php', $payload);
    assert_status(403, $withoutCsrf, 'PATCH bez tokenu CSRF powinien dać 403');
});

test('22. poprawne dane zmieniają rekord w bazie', function (): void {
    $session = login_as('jan.kowalski@example.com');
    $csrf = csrf_from_page($session['cookies'], '/profil.php');

    $response = patch_json($session['cookies'], '/api/profile.php', [
        'imie' => 'Jan',
        'nazwisko' => 'Kowalski-Nowy',
        'email' => 'jan.kowalski@example.com',
        'telefon' => '+48111222333',
    ], $csrf);

    assert_status(200, $response, 'Poprawna edycja profilu powinna dać 200');
    $updated = json_body($response)['user'] ?? [];
    assert_same('Kowalski-Nowy', $updated['nazwisko'] ?? null, 'Odpowiedź powinna zawierać nowe nazwisko');

    $row = test_db()->query("SELECT nazwisko, telefon FROM uzytkownicy WHERE email = 'jan.kowalski@example.com'")->fetch();
    assert_same('Kowalski-Nowy', $row['nazwisko'] ?? null, 'Nazwisko powinno zostać zapisane w bazie');
    assert_same('+48111222333', $row['telefon'] ?? null, 'Telefon powinien zostać zapisany w bazie');
});

test('23. e-mail należący do innego konta daje 409', function (): void {
    $session = login_as('jan.kowalski@example.com');
    $csrf = csrf_from_page($session['cookies'], '/profil.php');

    $response = patch_json($session['cookies'], '/api/profile.php', [
        'imie' => 'Jan',
        'nazwisko' => 'Kowalski',
        'email' => 'anna.nowak@example.com',
        'telefon' => '+48987654321',
    ], $csrf);

    assert_status(409, $response, 'Zajęty e-mail powinien dać 409');
});

test('24. id i rola w body nie zmieniają cudzego konta ani własnej roli', function (): void {
    $session = login_as('jan.kowalski@example.com');
    $csrf = csrf_from_page($session['cookies'], '/profil.php');

    $pdo = test_db();
    $otherId = (int) $pdo->query("SELECT id FROM uzytkownicy WHERE email = 'anna.nowak@example.com'")->fetchColumn();

    $response = patch_json($session['cookies'], '/api/profile.php', [
        'id' => $otherId,
        'user_id' => $otherId,
        'rola' => 'admin',
        'aktywny' => 0,
        'imie' => 'Jan',
        'nazwisko' => 'Kowalski',
        'email' => 'jan.kowalski@example.com',
        'telefon' => '+48987654321',
    ], $csrf);

    assert_status(200, $response, 'Edycja własnego profilu powinna się udać');

    $self = $pdo->query("SELECT rola, aktywny FROM uzytkownicy WHERE email = 'jan.kowalski@example.com'")->fetch();
    assert_same('klient', $self['rola'] ?? null, 'Rola własna nie może się zmienić przez body');
    assert_true((int) ($self['aktywny'] ?? 0) === 1, 'Flaga aktywny własnego konta nie może się zmienić');

    $other = $pdo->query('SELECT imie, nazwisko, email, rola FROM uzytkownicy WHERE id = ' . $otherId)->fetch();
    assert_same('Anna', $other['imie'] ?? null, 'Cudze konto nie może zostać zmienione');
    assert_same('anna.nowak@example.com', $other['email'] ?? null, 'Cudzy e-mail nie może się zmienić');
    assert_same('klient', $other['rola'] ?? null, 'Cudza rola nie może się zmienić');
});

test('25. edycja innego konta nie jest możliwa żadnym parametrem żądania', function (): void {
    $session = login_as('jan.kowalski@example.com');
    $csrf = csrf_from_page($session['cookies'], '/profil.php');

    $pdo = test_db();
    $other = $pdo->query(
        "SELECT id, imie, nazwisko, email, telefon FROM uzytkownicy WHERE email = 'piotr.wisniewski@example.com'"
    )->fetch();
    $otherId = (int) $other['id'];

    // Query string oraz dodatkowe pola body próbują wskazać cudze konto.
    $response = patch_json($session['cookies'], '/api/profile.php?id=' . $otherId . '&user_id=' . $otherId, [
        'id' => $otherId,
        'user_id' => $otherId,
        'uzytkownik_id' => $otherId,
        'imie' => 'Jan',
        'nazwisko' => 'Kowalski',
        'email' => 'jan.kowalski@example.com',
        'telefon' => '+48987654321',
    ], $csrf);

    assert_status(200, $response, 'Żądanie powinno edytować wyłącznie zalogowane konto');

    $unchanged = $pdo->query('SELECT imie, nazwisko, email, telefon FROM uzytkownicy WHERE id = ' . $otherId)->fetch();
    assert_same($other['imie'], $unchanged['imie'] ?? null, 'Cudze imię nie może się zmienić');
    assert_same($other['nazwisko'], $unchanged['nazwisko'] ?? null, 'Cudze nazwisko nie może się zmienić');
    assert_same($other['email'], $unchanged['email'] ?? null, 'Cudzy e-mail nie może się zmienić');
    assert_same($other['telefon'], $unchanged['telefon'] ?? null, 'Cudzy telefon nie może się zmienić');
});
