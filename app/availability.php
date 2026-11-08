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

// Czas z grafiku jest zawsze zapisany jako MySQL TIME bez ułamków sekund.
function availability_time_seconds(string $value): ?int
{
    if (preg_match('/^(?:([01]\d|2[0-3])):([0-5]\d):([0-5]\d)$/D', $value, $matches) !== 1) {
        return null;
    }

    return (int) $matches[1] * 3600 + (int) $matches[2] * 60 + (int) $matches[3];
}

function availability_seconds_time(int $seconds): string
{
    return sprintf(
        '%02d:%02d:%02d',
        intdiv($seconds, 3600),
        intdiv($seconds % 3600, 60),
        $seconds % 60
    );
}

/*
    Zwraca status ok z listą slotów albo error, gdy dane wejściowe są błędne
    lub zapytanie do bazy się nie powiodło. Pusta lista slotów jest poprawnym
    wynikiem. Sloty są wyrównane do AVAILABILITY_SLOT_MINUTES od początku
    przedziału grafiku i muszą w całości mieścić się w jednym przedziale.
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
        return ['status' => 'error'];
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

    $reservations = [];

    foreach ($reservationRows as $row) {
        $reservations[] = [
            (string) $row['czas_rozpoczecia'],
            (string) $row['czas_zakonczenia'],
        ];
    }

    $nowValue = $now->format('Y-m-d H:i:s');
    $durationSeconds = $duration * 60;
    $stepSeconds = AVAILABILITY_SLOT_MINUTES * 60;
    $slots = [];

    foreach ($scheduleRows as $row) {
        $intervalStart = availability_time_seconds((string) $row['czas_rozpoczecia']);
        $intervalEnd = availability_time_seconds((string) $row['czas_zakonczenia']);

        if ($intervalStart === null || $intervalEnd === null || $intervalStart >= $intervalEnd) {
            continue;
        }

        for (
            $cursor = $intervalStart;
            $cursor + $durationSeconds <= $intervalEnd;
            $cursor += $stepSeconds
        ) {
            $slotStart = $dateValue . ' ' . availability_seconds_time($cursor);
            $slotEnd = $dateValue . ' ' . availability_seconds_time($cursor + $durationSeconds);

            if ($slotStart <= $nowValue) {
                continue;
            }

            $conflict = false;

            foreach ($reservations as [$reservationStart, $reservationEnd]) {
                if (intervals_overlap($slotStart, $slotEnd, $reservationStart, $reservationEnd)) {
                    $conflict = true;
                    break;
                }
            }

            if (!$conflict) {
                $slots[$slotStart] = ['start' => $slotStart, 'end' => $slotEnd];
            }
        }
    }

    ksort($slots, SORT_STRING);
    $result['sloty'] = array_values($slots);

    return $result;
}
