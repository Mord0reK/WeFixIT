<?php
declare(strict_types=1);

/*
    Wspólny moduł sesji.

    Udostępnia bezpieczne uruchomienie sesji z parametrami cookies.
    Wczytanie tego pliku nie ma żadnych efektów ubocznych — sesję uruchamia
    dopiero wywołanie session_start_secure().
*/
function session_start_secure(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }

    // HTTPS wykrywamy po $_SERVER['HTTPS'], żeby Secure nie blokował pracy po HTTP.
    $isHttps = isset($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== ''
        && strtolower((string) $_SERVER['HTTPS']) !== 'off';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');

    // Parametry cookie ustawiamy przed startem sesji; późniejsza zmiana jest odrzucana.
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $isHttps,
    ]);

    if (!@session_start()) {
        error_log('Nie udało się uruchomić sesji.');
        return false;
    }

    header('Cache-Control: no-store');

    return true;
}
