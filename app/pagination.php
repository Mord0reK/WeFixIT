<?php
declare(strict_types=1);

/*
    Wspólne funkcje paginacji i wyszukiwania list panelu.

    Plik zawiera wyłącznie czyste funkcje: walidację rozmiaru strony, numeru
    strony, obliczanie offsetu i liczby stron oraz przygotowanie fragmentu
    LIMIT/OFFSET. Nie zawiera HTML, PDO ani operacji zapisu.
*/

/*
    Allowlista jest zamknięta i zawiera wyłącznie wartości dopuszczalne
    w interfejsie. Wartość spoza listy wraca do rozmiaru domyślnego.
*/
const PAGINATION_PAGE_SIZES = [1, 2, 10, 25, 50, 100];
const PAGINATION_DEFAULT_SIZE = 25;

function pagination_size(mixed $value): int
{
    if (is_int($value)) {
        $size = $value;
    } elseif (is_string($value) && $value !== '' && ctype_digit($value)) {
        $size = (int) $value;
    } else {
        return PAGINATION_DEFAULT_SIZE;
    }

    return in_array($size, PAGINATION_PAGE_SIZES, true)
        ? $size
        : PAGINATION_DEFAULT_SIZE;
}

function pagination_page(mixed $value): int
{
    if (is_int($value)) {
        return $value >= 1 ? $value : 1;
    }

    if (is_string($value) && $value !== '' && ctype_digit($value)) {
        $page = (int) $value;

        return $page >= 1 ? $page : 1;
    }

    return 1;
}

function pagination_offset(int $page, int $size): int
{
    $page = max(1, $page);
    $size = max(1, $size);

    if ($page - 1 > intdiv(PHP_INT_MAX, $size)) {
        return PHP_INT_MAX;
    }

    return ($page - 1) * $size;
}

/*
    Minimum jednej strony zapobiega stronie 0 dla pustego wyniku. Listy używają
    tej wartości do sprowadzenia numeru spoza zakresu do ostatniej strony.
*/
function pagination_total_pages(int $total, int $size): int
{
    if ($total <= 0 || $size <= 0) {
        return 1;
    }

    return (int) ceil($total / $size);
}

/*
    Fraza jest ucinana do 100 znaków zamiast odrzucana: formularze mają
    maxlength=100, a API ma odpowiadać 200 na zbyt długi parametr, nie 422.
*/
function pagination_search_value(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }

    return mb_substr(trim($value), 0, 100);
}

// Escapuje znaki specjalne LIKE, żeby % i _ były traktowane dosłownie.
function pagination_like_pattern(string $search): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
}

/*
    LIMIT/OFFSET nie mogą być bindowane jako parametry prepared statement:
    PDO z emulacją prepared statements wstawia je jako string, a MariaDB
    odrzuca taki LIMIT. Dlatego wartości są rzutowane na int i wstawiane
    jako literały. Pochodzą wyłącznie z allowlisty i obliczeń, nie wprost
    z żądania klienta.
*/
function pagination_limit_sql(int $size, int $offset): string
{
    $size = pagination_size($size);
    $offset = max(0, $offset);

    return 'LIMIT ' . $size . ' OFFSET ' . $offset;
}
