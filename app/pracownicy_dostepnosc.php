<?php
declare(strict_types=1);

/*
    Moduł danych dostępności pracownika: tygodniowy grafik i nieobecności.

    Grafik obsługuje odczyt, dodawanie przedziału, zmianę flagi aktywności
    i fizyczne usunięcie wpisu. Tabela godziny_pracy nie jest celem żadnego
    klucza obcego z tabeli rezerwacje — rezerwacje wiążą się z parą
    (pracownik_id, usluga_id) w uslugi_pracownikow — dlatego usunięcie wpisu
    grafiku nie narusza historii rezerwacji.

    Nieobecności obsługują odczyt, utworzenie wniosku i zmianę statusu według
    jawnej maszyny stanów. Moduł nie zawiera HTML ani logiki HTTP.
*/
require_once __DIR__ . '/../public/config.php';

// Wspólne komunikaty, żeby endpoint i widok nie powielały tych samych tekstów.
const PRACOWNIK_GRAFIK_DAY_ERROR = 'Dzień tygodnia musi być liczbą całkowitą od 1 do 7.';
const PRACOWNIK_GRAFIK_TIME_ERROR = 'Podaj godzinę w formacie HH:MM lub HH:MM:SS.';
const PRACOWNIK_GRAFIK_ORDER_ERROR = 'Godzina zakończenia musi być późniejsza niż godzina rozpoczęcia.';
const PRACOWNIK_GRAFIK_OVERLAP_ERROR = 'Przedział nakłada się na inny aktywny przedział w tym dniu.';
const PRACOWNIK_GRAFIK_DUPLICATE_ERROR = 'Taki przedział już istnieje w grafiku tego dnia.';
const PRACOWNIK_GRAFIK_EMPLOYEE_ERROR = 'Podaj identyfikator pracownika.';
const PRACOWNIK_GRAFIK_ID_ERROR = 'Podaj identyfikator wpisu grafiku.';
const PRACOWNIK_GRAFIK_ACTIVE_ERROR = 'Pole aktywne musi być wartością logiczną.';

const PRACOWNIK_NIEOBECNOSC_EMPLOYEE_ERROR = 'Podaj identyfikator pracownika.';
const PRACOWNIK_NIEOBECNOSC_DATE_ERROR = 'Podaj poprawną datę w formacie RRRR-MM-DD.';
const PRACOWNIK_NIEOBECNOSC_RANGE_ERROR = 'Data rozpoczęcia nie może być późniejsza niż data zakończenia.';
const PRACOWNIK_NIEOBECNOSC_TYPE_ERROR = 'Wybierz rodzaj nieobecności: urlop, choroba lub inny.';
const PRACOWNIK_NIEOBECNOSC_REASON_ERROR = 'Powód nieobecności musi być tekstem o długości do 500 znaków.';
const PRACOWNIK_NIEOBECNOSC_OVERLAP_ERROR = 'W tym okresie istnieje już nieobecność, która nie została odrzucona ani anulowana.';
const PRACOWNIK_NIEOBECNOSC_STATUS_ERROR = 'Wybierz prawidłowy status nieobecności.';
const PRACOWNIK_NIEOBECNOSC_TRANSITION_ERROR = 'Nie można zmienić statusu nieobecności w ten sposób.';
const PRACOWNIK_NIEOBECNOSC_ID_ERROR = 'Podaj identyfikator nieobecności.';

// Allowlisty są jedynym źródłem dopuszczalnych rodzajów i statusów.
const PRACOWNIK_NIEOBECNOSC_TYPES = ['urlop', 'choroba', 'inny'];
const PRACOWNIK_NIEOBECNOSC_STATUSES = ['oczekujaca', 'zatwierdzona', 'odrzucona', 'anulowana'];
const PRACOWNIK_NIEOBECNOSC_TRANSITIONS = [
    'oczekujaca' => ['zatwierdzona', 'odrzucona', 'anulowana'],
    'zatwierdzona' => ['anulowana'],
    'odrzucona' => [],
    'anulowana' => [],
];

function pracownicy_dostepnosc_db(): ?PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        error_log('Brak połączenia z bazą w module dostępności pracownika.');
        return null;
    }

    return $pdo;
}

// ---------------------------------------------------------------------------
// Grafik tygodniowy
// ---------------------------------------------------------------------------

function pracownik_schedule_day_value(mixed $value): ?int
{
    if (!is_int($value) || $value < 1 || $value > 7) {
        return null;
    }

    return $value;
}

// Przyjmujemy wyłącznie pełny zapis HH:MM lub HH:MM:SS i normalizujemy go do TIME.
function pracownik_schedule_time_value(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);

    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $value) !== 1) {
        return null;
    }

    return strlen($value) === 5 ? $value . ':00' : $value;
}

function pracownik_schedule_errors(array $data): array
{
    $errors = [];
    $start = pracownik_schedule_time_value($data['czas_rozpoczecia'] ?? null);
    $end = pracownik_schedule_time_value($data['czas_zakonczenia'] ?? null);

    if (pracownik_schedule_day_value($data['dzien_tygodnia'] ?? null) === null) {
        $errors['dzien_tygodnia'] = PRACOWNIK_GRAFIK_DAY_ERROR;
    }

    if ($start === null) {
        $errors['czas_rozpoczecia'] = PRACOWNIK_GRAFIK_TIME_ERROR;
    }

    if ($end === null) {
        $errors['czas_zakonczenia'] = PRACOWNIK_GRAFIK_TIME_ERROR;
    }

    if ($start !== null && $end !== null && $start >= $end) {
        $errors['czas_zakonczenia'] = PRACOWNIK_GRAFIK_ORDER_ERROR;
    }

    return $errors;
}

// Wszystkie dni 1-7 są zawsze obecne w wyniku, także gdy nie mają wpisów.
function pracownik_schedule_list(int $employeeId): ?array
{
    $pdo = pracownicy_dostepnosc_db();

    if ($employeeId <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT id, dzien_tygodnia, czas_rozpoczecia, czas_zakonczenia, aktywne
             FROM godziny_pracy
             WHERE pracownik_id = :pracownik_id
             ORDER BY dzien_tygodnia ASC, czas_rozpoczecia ASC, id ASC'
        );
        $statement->execute(['pracownik_id' => $employeeId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać grafiku pracownika: ' . $e->getMessage());
        return null;
    }

    $schedule = array_fill(1, 7, []);

    foreach ($rows as $row) {
        $day = (int) $row['dzien_tygodnia'];

        if ($day < 1 || $day > 7) {
            continue;
        }

        $schedule[$day][] = [
            'id' => (int) $row['id'],
            'dzien_tygodnia' => $day,
            'czas_rozpoczecia' => (string) $row['czas_rozpoczecia'],
            'czas_zakonczenia' => (string) $row['czas_zakonczenia'],
            'aktywne' => (int) $row['aktywne'] === 1,
        ];
    }

    return $schedule;
}

function pracownik_schedule_find(int $id): ?array
{
    $pdo = pracownicy_dostepnosc_db();

    if ($id <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT id, pracownik_id, dzien_tygodnia, czas_rozpoczecia, czas_zakonczenia, aktywne
             FROM godziny_pracy
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać wpisu grafiku: ' . $e->getMessage());
        return null;
    }

    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'pracownik_id' => (int) $row['pracownik_id'],
        'dzien_tygodnia' => (int) $row['dzien_tygodnia'],
        'czas_rozpoczecia' => (string) $row['czas_rozpoczecia'],
        'czas_zakonczenia' => (string) $row['czas_zakonczenia'],
        'aktywne' => (int) $row['aktywne'] === 1,
    ];
}

/*
    Dodaje przedział w transakcji. Blokada profilu serializuje równoległe
    zapisy grafiku tego pracownika. Kolizję porównujemy w SQL natywnym dla
    TIME, bo MariaDB dopuszcza wartości spoza zakresu 00-23 (np. 24:00:00),
    a PHP-owa reguła obsługuje wyłącznie formaty dobowe. Dokładny duplikat
    celowo zostawiamy do naruszenia UNIQUE, żeby dostał odrębny komunikat.
*/
function pracownik_schedule_create(int $employeeId, array $data): array
{
    $pdo = pracownicy_dostepnosc_db();
    $day = pracownik_schedule_day_value($data['dzien_tygodnia'] ?? null);
    $start = pracownik_schedule_time_value($data['czas_rozpoczecia'] ?? null);
    $end = pracownik_schedule_time_value($data['czas_zakonczenia'] ?? null);

    if ($employeeId <= 0 || $pdo === null) {
        return ['status' => 'error'];
    }

    if ($day === null || $start === null || $end === null || $start >= $end) {
        return ['status' => 'invalid', 'errors' => pracownik_schedule_errors($data)];
    }

    try {
        $pdo->beginTransaction();

        $statement = $pdo->prepare('SELECT id FROM pracownicy WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $employeeId]);

        if ($statement->fetch(PDO::FETCH_ASSOC) === false) {
            $pdo->rollBack();
            return ['status' => 'not_found'];
        }

        $statement = $pdo->prepare(
            'SELECT id
             FROM godziny_pracy
             WHERE pracownik_id = :pracownik_id
               AND dzien_tygodnia = :dzien_tygodnia
               AND aktywne = TRUE
               AND czas_rozpoczecia < :czas_zakonczenia
               AND czas_zakonczenia > :czas_rozpoczecia
               AND NOT (
                   czas_rozpoczecia = :start_duplikatu
                   AND czas_zakonczenia = :koniec_duplikatu
               )
             LIMIT 1'
        );
        $statement->execute([
            'pracownik_id' => $employeeId,
            'dzien_tygodnia' => $day,
            'czas_rozpoczecia' => $start,
            'czas_zakonczenia' => $end,
            'start_duplikatu' => $start,
            'koniec_duplikatu' => $end,
        ]);

        if ($statement->fetch(PDO::FETCH_ASSOC) !== false) {
            $pdo->rollBack();
            return [
                'status' => 'invalid',
                'errors' => ['czas_rozpoczecia' => PRACOWNIK_GRAFIK_OVERLAP_ERROR],
            ];
        }

        $statement = $pdo->prepare(
            'INSERT INTO godziny_pracy
                (pracownik_id, dzien_tygodnia, czas_rozpoczecia, czas_zakonczenia, aktywne)
             VALUES
                (:pracownik_id, :dzien_tygodnia, :czas_rozpoczecia, :czas_zakonczenia, TRUE)'
        );
        $statement->execute([
            'pracownik_id' => $employeeId,
            'dzien_tygodnia' => $day,
            'czas_rozpoczecia' => $start,
            'czas_zakonczenia' => $end,
        ]);

        $scheduleId = (int) $pdo->lastInsertId();
        $pdo->commit();

        return ['status' => 'ok', 'id' => $scheduleId];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // UNIQUE uq_godziny_pracy_przedzial obejmuje dokładnie ten sam przedział.
        if ($e->getCode() === '23000') {
            return [
                'status' => 'invalid',
                'errors' => ['czas_rozpoczecia' => PRACOWNIK_GRAFIK_DUPLICATE_ERROR],
            ];
        }

        error_log('Nie udało się dodać przedziału grafiku: ' . $e->getMessage());
        return ['status' => 'error'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Nie udało się dodać przedziału grafiku: ' . $e->getMessage());
        return ['status' => 'error'];
    }
}

/*
    Reaktywacja musi sprawdzić kolizję, bo po dezaktywacji wpisu można dodać
    nowy przedział w tym samym czasie. Kolizję porównujemy w SQL natywnym dla
    TIME, żeby obsłużyć także istniejące wpisy z wartościami spoza 00-23.
    Blokada profilu serializuje równoległe zmiany, a blokada wpisu stabilizuje
    jego godziny do czasu UPDATE. Dezaktywacja nie wymaga sprawdzania kolizji.
*/
function pracownik_schedule_set_active(int $employeeId, int $id, bool $active): array
{
    $pdo = pracownicy_dostepnosc_db();

    if ($employeeId <= 0 || $id <= 0 || $pdo === null) {
        return ['status' => 'error'];
    }

    try {
        $pdo->beginTransaction();

        $statement = $pdo->prepare('SELECT id FROM pracownicy WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $employeeId]);

        if ($statement->fetch(PDO::FETCH_ASSOC) === false) {
            $pdo->rollBack();
            return ['status' => 'not_found'];
        }

        $statement = $pdo->prepare(
            'SELECT dzien_tygodnia, czas_rozpoczecia, czas_zakonczenia
             FROM godziny_pracy
             WHERE id = :id AND pracownik_id = :pracownik_id
             FOR UPDATE'
        );
        $statement->execute([
            'id' => $id,
            'pracownik_id' => $employeeId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            $pdo->rollBack();
            return ['status' => 'not_found'];
        }

        if ($active) {
            $statement = $pdo->prepare(
                'SELECT id
                 FROM godziny_pracy
                 WHERE pracownik_id = :pracownik_id
                   AND dzien_tygodnia = :dzien_tygodnia
                   AND aktywne = TRUE
                   AND id <> :id
                   AND czas_rozpoczecia < :czas_zakonczenia
                   AND czas_zakonczenia > :czas_rozpoczecia
                 LIMIT 1'
            );
            $statement->execute([
                'pracownik_id' => $employeeId,
                'dzien_tygodnia' => (int) $row['dzien_tygodnia'],
                'id' => $id,
                'czas_rozpoczecia' => (string) $row['czas_rozpoczecia'],
                'czas_zakonczenia' => (string) $row['czas_zakonczenia'],
            ]);

            if ($statement->fetch(PDO::FETCH_ASSOC) !== false) {
                $pdo->rollBack();
                return [
                    'status' => 'invalid',
                    'errors' => ['aktywne' => PRACOWNIK_GRAFIK_OVERLAP_ERROR],
                ];
            }
        }

        $statement = $pdo->prepare(
            'UPDATE godziny_pracy
             SET aktywne = :aktywne
             WHERE id = :id AND pracownik_id = :pracownik_id'
        );
        $statement->execute([
            'id' => $id,
            'pracownik_id' => $employeeId,
            'aktywne' => $active ? 1 : 0,
        ]);

        $pdo->commit();

        return ['status' => 'ok'];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Nie udało się zmienić aktywności wpisu grafiku: ' . $e->getMessage());
        return ['status' => 'error'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Nie udało się zmienić aktywności wpisu grafiku: ' . $e->getMessage());
        return ['status' => 'error'];
    }
}

// Fizyczne DELETE jest bezpieczne, bo rezerwacje nie mają klucza obcego do godziny_pracy.
// Istnienie wpisu i ownership sprawdza wcześniej pracownik_schedule_find() w endpointcie.
function pracownik_schedule_delete(int $employeeId, int $id): array
{
    $pdo = pracownicy_dostepnosc_db();

    if ($employeeId <= 0 || $id <= 0 || $pdo === null) {
        return ['status' => 'error'];
    }

    try {
        $statement = $pdo->prepare(
            'DELETE FROM godziny_pracy
             WHERE id = :id AND pracownik_id = :pracownik_id'
        );
        $statement->execute([
            'id' => $id,
            'pracownik_id' => $employeeId,
        ]);
    } catch (PDOException $e) {
        error_log('Nie udało się usunąć wpisu grafiku: ' . $e->getMessage());
        return ['status' => 'error'];
    }

    return ['status' => 'ok'];
}

// ---------------------------------------------------------------------------
// Nieobecności
// ---------------------------------------------------------------------------

function pracownik_absence_date_value(mixed $value): ?string
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

function pracownik_absence_type_value(mixed $value): ?string
{
    return is_string($value) && in_array($value, PRACOWNIK_NIEOBECNOSC_TYPES, true)
        ? $value
        : null;
}

function pracownik_absence_status_value(mixed $value): ?string
{
    return is_string($value) && in_array($value, PRACOWNIK_NIEOBECNOSC_STATUSES, true)
        ? $value
        : null;
}

function pracownik_absence_transition_allowed(string $current, string $target): bool
{
    $allowed = PRACOWNIK_NIEOBECNOSC_TRANSITIONS[$current] ?? [];

    return in_array($target, $allowed, true);
}

function pracownik_absence_overlap_exists(
    int $employeeId,
    string $from,
    string $to,
    ?int $ignoreId = null
): ?bool
{
    $pdo = pracownicy_dostepnosc_db();

    if ($employeeId <= 0 || $pdo === null) {
        return null;
    }

    $sql = "SELECT id
            FROM nieobecnosci
            WHERE pracownik_id = :pracownik_id
              AND status NOT IN ('odrzucona', 'anulowana')
              AND data_od <= :data_do
              AND data_do >= :data_od";
    $params = [
        'pracownik_id' => $employeeId,
        'data_od' => $from,
        'data_do' => $to,
    ];

    if ($ignoreId !== null && $ignoreId > 0) {
        $sql .= ' AND id <> :id';
        $params['id'] = $ignoreId;
    }

    $sql .= ' LIMIT 1';

    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się sprawdzić nakładania nieobecności: ' . $e->getMessage());
        return null;
    }

    return $row !== false;
}

function pracownik_absence_errors(array $data, int $employeeId = 0): array
{
    $errors = [];
    $from = pracownik_absence_date_value($data['data_od'] ?? null);
    $to = pracownik_absence_date_value($data['data_do'] ?? null);
    $type = pracownik_absence_type_value($data['rodzaj'] ?? null);

    if ($from === null) {
        $errors['data_od'] = PRACOWNIK_NIEOBECNOSC_DATE_ERROR;
    }

    if ($to === null) {
        $errors['data_do'] = PRACOWNIK_NIEOBECNOSC_DATE_ERROR;
    }

    if ($from !== null && $to !== null && $from > $to) {
        $errors['data_do'] = PRACOWNIK_NIEOBECNOSC_RANGE_ERROR;
    }

    if ($type === null) {
        $errors['rodzaj'] = PRACOWNIK_NIEOBECNOSC_TYPE_ERROR;
    }

    if (array_key_exists('powod', $data) && $data['powod'] !== null) {
        if (!is_string($data['powod']) || mb_strlen(trim($data['powod'])) > 500) {
            $errors['powod'] = PRACOWNIK_NIEOBECNOSC_REASON_ERROR;
        }
    }

    if ($errors === [] && $employeeId > 0) {
        $overlap = pracownik_absence_overlap_exists($employeeId, $from, $to);

        if ($overlap === null) {
            $errors['data_od'] = 'Nie udało się sprawdzić istniejących nieobecności.';
        } elseif ($overlap) {
            $errors['data_od'] = PRACOWNIK_NIEOBECNOSC_OVERLAP_ERROR;
        }
    }

    return $errors;
}

function pracownik_absences_list(int $employeeId): ?array
{
    $pdo = pracownicy_dostepnosc_db();

    if ($employeeId <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT
                n.id,
                n.data_od,
                n.data_do,
                n.rodzaj,
                n.status,
                n.powod,
                n.rozpatrzyl_id,
                n.rozpatrzono,
                n.utworzono,
                u.imie AS rozpatrzyl_imie,
                u.nazwisko AS rozpatrzyl_nazwisko
             FROM nieobecnosci n
             LEFT JOIN uzytkownicy u ON u.id = n.rozpatrzyl_id
             WHERE n.pracownik_id = :pracownik_id
             ORDER BY n.data_od DESC, n.id DESC'
        );
        $statement->execute(['pracownik_id' => $employeeId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać nieobecności pracownika: ' . $e->getMessage());
        return null;
    }

    $absences = [];

    foreach ($rows as $row) {
        $absences[] = [
            'id' => (int) $row['id'],
            'data_od' => (string) $row['data_od'],
            'data_do' => (string) $row['data_do'],
            'rodzaj' => (string) $row['rodzaj'],
            'status' => (string) $row['status'],
            'powod' => $row['powod'] !== null ? (string) $row['powod'] : null,
            'rozpatrzyl_id' => $row['rozpatrzyl_id'] !== null ? (int) $row['rozpatrzyl_id'] : null,
            'rozpatrzono' => $row['rozpatrzono'] !== null ? (string) $row['rozpatrzono'] : null,
            'utworzono' => (string) $row['utworzono'],
            'rozpatrzyl_imie' => $row['rozpatrzyl_imie'] !== null ? (string) $row['rozpatrzyl_imie'] : null,
            'rozpatrzyl_nazwisko' => $row['rozpatrzyl_nazwisko'] !== null ? (string) $row['rozpatrzyl_nazwisko'] : null,
        ];
    }

    return $absences;
}

function pracownik_absence_find(int $id): ?array
{
    $pdo = pracownicy_dostepnosc_db();

    if ($id <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT id, pracownik_id, data_od, data_do, rodzaj, status, powod,
                    rozpatrzyl_id, rozpatrzono, utworzono
             FROM nieobecnosci
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać nieobecności: ' . $e->getMessage());
        return null;
    }

    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'pracownik_id' => (int) $row['pracownik_id'],
        'data_od' => (string) $row['data_od'],
        'data_do' => (string) $row['data_do'],
        'rodzaj' => (string) $row['rodzaj'],
        'status' => (string) $row['status'],
        'powod' => $row['powod'] !== null ? (string) $row['powod'] : null,
        'rozpatrzyl_id' => $row['rozpatrzyl_id'] !== null ? (int) $row['rozpatrzyl_id'] : null,
        'rozpatrzono' => $row['rozpatrzono'] !== null ? (string) $row['rozpatrzono'] : null,
        'utworzono' => (string) $row['utworzono'],
    ];
}

/*
    Nowy wniosek zawsze jest oczekujący. Status i pola rozpatrzenia nie
    pochodzą z żądania, więc klient nie może zgłosić od razu zatwierdzonej
    nieobecności. Blokada profilu serializuje równoległe wnioski pracownika.
*/
function pracownik_absence_create(int $employeeId, array $data): array
{
    $pdo = pracownicy_dostepnosc_db();
    $from = pracownik_absence_date_value($data['data_od'] ?? null);
    $to = pracownik_absence_date_value($data['data_do'] ?? null);
    $type = pracownik_absence_type_value($data['rodzaj'] ?? null);

    if ($employeeId <= 0 || $pdo === null) {
        return ['status' => 'error'];
    }

    if ($from === null || $to === null || $type === null || $from > $to) {
        return ['status' => 'invalid', 'errors' => pracownik_absence_errors($data)];
    }

    $reason = null;

    if (isset($data['powod']) && is_string($data['powod'])) {
        $reason = trim($data['powod']);
        $reason = $reason !== '' ? $reason : null;
    }

    try {
        $pdo->beginTransaction();

        $statement = $pdo->prepare('SELECT id FROM pracownicy WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $employeeId]);

        if ($statement->fetch(PDO::FETCH_ASSOC) === false) {
            $pdo->rollBack();
            return ['status' => 'not_found'];
        }

        $overlap = pracownik_absence_overlap_exists($employeeId, $from, $to);

        if ($overlap === null) {
            $pdo->rollBack();
            return ['status' => 'error'];
        }

        if ($overlap) {
            $pdo->rollBack();
            return [
                'status' => 'overlap',
                'errors' => ['data_od' => PRACOWNIK_NIEOBECNOSC_OVERLAP_ERROR],
            ];
        }

        $statement = $pdo->prepare(
            "INSERT INTO nieobecnosci
                (pracownik_id, data_od, data_do, rodzaj, status, powod, rozpatrzyl_id, rozpatrzono)
             VALUES
                (:pracownik_id, :data_od, :data_do, :rodzaj, 'oczekujaca', :powod, NULL, NULL)"
        );
        $statement->execute([
            'pracownik_id' => $employeeId,
            'data_od' => $from,
            'data_do' => $to,
            'rodzaj' => $type,
            'powod' => $reason,
        ]);

        $absenceId = (int) $pdo->lastInsertId();
        $pdo->commit();

        return ['status' => 'ok', 'id' => $absenceId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Nie udało się utworzyć nieobecności: ' . $e->getMessage());
        return ['status' => 'error'];
    }
}

/*
    Status zmieniamy wyłącznie po dozwolonym przejściu i pod blokadą wiersza.
    CHECK chk_nieobecnosci_rozpatrzenie wymaga jednocześnie rozpatrzyl_id
    i rozpatrzono dla zatwierdzonej/odrzuconej oraz obu pustych dla anulowanej,
    dlatego oba pola zapisujemy w tej samej instrukcji UPDATE.

    Aktor pochodzi wyłącznie z sesji administratora sprawdzonej w endpointcie.
    rozpatrzyl_id nigdy nie jest czytany z body żądania, więc zatwierdzenie
    bez administratora w sesji jest niemożliwe.
*/
function pracownik_absence_set_status(int $id, string $targetStatus, int $actorId): array
{
    $pdo = pracownicy_dostepnosc_db();

    if ($id <= 0 || $actorId <= 0 || $pdo === null) {
        return ['status' => 'error'];
    }

    if (pracownik_absence_status_value($targetStatus) === null) {
        return ['status' => 'invalid_transition'];
    }

    try {
        $pdo->beginTransaction();

        $statement = $pdo->prepare(
            'SELECT id, pracownik_id, data_od, data_do, status
             FROM nieobecnosci
             WHERE id = :id
             FOR UPDATE'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            $pdo->rollBack();
            return ['status' => 'not_found'];
        }

        $currentStatus = (string) $row['status'];

        if (!pracownik_absence_transition_allowed($currentStatus, $targetStatus)) {
            $pdo->rollBack();
            return ['status' => 'invalid_transition'];
        }

        if ($targetStatus === 'zatwierdzona' || $targetStatus === 'odrzucona') {
            $statement = $pdo->prepare(
                'UPDATE nieobecnosci
                 SET status = :status, rozpatrzyl_id = :rozpatrzyl_id, rozpatrzono = NOW()
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $id,
                'status' => $targetStatus,
                'rozpatrzyl_id' => $actorId,
            ]);
        } else {
            $statement = $pdo->prepare(
                'UPDATE nieobecnosci
                 SET status = :status, rozpatrzyl_id = NULL, rozpatrzono = NULL
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $id,
                'status' => $targetStatus,
            ]);
        }

        $pdo->commit();

        return [
            'status' => 'ok',
            'id' => $id,
            'pracownik_id' => (int) $row['pracownik_id'],
            'data_od' => (string) $row['data_od'],
            'data_do' => (string) $row['data_do'],
            'poprzedni_status' => $currentStatus,
            'nowy_status' => $targetStatus,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Nie udało się zmienić statusu nieobecności: ' . $e->getMessage());
        return ['status' => 'error'];
    }
}

// Liczba rezerwacji w nakładającym się okresie, z pominięciem anulowanych.
function pracownik_absence_reservation_count(int $employeeId, string $from, string $to): ?int
{
    $pdo = pracownicy_dostepnosc_db();

    if ($employeeId <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM rezerwacje
             WHERE pracownik_id = :pracownik_id
               AND status_rezerwacji <> 'anulowana'
               AND DATE(czas_rozpoczecia) <= :data_do
               AND DATE(czas_zakonczenia) >= :data_od"
        );
        $statement->execute([
            'pracownik_id' => $employeeId,
            'data_od' => $from,
            'data_do' => $to,
        ]);
        $count = $statement->fetchColumn();
    } catch (PDOException $e) {
        error_log('Nie udało się policzyć rezerwacji w okresie nieobecności: ' . $e->getMessage());
        return null;
    }

    return (int) $count;
}

function pracownik_absence_warning_message(int $count): string
{
    return 'W okresie nieobecności zaplanowano ' . $count
        . ' rezerwacji. Zatwierdzenie nieobecności nie anuluje ich automatycznie.';
}

function pracownik_absence_warning(int $count): ?array
{
    if ($count <= 0) {
        return null;
    }

    return [
        'liczba_rezerwacji' => $count,
        'komunikat' => pracownik_absence_warning_message($count),
    ];
}
