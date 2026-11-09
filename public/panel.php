<?php
declare(strict_types=1);

/*
    Panel klienta: lista nadchodzących i historycznych rezerwacji.

    Widok korzysta wyłącznie z reservation_client_list() i nie wykonuje SQL.
    Anulowanie przyszłej rezerwacji obsługuje JavaScript przez osobny endpoint
    API; warunek dostępności przycisku liczy serwer.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';
require_once dirname(__DIR__) . '/app/rezerwacje_klienta.php';
require_once dirname(__DIR__) . '/app/rezerwacje_historia.php';

$user = require_login();
$basePath = auth_base_path();
$reservations = reservation_client_list((int) $user['id']);
$upcoming = [];
$historical = [];
$historyByReservation = [];

if (is_array($reservations)) {
    $upcoming = is_array($reservations['przyszle'] ?? null) ? $reservations['przyszle'] : [];
    $historical = is_array($reservations['historyczne'] ?? null) ? $reservations['historyczne'] : [];
    $visibleIds = [];

    foreach (array_merge($upcoming, $historical) as $reservation) {
        $visibleIds[] = (int) $reservation['id'];
    }

    $historyBatch = reservation_history_batch($visibleIds);

    if (is_array($historyBatch)) {
        $historyByReservation = $historyBatch;
    }
}

$statusMeta = [
    'oczekujaca' => ['label' => 'Oczekująca', 'variant' => 'warning'],
    'potwierdzona' => ['label' => 'Potwierdzona', 'variant' => 'default'],
    'w_trakcie' => ['label' => 'W trakcie', 'variant' => 'warning'],
    'zrealizowana' => ['label' => 'Zrealizowana', 'variant' => 'success'],
    'anulowana' => ['label' => 'Anulowana', 'variant' => 'danger'],
];

/*
    Tabela jest wspólna dla obu sekcji, żeby nagłówki i formatowanie pozostały
    identyczne. Komentarz renderujemy w dodatkowym wierszu pod rezerwacją.
*/
function client_panel_reservation_table(
    array $reservations,
    array $statusMeta,
    DateTimeImmutable $now,
    array $historyByReservation
): void {
    ?>
    <div class="<?= PANEL_TABLE_WRAP_CLASS ?>">
        <table class="<?= PANEL_TABLE_CLASS ?>">
            <thead>
                <tr>
                    <th scope="col" class="<?= PANEL_TH_CLASS ?>">Usługa</th>
                    <th scope="col" class="<?= PANEL_TH_CLASS ?>">Pracownik</th>
                    <th scope="col" class="<?= PANEL_TH_CLASS ?>">Data</th>
                    <th scope="col" class="<?= PANEL_TH_CLASS ?>">Godzina</th>
                    <th scope="col" class="<?= PANEL_TH_CLASS ?>">Cena</th>
                    <th scope="col" class="<?= PANEL_TH_CLASS ?>">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reservations as $reservation): ?>
                    <?php
                    $start = new DateTimeImmutable((string) $reservation['czas_rozpoczecia']);
                    $end = new DateTimeImmutable((string) $reservation['czas_zakonczenia']);
                    $status = (string) $reservation['status_rezerwacji'];
                    $meta = $statusMeta[$status] ?? ['label' => $status, 'variant' => 'default'];
                    $serviceLabel = (string) $reservation['usluga_nazwa'];
                    $employeeLabel = trim(
                        (string) $reservation['pracownik_imie']
                        . ' '
                        . (string) $reservation['pracownik_nazwisko']
                    );
                    $dateLabel = $start->format('d.m.Y');
                    $timeLabel = $start->format('H:i') . '–' . $end->format('H:i');
                    $priceLabel = number_format((float) $reservation['cena_historyczna'], 2, ',', ' ') . ' zł';
                    $comment = $reservation['komentarz_klienta'] !== null
                        ? (string) $reservation['komentarz_klienta']
                        : null;
                    $commentShort = $comment !== null && mb_strlen($comment) > 120
                        ? mb_substr($comment, 0, 120) . '…'
                        : $comment;
                    $cancellable = in_array($status, ['oczekujaca', 'potwierdzona'], true)
                        && $start > $now;
                    $reservationId = (int) $reservation['id'];
                    $historyEvents = is_array($historyByReservation[$reservationId] ?? null)
                        ? $historyByReservation[$reservationId]
                        : [];
                    ?>
                    <tr class="<?= PANEL_TR_CLASS ?>">
                        <td class="<?= PANEL_TD_CLASS ?> font-medium text-zinc-900 dark:text-zinc-50">
                            <?= htmlspecialchars($serviceLabel, ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="<?= PANEL_TD_CLASS ?>">
                            <?= htmlspecialchars($employeeLabel, ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="<?= PANEL_TD_CLASS ?>">
                            <?= htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="<?= PANEL_TD_CLASS ?>">
                            <?= htmlspecialchars($timeLabel, ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="<?= PANEL_TD_CLASS ?>">
                            <?= htmlspecialchars($priceLabel, ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="<?= PANEL_TD_CLASS ?>">
                            <?= panel_badge($meta['label'], $meta['variant']) ?>
                            <?php if ($cancellable): ?>
                                <button type="button" data-client-cancel
                                    data-id="<?= $reservationId ?>"
                                    data-label="<?= htmlspecialchars($serviceLabel . ' — ' . $dateLabel, ENT_QUOTES, 'UTF-8') ?>"
                                    class="<?= panel_button_classes('danger') ?> mt-2">
                                    Anuluj
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($comment !== null): ?>
                        <tr>
                            <td colspan="6" class="<?= PANEL_TD_CLASS ?> text-xs text-zinc-500 dark:text-zinc-400">
                                <span class="break-words" title="<?= htmlspecialchars($comment, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($commentShort, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($historyEvents !== []): ?>
                        <tr>
                            <td colspan="6" class="<?= PANEL_TD_CLASS ?> text-xs text-zinc-500 dark:text-zinc-400">
                                <details>
                                    <summary class="cursor-pointer font-medium text-zinc-700 dark:text-zinc-300">
                                        Historia zmian (<?= count($historyEvents) ?>)
                                    </summary>
                                    <ul class="mt-2 space-y-2">
                                        <?php foreach ($historyEvents as $event): ?>
                                            <?php
                                            $eventTime = (new DateTimeImmutable((string) $event['utworzono']))
                                                ->format('d.m.Y H:i');
                                            $actor = trim(
                                                ((string) ($event['aktor_imie'] ?? ''))
                                                . ' '
                                                . ((string) ($event['aktor_nazwisko'] ?? ''))
                                            );
                                            $details = is_array($event['szczegoly'] ?? null)
                                                ? $event['szczegoly']
                                                : null;
                                            $statusDiff = is_array($details['status'] ?? null)
                                                ? $details['status']
                                                : null;
                                            $transition = is_array($statusDiff)
                                                && is_string($statusDiff['przed'] ?? null)
                                                && is_string($statusDiff['po'] ?? null)
                                                ? $statusDiff['przed'] . ' → ' . $statusDiff['po']
                                                : '';
                                            ?>
                                            <li>
                                                <span class="font-medium text-zinc-700 dark:text-zinc-300">
                                                    <?= htmlspecialchars($eventTime, ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                                — <?= htmlspecialchars((string) $event['opis'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php if ($actor !== ''): ?>
                                                    (<?= htmlspecialchars($actor, ENT_QUOTES, 'UTF-8') ?>)
                                                <?php endif; ?>
                                                <?php if ($transition !== ''): ?>
                                                    <span class="font-medium text-zinc-700 dark:text-zinc-300">
                                                        [<?= htmlspecialchars($transition, ENT_QUOTES, 'UTF-8') ?>]
                                                    </span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

$now = new DateTimeImmutable('now');
$apiUrl = htmlspecialchars($basePath . '/api/client-reservations', ENT_QUOTES, 'UTF-8');
$loginUrl = htmlspecialchars($basePath . '/login', ENT_QUOTES, 'UTF-8');

panel_layout_head($user, 'Panel — WeFixIT', $user['rola'] === 'admin' ? 'client_panel' : 'home');
?>
    <section id="client-reservations" data-api="<?= $apiUrl ?>" data-login-url="<?= $loginUrl ?>">
        <?php panel_section_header('Moje rezerwacje', 'Twoje wizyty w serwisie WeFixIT.', [
            ['label' => 'Nowa rezerwacja', 'href' => $basePath . '/rezerwacja'],
        ]); ?>

        <p id="client-reservations-error" role="alert"
            class="hidden rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300"></p>

        <?php if (!is_array($reservations)): ?>
            <?php panel_empty_state(
                'Nie udało się pobrać rezerwacji',
                'Wystąpił problem z serwerem. Odśwież stronę i spróbuj ponownie.'
            ); ?>
        <?php elseif ($upcoming === [] && $historical === []): ?>
            <?php panel_empty_state(
                'Brak rezerwacji',
                'Nie masz jeszcze żadnych rezerwacji w serwisie.',
                ['label' => 'Przejdź do oferty', 'href' => $basePath . '/oferta']
            ); ?>
        <?php else: ?>
            <div class="space-y-6">
                <section id="client-reservations-upcoming"
                    aria-labelledby="client-reservations-upcoming-title">
                    <h2 id="client-reservations-upcoming-title"
                        class="mb-4 text-lg font-semibold tracking-tight">Nadchodzące</h2>
                    <?php if ($upcoming === []): ?>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            Brak nadchodzących rezerwacji.
                        </p>
                    <?php else: ?>
                        <?php client_panel_reservation_table($upcoming, $statusMeta, $now, $historyByReservation); ?>
                    <?php endif; ?>
                </section>

                <section id="client-reservations-history"
                    aria-labelledby="client-reservations-history-title">
                    <h2 id="client-reservations-history-title"
                        class="mb-4 text-lg font-semibold tracking-tight">Historia</h2>
                    <?php if ($historical === []): ?>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            Brak historycznych rezerwacji.
                        </p>
                    <?php else: ?>
                        <?php client_panel_reservation_table($historical, $statusMeta, $now, $historyByReservation); ?>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </section>
<?php panel_layout_foot('client-reservations.js'); ?>
