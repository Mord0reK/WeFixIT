<?php
declare(strict_types=1);

/*
    Odczyt historii zmian statusu rezerwacji z tabeli log_zdarzen.

    Moduł pobiera zdarzenia pojedynczej rezerwacji lub paczki identyfikatorów
    widocznych w panelu klienta i pracownika, dekodując szczegóły JSON przez
    log_view_details_decode(). Nie zawiera HTML, nagłówków HTTP ani exit.
*/
require_once __DIR__ . '/../public/config.php';
require_once __DIR__ . '/log_view.php';

const REZERWACJA_HISTORY_ACTIONS = [
    'rezerwacja_utworzona',
    'rezerwacja_status_zmieniony',
    'rezerwacja_anulowana',
    'rezerwacja_anulowana_klient',
];

function rezerwacje_historia_db(): ?PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        error_log('Brak połączenia z bazą w module historii rezerwacji.');
        return null;
    }

    return $pdo;
}

function reservation_history_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'akcja' => (string) $row['akcja'],
        'poziom' => (string) $row['poziom'],
        'opis' => (string) $row['opis'],
        'utworzono' => (string) $row['utworzono'],
        'aktor_imie' => $row['aktor_imie'] !== null ? (string) $row['aktor_imie'] : null,
        'aktor_nazwisko' => $row['aktor_nazwisko'] !== null ? (string) $row['aktor_nazwisko'] : null,
        'szczegoly' => log_view_details_decode($row['szczegoly']),
    ];
}

function reservation_history(int $reservationId): ?array
{
    if ($reservationId <= 0) {
        return [];
    }

    $batch = reservation_history_batch([$reservationId]);

    if ($batch === null) {
        return null;
    }

    return $batch[$reservationId] ?? [];
}

/*
    Pobiera historię wielu rezerwacji w jednym zapytaniu, żeby widoki list nie
    wykonywały osobnego SELECT w pętli (N+1). Dla pustej listy zwraca pustą
    tablicę bez odpytywania bazy.
*/
function reservation_history_batch(array $reservationIds): ?array
{
    $ids = [];

    foreach ($reservationIds as $id) {
        if (is_int($id) && $id > 0) {
            $ids[] = $id;
        } elseif (is_string($id) && $id !== '' && ctype_digit($id)) {
            $parsed = (int) $id;

            if ($parsed > 0) {
                $ids[] = $parsed;
            }
        }
    }

    if ($ids === []) {
        return [];
    }

    sort($ids);
    $ids = array_values(array_unique($ids));
    $pdo = rezerwacje_historia_db();

    if ($pdo === null) {
        return null;
    }

    $placeholders = implode(', ', array_fill(0, count($ids), '?'));

    try {
        $statement = $pdo->prepare(
            "SELECT
                l.id,
                l.obiekt_id,
                l.akcja,
                l.poziom,
                l.opis,
                l.utworzono,
                u.imie AS aktor_imie,
                u.nazwisko AS aktor_nazwisko,
                l.szczegoly
             FROM log_zdarzen l
             LEFT JOIN uzytkownicy u ON u.id = l.aktor_id
             WHERE l.obiekt_typ = 'rezerwacja'
               AND l.akcja IN (
                   'rezerwacja_utworzona',
                   'rezerwacja_status_zmieniony',
                   'rezerwacja_anulowana',
                   'rezerwacja_anulowana_klient'
               )
               AND l.obiekt_id IN ($placeholders)
             ORDER BY l.utworzono ASC, l.id ASC"
        );
        $statement->execute($ids);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać historii rezerwacji: ' . $e->getMessage());
        return null;
    }

    $history = [];

    foreach ($rows as $row) {
        $objectId = (int) $row['obiekt_id'];
        $history[$objectId][] = reservation_history_row($row);
    }

    return $history;
}
