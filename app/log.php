<?php
declare(strict_types=1);

/*
    Wspólny zapis zdarzeń do tabeli log_zdarzen.

    Log nigdy nie przerywa operacji biznesowej: błąd zapisu trafia wyłącznie
    do error_log(). Funkcja nie przyjmuje haseł, tokenów ani pełnych danych
    osobowych — szczegóły przekazuje wyłącznie wywołujący endpoint.
*/
require_once __DIR__ . '/../public/config.php';

function log_event(
    ?int $actorId,
    string $action,
    string $description,
    string $level = 'info',
    ?string $objectType = null,
    ?int $objectId = null,
    array $details = []
): void
{
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        error_log('Brak połączenia z bazą w module logu.');
        return;
    }

    $action = mb_substr(trim($action), 0, 80);
    $description = mb_substr(trim($description), 0, 500);

    if ($action === '' || $description === '') {
        error_log('Pominięto zdarzenie logu bez akcji lub opisu.');
        return;
    }

    if (!in_array($level, ['info', 'warning', 'critical'], true)) {
        $level = 'info';
    }

    $objectType = $objectType !== null ? mb_substr(trim($objectType), 0, 40) : null;

    // CHECK chk_log_zdarzen_obiekt wymaga obu wartości albo żadnej.
    if ($objectType === null || $objectType === '' || $objectId === null || $objectId <= 0) {
        $objectType = null;
        $objectId = null;
    }

    $detailsJson = $details === []
        ? null
        : json_encode($details, JSON_UNESCAPED_UNICODE);

    if ($detailsJson === false) {
        $detailsJson = null;
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO log_zdarzen (aktor_id, akcja, obiekt_typ, obiekt_id, poziom, opis, szczegoly)
             VALUES (:aktor_id, :akcja, :obiekt_typ, :obiekt_id, :poziom, :opis, :szczegoly)'
        );
        $statement->execute([
            'aktor_id' => $actorId,
            'akcja' => $action,
            'obiekt_typ' => $objectType,
            'obiekt_id' => $objectId,
            'poziom' => $level,
            'opis' => $description,
            'szczegoly' => $detailsJson,
        ]);
    } catch (Throwable $e) {
        error_log('Nie udało się zapisać zdarzenia w logu: ' . $e->getMessage());
    }
}
