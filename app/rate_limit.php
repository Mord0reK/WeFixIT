<?php
declare(strict_types=1);

/*
    Limit nieudanych prób logowania.

    Próby grupujemy po SHA-256 znormalizowanego e-maila i adresu IP, więc
    w tabeli nie ma jawnych danych kontaktowych. Limit działa niezależnie od
    sesji: 10 nieudanych prób dla e-maila albo 20 dla IP w oknie 15 minut
    blokuje kolejne logowanie. X-Forwarded-For jest ignorowany, bo klient może
    go dowolnie podmienić.
*/
require_once __DIR__ . '/../public/config.php';

const RATE_LIMIT_WINDOW_MINUTES = 15;
const RATE_LIMIT_EMAIL_MAX = 10;
const RATE_LIMIT_IP_MAX = 20;

function rate_limit_db(): ?PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        error_log('Brak połączenia z bazą w module limitu logowania.');
        return null;
    }

    return $pdo;
}

function rate_limit_email_hash(string $email): string
{
    return hash('sha256', mb_strtolower(trim($email)));
}

function rate_limit_ip_hash(string $ip): string
{
    return hash('sha256', trim($ip));
}

function rate_limit_window_start(): string
{
    return (new DateTimeImmutable('-' . RATE_LIMIT_WINDOW_MINUTES . ' minutes'))
        ->format('Y-m-d H:i:s');
}

function rate_limit_cleanup(): void
{
    $pdo = rate_limit_db();

    if ($pdo === null) {
        return;
    }

    try {
        $statement = $pdo->prepare(
            'DELETE FROM proby_logowania WHERE utworzono < :cutoff'
        );
        $statement->execute(['cutoff' => rate_limit_window_start()]);
    } catch (PDOException $e) {
        error_log('Nie udało się wyczyścić starych prób logowania: ' . $e->getMessage());
    }
}

function rate_limit_record_failure(string $emailHash, string $ipHash): void
{
    $pdo = rate_limit_db();

    if ($pdo === null) {
        return;
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO proby_logowania (email_hash, ip_hash) VALUES (:email_hash, :ip_hash)'
        );
        $statement->execute([
            'email_hash' => $emailHash,
            'ip_hash' => $ipHash,
        ]);
    } catch (PDOException $e) {
        error_log('Nie udało się zapisać nieudanej próby logowania: ' . $e->getMessage());
    }
}

function rate_limit_email_count(PDO $pdo, string $emailHash): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM proby_logowania
         WHERE email_hash = :email_hash AND utworzono >= :cutoff'
    );
    $statement->execute([
        'email_hash' => $emailHash,
        'cutoff' => rate_limit_window_start(),
    ]);

    return (int) $statement->fetchColumn();
}

function rate_limit_ip_count(PDO $pdo, string $ipHash): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM proby_logowania
         WHERE ip_hash = :ip_hash AND utworzono >= :cutoff'
    );
    $statement->execute([
        'ip_hash' => $ipHash,
        'cutoff' => rate_limit_window_start(),
    ]);

    return (int) $statement->fetchColumn();
}

function rate_limit_is_limited(string $emailHash, string $ipHash): bool
{
    $pdo = rate_limit_db();

    if ($pdo === null) {
        return false;
    }

    try {
        return rate_limit_email_count($pdo, $emailHash) >= RATE_LIMIT_EMAIL_MAX
            || rate_limit_ip_count($pdo, $ipHash) >= RATE_LIMIT_IP_MAX;
    } catch (PDOException $e) {
        error_log('Nie udało się sprawdzić limitu logowania: ' . $e->getMessage());
        return false;
    }
}

// Zwraca czas do odblokowania najstarszej próby, która utrzymuje limit.
function rate_limit_retry_after(string $emailHash, string $ipHash): int
{
    $pdo = rate_limit_db();

    if ($pdo === null) {
        return RATE_LIMIT_WINDOW_MINUTES * 60;
    }

    $retryAfter = 0;
    $cutoff = rate_limit_window_start();

    try {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) AS total, MIN(utworzono) AS oldest
             FROM proby_logowania
             WHERE email_hash = :email_hash AND utworzono >= :cutoff'
        );
        $statement->execute([
            'email_hash' => $emailHash,
            'cutoff' => $cutoff,
        ]);
        $email = $statement->fetch(PDO::FETCH_ASSOC);

        if (is_array($email) && (int) $email['total'] >= RATE_LIMIT_EMAIL_MAX) {
            $retryAfter = max($retryAfter, rate_limit_seconds_left((string) $email['oldest']));
        }

        $statement = $pdo->prepare(
            'SELECT COUNT(*) AS total, MIN(utworzono) AS oldest
             FROM proby_logowania
             WHERE ip_hash = :ip_hash AND utworzono >= :cutoff'
        );
        $statement->execute([
            'ip_hash' => $ipHash,
            'cutoff' => $cutoff,
        ]);
        $ip = $statement->fetch(PDO::FETCH_ASSOC);

        if (is_array($ip) && (int) $ip['total'] >= RATE_LIMIT_IP_MAX) {
            $retryAfter = max($retryAfter, rate_limit_seconds_left((string) $ip['oldest']));
        }
    } catch (PDOException $e) {
        error_log('Nie udało się wyliczyć czasu blokady logowania: ' . $e->getMessage());
        return RATE_LIMIT_WINDOW_MINUTES * 60;
    }

    return max(1, $retryAfter);
}

function rate_limit_seconds_left(string $oldest): int
{
    $oldestTime = new DateTimeImmutable($oldest);
    $elapsed = time() - $oldestTime->getTimestamp();
    $window = RATE_LIMIT_WINDOW_MINUTES * 60;

    return min($window, max(1, $window - $elapsed));
}

function rate_limit_clear_email(string $emailHash): void
{
    $pdo = rate_limit_db();

    if ($pdo === null) {
        return;
    }

    try {
        $statement = $pdo->prepare(
            'DELETE FROM proby_logowania WHERE email_hash = :email_hash'
        );
        $statement->execute(['email_hash' => $emailHash]);
    } catch (PDOException $e) {
        error_log('Nie udało się wyczyścić prób logowania: ' . $e->getMessage());
    }
}
