<?php
declare(strict_types=1);

function loadEnvFile(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        $value = trim($value, "\"'");

        if ($name !== '' && getenv($name) === false) {
            putenv($name . '=' . $value);
        }
    }
}

function env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);

    if ($value === false) {
        return $default;
    }

    return $value;
}

loadEnvFile(dirname(__DIR__) . '/.env');

$dbHost = env('DB_HOST');
$dbPort = env('DB_PORT', '3306');
$dbName = env('DB_NAME');
$dbUser = env('DB_USER');
$dbPassword = env('DB_PASSWORD');

$configurationValid = $dbHost !== null
    && $dbHost !== ''
    && $dbName !== null
    && $dbName !== ''
    && $dbUser !== null
    && $dbUser !== ''
    && $dbPassword !== null;

$databaseConnected = false;
$errorMessage = null;

if (!$configurationValid) {
    http_response_code(500);
    $errorMessage = 'Brakuje wymaganych zmiennych konfiguracji bazy danych.';
} else {
    try {
        $connection = mysqli_connect(
            $dbHost,
            $dbUser,
            $dbPassword,
            $dbName,
            (int) $dbPort
        );

        mysqli_set_charset($connection, 'utf8mb4');

        $statement = mysqli_prepare($connection, 'SELECT 1');
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        mysqli_close($connection);

        $databaseConnected = true;
    } catch (mysqli_sql_exception) {
        http_response_code(503);
        $errorMessage = 'Nie można połączyć się z bazą danych.';
    }
}

$statusClass = $databaseConnected
    ? 'bg-emerald-500/15 text-emerald-300 ring-emerald-400/30'
    : 'bg-red-500/15 text-red-300 ring-red-400/30';

$statusText = $databaseConnected
    ? 'Połączenie z MariaDB działa poprawnie.'
    : $errorMessage;
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WeFixIT — status środowiska</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="min-h-screen bg-slate-950 font-sans text-slate-100">
    <main class="mx-auto flex min-h-screen max-w-3xl items-center px-6 py-12">
        <section class="w-full rounded-2xl border border-slate-800 bg-slate-900 p-8 shadow-2xl shadow-black/30">
            <p class="mb-3 text-sm font-semibold uppercase tracking-[0.2em] text-cyan-400">
                WeFixIT
            </p>

            <h1 class="text-3xl font-bold tracking-tight text-white">
                Środowisko aplikacji działa
            </h1>

            <p class="mt-3 text-slate-400">
                Testowy bootstrap PHP 8.2 / Apache / MariaDB 11.4.
            </p>

            <div class="mt-8 rounded-xl px-4 py-3 text-sm font-medium ring-1 <?= $statusClass ?>">
                <?= htmlspecialchars($statusText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>

            <dl class="mt-8 grid gap-4 text-sm sm:grid-cols-2">
                <div class="rounded-xl bg-slate-800/70 p-4">
                    <dt class="text-slate-400">Runtime</dt>
                    <dd class="mt-1 font-semibold text-white">
                        PHP <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </dd>
                </div>

                <div class="rounded-xl bg-slate-800/70 p-4">
                    <dt class="text-slate-400">Host bazy</dt>
                    <dd class="mt-1 font-semibold text-white">
                        <?= htmlspecialchars($dbHost ?? 'brak', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </dd>
                </div>
            </dl>
        </section>
    </main>
</body>
</html>
