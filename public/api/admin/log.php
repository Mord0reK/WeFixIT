<?php
declare(strict_types=1);

/*
    Endpoint administracyjny dziennika zdarzeń.

    Obsługuje wyłącznie GET: listę wpisów z filtrami, zakresami dat i
    paginacją. Dziennik jest tylko do odczytu, dlatego pozostałe metody
    zwracają 405. Dane kontaktowe aktora nie opuszczają warstwy odczytu.
*/
require_once __DIR__ . '/../../../app/json.php';
require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/log_view.php';

json_require_method(['GET']);

$user = require_api_login();

if (($user['rola'] ?? '') !== 'admin') {
    json_response(403, ['error' => 'Brak uprawnień do przeglądania dziennika zdarzeń.']);
}

$page = pagination_page($_GET['page'] ?? null);
$size = pagination_size($_GET['size'] ?? null);
$result = log_view_list([
    'akcja' => $_GET['akcja'] ?? null,
    'poziom' => $_GET['poziom'] ?? null,
    'obiekt_typ' => $_GET['obiekt_typ'] ?? null,
    'data_od' => $_GET['data_od'] ?? null,
    'data_do' => $_GET['data_do'] ?? null,
], $page, $size);

if ($result === null) {
    json_response(500, ['error' => 'Nie udało się pobrać dziennika zdarzeń. Wystąpił problem z serwerem.']);
}

json_response(200, [
    'success' => true,
    'zdarzenia' => $result['items'],
    'paginacja' => [
        'strona' => $result['page'],
        'rozmiar' => $result['size'],
        'stron' => $result['pages'],
        'razem' => $result['total'],
    ],
]);
