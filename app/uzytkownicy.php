<?php
declare(strict_types=1);

/*
    Moduł administracji kontami użytkowników.

    Obsługuje listę z filtrami, wyszukiwaniem i paginacją, odczyt po id oraz
    zmianę roli i aktywności. Nie tworzy kont, nie zmienia haseł i nie usuwa
    użytkowników — te operacje
    mają własne ścieżki. Moduł nie zawiera HTML ani logiki HTTP i nigdy nie
    pobiera kolumny haslo_hash.
*/
require_once __DIR__ . '/../public/config.php';
require_once __DIR__ . '/pagination.php';

const UZYTKOWNIK_ROLES = ['klient', 'pracownik', 'admin'];
const UZYTKOWNIK_STATUS_FILTERS = ['all', 'active', 'inactive'];

// Wspólne komunikaty, żeby endpoint i widok nie powielały tych samych tekstów.
const UZYTKOWNIK_ID_ERROR = 'Podaj identyfikator użytkownika.';
const UZYTKOWNIK_NO_CHANGES_ERROR = 'Podaj rolę lub status konta do zmiany.';
const UZYTKOWNIK_ROLE_ERROR = 'Wybierz rolę: klient, pracownik lub admin.';
const UZYTKOWNIK_ACTIVE_ERROR = 'Pole aktywny musi być wartością logiczną.';
const UZYTKOWNIK_SELF_ROLE_ERROR = 'Nie możesz odebrać sobie roli administratora.';
const UZYTKOWNIK_SELF_ACTIVE_ERROR = 'Nie możesz dezaktywować własnego konta.';
const UZYTKOWNIK_EMPLOYEE_PROFILE_ROLE_ERROR = 'Konto ma profil pracownika. Najpierw dezaktywuj profil pracownika, aby zmienić rolę na klient.';
const UZYTKOWNIK_LAST_ADMIN_ERROR = 'Nie można odebrać roli ani dezaktywować ostatniego aktywnego administratora.';

function uzytkownicy_db(): ?PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        error_log('Brak połączenia z bazą w module użytkowników.');
        return null;
    }

    return $pdo;
}

// Filtr roli pochodzi wyłącznie z allowlisty; błędna wartość oznacza brak filtra.
function uzytkownik_role_filter(mixed $value): string
{
    return is_string($value) && in_array($value, UZYTKOWNIK_ROLES, true) ? $value : '';
}

// Filtr statusu ma własną allowlistę; błędna wartość wraca do pokazania wszystkich.
function uzytkownik_status_filter(mixed $value): string
{
    return is_string($value) && in_array($value, UZYTKOWNIK_STATUS_FILTERS, true)
        ? $value
        : 'all';
}

function uzytkownik_role_value(mixed $value): ?string
{
    return is_string($value) && in_array($value, UZYTKOWNIK_ROLES, true) ? $value : null;
}

function uzytkownik_active_value(mixed $value): ?bool
{
    return is_bool($value) ? $value : null;
}

// Wspólne mapowanie rekordu — bez haslo_hash i danych technicznych.
function uzytkownik_row(array $row): array
{
    $hasEmployeeProfile = $row['pracownik_id'] !== null;

    return [
        'id' => (int) $row['id'],
        'imie' => (string) $row['imie'],
        'nazwisko' => (string) $row['nazwisko'],
        'email' => (string) $row['email'],
        'rola' => (string) $row['rola'],
        'aktywny' => (int) $row['aktywny'] === 1,
        'utworzono' => (string) $row['utworzono'],
        'ma_profil_pracownika' => $hasEmployeeProfile,
        'profil_pracownika_aktywny' => $hasEmployeeProfile
            ? (int) $row['pracownik_aktywny'] === 1
            : null,
    ];
}

function uzytkownik_list_conditions(string $role, string $status, string $search): array
{
    $role = uzytkownik_role_filter($role);
    $status = uzytkownik_status_filter($status);
    $search = pagination_search_value($search);
    $conditions = [];
    $params = [];

    if ($role !== '') {
        $conditions[] = 'u.rola = :rola';
        $params['rola'] = $role;
    }

    if ($status === 'active') {
        $conditions[] = 'u.aktywny = TRUE';
    } elseif ($status === 'inactive') {
        $conditions[] = 'u.aktywny = FALSE';
    }

    if ($search !== '') {
        $pattern = pagination_like_pattern($search);
        $conditions[] = '(u.imie LIKE CONCAT(\'%\', :szukaj_imie, \'%\')'
            . ' OR u.nazwisko LIKE CONCAT(\'%\', :szukaj_nazwisko, \'%\')'
            . ' OR u.email LIKE CONCAT(\'%\', :szukaj_email, \'%\'))';
        $params['szukaj_imie'] = $pattern;
        $params['szukaj_nazwisko'] = $pattern;
        $params['szukaj_email'] = $pattern;
    }

    return [$conditions, $params];
}

function uzytkownik_list_select_sql(): string
{
    return 'SELECT
                u.id,
                u.imie,
                u.nazwisko,
                u.email,
                u.rola,
                u.aktywny,
                u.utworzono,
                p.id AS pracownik_id,
                p.aktywny AS pracownik_aktywny
             FROM uzytkownicy u
             LEFT JOIN pracownicy p ON p.uzytkownik_id = u.id';
}

function uzytkownicy_list(string $role = '', string $status = 'all'): ?array
{
    $pdo = uzytkownicy_db();

    if ($pdo === null) {
        return null;
    }

    [$conditions, $params] = uzytkownik_list_conditions($role, $status, '');
    $where = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';

    try {
        $statement = $pdo->prepare(
            uzytkownik_list_select_sql()
            . $where
            . ' ORDER BY u.nazwisko ASC, u.imie ASC, u.id ASC'
        );
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać listy użytkowników: ' . $e->getMessage());
        return null;
    }

    $users = [];

    foreach ($rows as $row) {
        $users[] = uzytkownik_row($row);
    }

    return $users;
}

/*
    Wariant paginowany zachowuje dotychczasową funkcję dla pełnych list
    potrzebnych w filtrach innych modułów.
*/
function uzytkownicy_list_paginated(
    string $role = '',
    string $status = 'all',
    string $search = '',
    int $page = 1,
    int $size = 25
): ?array
{
    $pdo = uzytkownicy_db();

    if ($pdo === null) {
        return null;
    }

    $page = pagination_page($page);
    $size = pagination_size($size);
    [$conditions, $params] = uzytkownik_list_conditions($role, $status, $search);
    $where = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';

    try {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM uzytkownicy u' . $where);
        $statement->execute($params);
        $total = (int) $statement->fetchColumn();
    } catch (PDOException $e) {
        error_log('Nie udało się policzyć użytkowników: ' . $e->getMessage());
        return null;
    }

    $pages = pagination_total_pages($total, $size);
    $page = min($page, $pages);
    $offset = pagination_offset($page, $size);

    try {
        $statement = $pdo->prepare(
            uzytkownik_list_select_sql()
            . $where
            . ' ORDER BY u.nazwisko ASC, u.imie ASC, u.id ASC '
            . pagination_limit_sql($size, $offset)
        );
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać listy użytkowników: ' . $e->getMessage());
        return null;
    }

    $users = [];

    foreach ($rows as $row) {
        $users[] = uzytkownik_row($row);
    }

    return [
        'items' => $users,
        'total' => $total,
        'page' => $page,
        'size' => $size,
        'pages' => $pages,
    ];
}

function uzytkownik_find_by_id(int $id): ?array
{
    $pdo = uzytkownicy_db();

    if ($id <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT
                u.id,
                u.imie,
                u.nazwisko,
                u.email,
                u.rola,
                u.aktywny,
                u.utworzono,
                p.id AS pracownik_id,
                p.aktywny AS pracownik_aktywny
             FROM uzytkownicy u
             LEFT JOIN pracownicy p ON p.uzytkownik_id = u.id
             WHERE u.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać użytkownika po id: ' . $e->getMessage());
        return null;
    }

    return is_array($row) ? uzytkownik_row($row) : null;
}

// Walidacja kształtu body przed sprawdzeniem istnienia konta.
function uzytkownik_change_errors(array $data): array
{
    $errors = [];
    $id = $data['id'] ?? null;
    $hasRole = array_key_exists('rola', $data);
    $hasActive = array_key_exists('aktywny', $data);

    if (!is_int($id) || $id <= 0) {
        $errors['id'] = UZYTKOWNIK_ID_ERROR;
    } elseif (!$hasRole && !$hasActive) {
        // Brak pól do zmiany dotyczy całego żądania, więc używamy klucza id.
        // Przy niepoprawnym id zwracamy wyłącznie błąd identyfikatora.
        $errors['id'] = UZYTKOWNIK_NO_CHANGES_ERROR;
    }

    if ($hasRole && uzytkownik_role_value($data['rola']) === null) {
        $errors['rola'] = UZYTKOWNIK_ROLE_ERROR;
    }

    if ($hasActive && uzytkownik_active_value($data['aktywny']) === null) {
        $errors['aktywny'] = UZYTKOWNIK_ACTIVE_ERROR;
    }

    return $errors;
}

/*
    Zmiana roli i aktywności w transakcji.

    Przed regułami blokujemy wiersz docelowy oraz wszystkie wiersze adminów w
    kolejności id. Dzięki temu równoległe odebranie roli dwóm ostatnim adminom
    nie może jednocześnie zobaczyć stanu "jest jeszcze drugi admin".
*/
function uzytkownik_update(int $targetId, array $data, int $actorId): array
{
    $pdo = uzytkownicy_db();
    $hasRole = array_key_exists('rola', $data);
    $hasActive = array_key_exists('aktywny', $data);
    $newRole = $hasRole ? uzytkownik_role_value($data['rola']) : null;
    $newActive = $hasActive ? uzytkownik_active_value($data['aktywny']) : null;

    if ($targetId <= 0 || $actorId <= 0 || $pdo === null) {
        return ['status' => 'error'];
    }

    if ((!$hasRole && !$hasActive) || ($hasRole && $newRole === null) || ($hasActive && $newActive === null)) {
        return ['status' => 'invalid', 'errors' => uzytkownik_change_errors($data)];
    }

    try {
        $pdo->beginTransaction();

        // Wszyscy admini w stałej kolejności — wspólna blokada reguły ostatniego admina.
        $statement = $pdo->query('SELECT id FROM uzytkownicy WHERE rola = \'admin\' ORDER BY id FOR UPDATE');
        $statement->fetchAll(PDO::FETCH_COLUMN);

        $statement = $pdo->prepare(
            'SELECT id, rola, aktywny
             FROM uzytkownicy
             WHERE id = :id
             FOR UPDATE'
        );
        $statement->execute(['id' => $targetId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            $pdo->rollBack();
            return ['status' => 'not_found'];
        }

        $statement = $pdo->prepare(
            'SELECT id, aktywny
             FROM pracownicy
             WHERE uzytkownik_id = :uzytkownik_id
             FOR UPDATE'
        );
        $statement->execute(['uzytkownik_id' => $targetId]);
        $profile = $statement->fetch(PDO::FETCH_ASSOC);

        $currentRole = (string) $row['rola'];
        $currentActive = (int) $row['aktywny'] === 1;
        $targetRole = $hasRole ? (string) $newRole : $currentRole;
        $targetActive = $hasActive ? (bool) $newActive : $currentActive;
        $roleChanged = $hasRole && $targetRole !== $currentRole;
        $activeChanged = $hasActive && $targetActive !== $currentActive;
        $errors = [];

        if ($targetId === $actorId && $hasRole && $targetRole !== 'admin') {
            $errors['rola'] = UZYTKOWNIK_SELF_ROLE_ERROR;
        }

        if ($targetId === $actorId && $hasActive && $targetActive === false) {
            $errors['aktywny'] = UZYTKOWNIK_SELF_ACTIVE_ERROR;
        }

        if (!isset($errors['rola']) && $roleChanged && $targetRole === 'klient' && is_array($profile)) {
            $errors['rola'] = UZYTKOWNIK_EMPLOYEE_PROFILE_ROLE_ERROR;
        }

        $wouldRemoveActiveAdmin = $currentRole === 'admin'
            && $currentActive
            && ($targetRole !== 'admin' || $targetActive === false);

        if ($errors === [] && $wouldRemoveActiveAdmin) {
            $statement = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM uzytkownicy
                 WHERE rola = 'admin' AND aktywny = TRUE AND id <> :id"
            );
            $statement->execute(['id' => $targetId]);

            if ((int) $statement->fetchColumn() === 0) {
                if ($targetRole !== 'admin') {
                    $errors['rola'] = UZYTKOWNIK_LAST_ADMIN_ERROR;
                }

                if ($targetActive === false) {
                    $errors['aktywny'] = UZYTKOWNIK_LAST_ADMIN_ERROR;
                }
            }
        }

        if ($errors !== []) {
            $pdo->rollBack();
            return ['status' => 'invalid', 'errors' => $errors];
        }

        $changedFields = [];

        if ($roleChanged) {
            $changedFields[] = 'rola';
        }

        if ($activeChanged) {
            $changedFields[] = 'aktywny';
        }

        if ($changedFields !== []) {
            $assignments = [];
            $params = ['id' => $targetId];

            if ($roleChanged) {
                $assignments[] = 'rola = :rola';
                $params['rola'] = $targetRole;
            }

            if ($activeChanged) {
                $assignments[] = 'aktywny = :aktywny';
                $params['aktywny'] = $targetActive ? 1 : 0;
            }

            $statement = $pdo->prepare(
                'UPDATE uzytkownicy SET ' . implode(', ', $assignments) . ' WHERE id = :id'
            );
            $statement->execute($params);
        }

        $pdo->commit();

        return [
            'status' => 'ok',
            'id' => $targetId,
            'rola' => $targetRole,
            'aktywny' => $targetActive,
            'rola_przed' => $currentRole,
            'aktywny_przed' => $currentActive,
            'rola_po' => $targetRole,
            'aktywny_po' => $targetActive,
            'zmienione_pola' => $changedFields,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Nie udało się zmienić konta użytkownika: ' . $e->getMessage());
        return ['status' => 'error'];
    }
}
