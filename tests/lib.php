<?php
declare(strict_types=1);

/*
    Wspólne helpery testów integracyjnych Etapu 2.

    Zawiera mini-klienta HTTP opartego o cURL (obsługa cookies i nagłówków),
    prosty rejestr asercji oraz dostęp do izolowanej bazy testowej tworzonej
    od zera z plików w database/. Klient zachowuje się jak przeglądarka:
    sam przechowuje cookie sesyjne i wysyła je w kolejnych żądaniach.
*/

final class TestFailure extends RuntimeException
{
}

// ---------------------------------------------------------------------------
// Rejestr testów
// ---------------------------------------------------------------------------

function test_group(string $name, string $sqlFile): void
{
    if (!isset($GLOBALS['__groups'])) {
        $GLOBALS['__groups'] = [];
    }

    $GLOBALS['__groups'][$name] = ['sql' => $sqlFile, 'tests' => []];
    $GLOBALS['__current_group'] = $name;
}

function test(string $name, callable $fn): void
{
    $group = $GLOBALS['__current_group'] ?? null;

    if ($group === null) {
        throw new RuntimeException('test() wywołane poza grupą testów.');
    }

    $GLOBALS['__groups'][$group]['tests'][] = ['name' => $name, 'fn' => $fn];
}

function test_base_url(): string
{
    return $GLOBALS['__base_url'] ?? '';
}

// ---------------------------------------------------------------------------
// Asercje
// ---------------------------------------------------------------------------

function fail(string $message): void
{
    throw new TestFailure($message);
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ' (oczekiwano: ' . var_export($expected, true) . ', otrzymano: ' . var_export($actual, true) . ')');
    }
}

function assert_status(int $expected, array $response, string $message): void
{
    if ($response['status'] !== $expected) {
        fail($message . ' (oczekiwano HTTP ' . $expected . ', otrzymano HTTP ' . $response['status']
            . '; treść: ' . substr($response['body'], 0, 200) . ')');
    }
}

function assert_array_key(array $data, string $key, string $message): void
{
    if (!array_key_exists($key, $data)) {
        fail($message . ' (brak klucza: ' . $key . ')');
    }
}

function assert_array_has_not_key(array $data, string $key, string $message): void
{
    if (array_key_exists($key, $data)) {
        fail($message . ' (nieoczekiwany klucz: ' . $key . ')');
    }
}

// ---------------------------------------------------------------------------
// Mini-klient HTTP
// ---------------------------------------------------------------------------

// Wykonuje żądanie HTTP i zwraca status, nagłówki oraz treść. Cookies są
// przekazywane przez referencję, więc kolejne żądania tej samej "przeglądarki"
// współdzielą sesję.
function http_request(array &$cookies, string $method, string $path, array $options = []): array
{
    $url = test_base_url() . $path;
    $requestHeaders = [];
    $body = $options['body'] ?? null;

    foreach (($options['headers'] ?? []) as $name => $value) {
        $requestHeaders[] = $name . ': ' . $value;
    }

    if (array_key_exists('json', $options)) {
        $body = json_encode($options['json'], JSON_UNESCAPED_UNICODE);
        $requestHeaders[] = 'Content-Type: application/json';
    }

    if (isset($options['content_type'])) {
        $requestHeaders[] = 'Content-Type: ' . $options['content_type'];
    }

    if ($cookies !== []) {
        $pairs = [];

        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        $requestHeaders[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $responseHeaders = [];

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FOLLOWLOCATION => (bool) ($options['follow'] ?? false),
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $trimmed = trim($line);

            // Linia statusu nie zawiera dwukropka i nie jest nagłówkiem.
            if ($trimmed === '' || strpos($trimmed, ':') === false) {
                return $length;
            }

            [$name, $value] = explode(':', $trimmed, 2);
            $responseHeaders[strtolower(trim($name))][] = trim($value);

            return $length;
        },
    ]);

    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($curl);

    if ($responseBody === false) {
        $error = curl_error($curl);
        curl_close($curl);
        fail('Błąd połączenia HTTP: ' . $error);
    }

    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    store_cookies($cookies, $responseHeaders['set-cookie'] ?? []);

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => (string) $responseBody,
    ];
}

// Aktualizuje lokalny magazyn cookies na podstawie nagłówków Set-Cookie.
function store_cookies(array &$cookies, array $setCookieLines): void
{
    foreach ($setCookieLines as $line) {
        $attributes = explode(';', $line);
        $pair = trim((string) array_shift($attributes));
        $separator = strpos($pair, '=');

        if ($separator === false) {
            continue;
        }

        $name = trim(substr($pair, 0, $separator));
        $value = trim(substr($pair, $separator + 1));
        $expired = false;

        foreach ($attributes as $attribute) {
            $attribute = trim($attribute);
            $attributeSeparator = strpos($attribute, '=');
            $key = $attributeSeparator === false
                ? strtolower($attribute)
                : strtolower(trim(substr($attribute, 0, $attributeSeparator)));
            $attributeValue = $attributeSeparator === false
                ? ''
                : trim(substr($attribute, $attributeSeparator + 1));

            if ($key === 'max-age' && (int) $attributeValue <= 0) {
                $expired = true;
            }

            if ($key === 'expires') {
                $timestamp = strtotime($attributeValue);

                if ($timestamp !== false && $timestamp < time()) {
                    $expired = true;
                }
            }
        }

        if ($name === '' || $value === '' || $expired) {
            unset($cookies[$name]);
        } else {
            $cookies[$name] = $value;
        }
    }
}

function header_value(array $response, string $name): ?string
{
    $values = $response['headers'][strtolower($name)] ?? null;

    return is_array($values) && $values !== [] ? $values[0] : null;
}

function json_body(array $response): array
{
    $decoded = json_decode($response['body'], true);

    if (!is_array($decoded)) {
        fail('Odpowiedź nie jest poprawnym JSON: ' . substr($response['body'], 0, 200));
    }

    return $decoded;
}

// ---------------------------------------------------------------------------
// Scenariusze pomocnicze
// ---------------------------------------------------------------------------

// Odczytuje token CSRF ze strony tak, jak robi to frontend (z osadzonego w HTML).
function csrf_from_page(array &$cookies, string $path): string
{
    $response = http_request($cookies, 'GET', $path);
    assert_status(200, $response, 'Nie udało się pobrać strony ' . $path);

    if (preg_match('/const csrfToken = "([0-9a-f]+)"/', $response['body'], $matches) !== 1) {
        fail('Nie znaleziono tokenu CSRF na stronie ' . $path);
    }

    return $matches[1];
}

function post_json(array &$cookies, string $path, array $payload, ?string $csrf = null): array
{
    $headers = $csrf === null ? [] : ['X-CSRF-Token' => $csrf];

    return http_request($cookies, 'POST', $path, ['json' => $payload, 'headers' => $headers]);
}

function patch_json(array &$cookies, string $path, array $payload, ?string $csrf = null): array
{
    $headers = $csrf === null ? [] : ['X-CSRF-Token' => $csrf];

    return http_request($cookies, 'PATCH', $path, ['json' => $payload, 'headers' => $headers]);
}

function delete_json(array &$cookies, string $path, array $payload, ?string $csrf = null): array
{
    $headers = $csrf === null ? [] : ['X-CSRF-Token' => $csrf];

    return http_request($cookies, 'DELETE', $path, ['json' => $payload, 'headers' => $headers]);
}

// Loguje użytkownika i zwraca cookies, token CSRF oraz odpowiedź endpointu.
function login_as(string $email, string $password = 'haslo123'): array
{
    $cookies = [];
    $csrf = csrf_from_page($cookies, '/login.php');
    $response = post_json($cookies, '/api/auth/login.php', [
        'email' => $email,
        'password' => $password,
    ], $csrf);

    return ['cookies' => $cookies, 'csrf' => $csrf, 'response' => $response];
}

// ---------------------------------------------------------------------------
// Baza danych testowa
// ---------------------------------------------------------------------------

function test_db_name(): string
{
    $name = getenv('DB_NAME');

    return is_string($name) && $name !== '' ? $name : 'wefixit_test';
}

function test_db_host(): string
{
    $host = getenv('DB_HOST');

    return is_string($host) && $host !== '' ? $host : '127.0.0.1';
}

function test_db_port(): string
{
    $port = getenv('DB_PORT');

    return is_string($port) && $port !== '' ? $port : '3306';
}

function test_db_user(): string
{
    $user = getenv('DB_USER');

    return is_string($user) && $user !== '' ? $user : 'root';
}

function test_db_password(): string
{
    $password = getenv('DB_PASSWORD');

    return is_string($password) ? $password : '';
}

function test_db_dsn(bool $withDatabase): string
{
    $dsn = 'mysql:host=' . test_db_host() . ';port=' . test_db_port() . ';charset=utf8mb4';

    if ($withDatabase) {
        $dsn .= ';dbname=' . test_db_name();
    }

    return $dsn;
}

function test_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO(test_db_dsn(true), test_db_user(), test_db_password(), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

// Resetuje bazę testową do stanu z podanego pliku SQL. Bazę najpierw tworzymy
// (jeśli pozwalają na to uprawnienia), a potem importujemy schemat i dane —
// dzięki temu każde uruchomienie startuje z czystego stanu.
function reset_database(string $sqlFile): void
{
    $name = test_db_name();

    if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
        throw new RuntimeException('Niepoprawna nazwa bazy testowej: ' . $name);
    }

    $sqlPath = realpath(__DIR__ . '/../' . $sqlFile);
    $databaseDir = realpath(__DIR__ . '/../database');

    if ($sqlPath === false || $databaseDir === false || !str_starts_with($sqlPath, $databaseDir)) {
        throw new RuntimeException('Nie znaleziono pliku SQL: ' . $sqlFile);
    }

    try {
        $server = new PDO(test_db_dsn(false), test_db_user(), test_db_password(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    } catch (PDOException) {
        // Brak uprawnień CREATE DATABASE nie jest błędem, gdy baza już istnieje.
    }

    test_db()->exec((string) file_get_contents($sqlPath));
}

// ---------------------------------------------------------------------------
// Serwer wbudowany PHP
// ---------------------------------------------------------------------------

function test_http_port(): int
{
    $configured = getenv('TEST_HTTP_PORT');

    if (is_string($configured) && $configured !== '') {
        return (int) $configured;
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

    if ($socket === false) {
        throw new RuntimeException('Nie udało się znaleźć wolnego portu: ' . $errorMessage);
    }

    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($address, strrpos($address, ':') + 1);
}

function start_test_server(): array
{
    $port = test_http_port();
    $documentRoot = realpath(__DIR__ . '/../public');
    $logFile = tempnam(sys_get_temp_dir(), 'wefixit-server-');

    if ($documentRoot === false || $logFile === false) {
        throw new RuntimeException('Nie udało się przygotować katalogu testów.');
    }

    $GLOBALS['__test_server_log'] = $logFile;

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $logFile, 'a'],
        2 => ['file', $logFile, 'a'],
    ];

    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $documentRoot];
    $process = proc_open($command, $descriptors, $pipes, $documentRoot);

    if (!is_resource($process)) {
        throw new RuntimeException('Nie udało się uruchomić wbudowanego serwera PHP.');
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    $GLOBALS['__base_url'] = 'http://127.0.0.1:' . $port;

    $deadline = microtime(true) + 20;
    $ready = false;

    while (microtime(true) < $deadline) {
        $probeCookies = [];

        try {
            $response = http_request($probeCookies, 'GET', '/login.php');

            if ($response['status'] > 0) {
                $ready = true;
                break;
            }
        } catch (TestFailure) {
            usleep(200000);
        }
    }

    return ['process' => $process, 'port' => $port, 'log' => $logFile, 'ready' => $ready];
}

function stop_test_server(array $server): void
{
    $process = $server['process'] ?? null;

    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
}

// Zwraca linie logu serwera zawierające błędy/ostrzeżenia PHP.
function server_log_errors(string $logFile): array
{
    if (!is_file($logFile)) {
        return [];
    }

    $errors = [];

    foreach (explode("\n", (string) file_get_contents($logFile)) as $line) {
        if (preg_match('/PHP (Fatal error|Parse error|Warning|Notice|Deprecated)/', $line) === 1) {
            $errors[] = trim($line);
        }
    }

    return $errors;
}

// ---------------------------------------------------------------------------
// Uruchamianie
// ---------------------------------------------------------------------------

function run_test_suite(): int
{
    $groups = $GLOBALS['__groups'] ?? [];
    $passed = 0;
    $failed = 0;

    foreach ($groups as $groupName => $group) {
        echo "\n== " . $groupName . " (baza z " . $group['sql'] . ") ==\n";
        reset_database($group['sql']);

        foreach ($group['tests'] as $case) {
            try {
                ($case['fn'])();
                echo '  PASS  ' . $case['name'] . "\n";
                $passed++;
            } catch (TestFailure $failure) {
                echo '  FAIL  ' . $case['name'] . "\n";
                echo '        ' . $failure->getMessage() . "\n";
                $failed++;
            } catch (Throwable $error) {
                echo '  FAIL  ' . $case['name'] . "\n";
                echo '        ' . get_class($error) . ': ' . $error->getMessage() . "\n";
                $failed++;
            }
        }
    }

    echo "\n----------------------------------------\n";
    echo 'Wynik: ' . $passed . ' poprawnych, ' . $failed . ' niepoprawnych.' . "\n";

    return $failed === 0 ? 0 : 1;
}
