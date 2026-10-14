<?php
declare(strict_types=1);

/*
    Wspólny moduł uwierzytelniania i autoryzacji.

    Tożsamość i rola użytkownika są każdorazowo weryfikowane w bazie danych.
    Obsługuje chronione strony (przekierowanie lub HTTP 403) oraz endpointy
    JSON (HTTP 401, bez przekierowań).
*/
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/json.php';
require_once __DIR__ . '/../public/config.php';
require_once __DIR__ . '/users.php';

function auth_user_id(): ?int
{
    $userId = $_SESSION['user_id'] ?? null;

    if (!is_int($userId) || $userId <= 0) {
        return null;
    }

    return $userId;
}

function is_logged_in(): bool
{
    return auth_user_id() !== null;
}

// Dane użytkownika pamiętamy na czas jednego żądania; $fresh wymusza odczyt z bazy.
function current_user(bool $fresh = false): ?array
{
    static $user = null;
    static $loaded = false;

    if ($loaded && !$fresh) {
        return $user;
    }

    $loaded = true;
    $user = null;

    $userId = auth_user_id();

    if ($userId === null) {
        return null;
    }

    $row = user_find_by_id($userId);

    // Dezaktywowane konto traci dostęp mimo poprawnego user_id w sesji.
    if (!is_array($row) || (int) $row['aktywny'] !== 1) {
        return null;
    }

    $user = $row;

    return $user;
}

function auth_login_user(int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    if (!session_regenerate_id(true)) {
        error_log('Nie udało się odświeżyć identyfikatora sesji.');
        return false;
    }

    $_SESSION['user_id'] = $userId;
    csrf_rotate_token();

    return true;
}

function auth_logout_user(): bool
{
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE && !session_destroy()) {
        error_log('Nie udało się usunąć sesji podczas wylogowania.');
        return false;
    }

    // Wygaszamy cookie z zachowaniem jego parametrów, żeby przeglądarka usunęła właściwe.
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
    }

    return true;
}

// Ścieżka bazowa aplikacji, żeby przekierowania działały też w podkatalogu XAMPP.
function auth_base_path(): string
{
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
}

function auth_role_home_path(string $role): string
{
    return match ($role) {
        'pracownik' => 'pracownik',
        'admin' => 'admin',
        default => 'panel',
    };
}

// Czytelna nazwa roli do wyświetlenia w interfejsie.
function auth_role_label(string $role): string
{
    return match ($role) {
        'pracownik' => 'Pracownik',
        'admin' => 'Administrator',
        default => 'Klient',
    };
}

function require_login(): array
{
    $user = current_user();

    if ($user === null) {
        header('Location: ' . auth_base_path() . '/login');
        exit;
    }

    return $user;
}

function require_role(array $roles): array
{
    $user = require_login();

    if (in_array($user['rola'], $roles, true)) {
        return $user;
    }

    require_once __DIR__ . '/layout.php';

    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');

    $homeHref = htmlspecialchars(
        auth_base_path() . '/' . auth_role_home_path($user['rola']),
        ENT_QUOTES,
        'UTF-8'
    );

    layout_head('Brak dostępu — WeFixIT');
    ?>
    <main class="<?= LAYOUT_CARD_CLASS ?>">
        <h1 class="mb-4 text-2xl font-semibold">Brak dostępu</h1>
        <p>Nie masz uprawnień do wyświetlenia tej strony.</p>
        <p class="mt-4 text-sm">
            <a href="<?= $homeHref ?>" class="text-blue-600 underline hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">Wróć do panelu</a>
        </p>
    </main>
    <?php
    layout_foot();

    exit;
}

function require_api_login(): array
{
    $user = current_user();

    if ($user === null) {
        json_response(401, ['error' => 'Wymagane zalogowanie.']);
    }

    return $user;
}
