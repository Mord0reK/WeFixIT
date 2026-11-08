<?php
declare(strict_types=1);

/*
    Wspólne reguły przedziałów czasowych i budowy siatki wolnych slotów.

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

/*
    Czas z grafiku jest zapisany jako MySQL TIME bez ułamków sekund.
    Wartość 24:00:00 oznacza koniec dnia i zwracamy dla niej 86400 sekund.
    Inne godziny z prefiksem 24 są niepoprawne.
*/
function interval_time_seconds(string $value): ?int
{
    if ($value === '24:00:00') {
        return 86400;
    }

    if (preg_match('/^(?:([01]\d|2[0-3])):([0-5]\d):([0-5]\d)$/D', $value, $matches) !== 1) {
        return null;
    }

    return (int) $matches[1] * 3600 + (int) $matches[2] * 60 + (int) $matches[3];
}

function interval_seconds_time(int $seconds): string
{
    return sprintf(
        '%02d:%02d:%02d',
        intdiv($seconds, 3600),
        intdiv($seconds % 3600, 60),
        $seconds % 60
    );
}

/*
    Zwraca pełny DATETIME dla dnia i sekund od północy. Nadmiar sekund
    przechodzi na kolejne dni, więc koniec slotu o 24:00 ma postać
    "następny dzień 00:00:00", którą interval_format() rozpoznaje jako DATETIME.
*/
function interval_slot_datetime(string $date, int $seconds): string
{
    $day = new DateTimeImmutable($date);
    $daysToAdd = intdiv($seconds, 86400);
    $secondsOfDay = $seconds % 86400;

    if ($daysToAdd > 0) {
        $day = $day->modify('+' . $daysToAdd . ' days');
    }

    return $day->format('Y-m-d') . ' ' . interval_seconds_time($secondsOfDay);
}

/*
    Buduje sloty od początku każdego przedziału grafiku, przesuwając kandydatów
    o krok siatki. Po zaakceptowaniu slotu kolejny kandydat z tego samego
    przedziału nie może zaczynać się przed jego końcem — dzięki temu usługa
    50-minutowa nie daje nachodzących slotów co 15 minut. Znacznik końca jest
    resetowany dla każdego kolejnego przedziału grafiku. Po zebraniu slotów
    z wszystkich przedziałów odrzucamy też te, które kolidują z wcześniej
    zaakceptowanym slotem z innego przedziału. Czas z grafiku jest zamieniany
    na pełny DATETIME przed porównaniem z zajętościami. Koniec dnia zapisany
    jako 24:00 staje się północą następnego dnia, więc nadal jest poprawnym
    DATETIME dla interval_format().
*/
function interval_slots_build(
    array $scheduleIntervals,
    array $busyIntervals,
    string $date,
    int $durationMinutes,
    int $slotStepMinutes,
    string $nowValue
): array {
    if (
        $durationMinutes <= 0
        || $slotStepMinutes <= 0
        || interval_format($nowValue) !== 'datetime'
        || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1
    ) {
        return [];
    }

    $busy = [];

    foreach ($busyIntervals as $busyInterval) {
        $start = $busyInterval['start'] ?? null;
        $end = $busyInterval['end'] ?? null;

        if (
            !is_string($start)
            || !is_string($end)
            || interval_format($start) !== 'datetime'
            || interval_format($end) !== 'datetime'
        ) {
            continue;
        }

        $busy[] = [$start, $end];
    }

    $durationSeconds = $durationMinutes * 60;
    $stepSeconds = $slotStepMinutes * 60;
    $slots = [];

    foreach ($scheduleIntervals as $scheduleInterval) {
        $startValue = $scheduleInterval['start'] ?? null;
        $endValue = $scheduleInterval['end'] ?? null;

        if (!is_string($startValue) || !is_string($endValue)) {
            continue;
        }

        $intervalStart = interval_time_seconds($startValue);
        $intervalEnd = interval_time_seconds($endValue);

        if ($intervalStart === null || $intervalEnd === null || $intervalStart >= $intervalEnd) {
            continue;
        }

        $lastAcceptedEnd = null;

        for (
            $cursor = $intervalStart;
            $cursor + $durationSeconds <= $intervalEnd;
            $cursor += $stepSeconds
        ) {
            $slotStart = interval_slot_datetime($date, $cursor);
            $slotEnd = interval_slot_datetime($date, $cursor + $durationSeconds);

            if ($slotStart <= $nowValue) {
                continue;
            }

            if ($lastAcceptedEnd !== null && $slotStart < $lastAcceptedEnd) {
                continue;
            }

            $conflict = false;

            foreach ($busy as [$busyStart, $busyEnd]) {
                if (intervals_overlap($slotStart, $slotEnd, $busyStart, $busyEnd)) {
                    $conflict = true;
                    break;
                }
            }

            if ($conflict) {
                continue;
            }

            $slots[$slotStart] = ['start' => $slotStart, 'end' => $slotEnd];
            $lastAcceptedEnd = $slotEnd;
        }
    }

    ksort($slots, SORT_STRING);

    $filteredSlots = [];

    foreach ($slots as $slot) {
        $conflict = false;

        foreach ($filteredSlots as $acceptedSlot) {
            if (intervals_overlap(
                $slot['start'],
                $slot['end'],
                $acceptedSlot['start'],
                $acceptedSlot['end']
            )) {
                $conflict = true;
                break;
            }
        }

        if (!$conflict) {
            $filteredSlots[] = $slot;
        }
    }

    return $filteredSlots;
}
