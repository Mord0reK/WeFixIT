<?php
declare(strict_types=1);

/*
    Logika klienta dla dostępnych terminów i tworzenia rezerwacji.

    Moduł waliduje dane wejściowe, sprawdza parę pracownik + usługa, tworzy
    rezerwację atomowo pod blokadą wiersza pracownika i udostępnia listę
    rezerwacji klienta. Nie zawiera HTML, nagłówków HTTP ani exit.
*/
require_once __DIR__ . '/../public/config.php';
require_once __DIR__ . '/availability.php';
require_once __DIR__ . '/rezerwacje.php';

function rezerwacje_klienta_db(): ?PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;

    if (!$pdo instanceof PDO) {
        error_log('Brak połączenia z bazą w module rezerwacji klienta.');
        return null;
    }

    return $pdo;
}

function reservation_id_value(mixed $value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }

    if (is_string($value) && $value !== '' && ctype_digit($value)) {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    return null;
}

function reservation_date_value(mixed $value): ?string
{
    return availability_date_value($value);
}

// Przyjmujemy HH:MM lub HH:MM:SS i normalizujemy do pełnego TIME.
function reservation_time_value(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);

    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $value) !== 1) {
        return null;
    }

    return strlen($value) === 5 ? $value . ':00' : $value;
}

function reservation_comment_value(array $data): ?string
{
    $value = $data['komentarz'] ?? null;

    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);

    return $value !== '' ? $value : null;
}

function reservation_field_errors(array $data): array
{
    $errors = [];

    if (reservation_id_value($data['pracownik_id'] ?? null) === null) {
        $errors['pracownik_id'] = 'Podaj identyfikator pracownika.';
    }

    if (reservation_id_value($data['usluga_id'] ?? null) === null) {
        $errors['usluga_id'] = 'Podaj identyfikator usługi.';
    }

    if (reservation_date_value($data['data'] ?? null) === null) {
        $errors['data'] = 'Podaj poprawną datę w formacie RRRR-MM-DD.';
    }

    if (reservation_time_value($data['godzina'] ?? null) === null) {
        $errors['godzina'] = 'Podaj godzinę w formacie HH:MM lub HH:MM:SS.';
    }

    if (array_key_exists('komentarz', $data) && $data['komentarz'] !== null) {
        if (!is_string($data['komentarz']) || mb_strlen(trim($data['komentarz'])) > 1000) {
            $errors['komentarz'] = 'Komentarz musi być tekstem o długości do 1000 znaków.';
        }
    }

    return $errors;
}

function reservation_pair_check(int $employeeId, int $serviceId): array
{
    $pdo = rezerwacje_klienta_db();

    if ($employeeId <= 0 || $serviceId <= 0 || $pdo === null) {
        return ['status' => 'error'];
    }

    try {
        $statement = $pdo->prepare(
            'SELECT u.aktywna AS usluga_aktywna, k.aktywna AS kategoria_aktywna
             FROM uslugi u
             JOIN kategorie_uslug k ON k.id = u.kategoria_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $serviceId]);
        $service = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się sprawdzić usługi rezerwacji: ' . $e->getMessage());
        return ['status' => 'error'];
    }

    if (!is_array($service)) {
        return ['status' => 'service_not_found'];
    }

    if ((int) $service['usluga_aktywna'] !== 1 || (int) $service['kategoria_aktywna'] !== 1) {
        return ['status' => 'service_inactive'];
    }

    try {
        $statement = $pdo->prepare(
            'SELECT
                p.aktywny,
                u.aktywny AS konto_aktywne,
                up.aktywne AS przypisanie_aktywne
             FROM pracownicy p
             JOIN uzytkownicy u ON u.id = p.uzytkownik_id
             LEFT JOIN uslugi_pracownikow up
                ON up.pracownik_id = p.id AND up.usluga_id = :usluga_id
             WHERE p.id = :pracownik_id
             LIMIT 1'
        );
        $statement->execute([
            'pracownik_id' => $employeeId,
            'usluga_id' => $serviceId,
        ]);
        $employee = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Nie udało się sprawdzić pracownika rezerwacji: ' . $e->getMessage());
        return ['status' => 'error'];
    }

    if (!is_array($employee)) {
        return ['status' => 'employee_not_found'];
    }

    if ((int) $employee['aktywny'] !== 1 || (int) $employee['konto_aktywne'] !== 1) {
        return ['status' => 'employee_inactive'];
    }

    if ($employee['przypisanie_aktywne'] === null || (int) $employee['przypisanie_aktywne'] !== 1) {
        return ['status' => 'not_assigned'];
    }

    return ['status' => 'ok'];
}

/*
    Sprawdza bezpośrednio żądany przedział, niezależnie od listy kandydatów
    generowanej przez availability_day(). Dzięki temu terminy wypadające
    z siatki po wcześniejszej rezerwacji nadal mogą zostać przyjęte, jeśli
    mieszczą się w grafiku, są wyrównane do siatki i nie kolidują z niczym.
*/
function reservation_interval_is_free(
    int $employeeId,
    string $date,
    string $time,
    int $durationMinutes
): ?bool {
    $pdo = rezerwacje_klienta_db();
    $startSeconds = interval_time_seconds($time);

    if ($employeeId <= 0 || $durationMinutes <= 0 || $startSeconds === null || $pdo === null) {
        return null;
    }

    $endSeconds = $startSeconds + $durationMinutes * 60;
    $dayOfWeek = (int) (new DateTimeImmutable($date))->format('N');
    $stepSeconds = AVAILABILITY_SLOT_MINUTES * 60;

    try {
        $statement = $pdo->prepare(
            'SELECT czas_rozpoczecia, czas_zakonczenia
             FROM godziny_pracy
             WHERE pracownik_id = :pracownik_id
               AND dzien_tygodnia = :dzien_tygodnia
               AND aktywne = TRUE
             ORDER BY czas_rozpoczecia ASC, id ASC'
        );
        $statement->execute([
            'pracownik_id' => $employeeId,
            'dzien_tygodnia' => $dayOfWeek,
        ]);

        $insideSchedule = false;

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $intervalStart = interval_time_seconds((string) $row['czas_rozpoczecia']);
            $intervalEnd = interval_time_seconds((string) $row['czas_zakonczenia']);

            if ($intervalStart === null || $intervalEnd === null || $intervalStart >= $intervalEnd) {
                continue;
            }

            if ($startSeconds < $intervalStart || $endSeconds > $intervalEnd) {
                continue;
            }

            if (($startSeconds - $intervalStart) % $stepSeconds !== 0) {
                continue;
            }

            $insideSchedule = true;
            break;
        }

        if (!$insideSchedule) {
            return false;
        }

        $startValue = interval_slot_datetime($date, $startSeconds);
        $endValue = interval_slot_datetime($date, $endSeconds);
        $nowValue = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        if ($startValue <= $nowValue) {
            return false;
        }

        $statement = $pdo->prepare(
            "SELECT 1
             FROM nieobecnosci
             WHERE pracownik_id = :pracownik_id
               AND status = 'zatwierdzona'
               AND data_od <= :data
               AND data_do >= :data
             LIMIT 1"
        );
        $statement->execute([
            'pracownik_id' => $employeeId,
            'data' => $date,
        ]);

        if ($statement->fetchColumn() !== false) {
            return false;
        }

        $statement = $pdo->prepare(
            "SELECT 1
             FROM rezerwacje
             WHERE pracownik_id = :pracownik_id
               AND status_rezerwacji <> 'anulowana'
               AND czas_rozpoczecia < :czas_zakonczenia
               AND czas_zakonczenia > :czas_rozpoczecia
             LIMIT 1"
        );
        $statement->execute([
            'pracownik_id' => $employeeId,
            'czas_rozpoczecia' => $startValue,
            'czas_zakonczenia' => $endValue,
        ]);

        return $statement->fetchColumn() === false;
    } catch (PDOException $e) {
        error_log('Nie udało się sprawdzić przedziału rezerwacji: ' . $e->getMessage());
        return null;
    }
}

/*
    Tworzy rezerwację w transakcji. Blokada wiersza pracownika jest pierwszą
    instrukcją transakcji, przed jakimkolwiek odczytem niemodyfikującym:
    dzięki temu stan dostępności jest czytany dopiero po przejęciu blokady
    i dwie równoległe sesje nie mogą obie zobaczyć tego samego terminu jako
    wolnego. W tej samej transakcji blokujemy też konto klienta i wiersz
    usługi, żeby ich dezaktywacja w trakcie żądania nie pozwoliła zapisać
    rezerwacji.
*/
function reservation_create(int $userId, array $data): array
{
    $errors = reservation_field_errors($data);

    if ($errors !== []) {
        return ['status' => 'invalid', 'errors' => $errors];
    }

    $employeeId = reservation_id_value($data['pracownik_id']);
    $serviceId = reservation_id_value($data['usluga_id']);
    $date = reservation_date_value($data['data']);
    $time = reservation_time_value($data['godzina']);

    if ($employeeId === null || $serviceId === null || $date === null || $time === null) {
        return ['status' => 'invalid', 'errors' => reservation_field_errors($data)];
    }

    $pdo = rezerwacje_klienta_db();

    if ($userId <= 0 || $pdo === null) {
        return ['status' => 'error'];
    }

    $comment = reservation_comment_value($data);

    try {
        $pdo->beginTransaction();

        $statement = $pdo->prepare('SELECT id FROM pracownicy WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $employeeId]);

        if ($statement->fetch(PDO::FETCH_ASSOC) === false) {
            $pdo->rollBack();
            return ['status' => 'not_found', 'reason' => 'employee_not_found'];
        }

        $statement = $pdo->prepare(
            'SELECT aktywny, rola
             FROM uzytkownicy
             WHERE id = :id
             FOR UPDATE'
        );
        $statement->execute(['id' => $userId]);
        $client = $statement->fetch(PDO::FETCH_ASSOC);

        if (
            !is_array($client)
            || (int) $client['aktywny'] !== 1
            || (string) $client['rola'] !== 'klient'
        ) {
            $pdo->rollBack();
            return ['status' => 'forbidden'];
        }

        $statement = $pdo->prepare(
            'SELECT czas_trwania_minuty, cena
             FROM uslugi
             WHERE id = :id
             FOR UPDATE'
        );
        $statement->execute(['id' => $serviceId]);
        $service = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($service)) {
            $pdo->rollBack();
            return ['status' => 'not_found', 'reason' => 'service_not_found'];
        }

        $pair = reservation_pair_check($employeeId, $serviceId);

        if ($pair['status'] === 'error') {
            $pdo->rollBack();
            return ['status' => 'error'];
        }

        if ($pair['status'] !== 'ok') {
            $pdo->rollBack();
            return ['status' => 'not_found', 'reason' => $pair['status']];
        }

        $duration = (int) $service['czas_trwania_minuty'];

        if ($duration <= 0) {
            $pdo->rollBack();
            return ['status' => 'error'];
        }

        $price = (string) $service['cena'];
        $startSeconds = interval_time_seconds($time);

        if ($startSeconds === null) {
            $pdo->rollBack();
            return [
                'status' => 'invalid',
                'errors' => ['godzina' => 'Podaj godzinę w formacie HH:MM lub HH:MM:SS.'],
            ];
        }

        $endSeconds = $startSeconds + $duration * 60;
        $startValue = interval_slot_datetime($date, $startSeconds);
        $endValue = interval_slot_datetime($date, $endSeconds);

        $now = new DateTimeImmutable('now');
        $today = $now->format('Y-m-d');
        $horizonEnd = $now
            ->modify('+' . (AVAILABILITY_HORIZON_DAYS - 1) . ' days')
            ->format('Y-m-d');

        if ($date < $today || $date > $horizonEnd) {
            $pdo->rollBack();
            return ['status' => 'out_of_horizon'];
        }

        $intervalFree = reservation_interval_is_free($employeeId, $date, $time, $duration);

        if ($intervalFree === null) {
            $pdo->rollBack();
            return ['status' => 'error'];
        }

        if (!$intervalFree) {
            $pdo->rollBack();
            return ['status' => 'conflict'];
        }

        $statement = $pdo->prepare(
            "INSERT INTO rezerwacje (
                uzytkownik_id,
                pracownik_id,
                usluga_id,
                czas_rozpoczecia,
                czas_zakonczenia,
                cena_historyczna,
                status_rezerwacji,
                komentarz_klienta
             ) VALUES (
                :uzytkownik_id,
                :pracownik_id,
                :usluga_id,
                :czas_rozpoczecia,
                :czas_zakonczenia,
                :cena_historyczna,
                'oczekujaca',
                :komentarz_klienta
             )"
        );
        $statement->execute([
            'uzytkownik_id' => $userId,
            'pracownik_id' => $employeeId,
            'usluga_id' => $serviceId,
            'czas_rozpoczecia' => $startValue,
            'czas_zakonczenia' => $endValue,
            'cena_historyczna' => $price,
            'komentarz_klienta' => $comment,
        ]);

        $reservationId = (int) $pdo->lastInsertId();
        $reservation = rezerwacja_find_by_id($reservationId);

        if (!is_array($reservation)) {
            $pdo->rollBack();
            return ['status' => 'error'];
        }

        $pdo->commit();

        return [
            'status' => 'ok',
            'id' => $reservationId,
            'rezerwacja' => $reservation,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Nie udało się utworzyć rezerwacji klienta: ' . $e->getMessage());
        return ['status' => 'error'];
    }
}

// Rezerwacje klienta przygotowane dla panelu klienta w późniejszym batchu.
function reservation_client_list(int $userId): ?array
{
    $pdo = rezerwacje_klienta_db();

    if ($userId <= 0 || $pdo === null) {
        return null;
    }

    $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $future = [];
    $historical = [];

    try {
        $statement = $pdo->prepare(
            rezerwacja_select_sql()
            . " WHERE r.uzytkownik_id = :uzytkownik_id
                  AND r.czas_rozpoczecia >= :now
                  AND r.status_rezerwacji IN ('oczekujaca', 'potwierdzona', 'w_trakcie')
                ORDER BY r.czas_rozpoczecia ASC, r.id ASC"
        );
        $statement->execute([
            'uzytkownik_id' => $userId,
            'now' => $now,
        ]);

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $future[] = reservation_client_row(rezerwacja_row($row));
        }

        $statement = $pdo->prepare(
            rezerwacja_select_sql()
            . " WHERE r.uzytkownik_id = :uzytkownik_id
                  AND (
                      r.czas_rozpoczecia < :now
                      OR r.status_rezerwacji IN ('zrealizowana', 'anulowana')
                  )
                ORDER BY r.czas_rozpoczecia DESC, r.id DESC"
        );
        $statement->execute([
            'uzytkownik_id' => $userId,
            'now' => $now,
        ]);

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $historical[] = reservation_client_row(rezerwacja_row($row));
        }
    } catch (PDOException $e) {
        error_log('Nie udało się pobrać rezerwacji klienta: ' . $e->getMessage());
        return null;
    }

    return [
        'przyszle' => $future,
        'historyczne' => $historical,
    ];
}

function reservation_client_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'czas_rozpoczecia' => (string) $row['czas_rozpoczecia'],
        'czas_zakonczenia' => (string) $row['czas_zakonczenia'],
        'cena_historyczna' => (string) $row['cena_historyczna'],
        'status_rezerwacji' => (string) $row['status_rezerwacji'],
        'komentarz_klienta' => $row['komentarz_klienta'] !== null
            ? (string) $row['komentarz_klienta']
            : null,
        'anulowano' => $row['anulowano'] !== null ? (string) $row['anulowano'] : null,
        'usluga_nazwa' => (string) $row['usluga_nazwa'],
        'pracownik_imie' => (string) $row['pracownik_imie'],
        'pracownik_nazwisko' => (string) $row['pracownik_nazwisko'],
    ];
}
