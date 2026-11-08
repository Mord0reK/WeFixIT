<?php
declare(strict_types=1);

/*
    Testy czystej reguły nakładania przedziałów czasowych.

    Grupa nie wykonuje żądań HTTP ani nie korzysta bezpośrednio z bazy danych.
    Sprawdza wyłącznie intervals_overlap() z app/przedzialy.php, w tym styk
    granic, pokrycie, formaty TIME/DATETIME i odrzucanie mieszanych formatów.
*/

require_once __DIR__ . '/../app/przedzialy.php';

test_group('Przedziały czasowe', 'database/database.sql');

test('142. całkowicie rozłączne przedziały nie kolidują', function (): void {
    assert_same(
        false,
        intervals_overlap('08:00:00', '09:00:00', '09:30:00', '10:30:00'),
        'Późniejszy przedział po przerwie nie powinien kolidować'
    );
    assert_same(
        false,
        intervals_overlap('09:30:00', '10:30:00', '08:00:00', '09:00:00'),
        'Wcześniejszy przedział po przerwie nie powinien kolidować'
    );
});

test('143. styk granic z obu stron nie jest konfliktem', function (): void {
    assert_same(
        false,
        intervals_overlap('10:00:00', '11:00:00', '11:00:00', '12:00:00'),
        'Koniec pierwszego równy początkowi drugiego nie może kolidować'
    );
    assert_same(
        false,
        intervals_overlap('11:00:00', '12:00:00', '10:00:00', '11:00:00'),
        'Początek pierwszego równy końcowi drugiego nie może kolidować'
    );
});

test('144. pełne pokrycie przedziału jest konfliktem', function (): void {
    assert_true(
        intervals_overlap('08:00:00', '12:00:00', '09:00:00', '10:00:00'),
        'Szerszy przedział powinien kolidować z zawartym w nim węższym'
    );
    assert_true(
        intervals_overlap('09:00:00', '10:00:00', '08:00:00', '12:00:00'),
        'Węższy przedział powinien kolidować z obejmującym go szerszym'
    );
});

test('145. częściowe nakładanie z lewej i prawej strony jest konfliktem', function (): void {
    assert_true(
        intervals_overlap('09:00:00', '11:00:00', '10:00:00', '12:00:00'),
        'Nachodzenie prawym końcem na początek drugiego przedziału powinno kolidować'
    );
    assert_true(
        intervals_overlap('10:00:00', '12:00:00', '09:00:00', '11:00:00'),
        'Nachodzenie lewym początkiem na koniec drugiego przedziału powinno kolidować'
    );
});

test('146. identyczne przedziały kolidują', function (): void {
    assert_true(
        intervals_overlap('10:00:00', '11:00:00', '10:00:00', '11:00:00'),
        'Ten sam przedział powinien być konfliktem'
    );
});

test('147. przedział zerowej długości podlega dokładnie warunkowi nakładania', function (): void {
    assert_same(
        false,
        intervals_overlap('10:00:00', '10:00:00', '10:00:00', '11:00:00'),
        'Zerowy przedział na granicy drugiego nie powinien kolidować'
    );
    assert_same(
        false,
        intervals_overlap('10:00:00', '10:00:00', '10:00:00', '10:00:00'),
        'Dwa zerowe przedziały w tym samym punkcie nie powinny kolidować'
    );
    assert_same(
        true,
        intervals_overlap('10:30:00', '10:30:00', '10:00:00', '11:00:00'),
        'Zerowy przedział wewnątrz drugiego spełnia warunek start < end i end > start'
    );
});

test('148. reguła działa dla formatu TIME i DATETIME', function (): void {
    assert_same(
        false,
        intervals_overlap(
            '2026-05-10 08:00:00',
            '2026-05-10 09:00:00',
            '2026-05-10 09:00:00',
            '2026-05-10 10:00:00'
        ),
        'Styk granic w formacie DATETIME nie powinien kolidować'
    );
    assert_true(
        intervals_overlap(
            '2026-05-10 23:30:00',
            '2026-05-11 00:30:00',
            '2026-05-11 00:15:00',
            '2026-05-11 01:00:00'
        ),
        'Przedziały DATETIME przechodzące przez północ powinny kolidować'
    );
    assert_same(
        false,
        intervals_overlap(
            '2026-05-10 08:00:00',
            '2026-05-10 09:00:00',
            '2026-05-11 08:00:00',
            '2026-05-11 09:00:00'
        ),
        'Przedziały DATETIME w różnych dniach nie powinny kolidować'
    );
});

test('149. mieszanie formatów TIME i DATETIME jest odrzucane', function (): void {
    $thrown = false;

    try {
        intervals_overlap(
            '10:00:00',
            '11:00:00',
            '2026-05-10 10:30:00',
            '2026-05-10 11:30:00'
        );
    } catch (InvalidArgumentException) {
        $thrown = true;
    }

    assert_true($thrown, 'Różne formaty w jednym wywołaniu powinny rzucić InvalidArgumentException');

    $thrown = false;

    try {
        intervals_overlap('10:00', '11:00', '10:30', '11:30');
    } catch (InvalidArgumentException) {
        $thrown = true;
    }

    assert_true($thrown, 'Nieobsługiwany format HH:MM powinien rzucić InvalidArgumentException');
});
