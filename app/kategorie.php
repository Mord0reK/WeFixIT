<?php
declare(strict_types=1);

/*
    Moduł danych i walidacji kategorii usług.

    Odpowiada za odczyt listy z liczbą usług, zapis nazwy i opisu, zmianę
    flagi aktywności oraz usuwanie kategorii. Nie zawiera HTML ani logiki HTTP.
    Reguły walidacji zwracają mapę błędów przypisanych do pól.
*/
require_once __DIR__ . '/../public/config.php';

// Wspólne komunikaty, żeby endpoint i widok nie powielały tych samych tekstów.
const KATEGORIA_NAME_TAKEN_ERROR = 'Kategoria o tej nazwie już istnieje.';
const KATEGORIA_HAS_SERVICES_ERROR = 'Kategoria ma przypisane usługi. Zamiast usuwać, dezaktywuj ją.';

function kategorie_db(): ?PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        error_log('Brak połączenia z bazą w module kategorii.');
        return null;
    }

    return $pdo;
}

// Pola tekstowe przycinamy; brak wartości traktujemy jak pusty string.
function kategoria_field(array $data, string $field): string
{
    $value = $data[$field] ?? null;

    if (!is_string($value)) {
        return '';
    }

    return trim($value);
}

// Walidacja nazwy nie rzuca wyjątków; problem z bazą też trafia do mapy błędów pola.
function kategoria_name_errors(mixed $nazwa, ?int $ignoreId = null): array
{
    if (!is_string($nazwa)) {
        return ['nazwa' => 'Podaj nazwę kategorii.'];
    }

    $nazwa = trim($nazwa);

    if ($nazwa === '') {
        return ['nazwa' => 'Podaj nazwę kategorii.'];
    }

    if (mb_strlen($nazwa) > 100) {
        return ['nazwa' => 'Nazwa kategorii może mieć maksymalnie 100 znaków.'];
    }

    $pdo = kategorie_db();

    if ($pdo === null) {
        return ['nazwa' => 'Nie udało się sprawdzić nazwy kategorii.'];
    }

    try {
        $sql = 'SELECT id FROM kategorie_uslug WHERE nazwa = :nazwa';
        $params = ['nazwa' => $nazwa];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }

        $sql .= ' LIMIT 1';

        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        if ($statement->fetch(PDO::FETCH_ASSOC) !== false) {
            return ['nazwa' => KATEGORIA_NAME_TAKEN_ERROR];
        }
    } catch (PDOException $e) {
        error_log('Nie udało się sprawdzić unikalności kategorii: ' . $e->getMessage());
        return ['nazwa' => 'Nie udało się sprawdzić nazwy kategorii.'];
    }

    return [];
}

function kategoria_description_errors(mixed $opis): array
{
    if ($opis === null) {
        return [];
    }

    if (!is_string($opis)) {
        return ['opis' => 'Opis kategorii musi być tekstem.'];
    }

    if (mb_strlen(trim($opis)) > 2000) {
        return ['opis' => 'Opis kategorii może mieć maksymalnie 2000 znaków.'];
    }

    return [];
}

// Wspólna walidacja kompletnego zestawu pól dla utworzenia kategorii.
function kategoria_errors(array $data, ?int $ignoreId = null): array
{
    return kategoria_name_errors($data['nazwa'] ?? null, $ignoreId)
        + kategoria_description_errors($data['opis'] ?? null);
}

// Lista wszystkich kategorii wraz z liczbą przypisanych usług, także nieaktywnych.
function kategorie_list(): ?array
{
    $pdo = kategorie_db();

    if ($pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->query(
            'SELECT
                k.id,
                k.nazwa,
                k.opis,
                k.aktywna,
                COUNT(u.id) AS uslugi_total,
                COALESCE(SUM(CASE WHEN u.aktywna = TRUE THEN 1 ELSE 0 END), 0) AS uslugi_active
             FROM kategorie_uslug k
             LEFT JOIN uslugi u ON u.kategoria_id = k.id
             GROUP BY k.id, k.nazwa, k.opis, k.aktywna
             ORDER BY k.nazwa ASC'
        );
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać listy kategorii: ' . $e->getMessage());
        return null;
    }

    $categories = [];

    foreach ($rows as $row) {
        $categories[] = [
            'id' => (int) $row['id'],
            'nazwa' => (string) $row['nazwa'],
            'opis' => $row['opis'] !== null ? (string) $row['opis'] : null,
            'aktywna' => (int) $row['aktywna'] === 1,
            'uslugi_total' => (int) $row['uslugi_total'],
            'uslugi_active' => (int) $row['uslugi_active'],
        ];
    }

    return $categories;
}

function kategoria_find_by_id(int $id): ?array
{
    $pdo = kategorie_db();

    if ($id <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT id, nazwa, opis, aktywna
             FROM kategorie_uslug
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać kategorii po id: ' . $e->getMessage());
        return null;
    }

    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'nazwa' => (string) $row['nazwa'],
        'opis' => $row['opis'] !== null ? (string) $row['opis'] : null,
        'aktywna' => (int) $row['aktywna'] === 1,
    ];
}

// Nowa kategoria zawsze startuje jako aktywna.
function kategoria_create(array $data): int
{
    $pdo = kategorie_db();

    if ($pdo === null) {
        throw new RuntimeException('Brak połączenia z bazą.');
    }

    $opis = kategoria_field($data, 'opis');

    // PDOException celowo propagujemy: endpoint rozpoznaje 23000 jako duplikat nazwy.
    $statement = $pdo->prepare(
        'INSERT INTO kategorie_uslug (nazwa, opis, aktywna)
         VALUES (:nazwa, :opis, TRUE)'
    );
    $statement->execute([
        'nazwa' => kategoria_field($data, 'nazwa'),
        'opis' => $opis !== '' ? $opis : null,
    ]);

    return (int) $pdo->lastInsertId();
}

// Aktualizuje nazwę, opis i flagę aktywności w jednym zapisie.
function kategoria_update(int $id, array $data): bool
{
    $pdo = kategorie_db();

    if ($id <= 0 || $pdo === null) {
        return false;
    }

    $opis = kategoria_field($data, 'opis');
    $active = (bool) ($data['aktywna'] ?? true);

    $statement = $pdo->prepare(
        'UPDATE kategorie_uslug
         SET nazwa = :nazwa, opis = :opis, aktywna = :aktywna
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $id,
        'nazwa' => kategoria_field($data, 'nazwa'),
        'opis' => $opis !== '' ? $opis : null,
        'aktywna' => $active ? 1 : 0,
    ]);

    return true;
}

function kategoria_set_active(int $id, bool $active): bool
{
    $pdo = kategorie_db();

    if ($id <= 0 || $pdo === null) {
        return false;
    }

    try {
        $statement = $pdo->prepare('UPDATE kategorie_uslug SET aktywna = :aktywna WHERE id = :id');
        $statement->execute([
            'id' => $id,
            'aktywna' => $active ? 1 : 0,
        ]);
    } catch (PDOException $e) {
        error_log('Nie udało się zmienić aktywności kategorii: ' . $e->getMessage());
        return false;
    }

    return true;
}

// Sprawdzenie przed DELETE daje czytelny komunikat; wyścig rozstrzyga klucz obcy.
function kategoria_has_services(int $id): ?bool
{
    $pdo = kategorie_db();

    if ($id <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM uslugi WHERE kategoria_id = :id');
        $statement->execute(['id' => $id]);
        $count = $statement->fetchColumn();
    } catch (PDOException $e) {
        error_log('Nie udało się sprawdzić usług kategorii: ' . $e->getMessage());
        return null;
    }

    return (int) $count > 0;
}

// PDOException z kodem 23000 oznacza naruszenie klucza obcego przy wyścigu.
function kategoria_delete(int $id): bool
{
    $pdo = kategorie_db();

    if ($id <= 0 || $pdo === null) {
        return false;
    }

    $statement = $pdo->prepare('DELETE FROM kategorie_uslug WHERE id = :id');
    $statement->execute(['id' => $id]);

    return $statement->rowCount() > 0;
}
