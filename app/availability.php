<?php
declare(strict_types=1);

/*
    Kalkulator wolnych terminów dla pary pracownik + usługa w jednym dniu.

    Moduł liczy sloty z aktywnych przedziałów grafiku, czasu trwania usługi,
    zatwierdzonych nieobecności i rezerwacji. Zakłada, że wywołujący sprawdził
    już aktywność usługi i pracownika oraz aktywne przypisanie w tabeli
    uslugi_pracownikow — te uprawnienia i zależności należą do endpointów
    rezerwacji, a nie do samego kalkulatora. Moduł nie zawiera HTML ani logiki
    HTTP.
*/
require_once __DIR__ . '/../public/config.php';
require_once __DIR__ . '/przedzialy.php';

const AVAILABILITY_SLOT_MINUTES = 15;

// Horyzont ogranicza liczbę generowanych slotów i zapobiega rezerwacjom
// odległym w czasie, dla których grafik może się jeszcze zmienić.
const AVAILABILITY_HORIZON_DAYS = 14;

function availability_db(): ?PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        error_log('Brak połączenia z bazą w module dostępności terminów.');
        return null;
    }

    return $pdo;
}

// Pełny zapis YYYY-MM-DD i data rzeczywista, tak samo jak w pozostałych modułach.
function availability_date_value(mixed $value): ?string
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

/*
    Sprawdza bezpośrednio żądany przedział DATETIME. Weryfikuje aktywny grafik,
    wyrównanie do siatki, zatwierdzone nieobecności i konflikty rezerwacji.
    Opcjonalne $rejectPast pozwala administratorowi przywrócić historyczną
    rezerwację, ale nadal sprawdza jej grafik, nieobecności i konflikty.
*/
function availability_interval_is_free(
    int $employeeId,
    string $date,
    string $start,
    string $end,
    bool $rejectPast = true
): ?bool {
    $pdo = availability_db();
    $startDate = substr($start, 0, 10);
    $startTime = substr($start, 11, 8);
    $endDate = substr($end, 0, 10);
    $endTime = substr($end, 11, 8);
    $startSeconds = interval_time_seconds($startTime);
    $endSeconds = interval_time_seconds($endTime);

    if (
        $employeeId <= 0
        || $pdo === null
        || interval_format($start) !== 'datetime'
        || interval_format($end) !== 'datetime'
        || $startDate !== $date
        || $startSeconds === null
        || $endSeconds === null
    ) {
        return null;
    }

    if ($endDate !== $date) {
        $nextDate = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');

        if ($endDate !== $nextDate || $endSeconds !== 0) {
            return false;
        }

        $endSeconds = 86400;
    }

    if ($endSeconds <= $startSeconds) {
        return false;
    }

    if ($rejectPast && $start <= (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')) {
        return false;
    }

    $dayOfWeek = (int) (new DateTimeImmutable($date))->format('N');
    $stepSeconds = AVAILABILITY_SLOT_MINUTES * 60;

    try {
        $statement = $pdo->prepare(
            'SELECT czas_rozpoczecia, czas_zakonczenia
             FROM godziny_pracy
             WHERE pracownik_id = :pracownik_id
               AND dzien_tygodnia = :dzien_tygodnia
               AND aktywne = TRUE
             ORDER BY czas_rozpoczecia ASC, id ASC'
        );
        $statement->execute([
            'pracownik_id' => $employeeId,
            'dzien_tygodnia' => $dayOfWeek,
        ]);

        $insideSchedule = false;

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $intervalStart = interval_time_seconds((string) $row['czas_rozpoczecia']);
            $intervalEnd = interval_time_seconds((string) $row['czas_zakonczenia']);

            if ($intervalStart === null || $intervalEnd === null || $intervalStart >= $intervalEnd) {
                continue;
            }

            if ($startSeconds < $intervalStart || $endSeconds > $intervalEnd) {
                continue;
            }

            if (($startSeconds - $intervalStart) % $stepSeconds !== 0) {
                continue;
            }

            $insideSchedule = true;
            break;
        }

        if (!$insideSchedule) {
            return false;
        }

        $statement = $pdo->prepare(
            "SELECT 1
             FROM nieobecnosci
             WHERE pracownik_id = :pracownik_id
               AND status = 'zatwierdzona'
               AND data_od <= :data
               AND data_do >= :data
             LIMIT 1"
        );
        $statement->execute([
            'pracownik_id' => $employeeId,
            'data' => $date,
        ]);

        if ($statement->fetchColumn() !== false) {
            return false;
        }

        $statement = $pdo->prepare(
            "SELECT 1
             FROM rezerwacje
             WHERE pracownik_id = :pracownik_id
               AND status_rezerwacji <> 'anulowana'
               AND czas_rozpoczecia < :czas_zakonczenia
               AND czas_zakonczenia > :czas_rozpoczecia
             LIMIT 1"
        );
        $statement->execute([
            'pracownik_id' => $employeeId,
            'czas_rozpoczecia' => $start,
            'czas_zakonczenia' => $end,
        ]);

        return $statement->fetchColumn() === false;
    } catch (PDOException $e) {
        error_log('Nie udało się sprawdzić przedziału dostępności: ' . $e->getMessage());
        return null;
    }
}

/*
    Zwraca status ok z listą slotów, not_found gdy usługa nie istnieje, albo
    error przy błędnych danych wejściowych lub błędzie bazy. Endpoint rezerwacji
    musi zmapować not_found na 404 lub 422, a error na 500. Pusta lista slotów
    jest poprawnym wynikiem. Sloty buduje interval_slots_build(), więc są
    wyrównane do AVAILABILITY_SLOT_MINUTES od początku przedziału grafiku,
    mieszczą się w całości w jednym przedziale i nie nachodzą na siebie.
*/
function availability_day(int $employeeId, int $serviceId, string $date): array
{
    $pdo = availability_db();
    $dateValue = availability_date_value($date);

    if ($employeeId <= 0 || $serviceId <= 0 || $dateValue === null || $pdo === null) {
        return ['status' => 'error'];
    }

    try {
        $statement = $pdo->prepare(
            'SELECT czas_trwania_minuty, aktywna
             FROM uslugi
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $serviceId]);
        $service = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać usługi do kalkulatora terminów: ' . $e->getMessage());
        return ['status' => 'error'];
    }

    if (!is_array($service)) {
        return ['status' => 'not_found'];
    }

    $duration = (int) $service['czas_trwania_minuty'];

    if ($duration <= 0) {
        return ['status' => 'error'];
    }

    $result = [
        'status' => 'ok',
        'data' => $dateValue,
        'czas_trwania_minuty' => $duration,
        'sloty' => [],
    ];

    // Endpoint i tak weryfikuje parę, ale kalkulator nie powinien proponować
    // terminów dla usługi, która została w międzyczasie dezaktywowana.
    if ((int) $service['aktywna'] !== 1) {
        return $result;
    }

    $now = new DateTimeImmutable('now');
    $today = $now->format('Y-m-d');
    $horizonEnd = $now
        ->modify('+' . (AVAILABILITY_HORIZON_DAYS - 1) . ' days')
        ->format('Y-m-d');

    if ($dateValue < $today || $dateValue > $horizonEnd) {
        return $result;
    }

    $day = new DateTimeImmutable($dateValue);
    $dayOfWeek = (int) $day->format('N');
    $dayStart = $dateValue . ' 00:00:00';
    $dayEnd = $day->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

    try {
        $statement = $pdo->prepare(
            'SELECT czas_rozpoczecia, czas_zakonczenia
             FROM godziny_pracy
             WHERE pracownik_id = :pracownik_id
               AND dzien_tygodnia = :dzien_tygodnia
               AND aktywne = TRUE
             ORDER BY czas_rozpoczecia ASC, id ASC'
        );
        $statement->execute([
            'pracownik_id' => $employeeId,
            'dzien_tygodnia' => $dayOfWeek,
        ]);
        $scheduleRows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $statement = $pdo->prepare(
            "SELECT 1
             FROM nieobecnosci
             WHERE pracownik_id = :pracownik_id
               AND status = 'zatwierdzona'
               AND data_od <= :data
               AND data_do >= :data
             LIMIT 1"
        );
        $statement->execute([
            'pracownik_id' => $employeeId,
            'data' => $dateValue,
        ]);

        if ($statement->fetchColumn() !== false) {
            return $result;
        }

        $statement = $pdo->prepare(
            "SELECT czas_rozpoczecia, czas_zakonczenia
             FROM rezerwacje
             WHERE pracownik_id = :pracownik_id
               AND status_rezerwacji <> 'anulowana'
               AND czas_rozpoczecia < :dzien_koniec
               AND czas_zakonczenia > :dzien_start
             ORDER BY czas_rozpoczecia ASC, id ASC"
        );
        $statement->execute([
            'pracownik_id' => $employeeId,
            'dzien_start' => $dayStart,
            'dzien_koniec' => $dayEnd,
        ]);
        $reservationRows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać danych kalendarza pracownika: ' . $e->getMessage());
        return ['status' => 'error'];
    }

    $scheduleIntervals = [];

    foreach ($scheduleRows as $row) {
        $scheduleIntervals[] = [
            'start' => (string) $row['czas_rozpoczecia'],
            'end' => (string) $row['czas_zakonczenia'],
        ];
    }

    $busyIntervals = [];

    foreach ($reservationRows as $row) {
        $busyIntervals[] = [
            'start' => (string) $row['czas_rozpoczecia'],
            'end' => (string) $row['czas_zakonczenia'],
        ];
    }

    $result['sloty'] = interval_slots_build(
        $scheduleIntervals,
        $busyIntervals,
        $dateValue,
        $duration,
        AVAILABILITY_SLOT_MINUTES,
        $now->format('Y-m-d H:i:s')
    );

    return $result;
}
