<?php
declare(strict_types=1);

/*
    Ochrona CSRF dla operacji zmieniających stan.

    Token powstaje w sesji, a formularze przesyłają go w nagłówku X-CSRF-Token.
    Niestandardowy nagłówek jest z definicji "niesame-origin" dla przeglądarki,
    więc przeglądarka nie wyśle go z obcej domeny nawet przy zwykłym formularzu.
    Token porównujemy przez hash_equals(), żeby nie dało się go odgadywać po czasie.
*/
require_once __DIR__ . '/session.php';

function csrf_start_session(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }

    // Delegujemy do wspólnego startu sesji, żeby cookies miały HttpOnly i SameSite.
    return session_start_secure();
}

// Token jest tworzony raz na sesję i odtąd zwracany z sesji.
function csrf_token(): string
{
    if (!csrf_start_session()) {
        return '';
    }

    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        csrf_rotate_token();
    }

    return $_SESSION['csrf_token'];
}

// Po zmianie uprawnień token wypada, żeby nie dało się go użyć ponownie.
function csrf_rotate_token(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_verify(?string $token): bool
{
    if ($token === null || $token === '' || !csrf_start_session()) {
        return false;
    }

    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

// Apache przekazuje nagłówek X-CSRF-Token jako HTTP_X_CSRF_TOKEN.
function csrf_token_from_request(): ?string
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    return is_string($token) ? $token : null;
}