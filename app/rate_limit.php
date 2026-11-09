<?php
declare(strict_types=1);

/*
    Limit nieudanych prób logowania.

    Próby grupujemy po SHA-256 znormalizowanego e-maila i adresu IP, więc
    w tabeli nie ma jawnych danych kontaktowych. Limit działa niezależnie od
    sesji: 10 nieudanych prób dla e-maila albo 20 dla IP w oknie 15 minut
    blokuje kolejne logowanie. X-Forwarded-For jest ignorowany, bo klient może
    go dowolnie podmienić.

    Okno liczymy wyłącznie czasem bazy (NOW()), bo PHP i MariaDB mogą działać
    w różnych strefach czasowych. Dzięki temu cleanup i liczniki używają tego
    samego zegara co DEFAULT CURRENT_TIMESTAMP w tabeli.
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

function rate_limit_cleanup(): void
{
    $pdo = rate_limit_db();

    if ($pdo === null) {
        return;
    }

    try {
        $statement = $pdo->prepare(
            'DELETE FROM proby_logowania
             WHERE utworzono < DATE_SUB(NOW(), INTERVAL '
            . RATE_LIMIT_WINDOW_MINUTES . ' MINUTE)'
        );
        $statement->execute();
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
         WHERE email_hash = :email_hash
           AND utworzono >= DATE_SUB(NOW(), INTERVAL '
        . RATE_LIMIT_WINDOW_MINUTES . ' MINUTE)'
    );
    $statement->execute(['email_hash' => $emailHash]);

    return (int) $statement->fetchColumn();
}

function rate_limit_ip_count(PDO $pdo, string $ipHash): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM proby_logowania
         WHERE ip_hash = :ip_hash
           AND utworzono >= DATE_SUB(NOW(), INTERVAL '
        . RATE_LIMIT_WINDOW_MINUTES . ' MINUTE)'
    );
    $statement->execute(['ip_hash' => $ipHash]);

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
    $retrySql = 'SELECT COUNT(*) AS total,
                        GREATEST(1, TIMESTAMPDIFF(
                            SECOND,
                            NOW(),
                            DATE_ADD(MIN(utworzono), INTERVAL '
        . RATE_LIMIT_WINDOW_MINUTES . ' MINUTE)
                        )) AS retry_after
                 FROM proby_logowania
                 WHERE %s = :hash
                   AND utworzono >= DATE_SUB(NOW(), INTERVAL '
        . RATE_LIMIT_WINDOW_MINUTES . ' MINUTE)';

    try {
        $statement = $pdo->prepare(sprintf($retrySql, 'email_hash'));
        $statement->execute(['hash' => $emailHash]);
        $email = $statement->fetch(PDO::FETCH_ASSOC);

        if (is_array($email) && (int) $email['total'] >= RATE_LIMIT_EMAIL_MAX) {
            $retryAfter = max($retryAfter, (int) $email['retry_after']);
        }

        $statement = $pdo->prepare(sprintf($retrySql, 'ip_hash'));
        $statement->execute(['hash' => $ipHash]);
        $ip = $statement->fetch(PDO::FETCH_ASSOC);

        if (is_array($ip) && (int) $ip['total'] >= RATE_LIMIT_IP_MAX) {
            $retryAfter = max($retryAfter, (int) $ip['retry_after']);
        }
    } catch (PDOException $e) {
        error_log('Nie udało się wyliczyć czasu blokady logowania: ' . $e->getMessage());
        return RATE_LIMIT_WINDOW_MINUTES * 60;
    }

    return max(1, $retryAfter);
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
