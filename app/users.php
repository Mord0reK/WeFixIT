<?php
declare(strict_types=1);

/*
    Wspólny moduł danych i walidacji użytkownika.

    Udostępnia odczyt i zapis podstawowych danych profilu oraz walidację pól
    imie, nazwisko, email i telefon. Korzystają z niego widoki SSR
    (public/profil.php) i endpointy JSON (/api/profile), więc reguły nie są
    duplikowane.
*/
require_once __DIR__ . '/../public/config.php';

// Jedno miejsce pobierania połączenia PDO; brak połączenia logujemy bez szczegółów.
function user_db(): ?PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        error_log('Brak połączenia z bazą w module users.');
        return null;
    }

    return $pdo;
}

// Zwraca wartość pola tylko gdy jest stringiem; hasła czytamy z $trim = false.
function user_field(array $data, string $field, bool $trim = true): string
{
    $value = $data[$field] ?? null;

    if (!is_string($value)) {
        return '';
    }

    return $trim ? trim($value) : $value;
}

// Imię i nazwisko to nazwy, więc dopuszczamy litery dowolnego alfabetu i separatory.
function user_valid_name(string $value, bool $allowApostrophe): bool
{
    $pattern = $allowApostrophe
        ? '/^[\p{L}\p{M} .\'\-]+$/u'
        : '/^[\p{L}\p{M} .\-]+$/u';

    return preg_match($pattern, $value) === 1;
}

// Waliduje wyłącznie cztery wspólne pola profilu; nie dotyka bazy.
function user_profile_errors(array $data): array
{
    $imie = user_field($data, 'imie');
    $nazwisko = user_field($data, 'nazwisko');
    $email = user_field($data, 'email');
    $telefon = user_field($data, 'telefon');
    $errors = [];

    if ($imie === '' || mb_strlen($imie) > 100) {
        $errors['imie'] = 'Podaj imię, maksymalnie 100 znaków.';
    } elseif (!user_valid_name($imie, false)) {
        $errors['imie'] = 'Imię może zawierać tylko litery oraz spację, kropkę lub myślnik.';
    }

    if ($nazwisko === '' || mb_strlen($nazwisko) > 100) {
        $errors['nazwisko'] = 'Podaj nazwisko, maksymalnie 100 znaków.';
    } elseif (!user_valid_name($nazwisko, true)) {
        $errors['nazwisko'] = 'Nazwisko może zawierać tylko litery oraz spację, apostrof, kropkę lub myślnik.';
    }

    if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Podaj prawidłowy adres e-mail.';
    }

    // Telefon w bazie jest wymagany, więc dopuszczamy tylko cyfry z typowymi separatorami.
    $cyfryTelefonu = preg_replace('/[^0-9]/', '', $telefon) ?? '';

    if (preg_match('/^\+?[0-9 ()-]{9,20}$/', $telefon) !== 1 || strlen($cyfryTelefonu) < 9) {
        $errors['telefon'] = 'Podaj poprawny numer telefonu, na przykład +48123456789.';
    }

    return $errors;
}

function user_find_by_id(int $userId): ?array
{
    $pdo = user_db();

    if ($userId <= 0 || $pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT id, imie, nazwisko, email, telefon, rola, aktywny
             FROM uzytkownicy
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać użytkownika po id: ' . $e->getMessage());
        return null;
    }

    return is_array($row) ? $row : null;
}

// Wykorzystywane do sprawdzenia zajętości adresu e-mail przed zapisem profilu.
function user_find_by_email(string $email): ?array
{
    $pdo = user_db();

    if ($pdo === null) {
        return null;
    }

    try {
        $statement = $pdo->prepare('SELECT id FROM uzytkownicy WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać użytkownika po e-mailu: ' . $e->getMessage());
        return null;
    }

    return is_array($row) ? $row : null;
}

function user_update_profile(int $userId, array $data): bool
{
    $pdo = user_db();

    if ($userId <= 0 || $pdo === null) {
        return false;
    }

    // Nazwy kolumn pochodzą wyłącznie ze stałej allowlisty, nie z danych wejściowych.
    $columns = ['imie', 'nazwisko', 'email', 'telefon'];
    $assignments = [];
    $params = ['id' => $userId];

    foreach ($columns as $column) {
        $assignments[] = $column . ' = :' . $column;
        $params[$column] = user_field($data, $column);
    }

    // PDOException celowo propagujemy: endpoint rozpoznaje kod 23000 i mapuje go na 409.
    $statement = $pdo->prepare('UPDATE uzytkownicy SET ' . implode(', ', $assignments) . ' WHERE id = :id');
    $statement->execute($params);

    return true;
}
