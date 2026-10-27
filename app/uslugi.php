<?php
declare(strict_types=1);

/*
    Moduł danych i walidacji usług.

    Odpowiada za listę z filtrami, odczyt pojedynczej usługi, zapis, zmianę
    aktywności i bezpieczne usuwanie. Nie zawiera HTML ani logiki HTTP.
    Walidacja zwraca mapę błędów przypisanych do pól i nie rzuca wyjątków.
*/
require_once __DIR__ . '/../public/config.php';

// Wspólne komunikaty, żeby endpoint i widok nie powielały tych samych tekstów.
const USLUGA_NAME_TAKEN_ERROR = 'Usługa o tej nazwie już istnieje w tej kategorii.';
const USLUGA_HAS_DEPENDENCIES_ERROR = 'Usługa ma przypisanych pracowników lub rezerwacje. Zamiast usuwać, dezaktywuj ją.';

function uslugi_db(): ?PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        error_log('Brak połączenia z bazą w module usług.');
        return null;
    }

    return $pdo;
}

// Pola tekstowe przycinamy; brak wartości traktujemy jak pusty string.
function usluga_field(array $data, string $field): string
{
    $value = $data[$field] ?? null;

    if (!is_string($value)) {
        return '';
    }

    return trim($value);
}

// Filtr kategorii: 0 oznacza wszystkie kategorie, a błędna wartość nie może wywołać błędu.
function usluga_filter_category_id(mixed $value): int
{
    if (is_int($value)) {
        return $value > 0 ? $value : 0;
    }

    if (is_string($value) && $value !== '' && ctype_digit($value)) {
        $categoryId = (int) $value;

        return $categoryId > 0 ? $categoryId : 0;
    }

    return 0;
}

// Status filtru pochodzi wyłącznie z allowlisty, nigdy z dowolnego stringa klienta.
function usluga_filter_status(mixed $value): string
{
    $allowed = ['all', 'active', 'inactive'];

    return is_string($value) && in_array($value, $allowed, true) ? $value : 'all';
}

function usluga_category_id_value(mixed $value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }

    if (is_string($value) && $value !== '' && ctype_digit($value)) {
        $categoryId = (int) $value;

        return $categoryId > 0 ? $categoryId : null;
    }

    return null;
}

function usluga_duration_value(mixed $value): ?int
{
    $duration = null;

    if (is_int($value)) {
        $duration = $value;
    } elseif (is_string($value) && $value !== '' && ctype_digit($value)) {
        $duration = (int) $value;
    }

    if ($duration === null || $duration <= 0 || $duration > 1440) {
        return null;
    }

    return $duration;
}

// Parsuje cenę z kropką lub przecinkiem i zwraca znormalizowany string dla DECIMAL.
function usluga_price_parse(mixed $value): array
{
    if (is_int($value) || is_float($value)) {
        $value = (string) $value;
    }

    if (!is_string($value)) {
        return ['value' => null, 'error' => 'Podaj cenę usługi.'];
    }

    $value = trim($value);

    if ($value === '') {
        return ['value' => null, 'error' => 'Podaj cenę usługi.'];
    }

    if (str_starts_with($value, '-')) {
        return ['value' => null, 'error' => 'Cena nie może być ujemna.'];
    }

    if (preg_match('/^\d+(?:[.,]\d+)?$/', $value) !== 1) {
        return ['value' => null, 'error' => 'Podaj cenę w formacie np. 199,99.'];
    }

    $parts = preg_split('/[.,]/', $value, 2);
    $integerPart = ltrim($parts[0], '0');
    $decimalPart = $parts[1] ?? '';

    if ($integerPart === '') {
        $integerPart = '0';
    }

    if (strlen($decimalPart) > 2) {
        return ['value' => null, 'error' => 'Cena może mieć maksymalnie dwa miejsca po przecinku.'];
    }

    // DECIMAL(10,2) pozwala na maksymalnie osiem cyfr części całkowitej.
    if (strlen($integerPart) > 8) {
        return ['value' => null, 'error' => 'Cena jest zbyt duża.'];
    }

    $normalized = $integerPart . ($decimalPart !== '' ? '.' . $decimalPart : '');

    return ['value' => $normalized, 'error' => null];
}

function usluga_price_value(mixed $value): ?string
{
    return usluga_price_parse($value)['value'];
}

function usluga_price_errors(mixed $value): array
{
    $error = usluga_price_parse($value)['error'];

    return $error !== null ? ['cena' => $error] : [];
}

function usluga_name_basic_errors(mixed $name): array
{
    if (!is_string($name)) {
        return ['nazwa' => 'Podaj nazwę usługi.'];
    }

    $name = trim($name);

    if ($name === '') {
        return ['nazwa' => 'Podaj nazwę usługi.'];
    }

    if (mb_strlen($name) > 150) {
        return ['nazwa' => 'Nazwa usługi może mieć maksymalnie 150 znaków.'];
    }

    return [];
}

// Unikalność jest liczona w obrębie kategorii, zgodnie z uq_uslugi_kategoria_nazwa.
function usluga_name_errors(mixed $name, int $categoryId, ?int $ignoreId = null): array
{
    $errors = usluga_name_basic_errors($name);

    if ($errors !== []) {
        return $errors;
    }

    $pdo = uslugi_db();

    if ($pdo === null) {
        return ['nazwa' => 'Nie udało się sprawdzić nazwy usługi.'];
    }

    try {
        $sql = 'SELECT id FROM uslugi WHERE kategoria_id = :kategoria_id AND nazwa = :nazwa';
        $params = [
            'kategoria_id' => $categoryId,
            'nazwa' => trim((string) $name),
        ];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }

        $sql .= ' LIMIT 1';

        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        if ($statement->fetch(PDO::FETCH_ASSOC) !== false) {
            return ['nazwa' => USLUGA_NAME_TAKEN_ERROR];
        }
    } catch (PDOException $e) {
        error_log('Nie udało się sprawdzić unikalności usługi: ' . $e->getMessage());
        return ['nazwa' => 'Nie udało się sprawdzić nazwy usługi.'];
    }

    return [];
}

function usluga_description_errors(mixed $description): array
{
    if ($description === null) {
        return [];
    }

    if (!is_string($description)) {
        return ['opis' => 'Opis usługi musi być tekstem.'];
    }

    if (mb_strlen(trim($description)) > 2000) {
        return ['opis' => 'Opis usługi może mieć maksymalnie 2000 znaków.'];
    }

    return [];
}

// Kategoria musi istnieć i być aktywna; wartości z formularza nie są zaufane.
function usluga_category_errors(mixed $value): array
{
    $categoryId = usluga_category_id_value($value);

    if ($categoryId === null) {
        return ['kategoria_id' => 'Wybierz kategorię usługi.'];
    }

    $pdo = uslugi_db();

    if ($pdo === null) {
        return ['kategoria_id' => 'Nie udało się sprawdzić kategorii usługi.'];
    }

    try {
        $statement = $pdo->prepare('SELECT aktywna FROM kategorie_uslug WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $categoryId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się sprawdzić kategorii usługi: ' . $e->getMessage());
        return ['kategoria_id' => 'Nie udało się sprawdzić kategorii usługi.'];
    }

    if (!is_array($row)) {
        return ['kategoria_id' => 'Wybrana kategoria nie istnieje.'];
    }

    if ((int) $row['aktywna'] !== 1) {
        return ['kategoria_id' => 'Wybrana kategoria jest nieaktywna.'];
    }

    return [];
}

function usluga_duration_errors(mixed $value): array
{
    if (usluga_duration_value($value) === null) {
        return ['czas_trwania_minuty' => 'Podaj czas trwania w minutach od 1 do 1440.'];
    }

    return [];
}

// Wspólna walidacja kompletnego zestawu pól usługi.
function usluga_errors(array $data, ?int $ignoreId = null): array
{
    $errors = usluga_name_basic_errors($data['nazwa'] ?? null)
        + usluga_description_errors($data['opis'] ?? null)
        + usluga_category_errors($data['kategoria_id'] ?? null)
        + usluga_price_errors($data['cena'] ?? null)
        + usluga_duration_errors($data['czas_trwania_minuty'] ?? null);

    if (!isset($errors['nazwa']) && !isset($errors['kategoria_id'])) {
        $categoryId = usluga_category_id_value($data['kategoria_id'] ?? null);

        if ($categoryId !== null) {
            $errors += usluga_name_errors($data['nazwa'] ?? null, $categoryId, $ignoreId);
        }
    }

    return $errors;
}

// Lista usług z nazwą kategorii i licznikami przypisań pracowników.
function uslugi_list(int $categoryId = 0, string $status = 'all'): ?array
{
    $pdo = uslugi_db();

    if ($pdo === null) {
        return null;
    }

    $categoryId = usluga_filter_category_id($categoryId);
    $status = usluga_filter_status($status);
    $conditions = [];
    $params = [];

    if ($categoryId > 0) {
        $conditions[] = 'u.kategoria_id = :kategoria_id';
        $params['kategoria_id'] = $categoryId;
    }

    if ($status === 'active') {
        $conditions[] = 'u.aktywna = TRUE';
    } elseif ($status === 'inactive') {
        $conditions[] = 'u.aktywna = FALSE';
    }

    $where = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';

    try {
        $statement = $pdo->prepare(
            'SELECT
                u.id,
                u.kategoria_id,
                u.nazwa,
                u.opis,
                u.czas_trwania_minuty,
                u.cena,
                u.aktywna,
                k.nazwa AS kategoria_nazwa,
                k.aktywna AS kategoria_aktywna,
                (SELECT COUNT(*) FROM uslugi_pracownikow up WHERE up.usluga_id = u.id) AS przypisania_total,
                (SELECT COUNT(*) FROM uslugi_pracownikow up
                 WHERE up.usluga_id = u.id AND up.aktywne = TRUE) AS pracownicy_active
             FROM uslugi u
             JOIN kategorie_uslug k ON k.id = u.kategoria_id'
            . $where
            . ' ORDER BY u.nazwa ASC'
        );
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać listy usług: ' . $e->getMessage());
        return null;
    }

    $services = [];

    foreach ($rows as $row) {
        $services[] = [
            'id' => (int) $row['id'],
            'kategoria_id' => (int) $row['kategoria_id'],
            'kategoria_nazwa' => (string) $row['kategoria_nazwa'],
            'kategoria_aktywna' => (int) $row['kategoria_aktywna'] === 1,
            'nazwa' => (string) $row['nazwa'],
            'opis' => $row['opis'] !== null ? (string) $row['opis'] : null,
            'czas_trwania_minuty' => (int) $row['czas_trwania_minuty'],
            'cena' => (string) $row['cena'],
            'aktywna' => (int) $row['aktywna'] === 1,
            'pracownicy_active' => (int) $row['pracownicy_active'],
            'przypisania_total' => (int) $row['przypisania_total'],
        ];
    }

    return $services;
}

function usluga_find_by_id(int $id): ?array
{
    $pdo = uslugi_db();

    if ($id <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT
                u.id,
                u.kategoria_id,
                u.nazwa,
                u.opis,
                u.czas_trwania_minuty,
                u.cena,
                u.aktywna,
                k.nazwa AS kategoria_nazwa,
                k.aktywna AS kategoria_aktywna
             FROM uslugi u
             JOIN kategorie_uslug k ON k.id = u.kategoria_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać usługi po id: ' . $e->getMessage());
        return null;
    }

    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'kategoria_id' => (int) $row['kategoria_id'],
        'kategoria_nazwa' => (string) $row['kategoria_nazwa'],
        'kategoria_aktywna' => (int) $row['kategoria_aktywna'] === 1,
        'nazwa' => (string) $row['nazwa'],
        'opis' => $row['opis'] !== null ? (string) $row['opis'] : null,
        'czas_trwania_minuty' => (int) $row['czas_trwania_minuty'],
        'cena' => (string) $row['cena'],
        'aktywna' => (int) $row['aktywna'] === 1,
    ];
}

// Nowa usługa zawsze startuje jako aktywna.
function usluga_create(array $data): int
{
    $pdo = uslugi_db();

    if ($pdo === null) {
        throw new RuntimeException('Brak połączenia z bazą.');
    }

    $price = usluga_price_value($data['cena'] ?? null);
    $duration = usluga_duration_value($data['czas_trwania_minuty'] ?? null);
    $categoryId = usluga_category_id_value($data['kategoria_id'] ?? null);

    if ($price === null || $duration === null || $categoryId === null) {
        throw new RuntimeException('Nieprawidłowe dane usługi.');
    }

    $description = usluga_field($data, 'opis');

    // PDOException celowo propagujemy: endpoint rozpoznaje 23000 jako duplikat nazwy.
    $statement = $pdo->prepare(
        'INSERT INTO uslugi (kategoria_id, nazwa, opis, czas_trwania_minuty, cena, aktywna)
         VALUES (:kategoria_id, :nazwa, :opis, :czas_trwania_minuty, :cena, TRUE)'
    );
    $statement->execute([
        'kategoria_id' => $categoryId,
        'nazwa' => usluga_field($data, 'nazwa'),
        'opis' => $description !== '' ? $description : null,
        'czas_trwania_minuty' => $duration,
        'cena' => $price,
    ]);

    return (int) $pdo->lastInsertId();
}

// Aktualizuje wszystkie edytowalne pola usługi w jednym zapisie.
function usluga_update(int $id, array $data): bool
{
    $pdo = uslugi_db();

    if ($id <= 0 || $pdo === null) {
        return false;
    }

    $price = usluga_price_value($data['cena'] ?? null);
    $duration = usluga_duration_value($data['czas_trwania_minuty'] ?? null);
    $categoryId = usluga_category_id_value($data['kategoria_id'] ?? null);

    if ($price === null || $duration === null || $categoryId === null) {
        return false;
    }

    $description = usluga_field($data, 'opis');
    $active = (bool) ($data['aktywna'] ?? true);

    $statement = $pdo->prepare(
        'UPDATE uslugi
         SET kategoria_id = :kategoria_id, nazwa = :nazwa, opis = :opis,
             czas_trwania_minuty = :czas_trwania_minuty, cena = :cena, aktywna = :aktywna
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $id,
        'kategoria_id' => $categoryId,
        'nazwa' => usluga_field($data, 'nazwa'),
        'opis' => $description !== '' ? $description : null,
        'czas_trwania_minuty' => $duration,
        'cena' => $price,
        'aktywna' => $active ? 1 : 0,
    ]);

    // Sukces oznacza wykonany UPDATE; MySQL zwraca 0 wierszy, gdy wartości się nie zmieniły.
    return true;
}

function usluga_set_active(int $id, bool $active): bool
{
    $pdo = uslugi_db();

    if ($id <= 0 || $pdo === null) {
        return false;
    }

    try {
        $statement = $pdo->prepare('UPDATE uslugi SET aktywna = :aktywna WHERE id = :id');
        $statement->execute([
            'id' => $id,
            'aktywna' => $active ? 1 : 0,
        ]);
    } catch (PDOException $e) {
        error_log('Nie udało się zmienić aktywności usługi: ' . $e->getMessage());
        return false;
    }

    return true;
}

// Przypisania pracowników i rezerwacje blokują usunięcie niezależnie od flagi aktywne.
function usluga_has_dependencies(int $id): ?bool
{
    $pdo = uslugi_db();

    if ($id <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM uslugi_pracownikow WHERE usluga_id = :id');
        $statement->execute(['id' => $id]);
        $assignments = (int) $statement->fetchColumn();

        $statement = $pdo->prepare('SELECT COUNT(*) FROM rezerwacje WHERE usluga_id = :id');
        $statement->execute(['id' => $id]);
        $reservations = (int) $statement->fetchColumn();
    } catch (PDOException $e) {
        error_log('Nie udało się sprawdzić zależności usługi: ' . $e->getMessage());
        return null;
    }

    return $assignments > 0 || $reservations > 0;
}

// PDOException z kodem 23000 oznacza naruszenie klucza obcego przy wyścigu.
function usluga_delete(int $id): bool
{
    $pdo = uslugi_db();

    if ($id <= 0 || $pdo === null) {
        return false;
    }

    $statement = $pdo->prepare('DELETE FROM uslugi WHERE id = :id');
    $statement->execute(['id' => $id]);

    return $statement->rowCount() > 0;
}
