<?php
declare(strict_types=1);

/*
    Panel pracownika: wizyty na dziś, nadchodzące i historia oraz zmiana
    statusu przypisanych rezerwacji.

    Administrator nie ma profilu pracownika, więc otrzymuje informację z linkiem
    do pełnej listy rezerwacji. Widok nie wykonuje SQL ani operacji zapisu.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';
require_once dirname(__DIR__) . '/app/rezerwacje_pracownika.php';

$user = require_role(['pracownik', 'admin']);
$basePath = auth_base_path();
$isAdmin = ($user['rola'] ?? '') === 'admin';
$employeeId = !$isAdmin ? employee_profile_id((int) $user['id']) : null;
$reservations = $employeeId !== null ? employee_reservations($employeeId) : null;
$today = [];
$upcoming = [];
$history = [];

if (is_array($reservations)) {
    $today = is_array($reservations['dzis'] ?? null) ? $reservations['dzis'] : [];
    $upcoming = is_array($reservations['przyszle'] ?? null) ? $reservations['przyszle'] : [];
    $history = is_array($reservations['historia'] ?? null) ? $reservations['historia'] : [];
}

$statusMeta = [
    'oczekujaca' => ['label' => 'Oczekująca', 'variant' => 'warning'],
    'potwierdzona' => ['label' => 'Potwierdzona', 'variant' => 'default'],
    'w_trakcie' => ['label' => 'W trakcie', 'variant' => 'warning'],
    'zrealizowana' => ['label' => 'Zrealizowana', 'variant' => 'success'],
    'anulowana' => ['label' => 'Anulowana', 'variant' => 'danger'],
];

$targetLabels = [
    'oczekujaca' => 'Cofnij anulowanie',
    'potwierdzona' => 'Potwierdź',
    'w_trakcie' => 'Rozpocznij',
    'zrealizowana' => 'Zrealizuj',
    'anulowana' => 'Anuluj',
];

/*
    Wspólna tabela sekcji pracownika. Przyciski zmian wynikają z maszyny
    stanów i obsługują wyłącznie rezerwacje przypisane do pracownika.
*/
function employee_panel_reservation_table(
    array $reservations,
    array $statusMeta,
    array $targetLabels
): void {
    ?>
    <div class="<?= PANEL_TABLE_WRAP_CLASS ?>">
        <table class="<?= PANEL_TABLE_CLASS ?>">
            <thead>
                <tr>
                    <th scope="col" class="<?= PANEL_TH_CLASS ?>">Godzina</th>
                    <th scope="col" class="<?= PANEL_TH_CLASS ?>">Usługa</th>
                    <th scope="col" class="<?= PANEL_TH_CLASS ?>">Klient</th>
                    <th scope="col" class="<?= PANEL_TH_CLASS ?>">Cena</th>
                    <th scope="col" class="<?= PANEL_TH_CLASS ?>">Status</th>
                    <th scope="col" class="<?= PANEL_TH_CLASS ?> text-right">Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reservations as $reservation): ?>
                    <?php
                    $reservationId = (int) $reservation['id'];
                    $start = new DateTimeImmutable((string) $reservation['czas_rozpoczecia']);
                    $end = new DateTimeImmutable((string) $reservation['czas_zakonczenia']);
                    $dateLabel = $start->format('d.m.Y');
                    $timeLabel = $start->format('H:i') . '–' . $end->format('H:i');
                    $status = (string) $reservation['status_rezerwacji'];
                    $meta = $statusMeta[$status] ?? ['label' => $status, 'variant' => 'default'];
                    $serviceLabel = (string) $reservation['usluga_nazwa'];
                    $clientName = trim(
                        (string) $reservation['klient_imie']
                        . ' '
                        . (string) $reservation['klient_nazwisko']
                    );
                    $clientPhone = (string) $reservation['klient_telefon'];
                    $priceLabel = number_format((float) $reservation['cena_historyczna'], 2, ',', ' ') . ' zł';
                    $comment = $reservation['komentarz_klienta'] !== null
                        ? (string) $reservation['komentarz_klienta']
                        : null;
                    $commentShort = $comment !== null && mb_strlen($comment) > 120
                        ? mb_substr($comment, 0, 120) . '…'
                        : $comment;
                    $targets = rezerwacja_transition_targets($status);
                    ?>
                    <tr class="<?= PANEL_TR_CLASS ?>" data-employee-reservation-row
                        data-reservation-id="<?= $reservationId ?>">
                        <td class="<?= PANEL_TD_CLASS ?> whitespace-nowrap">
                            <span class="block font-medium text-zinc-900 dark:text-zinc-50">
                                <?= htmlspecialchars($timeLabel, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                                <?= htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td class="<?= PANEL_TD_CLASS ?>">
                            <span class="block font-medium text-zinc-900 dark:text-zinc-50">
                                <?= htmlspecialchars($serviceLabel, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php if ($comment !== null): ?>
                                <span class="mt-0.5 block max-w-xs break-words text-xs text-zinc-500 dark:text-zinc-400"
                                    title="<?= htmlspecialchars($comment, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($commentShort, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="<?= PANEL_TD_CLASS ?>">
                            <span class="block font-medium text-zinc-900 dark:text-zinc-50">
                                <?= htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                                <?= htmlspecialchars($clientPhone, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td class="<?= PANEL_TD_CLASS ?> whitespace-nowrap">
                            <?= htmlspecialchars($priceLabel, ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="<?= PANEL_TD_CLASS ?>">
                            <?= panel_badge($meta['label'], $meta['variant']) ?>
                        </td>
                        <td class="<?= PANEL_TD_CLASS ?>">
                            <div class="flex flex-wrap justify-end gap-2">
                                <?php if ($targets === []): ?>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Brak dostępnych zmian</span>
                                <?php else: ?>
                                    <?php foreach ($targets as $target): ?>
                                        <?php
                                        $targetLabel = $targetLabels[$target] ?? $target;
                                        $targetVariant = $target === 'anulowana' ? 'danger' : 'secondary';
                                        ?>
                                        <button type="button"
                                            class="<?= panel_button_classes($targetVariant) ?>"
                                            data-employee-status
                                            data-id="<?= $reservationId ?>"
                                            data-target="<?= htmlspecialchars($target, ENT_QUOTES, 'UTF-8') ?>"
                                            data-target-label="<?= htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8') ?>
                                        </button>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

$apiUrl = htmlspecialchars($basePath . '/api/employee-reservations', ENT_QUOTES, 'UTF-8');
$loginUrl = htmlspecialchars($basePath . '/login', ENT_QUOTES, 'UTF-8');

panel_layout_head($user, 'Panel pracownika — WeFixIT', $isAdmin ? 'employee_panel' : 'home');
?>
    <section id="employee-reservations" class="<?= PANEL_CARD_CLASS ?>"
        data-api="<?= $apiUrl ?>" data-login-url="<?= $loginUrl ?>">
        <?php panel_section_header('Panel pracownika', 'Twoje przypisane wizyty w serwisie WeFixIT.'); ?>

        <p id="employee-reservations-error" role="alert"
            class="hidden rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300"></p>

        <?php if ($isAdmin): ?>
            <?php panel_empty_state(
                'Konto administratora nie ma profilu pracownika',
                'Aby zarządzać wszystkimi wizytami w serwisie, przejdź do modułu rezerwacji.',
                ['label' => 'Przejdź do rezerwacji', 'href' => $basePath . '/rezerwacje']
            ); ?>
        <?php elseif ($employeeId === null): ?>
            <?php panel_empty_state(
                'Brak profilu pracownika',
                'Twoje konto nie ma jeszcze przypisanego profilu pracownika. Skontaktuj się z administratorem serwisu.'
            ); ?>
        <?php elseif (!is_array($reservations)): ?>
            <?php panel_empty_state(
                'Nie udało się pobrać wizyt',
                'Wystąpił problem z serwerem. Odśwież stronę i spróbuj ponownie.'
            ); ?>
        <?php else: ?>
            <div class="space-y-6">
                <section id="employee-reservations-today"
                    aria-labelledby="employee-reservations-today-title">
                    <h2 id="employee-reservations-today-title"
                        class="mb-4 text-lg font-semibold tracking-tight">Dzisiaj</h2>
                    <?php if ($today === []): ?>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            Brak wizyt zaplanowanych na dzisiaj.
                        </p>
                    <?php else: ?>
                        <?php employee_panel_reservation_table($today, $statusMeta, $targetLabels); ?>
                    <?php endif; ?>
                </section>

                <section id="employee-reservations-upcoming"
                    aria-labelledby="employee-reservations-upcoming-title">
                    <h2 id="employee-reservations-upcoming-title"
                        class="mb-4 text-lg font-semibold tracking-tight">Nadchodzące</h2>
                    <?php if ($upcoming === []): ?>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            Brak nadchodzących wizyt.
                        </p>
                    <?php else: ?>
                        <?php employee_panel_reservation_table($upcoming, $statusMeta, $targetLabels); ?>
                    <?php endif; ?>
                </section>

                <section id="employee-reservations-history"
                    aria-labelledby="employee-reservations-history-title">
                    <h2 id="employee-reservations-history-title"
                        class="mb-4 text-lg font-semibold tracking-tight">Historia</h2>
                    <?php if ($history === []): ?>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            Brak historycznych wizyt.
                        </p>
                    <?php else: ?>
                        <?php employee_panel_reservation_table($history, $statusMeta, $targetLabels); ?>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </section>
<?php panel_layout_foot('employee-reservations.js'); ?>
