<?php
declare(strict_types=1);

/*
    Moduł danych i walidacji pracowników.

    Panel nie tworzy nowych kont użytkowników. Tworzy wyłącznie profil
    pracownika powiązany z istniejącym, aktywnym kontem o roli pracownik.
    Rejestracja kont ma własną ścieżkę, a nadanie roli pracownik wymaga
    świadomej decyzji administratora.

    Moduł obsługuje listę, odczyt, zapis opisu i aktywności oraz bezpieczne
    usuwanie. Nie zawiera HTML ani logiki HTTP i nigdy nie zwraca haslo_hash.
*/
require_once __DIR__ . '/../public/config.php';

// Wspólne komunikaty, żeby endpoint i widok nie powielały tych samych tekstów.
const PRACOWNIK_USER_TAKEN_ERROR = 'To konto ma już profil pracownika.';
const PRACOWNIK_USER_CHANGE_ERROR = 'Powiązanie konta jest niezmienne. Zmiana konta wymaga utworzenia nowego profilu.';
const PRACOWNIK_HAS_DEPENDENCIES_ERROR = 'Pracownik ma powiązane usługi, godziny pracy, nieobecności lub rezerwacje. Zamiast usuwać, dezaktywuj profil.';

function pracownicy_db(): ?PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        error_log('Brak połączenia z bazą w module pracowników.');
        return null;
    }

    return $pdo;
}

// Pola tekstowe przycinamy; brak wartości traktujemy jak pusty string.
function pracownik_field(array $data, string $field): string
{
    $value = $data[$field] ?? null;

    if (!is_string($value)) {
        return '';
    }

    return trim($value);
}

function pracownik_user_id_value(mixed $value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }

    if (is_string($value) && $value !== '' && ctype_digit($value)) {
        $userId = (int) $value;

        return $userId > 0 ? $userId : null;
    }

    return null;
}

function pracownik_description_errors(mixed $description): array
{
    if ($description === null) {
        return [];
    }

    if (!is_string($description)) {
        return ['opis' => 'Opis pracownika musi być tekstem.'];
    }

    if (mb_strlen(trim($description)) > 2000) {
        return ['opis' => 'Opis pracownika może mieć maksymalnie 2000 znaków.'];
    }

    return [];
}

function pracownik_active_errors(mixed $value): array
{
    return is_bool($value) ? [] : ['aktywny' => 'Pole aktywny musi być wartością logiczną.'];
}

// Konto musi istnieć, być aktywne, mieć rolę pracownik i nie mieć innego profilu.
function pracownik_user_errors(mixed $value, ?int $ignoreProfileId = null): array
{
    $userId = pracownik_user_id_value($value);

    if ($userId === null) {
        return ['uzytkownik_id' => 'Wybierz konto pracownika.'];
    }

    $pdo = pracownicy_db();

    if ($pdo === null) {
        return ['uzytkownik_id' => 'Nie udało się sprawdzić konta pracownika.'];
    }

    try {
        $statement = $pdo->prepare(
            'SELECT
                u.id,
                u.rola,
                u.aktywny,
                (SELECT p.id FROM pracownicy p WHERE p.uzytkownik_id = u.id LIMIT 1) AS profile_id
             FROM uzytkownicy u
             WHERE u.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się sprawdzić konta pracownika: ' . $e->getMessage());
        return ['uzytkownik_id' => 'Nie udało się sprawdzić konta pracownika.'];
    }

    if (!is_array($row)) {
        return ['uzytkownik_id' => 'Wybrane konto nie istnieje.'];
    }

    if ((string) $row['rola'] !== 'pracownik') {
        return ['uzytkownik_id' => 'Wybrane konto nie ma roli pracownik.'];
    }

    if ((int) $row['aktywny'] !== 1) {
        return ['uzytkownik_id' => 'Wybrane konto jest nieaktywne.'];
    }

    $profileId = $row['profile_id'] !== null ? (int) $row['profile_id'] : null;

    if ($profileId !== null && $profileId !== $ignoreProfileId) {
        return ['uzytkownik_id' => PRACOWNIK_USER_TAKEN_ERROR];
    }

    return [];
}

// Wspólna walidacja kompletnego zestawu pól nowego profilu.
function pracownik_errors(array $data, ?int $ignoreProfileId = null): array
{
    $errors = pracownik_user_errors($data['uzytkownik_id'] ?? null, $ignoreProfileId)
        + pracownik_description_errors($data['opis'] ?? null);

    if (array_key_exists('aktywny', $data)) {
        $errors += pracownik_active_errors($data['aktywny']);
    }

    return $errors;
}

// Lista profili z danymi konta i licznikami zależności.
function pracownicy_list(): ?array
{
    $pdo = pracownicy_db();

    if ($pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->query(
            'SELECT
                p.id,
                p.uzytkownik_id,
                p.opis,
                p.aktywny,
                p.utworzono,
                u.imie,
                u.nazwisko,
                u.email,
                u.rola,
                u.aktywny AS konto_aktywne,
                (SELECT COUNT(*) FROM uslugi_pracownikow up
                 WHERE up.pracownik_id = p.id AND up.aktywne = TRUE) AS uslugi_active,
                (SELECT COUNT(*) FROM uslugi_pracownikow up
                 WHERE up.pracownik_id = p.id) AS assignments_total,
                (SELECT COUNT(*) FROM godziny_pracy gp
                 WHERE gp.pracownik_id = p.id) AS schedules_total,
                (SELECT COUNT(*) FROM nieobecnosci n
                 WHERE n.pracownik_id = p.id) AS absences_total,
                (SELECT COUNT(*) FROM rezerwacje r
                 WHERE r.pracownik_id = p.id) AS reservations_total
             FROM pracownicy p
             JOIN uzytkownicy u ON u.id = p.uzytkownik_id
             ORDER BY u.nazwisko ASC, u.imie ASC'
        );
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać listy pracowników: ' . $e->getMessage());
        return null;
    }

    $employees = [];

    foreach ($rows as $row) {
        $dependencies = (int) $row['assignments_total']
            + (int) $row['schedules_total']
            + (int) $row['absences_total']
            + (int) $row['reservations_total'];

        $employees[] = [
            'id' => (int) $row['id'],
            'uzytkownik_id' => (int) $row['uzytkownik_id'],
            'imie' => (string) $row['imie'],
            'nazwisko' => (string) $row['nazwisko'],
            'email' => (string) $row['email'],
            'rola' => (string) $row['rola'],
            'konto_aktywne' => (int) $row['konto_aktywne'] === 1,
            'opis' => $row['opis'] !== null ? (string) $row['opis'] : null,
            'aktywny' => (int) $row['aktywny'] === 1,
            'utworzono' => (string) $row['utworzono'],
            'uslugi_active' => (int) $row['uslugi_active'],
            'dependencies_total' => $dependencies,
            'has_dependencies' => $dependencies > 0,
        ];
    }

    return $employees;
}

function pracownik_find_by_id(int $id): ?array
{
    $pdo = pracownicy_db();

    if ($id <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT
                p.id,
                p.uzytkownik_id,
                p.opis,
                p.aktywny,
                p.utworzono,
                u.imie,
                u.nazwisko,
                u.email,
                u.rola,
                u.aktywny AS konto_aktywne
             FROM pracownicy p
             JOIN uzytkownicy u ON u.id = p.uzytkownik_id
             WHERE p.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać pracownika po id: ' . $e->getMessage());
        return null;
    }

    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'uzytkownik_id' => (int) $row['uzytkownik_id'],
        'imie' => (string) $row['imie'],
        'nazwisko' => (string) $row['nazwisko'],
        'email' => (string) $row['email'],
        'rola' => (string) $row['rola'],
        'konto_aktywne' => (int) $row['konto_aktywne'] === 1,
        'opis' => $row['opis'] !== null ? (string) $row['opis'] : null,
        'aktywny' => (int) $row['aktywny'] === 1,
        'utworzono' => (string) $row['utworzono'],
    ];
}

// Konta z rolą pracownik, aktywne, bez profilu; opcjonalnie pomijamy wskazane konto.
function pracownicy_available_accounts(?int $ignoreUserId = null): ?array
{
    $pdo = pracownicy_db();

    if ($pdo === null) {
        return null;
    }

    $sql = "SELECT u.id, u.imie, u.nazwisko, u.email
            FROM uzytkownicy u
            LEFT JOIN pracownicy p ON p.uzytkownik_id = u.id
            WHERE u.rola = 'pracownik'
              AND u.aktywny = TRUE
              AND p.id IS NULL";
    $params = [];

    if ($ignoreUserId !== null && $ignoreUserId > 0) {
        $sql .= ' AND u.id <> :ignore_user_id';
        $params['ignore_user_id'] = $ignoreUserId;
    }

    $sql .= ' ORDER BY u.nazwisko ASC, u.imie ASC';

    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać kont dostępnych dla pracowników: ' . $e->getMessage());
        return null;
    }

    $accounts = [];

    foreach ($rows as $row) {
        $accounts[] = [
            'id' => (int) $row['id'],
            'imie' => (string) $row['imie'],
            'nazwisko' => (string) $row['nazwisko'],
            'email' => (string) $row['email'],
        ];
    }

    return $accounts;
}

// Nowy profil domyślnie jest aktywny; aktywność można przekazać jawnie.
function pracownik_create(array $data): int
{
    $pdo = pracownicy_db();

    if ($pdo === null) {
        throw new RuntimeException('Brak połączenia z bazą.');
    }

    $userId = pracownik_user_id_value($data['uzytkownik_id'] ?? null);

    if ($userId === null) {
        throw new RuntimeException('Nieprawidłowe dane pracownika.');
    }

    $description = pracownik_field($data, 'opis');
    $active = array_key_exists('aktywny', $data) ? (bool) $data['aktywny'] : true;

    // PDOException celowo propagujemy: endpoint rozpoznaje 23000 jako zajęte konto.
    $statement = $pdo->prepare(
        'INSERT INTO pracownicy (uzytkownik_id, opis, aktywny)
         VALUES (:uzytkownik_id, :opis, :aktywny)'
    );
    $statement->execute([
        'uzytkownik_id' => $userId,
        'opis' => $description !== '' ? $description : null,
        'aktywny' => $active ? 1 : 0,
    ]);

    return (int) $pdo->lastInsertId();
}

// Aktualizuje wyłącznie opis i aktywność; powiązanie konta jest niezmienne.
function pracownik_update(int $id, array $data): bool
{
    $pdo = pracownicy_db();

    if ($id <= 0 || $pdo === null) {
        return false;
    }

    $description = pracownik_field($data, 'opis');
    $active = (bool) ($data['aktywny'] ?? true);

    $statement = $pdo->prepare(
        'UPDATE pracownicy
         SET opis = :opis, aktywny = :aktywny
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $id,
        'opis' => $description !== '' ? $description : null,
        'aktywny' => $active ? 1 : 0,
    ]);

    // Sukces oznacza wykonany UPDATE; MySQL zwraca 0 wierszy, gdy wartości się nie zmieniły.
    return true;
}

function pracownik_set_active(int $id, bool $active): bool
{
    $pdo = pracownicy_db();

    if ($id <= 0 || $pdo === null) {
        return false;
    }

    try {
        $statement = $pdo->prepare('UPDATE pracownicy SET aktywny = :aktywny WHERE id = :id');
        $statement->execute([
            'id' => $id,
            'aktywny' => $active ? 1 : 0,
        ]);
    } catch (PDOException $e) {
        error_log('Nie udało się zmienić aktywności pracownika: ' . $e->getMessage());
        return false;
    }

    return true;
}

// Wszystkie tabele powiązane z pracownikiem blokują usunięcie profilu.
function pracownik_has_dependencies(int $id): ?bool
{
    $pdo = pracownicy_db();

    if ($id <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM uslugi_pracownikow WHERE pracownik_id = :id');
        $statement->execute(['id' => $id]);
        $assignments = (int) $statement->fetchColumn();

        $statement = $pdo->prepare('SELECT COUNT(*) FROM godziny_pracy WHERE pracownik_id = :id');
        $statement->execute(['id' => $id]);
        $schedules = (int) $statement->fetchColumn();

        $statement = $pdo->prepare('SELECT COUNT(*) FROM nieobecnosci WHERE pracownik_id = :id');
        $statement->execute(['id' => $id]);
        $absences = (int) $statement->fetchColumn();

        $statement = $pdo->prepare('SELECT COUNT(*) FROM rezerwacje WHERE pracownik_id = :id');
        $statement->execute(['id' => $id]);
        $reservations = (int) $statement->fetchColumn();
    } catch (PDOException $e) {
        error_log('Nie udało się sprawdzić zależności pracownika: ' . $e->getMessage());
        return null;
    }

    return $assignments > 0 || $schedules > 0 || $absences > 0 || $reservations > 0;
}

// PDOException z kodem 23000 oznacza naruszenie klucza obcego przy wyścigu.
function pracownik_delete(int $id): bool
{
    $pdo = pracownicy_db();

    if ($id <= 0 || $pdo === null) {
        return false;
    }

    $statement = $pdo->prepare('DELETE FROM pracownicy WHERE id = :id');
    $statement->execute(['id' => $id]);

    return $statement->rowCount() > 0;
}
