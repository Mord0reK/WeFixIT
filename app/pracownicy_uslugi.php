<?php
declare(strict_types=1);

/*
    Moduł danych przypisań usług do pracowników.

    Odczytuje aktywne usługi wraz ze stanem przypisania oraz zapisuje cały
    zbiór przypisań w jednej transakcji. Tabela rezerwacje ma klucz obcy do
    pary (pracownik_id, usluga_id) w uslugi_pracownikow, więc wiersza nie
    wolno usuwać: odłączanie to aktywne = FALSE, a ponowne przypisanie to
    UPDATE aktywne = TRUE. Dzięki temu rezerwacje historyczne pozostają spójne.
    Moduł nie zawiera HTML ani logiki HTTP.
*/
require_once __DIR__ . '/../public/config.php';

const PRACOWNIK_USLUGI_IDS_ERROR = 'Lista usług musi być tablicą dodatnich liczb całkowitych.';
const PRACOWNIK_USLUGI_UNAVAILABLE_ERROR = 'Wybór zawiera usługę niedostępną.';

function pracownicy_uslugi_db(): ?PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        error_log('Brak połączenia z bazą w module przypisań usług.');
        return null;
    }

    return $pdo;
}

// Zwraca unikalne identyfikatory usług albo null, gdy lista ma niepoprawną strukturę.
function pracownik_uslugi_ids_value(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }

    $ids = [];

    foreach ($value as $serviceId) {
        if (!is_int($serviceId) || $serviceId <= 0) {
            return null;
        }

        $ids[$serviceId] = $serviceId;
    }

    $ids = array_values($ids);
    sort($ids, SORT_NUMERIC);

    return $ids;
}

// Wszystkie aktywne usługi z informacją o bieżącym i historycznym przypisaniu.
function pracownik_uslugi_state(int $employeeId): ?array
{
    $pdo = pracownicy_uslugi_db();

    if ($employeeId <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT
                u.id,
                u.kategoria_id,
                k.nazwa AS kategoria_nazwa,
                u.nazwa,
                u.cena,
                u.czas_trwania_minuty,
                COALESCE(up.aktywne, FALSE) AS przypisana,
                (up.pracownik_id IS NOT NULL) AS kiedykolwiek_przypisana
             FROM uslugi u
             JOIN kategorie_uslug k ON k.id = u.kategoria_id
             LEFT JOIN uslugi_pracownikow up
                ON up.usluga_id = u.id AND up.pracownik_id = :pracownik_id
             WHERE u.aktywna = TRUE
             ORDER BY k.nazwa ASC, u.nazwa ASC'
        );
        $statement->execute(['pracownik_id' => $employeeId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać przypisań usług pracownika: ' . $e->getMessage());
        return null;
    }

    $services = [];

    foreach ($rows as $row) {
        $services[] = [
            'id' => (int) $row['id'],
            'kategoria_id' => (int) $row['kategoria_id'],
            'kategoria_nazwa' => (string) $row['kategoria_nazwa'],
            'nazwa' => (string) $row['nazwa'],
            'cena' => (string) $row['cena'],
            'czas_trwania_minuty' => (int) $row['czas_trwania_minuty'],
            'przypisana' => (int) $row['przypisana'] === 1,
            'kiedykolwiek_przypisana' => (int) $row['kiedykolwiek_przypisana'] === 1,
        ];
    }

    return $services;
}

// Licznik bieżących przypisań, spójny z licznikiem na liście pracowników.
function pracownik_uslugi_active_count(int $employeeId): ?int
{
    $pdo = pracownicy_uslugi_db();

    if ($employeeId <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM uslugi_pracownikow
             WHERE pracownik_id = :pracownik_id AND aktywne = TRUE'
        );
        $statement->execute(['pracownik_id' => $employeeId]);
        $count = $statement->fetchColumn();
    } catch (PDOException $e) {
        error_log('Nie udało się policzyć przypisań usług pracownika: ' . $e->getMessage());
        return null;
    }

    return (int) $count;
}

/*
    Zapisuje stan docelowy przypisań w jednej transakcji. Zwraca status:
    ok, not_found, invalid albo error. Wiersze są tylko dodawane lub
    aktualizowane — moduł nigdy nie wykonuje DELETE na uslugi_pracownikow.
*/
function pracownik_uslugi_save(int $employeeId, array $serviceIds): array
{
    $pdo = pracownicy_uslugi_db();
    $ids = pracownik_uslugi_ids_value($serviceIds);

    if ($employeeId <= 0 || $pdo === null || $ids === null) {
        return ['status' => 'error'];
    }

    try {
        $pdo->beginTransaction();

        // Blokada profilu serializuje równoległe zapisy przypisań tego pracownika.
        $statement = $pdo->prepare('SELECT id FROM pracownicy WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $employeeId]);

        if ($statement->fetch(PDO::FETCH_ASSOC) === false) {
            $pdo->rollBack();
            return ['status' => 'not_found'];
        }

        $activeIds = [];

        foreach ($pdo->query('SELECT id FROM uslugi WHERE aktywna = TRUE')->fetchAll(PDO::FETCH_COLUMN) as $activeId) {
            $activeIds[] = (int) $activeId;
        }

        $invalidIds = array_values(array_diff($ids, $activeIds));

        if ($invalidIds !== []) {
            $pdo->rollBack();
            return ['status' => 'invalid'];
        }

        $insert = $pdo->prepare(
            'INSERT INTO uslugi_pracownikow (pracownik_id, usluga_id, aktywne)
             VALUES (:pracownik_id, :usluga_id, TRUE)
             ON DUPLICATE KEY UPDATE aktywne = TRUE'
        );

        foreach ($ids as $serviceId) {
            $insert->execute([
                'pracownik_id' => $employeeId,
                'usluga_id' => $serviceId,
            ]);
        }

        $placeholders = [];
        $params = ['pracownik_id' => $employeeId];

        foreach ($ids as $index => $serviceId) {
            $key = 'usluga_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $serviceId;
        }

        $sql = 'UPDATE uslugi_pracownikow SET aktywne = FALSE WHERE pracownik_id = :pracownik_id';

        if ($placeholders !== []) {
            $sql .= ' AND usluga_id NOT IN (' . implode(', ', $placeholders) . ')';
        }

        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        $pdo->commit();

        return ['status' => 'ok', 'usluga_ids' => $ids];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Nie udało się zapisać przypisań usług pracownika: ' . $e->getMessage());
        return ['status' => 'error'];
    }
}
