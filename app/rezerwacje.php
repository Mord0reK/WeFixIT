<?php
declare(strict_types=1);

/*
    Moduł danych i walidacji rezerwacji w panelu administratora.

    Obsługuje listę z filtrami, wyszukiwaniem i paginacją, odczyt pojedynczej
    rezerwacji i zmianę statusu według jawnej maszyny stanów.
    Rezerwacji nie usuwamy — anulowanie jest
    wyłącznie zmianą statusu. Status i anulowano zawsze zapisujemy w jednej
    instrukcji UPDATE, bo CHECK chk_rezerwacje_anulowanie wiąże oba pola.
    Moduł nie zawiera HTML ani logiki HTTP.
*/
require_once __DIR__ . '/../public/config.php';
require_once __DIR__ . '/pagination.php';
require_once __DIR__ . '/availability.php';

const REZERWACJA_STATUSES = [
    'oczekujaca',
    'potwierdzona',
    'w_trakcie',
    'zrealizowana',
    'anulowana',
];

/*
    Dopuszczalne przejścia odzwierciedlają kolejność realizacji usługi.
    Zrealizowana jest stanem końcowym, a anulowaną można tylko przywrócić
    do oczekującej — bez pomijania ponownego potwierdzenia.
*/
const REZERWACJA_TRANSITIONS = [
    'oczekujaca' => ['potwierdzona', 'anulowana'],
    'potwierdzona' => ['w_trakcie', 'anulowana'],
    'w_trakcie' => ['zrealizowana', 'anulowana'],
    'zrealizowana' => [],
    'anulowana' => ['oczekujaca'],
];

const REZERWACJA_SORT_DIRECTIONS = ['asc', 'desc'];

const REZERWACJA_ID_ERROR = 'Podaj identyfikator rezerwacji.';
const REZERWACJA_STATUS_ERROR = 'Wybierz prawidłowy status rezerwacji.';
const REZERWACJA_TRANSITION_ERROR = 'Nie można zmienić statusu rezerwacji w ten sposób.';

function rezerwacje_db(): ?PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        error_log('Brak połączenia z bazą w module rezerwacji.');
        return null;
    }

    return $pdo;
}

function rezerwacja_status_value(mixed $value): ?string
{
    return is_string($value) && in_array($value, REZERWACJA_STATUSES, true)
        ? $value
        : null;
}

// Filtr statusu: nieprawidłowa wartość oznacza brak filtra, nie błąd.
function rezerwacja_filter_status(mixed $value): string
{
    return rezerwacja_status_value($value) ?? '';
}

// Filtr identyfikatora akceptuje tylko dodatnią liczbę całkowitą.
function rezerwacja_filter_id(mixed $value): int
{
    if (is_int($value)) {
        return $value > 0 ? $value : 0;
    }

    if (is_string($value) && $value !== '' && ctype_digit($value)) {
        $id = (int) $value;

        return $id > 0 ? $id : 0;
    }

    return 0;
}

function rezerwacja_filter_date(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    if ($date === false || $date->format('Y-m-d') !== $value) {
        return null;
    }

    return $value;
}

// Filtr daty końcowej obejmuje cały dzień, także godziny po północy.
function rezerwacja_filter_date_end(string $date): string
{
    return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
}

// Kierunek sortowania pochodzi wyłącznie z allowlisty.
function rezerwacja_sort_direction(mixed $value): string
{
    return is_string($value) && in_array($value, REZERWACJA_SORT_DIRECTIONS, true)
        ? $value
        : 'desc';
}

function rezerwacja_transition_allowed(string $current, string $target): bool
{
    $allowed = REZERWACJA_TRANSITIONS[$current] ?? [];

    return in_array($target, $allowed, true);
}

function rezerwacja_transition_targets(string $current): array
{
    return REZERWACJA_TRANSITIONS[$current] ?? [];
}

// Walidacja kształtu body przed sprawdzeniem istnienia rezerwacji.
function rezerwacja_status_change_errors(array $data): array
{
    $errors = [];
    $id = $data['id'] ?? null;

    if (!is_int($id) || $id <= 0) {
        $errors['id'] = REZERWACJA_ID_ERROR;
    }

    if (rezerwacja_status_value($data['status'] ?? null) === null) {
        $errors['status'] = REZERWACJA_STATUS_ERROR;
    }

    return $errors;
}

// Wspólne mapowanie rekordu używane przez listę i odczyt pojedynczej rezerwacji.
function rezerwacja_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'uzytkownik_id' => (int) $row['uzytkownik_id'],
        'pracownik_id' => (int) $row['pracownik_id'],
        'usluga_id' => (int) $row['usluga_id'],
        'czas_rozpoczecia' => (string) $row['czas_rozpoczecia'],
        'czas_zakonczenia' => (string) $row['czas_zakonczenia'],
        'cena_historyczna' => (string) $row['cena_historyczna'],
        'status_rezerwacji' => (string) $row['status_rezerwacji'],
        'komentarz_klienta' => $row['komentarz_klienta'] !== null
            ? (string) $row['komentarz_klienta']
            : null,
        'anulowano' => $row['anulowano'] !== null ? (string) $row['anulowano'] : null,
        'utworzono' => (string) $row['utworzono'],
        'zaktualizowano' => (string) $row['zaktualizowano'],
        'klient_imie' => (string) $row['klient_imie'],
        'klient_nazwisko' => (string) $row['klient_nazwisko'],
        'klient_email' => (string) $row['klient_email'],
        'pracownik_imie' => (string) $row['pracownik_imie'],
        'pracownik_nazwisko' => (string) $row['pracownik_nazwisko'],
        'usluga_nazwa' => (string) $row['usluga_nazwa'],
        'usluga_kategoria' => (string) $row['usluga_kategoria'],
    ];
}

function rezerwacja_select_sql(): string
{
    return 'SELECT
                r.id,
                r.uzytkownik_id,
                r.pracownik_id,
                r.usluga_id,
                r.czas_rozpoczecia,
                r.czas_zakonczenia,
                r.cena_historyczna,
                r.status_rezerwacji,
                r.komentarz_klienta,
                r.anulowano,
                r.utworzono,
                r.zaktualizowano,
                u.imie AS klient_imie,
                u.nazwisko AS klient_nazwisko,
                u.email AS klient_email,
                pu.imie AS pracownik_imie,
                pu.nazwisko AS pracownik_nazwisko,
                s.nazwa AS usluga_nazwa,
                k.nazwa AS usluga_kategoria
             FROM rezerwacje r
             JOIN uzytkownicy u ON u.id = r.uzytkownik_id
             JOIN pracownicy p ON p.id = r.pracownik_id
             JOIN uzytkownicy pu ON pu.id = p.uzytkownik_id
             JOIN uslugi s ON s.id = r.usluga_id
             JOIN kategorie_uslug k ON k.id = s.kategoria_id';
}

function rezerwacja_list_conditions(array $filters): array
{
    $conditions = [];
    $params = [];
    $dateFrom = rezerwacja_filter_date($filters['data_od'] ?? null);
    $dateTo = rezerwacja_filter_date($filters['data_do'] ?? null);
    $status = rezerwacja_filter_status($filters['status'] ?? null);
    $employeeId = rezerwacja_filter_id($filters['pracownik_id'] ?? null);
    $serviceId = rezerwacja_filter_id($filters['usluga_id'] ?? null);
    $userId = rezerwacja_filter_id($filters['uzytkownik_id'] ?? null);
    $direction = rezerwacja_sort_direction($filters['kierunek'] ?? null);
    $search = pagination_search_value($filters['szukaj'] ?? null);

    if ($dateFrom !== null) {
        $conditions[] = 'r.czas_rozpoczecia >= :data_od';
        $params['data_od'] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== null) {
        $conditions[] = 'r.czas_rozpoczecia < :data_do_koniec';
        $params['data_do_koniec'] = rezerwacja_filter_date_end($dateTo) . ' 00:00:00';
    }

    if ($status !== '') {
        $conditions[] = 'r.status_rezerwacji = :status';
        $params['status'] = $status;
    }

    if ($employeeId > 0) {
        $conditions[] = 'r.pracownik_id = :pracownik_id';
        $params['pracownik_id'] = $employeeId;
    }

    if ($serviceId > 0) {
        $conditions[] = 'r.usluga_id = :usluga_id';
        $params['usluga_id'] = $serviceId;
    }

    if ($userId > 0) {
        $conditions[] = 'r.uzytkownik_id = :uzytkownik_id';
        $params['uzytkownik_id'] = $userId;
    }

    if ($search !== '') {
        $pattern = pagination_like_pattern($search);
        $conditions[] = '(u.nazwisko LIKE CONCAT(\'%\', :szukaj_klient, \'%\')'
            . ' OR pu.nazwisko LIKE CONCAT(\'%\', :szukaj_pracownik, \'%\'))';
        $params['szukaj_klient'] = $pattern;
        $params['szukaj_pracownik'] = $pattern;
    }

    return [
        'conditions' => $conditions,
        'params' => $params,
        'search' => $search,
        'direction' => $direction,
    ];
}

function rezerwacje_list(array $filters = []): ?array
{
    $pdo = rezerwacje_db();

    if ($pdo === null) {
        return null;
    }

    $filterData = rezerwacja_list_conditions($filters);
    $conditions = $filterData['conditions'];
    $params = $filterData['params'];
    $direction = $filterData['direction'];
    $where = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';
    $order = $direction === 'asc' ? 'ASC' : 'DESC';

    try {
        $statement = $pdo->prepare(
            rezerwacja_select_sql()
            . $where
            . ' ORDER BY r.czas_rozpoczecia ' . $order . ', r.id ' . $order
        );
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać listy rezerwacji: ' . $e->getMessage());
        return null;
    }

    $reservations = [];

    foreach ($rows as $row) {
        $reservations[] = rezerwacja_row($row);
    }

    return $reservations;
}

/*
    Wariant paginowany jest osobną funkcją, żeby dotychczasowa lista mogła
    nadal zwracać wszystkie rekordy wywołującym, którzy nie potrzebują stron.
    Wyszukiwanie obejmuje nazwiska klienta i pracownika.
*/
function rezerwacje_list_paginated(
    array $filters = [],
    int $page = 1,
    int $size = 25
): ?array
{
    $pdo = rezerwacje_db();

    if ($pdo === null) {
        return null;
    }

    $page = pagination_page($page);
    $size = pagination_size($size);
    $filterData = rezerwacja_list_conditions($filters);
    $conditions = $filterData['conditions'];
    $params = $filterData['params'];
    $direction = $filterData['direction'];
    $where = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';
    $order = $direction === 'asc' ? 'ASC' : 'DESC';
    $countSql = 'SELECT COUNT(*) FROM rezerwacje r';

    if ($filterData['search'] !== '') {
        $countSql .= ' JOIN uzytkownicy u ON u.id = r.uzytkownik_id'
            . ' JOIN pracownicy p ON p.id = r.pracownik_id'
            . ' JOIN uzytkownicy pu ON pu.id = p.uzytkownik_id';
    }

    try {
        $statement = $pdo->prepare($countSql . $where);
        $statement->execute($params);
        $total = (int) $statement->fetchColumn();
    } catch (PDOException $e) {
        error_log('Nie udało się policzyć rezerwacji: ' . $e->getMessage());
        return null;
    }

    $pages = pagination_total_pages($total, $size);
    $page = min($page, $pages);
    $offset = pagination_offset($page, $size);

    try {
        $statement = $pdo->prepare(
            rezerwacja_select_sql()
            . $where
            . ' ORDER BY r.czas_rozpoczecia ' . $order . ', r.id ' . $order . ' '
            . pagination_limit_sql($size, $offset)
        );
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać listy rezerwacji: ' . $e->getMessage());
        return null;
    }

    $reservations = [];

    foreach ($rows as $row) {
        $reservations[] = rezerwacja_row($row);
    }

    return [
        'items' => $reservations,
        'total' => $total,
        'page' => $page,
        'size' => $size,
        'pages' => $pages,
    ];
}

function rezerwacja_find_by_id(int $id): ?array
{
    $pdo = rezerwacje_db();

    if ($id <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            rezerwacja_select_sql() . ' WHERE r.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać rezerwacji po id: ' . $e->getMessage());
        return null;
    }

    return is_array($row) ? rezerwacja_row($row) : null;
}

/*
    Status zmieniamy wyłącznie po dozwolonym przejściu i pod blokadą wiersza.
    CHECK chk_rezerwacje_anulowanie wymaga, aby anulowana miała anulowano,
    a każdy inny status miało puste. Dlatego status i anulowano zapisujemy
    zawsze w jednej instrukcji UPDATE, także przy zwykłej zmianie statusu.

    Przywrócenie anulowanej rezerwacji blokuje wiersz pracownika i sprawdza
    aktywność pracownika i usługi, przypisanie, grafik, nieobecności oraz
    konflikty z pozostałymi rezerwacjami — w międzyczasie ten termin mógł
    zostać zajęty albo stracić dostępność.
*/
function rezerwacja_set_status(int $id, string $targetStatus): array
{
    $pdo = rezerwacje_db();

    if ($id <= 0 || $pdo === null) {
        return ['status' => 'error'];
    }

    if (rezerwacja_status_value($targetStatus) === null) {
        return ['status' => 'invalid_transition'];
    }

    try {
        $pdo->beginTransaction();

        $statement = $pdo->prepare(
            'SELECT id, pracownik_id, usluga_id, czas_rozpoczecia, czas_zakonczenia, status_rezerwacji
             FROM rezerwacje
             WHERE id = :id
             FOR UPDATE'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            $pdo->rollBack();
            return ['status' => 'not_found'];
        }

        $currentStatus = (string) $row['status_rezerwacji'];

        if (!rezerwacja_transition_allowed($currentStatus, $targetStatus)) {
            $pdo->rollBack();
            return ['status' => 'invalid_transition'];
        }

        if ($currentStatus === 'anulowana' && $targetStatus === 'oczekujaca') {
            $employeeId = (int) $row['pracownik_id'];
            $serviceId = (int) $row['usluga_id'];
            $start = (string) $row['czas_rozpoczecia'];
            $end = (string) $row['czas_zakonczenia'];

            $statement = $pdo->prepare(
                'SELECT p.id, p.aktywny, u.aktywny AS konto_aktywne
                 FROM pracownicy p
                 JOIN uzytkownicy u ON u.id = p.uzytkownik_id
                 WHERE p.id = :id
                 FOR UPDATE'
            );
            $statement->execute(['id' => $employeeId]);
            $employee = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($employee)) {
                $pdo->rollBack();
                return ['status' => 'not_found'];
            }

            if ((int) $employee['aktywny'] !== 1 || (int) $employee['konto_aktywne'] !== 1) {
                $pdo->rollBack();
                return ['status' => 'conflict'];
            }

            $statement = $pdo->prepare(
                'SELECT
                    u.aktywna AS usluga_aktywna,
                    k.aktywna AS kategoria_aktywna,
                    up.aktywne AS przypisanie_aktywne
                 FROM uslugi u
                 JOIN kategorie_uslug k ON k.id = u.kategoria_id
                 LEFT JOIN uslugi_pracownikow up
                    ON up.usluga_id = u.id AND up.pracownik_id = :pracownik_id
                 WHERE u.id = :usluga_id
                 LIMIT 1'
            );
            $statement->execute([
                'pracownik_id' => $employeeId,
                'usluga_id' => $serviceId,
            ]);
            $service = $statement->fetch(PDO::FETCH_ASSOC);

            if (
                !is_array($service)
                || (int) $service['usluga_aktywna'] !== 1
                || (int) $service['kategoria_aktywna'] !== 1
                || $service['przypisanie_aktywne'] === null
                || (int) $service['przypisanie_aktywne'] !== 1
            ) {
                $pdo->rollBack();
                return ['status' => 'conflict'];
            }

            $intervalFree = availability_interval_is_free(
                $employeeId,
                substr($start, 0, 10),
                $start,
                $end,
                false
            );

            if ($intervalFree === null) {
                $pdo->rollBack();
                return ['status' => 'error'];
            }

            if (!$intervalFree) {
                $pdo->rollBack();
                return ['status' => 'conflict'];
            }
        }

        if ($targetStatus === 'anulowana') {
            $statement = $pdo->prepare(
                'UPDATE rezerwacje
                 SET status_rezerwacji = :status, anulowano = NOW()
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $id,
                'status' => $targetStatus,
            ]);
        } else {
            $statement = $pdo->prepare(
                'UPDATE rezerwacje
                 SET status_rezerwacji = :status, anulowano = NULL
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $id,
                'status' => $targetStatus,
            ]);
        }

        $statement = $pdo->prepare('SELECT anulowano FROM rezerwacje WHERE id = :id');
        $statement->execute(['id' => $id]);
        $cancelledAt = $statement->fetchColumn();
        $pdo->commit();

        return [
            'status' => 'ok',
            'id' => $id,
            'poprzedni_status' => $currentStatus,
            'nowy_status' => $targetStatus,
            'anulowano' => is_string($cancelledAt) && $cancelledAt !== '' ? $cancelledAt : null,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Nie udało się zmienić statusu rezerwacji: ' . $e->getMessage());
        return ['status' => 'error'];
    }
}
