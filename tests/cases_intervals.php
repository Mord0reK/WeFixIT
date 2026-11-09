<?php
declare(strict_types=1);

/*
    Testy czystych reguł przedziałów czasowych i budowy slotów.

    Grupa nie używa bazy do asercji ani nie wykonuje żądań HTTP, ale mechanizm
    test_group() wymaga wskazania pliku SQL i wykonuje reset bazy przed grupą.
    Sprawdza intervals_overlap() oraz interval_slots_build() z
    app/przedzialy.php, w tym styk granic, pokrycie, formaty TIME/DATETIME,
    odrzucanie mieszanych formatów i brak nachodzenia slotów.
*/

require_once __DIR__ . '/../app/przedzialy.php';

test_group('Przedziały czasowe', 'database/database.sql');

function interval_slots_have_overlap(array $slots): bool
{
    $count = count($slots);

    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            if (intervals_overlap(
                (string) $slots[$i]['start'],
                (string) $slots[$i]['end'],
                (string) $slots[$j]['start'],
                (string) $slots[$j]['end']
            )) {
                return true;
            }
        }
    }

    return false;
}

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

test('150. siatka 15 minut nie tworzy nachodzących slotów 60-minutowej usługi', function (): void {
    $slots = interval_slots_build(
        [['start' => '08:00:00', 'end' => '12:00:00']],
        [],
        '2026-05-11',
        60,
        15,
        '2026-05-11 00:00:00'
    );

    assert_same(
        [
            ['start' => '2026-05-11 08:00:00', 'end' => '2026-05-11 09:00:00'],
            ['start' => '2026-05-11 09:00:00', 'end' => '2026-05-11 10:00:00'],
            ['start' => '2026-05-11 10:00:00', 'end' => '2026-05-11 11:00:00'],
            ['start' => '2026-05-11 11:00:00', 'end' => '2026-05-11 12:00:00'],
        ],
        $slots,
        'Kolejne sloty powinny zaczynać się po zakończeniu poprzedniego'
    );
    assert_same(false, interval_slots_have_overlap($slots), 'Sloty nie mogą na siebie nachodzić');
});

test('151. usługa 50 minut daje rzeczywistą przepustowość bez nachodzenia', function (): void {
    $slots = interval_slots_build(
        [['start' => '08:00:00', 'end' => '10:00:00']],
        [],
        '2026-05-11',
        50,
        15,
        '2026-05-11 00:00:00'
    );

    assert_same(2, count($slots), 'Okno 08:00-10:00 powinno pomieścić dwa sloty 50-minutowe');
    assert_same('2026-05-11 08:00:00', $slots[0]['start'] ?? null, 'Pierwszy slot powinien zaczynać się na początku grafiku');
    assert_same('2026-05-11 08:50:00', $slots[0]['end'] ?? null, 'Pierwszy slot powinien trwać 50 minut');
    assert_true(
        ($slots[1]['start'] ?? '') >= ($slots[0]['end'] ?? ''),
        'Drugi slot nie może zaczynać się przed końcem pierwszego'
    );
    assert_same(false, interval_slots_have_overlap($slots), 'Sloty 50-minutowe nie mogą na siebie nachodzić');
});

test('152. zajęty przedział usuwa kolidujące sloty, a styk granic zostaje', function (): void {
    $slots = interval_slots_build(
        [['start' => '08:00:00', 'end' => '12:00:00']],
        [['start' => '2026-05-11 09:00:00', 'end' => '2026-05-11 10:00:00']],
        '2026-05-11',
        60,
        15,
        '2026-05-11 00:00:00'
    );

    assert_same(
        [
            ['start' => '2026-05-11 08:00:00', 'end' => '2026-05-11 09:00:00'],
            ['start' => '2026-05-11 10:00:00', 'end' => '2026-05-11 11:00:00'],
            ['start' => '2026-05-11 11:00:00', 'end' => '2026-05-11 12:00:00'],
        ],
        $slots,
        'Slot kończący się o 09:00 i zaczynający się o 10:00 powinny zostać'
    );
});

test('153. slot kończący się na końcu grafiku jest dozwolony, a wystający odrzucony', function (): void {
    $slots = interval_slots_build(
        [['start' => '08:00:00', 'end' => '09:00:00']],
        [],
        '2026-05-11',
        30,
        15,
        '2026-05-11 00:00:00'
    );

    assert_same(2, count($slots), 'Okno 08:00-09:00 powinno pomieścić dwa sloty 30-minutowe');
    assert_same(
        '2026-05-11 09:00:00',
        $slots[1]['end'] ?? null,
        'Ostatni slot powinien kończyć się dokładnie na końcu grafiku'
    );

    foreach ($slots as $slot) {
        assert_true(
            $slot['end'] <= '2026-05-11 09:00:00',
            'Slot nie może wystawać poza przedział grafiku'
        );
    }
});

test('154. slot zaczynający się teraz jest odrzucany', function (): void {
    $slots = interval_slots_build(
        [['start' => '08:00:00', 'end' => '10:00:00']],
        [],
        '2026-05-11',
        30,
        15,
        '2026-05-11 08:00:00'
    );

    assert_same(
        '2026-05-11 08:15:00',
        $slots[0]['start'] ?? null,
        'Slot równy bieżącemu czasowi nie może być zwrócony'
    );

    foreach ($slots as $slot) {
        assert_true(
            $slot['start'] > '2026-05-11 08:00:00',
            'Każdy zwrócony slot musi zaczynać się po bieżącym czasie'
        );
    }
});

test('155. dwa rozłączne przedziały grafiku dają sloty niezależnie', function (): void {
    $slots = interval_slots_build(
        [
            ['start' => '10:00:00', 'end' => '11:00:00'],
            ['start' => '08:00:00', 'end' => '09:00:00'],
        ],
        [],
        '2026-05-11',
        30,
        15,
        '2026-05-11 00:00:00'
    );

    assert_same(
        [
            ['start' => '2026-05-11 08:00:00', 'end' => '2026-05-11 08:30:00'],
            ['start' => '2026-05-11 08:30:00', 'end' => '2026-05-11 09:00:00'],
            ['start' => '2026-05-11 10:00:00', 'end' => '2026-05-11 10:30:00'],
            ['start' => '2026-05-11 10:30:00', 'end' => '2026-05-11 11:00:00'],
        ],
        $slots,
        'Znacznik końca powinien resetować się między przedziałami grafiku'
    );
});

test('157. nachodzące przedziały grafiku nie dają nachodzących slotów', function (): void {
    $slots = interval_slots_build(
        [
            ['start' => '08:00:00', 'end' => '10:00:00'],
            ['start' => '08:30:00', 'end' => '10:30:00'],
        ],
        [],
        '2026-05-11',
        60,
        15,
        '2026-05-11 00:00:00'
    );

    assert_same(
        [
            ['start' => '2026-05-11 08:00:00', 'end' => '2026-05-11 09:00:00'],
            ['start' => '2026-05-11 09:00:00', 'end' => '2026-05-11 10:00:00'],
        ],
        $slots,
        'Sloty z drugiego przedziału nie mogą nachodzić na wcześniejsze'
    );
    assert_same(false, interval_slots_have_overlap($slots), 'Wynik nie może zawierać nachodzących slotów');
});

test('172. grafik do 24:00 daje sloty do końca dnia i północ następnego dnia', function (): void {
    $slots = interval_slots_build(
        [['start' => '08:00:00', 'end' => '24:00:00']],
        [],
        '2026-05-11',
        60,
        15,
        '2026-05-11 00:00:00'
    );

    assert_same(16, count($slots), 'Grafik 08:00-24:00 powinien pomieścić 16 slotów godzinnych');
    assert_same('2026-05-11 08:00:00', $slots[0]['start'] ?? null, 'Pierwszy slot powinien zaczynać się o 08:00');
    assert_same('2026-05-11 23:00:00', $slots[15]['start'] ?? null, 'Ostatni slot powinien zaczynać się o 23:00');
    assert_same(
        '2026-05-12 00:00:00',
        $slots[15]['end'] ?? null,
        'Slot 23:00-24:00 powinien kończyć się północą następnego dnia'
    );
});

test('173. zajętość 23:00-24:00 usuwa ostatni slot grafiku do 24:00', function (): void {
    $slots = interval_slots_build(
        [['start' => '08:00:00', 'end' => '24:00:00']],
        [['start' => '2026-05-11 23:00:00', 'end' => '2026-05-12 00:00:00']],
        '2026-05-11',
        60,
        15,
        '2026-05-11 00:00:00'
    );

    assert_same(15, count($slots), 'Zajęty koniec dnia powinien usunąć jeden slot');
    assert_same(
        false,
        in_array('2026-05-11 23:00:00', array_column($slots, 'start'), true),
        'Slot 23:00-24:00 nie może pojawić się, gdy jest zajęty'
    );
});

test('174. tylko dokładnie 24:00:00 jest poprawnym końcem dnia', function (): void {
    assert_same(86400, interval_time_seconds('24:00:00'), '24:00:00 powinno zwrócić 86400 sekund');
    assert_same(null, interval_time_seconds('24:00:01'), '24:00:01 powinno być odrzucone');
    assert_same(null, interval_time_seconds('24:30:00'), '24:30:00 powinno być odrzucone');
    assert_same(null, interval_time_seconds('25:00:00'), '25:00:00 powinno być odrzucone');
    assert_same(
        '2026-05-12 00:00:00',
        interval_slot_datetime('2026-05-11', 86400),
        'Nadmiar sekund powinien przejść na kolejny dzień'
    );
});
