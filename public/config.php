<?php
session_start();

/*
    * Wczytywanie zmiennych środowiskowych z pliku .env
    * w XAMPP trzeba wczytywanie wszystko z pliku .env, bo inaczej nie działa
 */
$env = getenv();

$envFile = dirname(__DIR__) . '/.env';

if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if (strncmp($line, 'export ', 7) === 0) {
            $line = trim(substr($line, 7));
        }

        $separator = strpos($line, '=');

        if ($separator === false) {
            continue;
        }

        $name = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));

        if ($name === '' || array_key_exists($name, $env)) {
            continue;
        }

        if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];
            $closing = strrpos($value, $quote);
            $value = $closing === false
                ? substr($value, 1)
                : substr($value, 1, $closing - 1);
        } else {
            $comment = strpos($value, ' #');

            if ($comment !== false) {
                $value = rtrim(substr($value, 0, $comment));
            }
        }

        $env[$name] = $value;
    }
}

$host = isset($env['DB_HOST']) ? $env['DB_HOST'] : 'localhost';
$port = isset($env['DB_PORT']) ? $env['DB_PORT'] : '3306';
$dbname = isset($env['DB_NAME']) ? $env['DB_NAME'] : 'wefixit';
$user = isset($env['DB_USER']) ? $env['DB_USER'] : 'root';
$pass = isset($env['DB_PASSWORD']) ? $env['DB_PASSWORD'] : '';

$bazaErr = false;

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname", $user, $pass);
    $pdo->query('SET NAMES utf8');
} catch (PDOException $e) {
    $e->getMessage();
    $bazaErr = true;
}
