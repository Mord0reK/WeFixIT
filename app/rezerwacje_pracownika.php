<?php
declare(strict_types=1);

/*
    Moduł rezerwacji dla panelu pracownika.

    Udostępnia wyszukanie profilu pracownika dla zalogowanego konta, rozłączny
    podział wizyt na dzisiaj, nadchodzące i historię, odczyt pojedynczej
    rezerwacji z weryfikacją przypisanego pracownika oraz zmianę statusu pod
    blokadą wiersza. Moduł nie zawiera HTML, nagłówków HTTP ani exit.
*/
require_once __DIR__ . '/../public/config.php';
require_once __DIR__ . '/rezerwacje.php';

function rezerwacje_pracownika_db(): ?PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        error_log('Brak połączenia z bazą w module rezerwacji pracownika.');
        return null;
    }

    return $pdo;
}

function employee_profile_id(int $userId): ?int
{
    $pdo = rezerwacje_pracownika_db();

    if ($userId <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT id FROM pracownicy WHERE uzytkownik_id = :uzytkownik_id LIMIT 1'
        );
        $statement->execute(['uzytkownik_id' => $userId]);
        $id = $statement->fetchColumn();
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać profilu pracownika: ' . $e->getMessage());
        return null;
    }

    if ($id === false) {
        return null;
    }

    $profileId = (int) $id;

    return $profileId > 0 ? $profileId : null;
}

function employee_reservation_select_sql(): string
{
    return 'SELECT
                r.id,
                r.czas_rozpoczecia,
                r.czas_zakonczenia,
                r.cena_historyczna,
                r.status_rezerwacji,
                r.komentarz_klienta,
                r.anulowano,
                s.nazwa AS usluga_nazwa,
                u.imie AS klient_imie,
                u.nazwisko AS klient_nazwisko,
                u.telefon AS klient_telefon
             FROM rezerwacje r
             JOIN uzytkownicy u ON u.id = r.uzytkownik_id
             JOIN uslugi s ON s.id = r.usluga_id';
}

function employee_reservation_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'czas_rozpoczecia' => (string) $row['czas_rozpoczecia'],
        'czas_zakonczenia' => (string) $row['czas_zakonczenia'],
        'cena_historyczna' => (string) $row['cena_historyczna'],
        'status_rezerwacji' => (string) $row['status_rezerwacji'],
        'komentarz_klienta' => $row['komentarz_klienta'] !== null
            ? (string) $row['komentarz_klienta']
            : null,
        'anulowano' => $row['anulowano'] !== null ? (string) $row['anulowano'] : null,
        'usluga_nazwa' => (string) $row['usluga_nazwa'],
        'klient_imie' => (string) $row['klient_imie'],
        'klient_nazwisko' => (string) $row['klient_nazwisko'],
        'klient_telefon' => (string) $row['klient_telefon'],
    ];
}

/*
    Wszystkie wizyty pracownika pobieramy jednym zapytaniem i dzielimy w PHP,
    żeby sekcje pochodziły z jednego stanu bazy i były rozłączne: dzisiejsze
    wizyty trafiają wyłącznie do klucza dzis niezależnie od statusu, przyszłe
    aktywne wizyty od jutra do przyszle, a pozostałe rekordy do historii.
*/
function employee_reservations(int $employeeId): ?array
{
    $pdo = rezerwacje_pracownika_db();

    if ($employeeId <= 0 || $pdo === null) {
        return null;
    }

    $todayStart = (new DateTimeImmutable('today'))->format('Y-m-d 00:00:00');
    $tomorrowStart = (new DateTimeImmutable('tomorrow'))->format('Y-m-d 00:00:00');
    $today = [];
    $future = [];
    $history = [];

    try {
        $statement = $pdo->prepare(
            employee_reservation_select_sql()
            . ' WHERE r.pracownik_id = :pracownik_id
                ORDER BY r.czas_rozpoczecia ASC, r.id ASC'
        );
        $statement->execute(['pracownik_id' => $employeeId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać wizyt pracownika: ' . $e->getMessage());
        return null;
    }

    foreach ($rows as $row) {
        $reservation = employee_reservation_row($row);
        $start = (string) $reservation['czas_rozpoczecia'];
        $status = (string) $reservation['status_rezerwacji'];

        if ($start >= $todayStart && $start < $tomorrowStart) {
            $today[] = $reservation;
            continue;
        }

        if (
            $start >= $tomorrowStart
            && in_array($status, ['oczekujaca', 'potwierdzona', 'w_trakcie'], true)
        ) {
            $future[] = $reservation;
            continue;
        }

        $history[] = $reservation;
    }

    return [
        'dzis' => $today,
        'przyszle' => $future,
        'historia' => array_reverse($history),
    ];
}

function employee_reservation_find(int $employeeId, int $reservationId): ?array
{
    $pdo = rezerwacje_pracownika_db();

    if ($employeeId <= 0 || $reservationId <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            employee_reservation_select_sql()
            . ' WHERE r.pracownik_id = :pracownik_id AND r.id = :id LIMIT 1'
        );
        $statement->execute([
            'pracownik_id' => $employeeId,
            'id' => $reservationId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać rezerwacji pracownika: ' . $e->getMessage());
        return null;
    }

    return is_array($row) ? employee_reservation_row($row) : null;
}

/*
    Pracownik zmienia status wyłącznie własnej rezerwacji. Cudza rezerwacja
    zwraca not_found tak samo jak nieistniejąca, żeby nie ujawniać istnienia
    rekordów innych pracowników. Status i anulowano zapisujemy w jednej
    instrukcji UPDATE zgodnie z ograniczeniem chk_rezerwacje_anulowanie.
*/
function employee_reservation_set_status(
    int $employeeId,
    int $reservationId,
    string $targetStatus
): array {
    $pdo = rezerwacje_pracownika_db();

    if ($employeeId <= 0 || $reservationId <= 0 || $pdo === null) {
        return ['status' => 'error'];
    }

    if (rezerwacja_status_value($targetStatus) === null) {
        return ['status' => 'invalid_transition'];
    }

    try {
        $pdo->beginTransaction();

        $statement = $pdo->prepare(
            'SELECT id, pracownik_id, status_rezerwacji
             FROM rezerwacje
             WHERE id = :id
             FOR UPDATE'
        );
        $statement->execute(['id' => $reservationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || (int) $row['pracownik_id'] !== $employeeId) {
            $pdo->rollBack();
            return ['status' => 'not_found'];
        }

        $currentStatus = (string) $row['status_rezerwacji'];

        if (!rezerwacja_transition_allowed($currentStatus, $targetStatus)) {
            $pdo->rollBack();
            return ['status' => 'invalid_transition'];
        }

        if ($targetStatus === 'anulowana') {
            $statement = $pdo->prepare(
                'UPDATE rezerwacje
                 SET status_rezerwacji = :status, anulowano = NOW()
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $reservationId,
                'status' => $targetStatus,
            ]);
        } else {
            $statement = $pdo->prepare(
                'UPDATE rezerwacje
                 SET status_rezerwacji = :status, anulowano = NULL
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $reservationId,
                'status' => $targetStatus,
            ]);
        }

        $statement = $pdo->prepare('SELECT anulowano FROM rezerwacje WHERE id = :id');
        $statement->execute(['id' => $reservationId]);
        $cancelledAt = $statement->fetchColumn();
        $pdo->commit();

        return [
            'status' => 'ok',
            'id' => $reservationId,
            'poprzedni_status' => $currentStatus,
            'nowy_status' => $targetStatus,
            'anulowano' => is_string($cancelledAt) && $cancelledAt !== '' ? $cancelledAt : null,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Nie udało się zmienić statusu rezerwacji pracownika: ' . $e->getMessage());
        return ['status' => 'error'];
    }
}
