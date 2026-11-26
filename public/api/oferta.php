<?php
declare(strict_types=1);

/*
    Endpoint GET /api/oferta.

    Zwraca aktywne kategorie i usługi widoczne na stronie oferty, opcjonalnie
    zawężone do jednej kategorii. Endpoint jest publiczny jak sama strona:
    dane nie zawierają niczego poza ofertą dostępną bez logowania, więc nie
    wymaga sesji ani tokenu CSRF (odczyt metodą GET).
*/

require_once __DIR__ . '/../../app/json.php';
require_once __DIR__ . '/../../app/oferta.php';

json_require_method(['GET']);

$categoryId = oferta_category_filter($_GET['kategoria'] ?? null);
$search = pagination_search_value($_GET['szukaj'] ?? null);
$categories = oferta_kategorie();
$services = oferta_uslugi($categoryId, $search);

if ($categories === null || $services === null) {
    json_response(500, ['error' => 'Nie udało się pobrać oferty. Wystąpił problem z serwerem.']);
}

json_response(200, [
    'success' => true,
    'kategorie' => $categories,
    'uslugi' => $services,
]);
