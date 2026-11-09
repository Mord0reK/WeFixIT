<?php
declare(strict_types=1);

/*
    Testy dostępności obejmującej stykające się wpisy grafiku.

    Sprawdzają, że wizyta może przekroczyć granicę dwóch kolejnych przedziałów
    tylko przy zerowej przerwie, a wyrównanie do siatki nadal liczy się od
    początku pierwszego użytego wpisu.
*/
require_once __DIR__ . '/../app/availability.php';

test_group('Dostępność — styk grafiku', 'database/database.sql');

function availability_schedule_replace(int $employeeId, int $dayOfWeek, array $intervals): void
{
    $pdo = test_db();
    $statement = $pdo->prepare(
        'DELETE FROM godziny_pracy
         WHERE pracownik_id = :pracownik_id AND dzien_tygodnia = :dzien_tygodnia'
    );
    $statement->execute([
        'pracownik_id' => $employeeId,
        'dzien_tygodnia' => $dayOfWeek,
    ]);

    $statement = $pdo->prepare(
        'INSERT INTO godziny_pracy (
            pracownik_id, dzien_tygodnia, czas_rozpoczecia, czas_zakonczenia, aktywne
         ) VALUES (
            :pracownik_id, :dzien_tygodnia, :czas_rozpoczecia, :czas_zakonczenia, TRUE
         )'
    );

    foreach ($intervals as [$start, $end]) {
        $statement->execute([
            'pracownik_id' => $employeeId,
            'dzien_tygodnia' => $dayOfWeek,
            'czas_rozpoczecia' => $start,
            'czas_zakonczenia' => $end,
        ]);
    }
}

test('246. wizyta obejmująca dwa stykające się wpisy grafiku jest akceptowana', function (): void {
    availability_schedule_replace(1, 1, [
        ['08:00:00', '12:00:00'],
        ['12:00:00', '16:00:00'],
    ]);

    assert_same(
        true,
        availability_interval_is_free(
            1,
            '2030-01-07',
            '2030-01-07 11:00:00',
            '2030-01-07 13:00:00'
        ),
        'Wizyta 11:00-13:00 powinna przejść przez styk 12:00'
    );
    assert_same(
        false,
        availability_interval_is_free(
            1,
            '2030-01-07',
            '2030-01-07 11:10:00',
            '2030-01-07 13:10:00'
        ),
        'Wyrównanie do siatki powinno liczyć się od początku pierwszego wpisu'
    );
});

test('247. przerwa między wpisami grafiku dyskwalifikuje wizytę', function (): void {
    availability_schedule_replace(1, 1, [
        ['08:00:00', '12:00:00'],
        ['12:30:00', '16:00:00'],
    ]);

    assert_same(
        false,
        availability_interval_is_free(
            1,
            '2030-01-07',
            '2030-01-07 11:00:00',
            '2030-01-07 13:00:00'
        ),
        'Przerwa 12:00-12:30 nie może zostać pominięta'
    );
});

test('248. wizyta wychodząca poza ostatni wpis grafiku jest odrzucana', function (): void {
    availability_schedule_replace(1, 1, [
        ['08:00:00', '12:00:00'],
        ['12:00:00', '16:00:00'],
    ]);

    assert_same(
        false,
        availability_interval_is_free(
            1,
            '2030-01-07',
            '2030-01-07 15:00:00',
            '2030-01-07 17:00:00'
        ),
        'Wizyta nie może wystawać poza koniec ostatniego wpisu'
    );
});

test('249. pojedynczy wpis grafiku zachowuje dotychczasowe zachowanie', function (): void {
    availability_schedule_replace(1, 1, [
        ['08:00:00', '16:00:00'],
    ]);

    assert_same(
        true,
        availability_interval_is_free(
            1,
            '2030-01-07',
            '2030-01-07 09:00:00',
            '2030-01-07 10:00:00'
        ),
        'Wizyta w jednym wpisie powinna nadal być akceptowana'
    );
    assert_same(
        false,
        availability_interval_is_free(
            1,
            '2030-01-07',
            '2030-01-07 09:10:00',
            '2030-01-07 10:10:00'
        ),
        'Wizyta poza siatką 15 minut nadal powinna być odrzucana'
    );
});

test('250. grafik 08:00-24:00 nadal obsługuje koniec dnia', function (): void {
    availability_schedule_replace(1, 1, [
        ['08:00:00', '24:00:00'],
    ]);

    assert_same(
        true,
        availability_interval_is_free(
            1,
            '2030-01-07',
            '2030-01-07 23:00:00',
            '2030-01-08 00:00:00'
        ),
        'Wizyta 23:00-24:00 powinna być akceptowana'
    );
});
