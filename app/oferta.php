<?php
declare(strict_types=1);

/*
    Warstwa danych publicznej oferty usług.

    Zwraca wyłącznie aktywne usługi z aktywnych kategorii oraz pracowników
    przypisanych do usługi. Aktywny pracownik to aktywny rekord w tabeli
    pracownicy i aktywne konto w tabeli uzytkownicy. Nie zawiera HTML ani
    logiki HTTP.
*/
require_once __DIR__ . '/../public/config.php';

function oferta_db(): ?PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        error_log('Brak połączenia z bazą w module oferty.');
        return null;
    }

    return $pdo;
}

// Filtr kategorii: 0 oznacza brak filtra, a błędna wartość nie może wywołać błędu.
function oferta_category_filter(mixed $value): int
{
    return oferta_id_value($value) ?? 0;
}

function oferta_id_value(mixed $value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }

    if (is_string($value) && $value !== '' && ctype_digit($value)) {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    return null;
}

function oferta_kategorie(): ?array
{
    $pdo = oferta_db();

    if ($pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT
                k.id,
                k.nazwa,
                k.opis,
                COUNT(u.id) AS uslugi_active
             FROM kategorie_uslug k
             JOIN uslugi u
                ON u.kategoria_id = k.id AND u.aktywna = TRUE
             WHERE k.aktywna = TRUE
             GROUP BY k.id, k.nazwa, k.opis
             ORDER BY k.nazwa ASC, k.id ASC'
        );
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać kategorii oferty: ' . $e->getMessage());
        return null;
    }

    $categories = [];

    foreach ($rows as $row) {
        $categories[] = [
            'id' => (int) $row['id'],
            'nazwa' => (string) $row['nazwa'],
            'opis' => $row['opis'] !== null ? (string) $row['opis'] : null,
            'uslugi_active' => (int) $row['uslugi_active'],
        ];
    }

    return $categories;
}

function oferta_service_select_sql(): string
{
    return 'SELECT
                u.id,
                u.nazwa,
                u.opis,
                u.kategoria_id,
                k.nazwa AS kategoria_nazwa,
                u.cena,
                u.czas_trwania_minuty,
                (
                    SELECT COUNT(*)
                    FROM uslugi_pracownikow up
                    JOIN pracownicy p ON p.id = up.pracownik_id
                    JOIN uzytkownicy u2 ON u2.id = p.uzytkownik_id
                    WHERE up.usluga_id = u.id
                      AND up.aktywne = TRUE
                      AND p.aktywny = TRUE
                      AND u2.aktywny = TRUE
                ) AS pracownicy_active
             FROM uslugi u
             JOIN kategorie_uslug k ON k.id = u.kategoria_id';
}

function oferta_service_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'nazwa' => (string) $row['nazwa'],
        'opis' => $row['opis'] !== null ? (string) $row['opis'] : null,
        'kategoria_id' => (int) $row['kategoria_id'],
        'kategoria_nazwa' => (string) $row['kategoria_nazwa'],
        'cena' => (string) $row['cena'],
        'czas_trwania_minuty' => (int) $row['czas_trwania_minuty'],
        'pracownicy_active' => (int) $row['pracownicy_active'],
    ];
}

function oferta_uslugi(int $categoryId = 0): ?array
{
    $pdo = oferta_db();

    if ($pdo === null) {
        return null;
    }

    $conditions = [
        'u.aktywna = TRUE',
        'k.aktywna = TRUE',
    ];
    $params = [];

    if ($categoryId > 0) {
        $conditions[] = 'u.kategoria_id = :kategoria_id';
        $params['kategoria_id'] = $categoryId;
    }

    try {
        $statement = $pdo->prepare(
            oferta_service_select_sql()
            . ' WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY u.nazwa ASC, u.id ASC'
        );
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać usług oferty: ' . $e->getMessage());
        return null;
    }

    $services = [];

    foreach ($rows as $row) {
        $services[] = oferta_service_row($row);
    }

    return $services;
}

function oferta_usluga_find(int $id): ?array
{
    $pdo = oferta_db();

    if ($id <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            oferta_service_select_sql()
            . ' WHERE u.id = :id
                  AND u.aktywna = TRUE
                  AND k.aktywna = TRUE
                LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać usługi oferty: ' . $e->getMessage());
        return null;
    }

    return is_array($row) ? oferta_service_row($row) : null;
}

function oferta_pracownicy_uslugi(int $serviceId): ?array
{
    $pdo = oferta_db();

    if ($serviceId <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT u.imie, u.nazwisko, p.opis
             FROM uslugi_pracownikow up
             JOIN pracownicy p ON p.id = up.pracownik_id
             JOIN uzytkownicy u ON u.id = p.uzytkownik_id
             WHERE up.usluga_id = :usluga_id
               AND up.aktywne = TRUE
               AND p.aktywny = TRUE
               AND u.aktywny = TRUE
             ORDER BY u.nazwisko ASC, u.imie ASC, p.id ASC'
        );
        $statement->execute(['usluga_id' => $serviceId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać pracowników usługi oferty: ' . $e->getMessage());
        return null;
    }

    $employees = [];

    foreach ($rows as $row) {
        $employees[] = [
            'imie' => (string) $row['imie'],
            'nazwisko' => (string) $row['nazwisko'],
            'opis' => $row['opis'] !== null ? (string) $row['opis'] : null,
        ];
    }

    return $employees;
}
