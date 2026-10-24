<?php
declare(strict_types=1);

/*
    Punkt wejścia testów integracyjnych Etapu 2.

    Wymaga działającego serwera MariaDB/MySQL wskazanego przez zmienne DB_HOST,
    DB_PORT, DB_NAME, DB_USER i DB_PASSWORD. Testy uruchamiają wbudowany serwer
    PHP (php -S) na czas trwania zestawu i sprawdzają aplikację przez HTTP.

    Uruchomienie:
        php tests/run.php
*/

require_once __DIR__ . '/lib.php';

foreach (['curl', 'mbstring', 'pdo_mysql', 'session'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, 'Brak wymaganego rozszerzenia PHP: ' . $extension . PHP_EOL);
        exit(2);
    }
}

require_once __DIR__ . '/cases_registration.php';
require_once __DIR__ . '/cases_endpoints.php';
require_once __DIR__ . '/cases_auth.php';
require_once __DIR__ . '/cases_pages.php';
require_once __DIR__ . '/cases_profile.php';
require_once __DIR__ . '/cases_admin.php';

// Najpierw przygotowujemy bazę, żeby pierwsze żądanie nie logowało błędu połączenia.
reset_database('database/database.sql');

$server = start_test_server();

if (($server['ready'] ?? false) !== true) {
    fwrite(STDERR, 'Wbudowany serwer PHP nie odpowiedział na czas.' . PHP_EOL);
    fwrite(STDERR, (string) file_get_contents($server['log']) . PHP_EOL);
    stop_test_server($server);
    exit(2);
}

$exitCode = run_test_suite();

stop_test_server($server);

$logErrors = server_log_errors($server['log']);

if ($logErrors !== []) {
    echo "\nBłędy PHP zapisane w logu serwera:\n";

    foreach ($logErrors as $line) {
        echo '  ' . $line . "\n";
    }

    $exitCode = 1;
} else {
    echo "Log serwera bez błędów PHP.\n";
}

exit($exitCode);
