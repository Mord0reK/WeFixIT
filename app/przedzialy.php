<?php
declare(strict_types=1);

/*
    Wspólna reguła nakładania przedziałów czasowych.

    Plik jest celowo samodzielny: nie wczytuje konfiguracji, nie dotyka bazy,
    sesji ani nagłówków HTTP, więc można testować go w izolacji.
*/

/*
    Grafik pracownika i przyszłe rezerwacje muszą używać tej samej definicji
    konfliktu, żeby nie powstały dwie różne interpretacje nakładania.
    Porównujemy wartości leksykalnie, bo MySQL TIME ('HH:MM:SS') i DATETIME
    ('YYYY-MM-DD HH:MM:SS') w stałym formacie sortują się poprawnie jako
    stringi. Styk granic nie jest konfliktem: 10:00-11:00 i 11:00-12:00 mogą
    istnieć obok siebie. Mieszanie formatów TIME i DATETIME jest błędem
    programisty, dlatego zamiast zgadywać wynik rzucamy InvalidArgumentException.
*/
function interval_format(string $value): ?string
{
    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $value) === 1) {
        return 'time';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2} (?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $value) === 1) {
        return 'datetime';
    }

    return null;
}

function intervals_overlap(string $startA, string $endA, string $startB, string $endB): bool
{
    $format = interval_format($startA);

    if (
        $format === null
        || interval_format($endA) !== $format
        || interval_format($startB) !== $format
        || interval_format($endB) !== $format
    ) {
        throw new InvalidArgumentException(
            'Przedziały muszą być w tym samym formacie TIME albo DATETIME.'
        );
    }

    return $startA < $endB && $endA > $startB;
}
