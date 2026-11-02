<?php
declare(strict_types=1);

/*
    Eksport CSV rezerwacji i użytkowników dla administratora.

    Eksport jest wyłącznie odczytem: nie wymaga CSRF i nie zapisuje zdarzenia
    do logu. Wartości z bazy są neutralizowane przed zapisem, żeby arkusz nie
    wykonał formuły pochodzącej z danych klienta.
*/
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/rezerwacje.php';
require_once dirname(__DIR__) . '/app/uzytkownicy.php';

function csv_export_cell(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    $string = (string) $value;

    if ($string !== '' && in_array($string[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true)) {
        return "'" . $string;
    }

    return $string;
}

function csv_export_write_row($stream, array $row): void
{
    fputcsv(
        $stream,
        array_map('csv_export_cell', $row),
        ';'
    );
}

$user = require_role(['admin']);
$type = $_GET['typ'] ?? null;

if (!is_string($type) || !in_array($type, ['rezerwacje', 'uzytkownicy'], true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nie znaleziono eksportu.';
    exit;
}

if ($type === 'rezerwacje') {
    $filters = [
        'data_od' => $_GET['data_od'] ?? null,
        'data_do' => $_GET['data_do'] ?? null,
        'status' => $_GET['status'] ?? null,
        'pracownik_id' => $_GET['pracownik_id'] ?? null,
        'usluga_id' => $_GET['usluga_id'] ?? null,
        'uzytkownik_id' => $_GET['uzytkownik_id'] ?? null,
        'kierunek' => $_GET['kierunek'] ?? null,
        'szukaj' => $_GET['szukaj'] ?? null,
    ];
    $rows = rezerwacje_list($filters);
    $filename = 'rezerwacje-' . date('Y-m-d') . '.csv';

    /*
        Rezerwacja jest dokumentem, dlatego eksport celowo zawiera e-mail
        klienta. Eksport użytkowników zawiera e-mail wyłącznie jako
        identyfikator konta i nigdy nie zawiera telefonu ani hasła.
    */
    $headers = [
        'ID',
        'Data rozpoczęcia',
        'Data zakończenia',
        'Klient',
        'E-mail klienta',
        'Pracownik',
        'Usługa',
        'Kategoria',
        'Cena historyczna',
        'Status',
        'Komentarz klienta',
        'Anulowano',
        'Utworzono',
        'Zaktualizowano',
    ];
    $statusLabels = [
        'oczekujaca' => 'Oczekująca',
        'potwierdzona' => 'Potwierdzona',
        'w_trakcie' => 'W trakcie',
        'zrealizowana' => 'Zrealizowana',
        'anulowana' => 'Anulowana',
    ];
} else {
    $role = uzytkownik_role_filter($_GET['rola'] ?? null);
    $status = uzytkownik_status_filter($_GET['status'] ?? null);
    $search = pagination_search_value($_GET['szukaj'] ?? null);
    $rows = uzytkownicy_list($role, $status, $search);
    $filename = 'uzytkownicy-' . date('Y-m-d') . '.csv';
    $headers = [
        'ID',
        'Imię',
        'Nazwisko',
        'E-mail',
        'Rola',
        'Aktywny',
        'Utworzono',
    ];
    $roleLabels = [
        'klient' => 'Klient',
        'pracownik' => 'Pracownik',
        'admin' => 'Administrator',
    ];
}

if ($rows === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nie udało się przygotować eksportu.';
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'wb');

if ($output === false) {
    exit;
}

csv_export_write_row($output, $headers);

foreach ($rows as $row) {
    if ($type === 'rezerwacje') {
        csv_export_write_row($output, [
            $row['id'],
            $row['czas_rozpoczecia'],
            $row['czas_zakonczenia'],
            $row['klient_imie'] . ' ' . $row['klient_nazwisko'],
            $row['klient_email'],
            $row['pracownik_imie'] . ' ' . $row['pracownik_nazwisko'],
            $row['usluga_nazwa'],
            $row['usluga_kategoria'],
            number_format((float) $row['cena_historyczna'], 2, ',', ''),
            $statusLabels[$row['status_rezerwacji']] ?? $row['status_rezerwacji'],
            $row['komentarz_klienta'] ?? '',
            $row['anulowano'] ?? '',
            $row['utworzono'],
            $row['zaktualizowano'],
        ]);
    } else {
        csv_export_write_row($output, [
            $row['id'],
            $row['imie'],
            $row['nazwisko'],
            $row['email'],
            $roleLabels[$row['rola']] ?? $row['rola'],
            $row['aktywny'] ? 'Tak' : 'Nie',
            $row['utworzono'],
        ]);
    }
}

fclose($output);
exit;
