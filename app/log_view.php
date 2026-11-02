<?php
declare(strict_types=1);

/*
    Warstwa odczytu dziennika zdarzeń dla administratora.

    Obsługuje filtrowanie, paginację i bezpieczne dekodowanie szczegółów.
    Nie zwraca danych kontaktowych aktora — wyłącznie imię i nazwisko.
    Plik nie zawiera HTML, logiki HTTP ani operacji zapisu.
*/
require_once __DIR__ . '/../public/config.php';
require_once __DIR__ . '/pagination.php';

// Allowlista akcji występujących w kodzie. Nie pobieramy jej z bazy.
const LOG_VIEW_ACTIONS = [
    'kategoria_utworzona',
    'kategoria_zaktualizowana',
    'kategoria_aktywowana',
    'kategoria_dezaktywowana',
    'kategoria_usunieta',
    'usluga_utworzona',
    'usluga_zaktualizowana',
    'usluga_aktywowana',
    'usluga_dezaktywowana',
    'usluga_usunieta',
    'pracownik_utworzony',
    'pracownik_zaktualizowany',
    'pracownik_aktywowany',
    'pracownik_dezaktywowany',
    'pracownik_usuniety',
    'pracownik_uslugi_zaktualizowane',
    'grafik_dodany',
    'grafik_zaktualizowany',
    'grafik_usuniety',
    'nieobecnosc_utworzona',
    'nieobecnosc_zatwierdzona',
    'nieobecnosc_odrzucona',
    'nieobecnosc_anulowana',
    'rezerwacja_status_zmieniony',
    'rezerwacja_anulowana',
    'uzytkownik_rola_zmieniona',
    'uzytkownik_status_zmieniony',
];

const LOG_VIEW_LEVELS = ['info', 'warning', 'critical'];

const LOG_VIEW_OBJECT_TYPES = [
    'kategoria_uslug',
    'usluga',
    'pracownik',
    'uzytkownik',
    'rezerwacja',
];

function log_view_db(): ?PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        error_log('Brak połączenia z bazą w module dziennika zdarzeń.');
        return null;
    }

    return $pdo;
}

function log_view_filter_action(mixed $value): string
{
    return is_string($value) && in_array($value, LOG_VIEW_ACTIONS, true) ? $value : '';
}

function log_view_filter_level(mixed $value): string
{
    return is_string($value) && in_array($value, LOG_VIEW_LEVELS, true) ? $value : '';
}

function log_view_filter_object_type(mixed $value): string
{
    return is_string($value) && in_array($value, LOG_VIEW_OBJECT_TYPES, true) ? $value : '';
}

function log_view_filter_date(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    if ($date === false || $date->format('Y-m-d') !== $value) {
        return null;
    }

    return $value;
}

function log_view_filter_date_end(string $date): string
{
    return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
}

// Uszkodzony lub nieobiektowy JSON nie może przerwać renderowania widoku.
function log_view_details_decode(mixed $raw): ?array
{
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    return is_array($decoded) ? $decoded : null;
}

function log_view_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'aktor_id' => $row['aktor_id'] !== null ? (int) $row['aktor_id'] : null,
        'aktor_imie' => $row['aktor_imie'] !== null ? (string) $row['aktor_imie'] : null,
        'aktor_nazwisko' => $row['aktor_nazwisko'] !== null ? (string) $row['aktor_nazwisko'] : null,
        'akcja' => (string) $row['akcja'],
        'obiekt_typ' => $row['obiekt_typ'] !== null ? (string) $row['obiekt_typ'] : null,
        'obiekt_id' => $row['obiekt_id'] !== null ? (int) $row['obiekt_id'] : null,
        'poziom' => (string) $row['poziom'],
        'opis' => (string) $row['opis'],
        'szczegoly' => log_view_details_decode($row['szczegoly']),
        'utworzono' => (string) $row['utworzono'],
    ];
}

function log_view_list(array $filters = [], int $page = 1, int $size = 25): ?array
{
    $pdo = log_view_db();

    if ($pdo === null) {
        return null;
    }

    $page = pagination_page($page);
    $size = pagination_size($size);
    $action = log_view_filter_action($filters['akcja'] ?? null);
    $level = log_view_filter_level($filters['poziom'] ?? null);
    $objectType = log_view_filter_object_type($filters['obiekt_typ'] ?? null);
    $dateFrom = log_view_filter_date($filters['data_od'] ?? null);
    $dateTo = log_view_filter_date($filters['data_do'] ?? null);
    $conditions = [];
    $params = [];

    if ($action !== '') {
        $conditions[] = 'l.akcja = :akcja';
        $params['akcja'] = $action;
    }

    if ($level !== '') {
        $conditions[] = 'l.poziom = :poziom';
        $params['poziom'] = $level;
    }

    if ($objectType !== '') {
        $conditions[] = 'l.obiekt_typ = :obiekt_typ';
        $params['obiekt_typ'] = $objectType;
    }

    if ($dateFrom !== null) {
        $conditions[] = 'l.utworzono >= :data_od';
        $params['data_od'] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== null) {
        $conditions[] = 'l.utworzono < :data_do_koniec';
        $params['data_do_koniec'] = log_view_filter_date_end($dateTo) . ' 00:00:00';
    }

    $where = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';

    try {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM log_zdarzen l' . $where);
        $statement->execute($params);
        $total = (int) $statement->fetchColumn();
    } catch (PDOException $e) {
        error_log('Nie udało się policzyć zdarzeń dziennika: ' . $e->getMessage());
        return null;
    }

    $pages = pagination_total_pages($total, $size);
    $page = min($page, $pages);
    $offset = pagination_offset($page, $size);

    try {
        $statement = $pdo->prepare(
            'SELECT
                l.id,
                l.aktor_id,
                u.imie AS aktor_imie,
                u.nazwisko AS aktor_nazwisko,
                l.akcja,
                l.obiekt_typ,
                l.obiekt_id,
                l.poziom,
                l.opis,
                l.szczegoly,
                l.utworzono
             FROM log_zdarzen l
             LEFT JOIN uzytkownicy u ON u.id = l.aktor_id'
            . $where
            . ' ORDER BY l.utworzono DESC, l.id DESC '
            . pagination_limit_sql($size, $offset)
        );
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać dziennika zdarzeń: ' . $e->getMessage());
        return null;
    }

    $events = [];

    foreach ($rows as $row) {
        $events[] = log_view_row($row);
    }

    return [
        'items' => $events,
        'total' => $total,
        'page' => $page,
        'size' => $size,
        'pages' => $pages,
    ];
}
