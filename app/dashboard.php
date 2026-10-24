<?php
declare(strict_types=1);

/*
    Statystyki dashboardu administratora.

    Wszystkie wartości są liczone zapytaniami SQL. Gdy połączenie z bazą jest
    niedostępne, funkcja zwraca bezpieczny zestaw zer z flagą available = false,
    dzięki czemu widok pokazuje komunikat zamiast błędu technicznego.
*/
require_once __DIR__ . '/../public/config.php';

function admin_dashboard_stats(): array
{
    $empty = [
        'available' => false,
        'clients_total' => 0,
        'employees_active' => 0,
        'services_total' => 0,
        'services_active' => 0,
        'reservations_total' => 0,
        'reservations_today' => 0,
    ];

    $pdo = $GLOBALS['pdo'] ?? null;
    $bazaErr = $GLOBALS['bazaErr'] ?? false;

    if ($bazaErr === true || !$pdo instanceof PDO) {
        error_log('Brak połączenia z bazą w module dashboardu.');
        return $empty;
    }

    try {
        $row = $pdo->query(
            "SELECT
                (SELECT COUNT(*) FROM uzytkownicy WHERE rola = 'klient') AS clients_total,
                (SELECT COUNT(*) FROM pracownicy WHERE aktywny = TRUE) AS employees_active,
                (SELECT COUNT(*) FROM uslugi) AS services_total,
                (SELECT COUNT(*) FROM uslugi WHERE aktywna = TRUE) AS services_active,
                (SELECT COUNT(*) FROM rezerwacje) AS reservations_total,
                (SELECT COUNT(*) FROM rezerwacje
                 WHERE czas_rozpoczecia >= CURDATE()
                   AND czas_rozpoczecia < CURDATE() + INTERVAL 1 DAY) AS reservations_today"
        )->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać statystyk dashboardu: ' . $e->getMessage());
        return $empty;
    }

    if (!is_array($row)) {
        return $empty;
    }

    return [
        'available' => true,
        'clients_total' => (int) ($row['clients_total'] ?? 0),
        'employees_active' => (int) ($row['employees_active'] ?? 0),
        'services_total' => (int) ($row['services_total'] ?? 0),
        'services_active' => (int) ($row['services_active'] ?? 0),
        'reservations_total' => (int) ($row['reservations_total'] ?? 0),
        'reservations_today' => (int) ($row['reservations_today'] ?? 0),
    ];
}
