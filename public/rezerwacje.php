<?php
declare(strict_types=1);

/*
    Strona rezerwacji w panelu administratora.

    Wyświetla listę z filtrami, wyszukiwaniem i paginacją oraz pozwala zmienić
    status rezerwacji przez endpoint JSON. Anulowanie i cofnięcie anulowania
    wymagają potwierdzenia window.panelConfirm. Widok nie wykonuje SQL ani
    operacji zapisu.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';
require_once dirname(__DIR__) . '/app/rezerwacje.php';
require_once dirname(__DIR__) . '/app/pracownicy.php';
require_once dirname(__DIR__) . '/app/uslugi.php';
require_once dirname(__DIR__) . '/app/uzytkownicy.php';

$user = require_role(['admin']);
$basePath = auth_base_path();
$reservationsUrl = $basePath . '/rezerwacje';
$reservationsApi = $basePath . '/api/admin/reservations';
$dateFrom = rezerwacja_filter_date($_GET['data_od'] ?? null);
$dateTo = rezerwacja_filter_date($_GET['data_do'] ?? null);
$statusFilter = rezerwacja_filter_status($_GET['status'] ?? null);
$employeeFilter = rezerwacja_filter_id($_GET['pracownik_id'] ?? null);
$serviceFilter = rezerwacja_filter_id($_GET['usluga_id'] ?? null);
$clientFilter = rezerwacja_filter_id($_GET['uzytkownik_id'] ?? null);
$sortDirection = rezerwacja_sort_direction($_GET['kierunek'] ?? null);
$search = pagination_search_value($_GET['szukaj'] ?? null);
$page = pagination_page($_GET['page'] ?? null);
$size = pagination_size($_GET['size'] ?? null);
$reservationPage = rezerwacje_list_paginated([
    'data_od' => $dateFrom,
    'data_do' => $dateTo,
    'status' => $statusFilter,
    'pracownik_id' => $employeeFilter,
    'usluga_id' => $serviceFilter,
    'uzytkownik_id' => $clientFilter,
    'kierunek' => $sortDirection,
    'szukaj' => $search,
], $page, $size);
$reservations = $reservationPage['items'] ?? null;
$employees = pracownicy_list();
$services = uslugi_list();
$clients = uzytkownicy_list('klient');
$filtersActive = $dateFrom !== null
    || $dateTo !== null
    || $statusFilter !== ''
    || $employeeFilter > 0
    || $serviceFilter > 0
    || $clientFilter > 0
    || $search !== '';
$paginationParams = ['size' => $size, 'kierunek' => $sortDirection];

if ($dateFrom !== null) {
    $paginationParams['data_od'] = $dateFrom;
}

if ($dateTo !== null) {
    $paginationParams['data_do'] = $dateTo;
}

if ($statusFilter !== '') {
    $paginationParams['status'] = $statusFilter;
}

if ($employeeFilter > 0) {
    $paginationParams['pracownik_id'] = $employeeFilter;
}

if ($serviceFilter > 0) {
    $paginationParams['usluga_id'] = $serviceFilter;
}

if ($clientFilter > 0) {
    $paginationParams['uzytkownik_id'] = $clientFilter;
}

if ($search !== '') {
    $paginationParams['szukaj'] = $search;
}

$exportParams = ['typ' => 'rezerwacje'] + array_diff_key($paginationParams, ['size' => true]);
$exportUrl = $basePath . '/eksport?' . http_build_query($exportParams);
$csrfToken = csrf_token();
$statusLabels = [
    'oczekujaca' => 'Oczekująca',
    'potwierdzona' => 'Potwierdzona',
    'w_trakcie' => 'W trakcie',
    'zrealizowana' => 'Zrealizowana',
    'anulowana' => 'Anulowana',
];
$statusVariants = [
    'oczekujaca' => 'warning',
    'potwierdzona' => 'neutral',
    'w_trakcie' => 'warning',
    'zrealizowana' => 'success',
    'anulowana' => 'danger',
];
$targetLabels = [
    'oczekujaca' => 'Cofnij anulowanie',
    'potwierdzona' => 'Potwierdź',
    'w_trakcie' => 'Rozpocznij',
    'zrealizowana' => 'Zrealizuj',
    'anulowana' => 'Anuluj',
];

panel_layout_head($user, 'Rezerwacje — WeFixIT', 'reservations');
?>
<section class="<?= PANEL_CARD_WIDE_CLASS ?>">
    <?php
    panel_section_header(
        'Rezerwacje',
        'Przeglądaj rezerwacje i zarządzaj ich statusem. Panel nie tworzy nowych rezerwacji.',
        [
            ['label' => 'Eksportuj CSV', 'href' => $exportUrl],
        ]
    );
    ?>

    <?php if ($reservations === null): ?>
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            Nie udało się pobrać listy rezerwacji. Spróbuj ponownie później.
        </div>
    <?php else: ?>
        <form id="reservations-filter-form" method="get"
            action="<?= htmlspecialchars($reservationsUrl, ENT_QUOTES, 'UTF-8') ?>"
            class="hidden">
            <input type="hidden" name="kierunek" value="<?= htmlspecialchars($sortDirection, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="size" value="<?= $size ?>">
            <?php if ($search !== ''): ?>
                <input type="hidden" name="szukaj" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
        </form>

        <div class="<?= PANEL_TABLE_WRAP_CLASS ?>">
            <table id="reservations-table" class="<?= PANEL_TABLE_CLASS ?>">
                <thead>
                    <tr>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Termin
                            <input form="reservations-filter-form" name="data_od" type="date"
                                value="<?= $dateFrom !== null ? htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') : '' ?>"
                                aria-label="Filtruj rezerwacje od daty"
                                class="<?= PANEL_TABLE_FILTER_SELECT_CLASS ?>"
                                data-filter-auto-submit>
                            <input form="reservations-filter-form" name="data_do" type="date"
                                value="<?= $dateTo !== null ? htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') : '' ?>"
                                aria-label="Filtruj rezerwacje do daty"
                                class="<?= PANEL_TABLE_FILTER_SELECT_CLASS ?>"
                                data-filter-auto-submit>
                        </th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Klient
                            <select form="reservations-filter-form" name="uzytkownik_id"
                                aria-label="Filtruj rezerwacje według klienta"
                                class="<?= PANEL_TABLE_FILTER_SELECT_CLASS ?>"
                                data-filter-auto-submit>
                                <option value="0" <?= $clientFilter === 0 ? 'selected' : '' ?>>Wszyscy</option>
                                <?php foreach ($clients ?? [] as $client): ?>
                                    <option value="<?= $client['id'] ?>" <?= $clientFilter === $client['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($client['imie'] . ' ' . $client['nazwisko'], ENT_QUOTES, 'UTF-8') ?>
                                        <?= $client['aktywny'] ? '' : '(nieaktywny)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Pracownik
                            <select form="reservations-filter-form" name="pracownik_id"
                                aria-label="Filtruj rezerwacje według pracownika"
                                class="<?= PANEL_TABLE_FILTER_SELECT_CLASS ?>"
                                data-filter-auto-submit>
                                <option value="0" <?= $employeeFilter === 0 ? 'selected' : '' ?>>Wszyscy</option>
                                <?php foreach ($employees ?? [] as $employee): ?>
                                    <option value="<?= $employee['id'] ?>" <?= $employeeFilter === $employee['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($employee['imie'] . ' ' . $employee['nazwisko'], ENT_QUOTES, 'UTF-8') ?>
                                        <?= $employee['aktywny'] ? '' : '(nieaktywny)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Usługa
                            <select form="reservations-filter-form" name="usluga_id"
                                aria-label="Filtruj rezerwacje według usługi"
                                class="<?= PANEL_TABLE_FILTER_SELECT_CLASS ?>"
                                data-filter-auto-submit>
                                <option value="0" <?= $serviceFilter === 0 ? 'selected' : '' ?>>Wszystkie</option>
                                <?php foreach ($services ?? [] as $service): ?>
                                    <option value="<?= $service['id'] ?>" <?= $serviceFilter === $service['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($service['nazwa'], ENT_QUOTES, 'UTF-8') ?>
                                        <?= $service['aktywna'] ? '' : '(nieaktywna)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Cena</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Status
                            <select form="reservations-filter-form" name="status"
                                aria-label="Filtruj rezerwacje według statusu"
                                class="<?= PANEL_TABLE_FILTER_SELECT_CLASS ?>"
                                data-filter-auto-submit>
                                <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>Wszystkie</option>
                                <?php foreach (REZERWACJA_STATUSES as $statusOption): ?>
                                    <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8') ?>"
                                        <?= $statusFilter === $statusOption ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($statusLabels[$statusOption] ?? $statusOption, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?> text-right">Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($reservations === []): ?>
                        <tr>
                            <td colspan="7" class="p-4">
                                <?php
                                if ($filtersActive) {
                                    panel_empty_state(
                                        'Brak rezerwacji dla wybranych filtrów',
                                        'Zmień zakres dat, filtry lub frazę wyszukiwania, aby zobaczyć inne rezerwacje.',
                                        ['label' => 'Wyczyść filtry', 'href' => $reservationsUrl]
                                    );
                                } else {
                                    panel_empty_state(
                                        'Brak rezerwacji',
                                        'Rezerwacje pojawią się tutaj, gdy klienci zaczną składać zamówienia.'
                                    );
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($reservations as $reservation): ?>
                        <?php
                        $reservationId = (int) $reservation['id'];
                        $start = new DateTimeImmutable($reservation['czas_rozpoczecia']);
                        $end = new DateTimeImmutable($reservation['czas_zakonczenia']);
                        $startLabel = $start->format('d.m.Y H:i');
                        $endLabel = $start->format('Y-m-d') === $end->format('Y-m-d')
                            ? $end->format('H:i')
                            : $end->format('d.m.Y H:i');
                        $currentStatus = $reservation['status_rezerwacji'];
                        $currentLabel = $statusLabels[$currentStatus] ?? $currentStatus;
                        $targets = rezerwacja_transition_targets($currentStatus);
                        ?>
                        <tr class="<?= PANEL_TR_CLASS ?>" data-reservation-row>
                            <td class="<?= PANEL_TD_CLASS ?> whitespace-nowrap">
                                <span class="block font-medium text-zinc-900 dark:text-zinc-50">
                                    <?= htmlspecialchars($startLabel . '–' . $endLabel, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php if ($reservation['komentarz_klienta'] !== null): ?>
                                    <span class="mt-0.5 block max-w-xs text-xs text-zinc-500 dark:text-zinc-400">
                                        <?= htmlspecialchars($reservation['komentarz_klienta'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <span class="block font-medium text-zinc-900 dark:text-zinc-50">
                                    <?= htmlspecialchars($reservation['klient_imie'] . ' ' . $reservation['klient_nazwisko'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                                    <?= htmlspecialchars($reservation['klient_email'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <?= htmlspecialchars($reservation['pracownik_imie'] . ' ' . $reservation['pracownik_nazwisko'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <span class="block"><?= htmlspecialchars($reservation['usluga_nazwa'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                                    <?= htmlspecialchars($reservation['usluga_kategoria'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?> whitespace-nowrap">
                                <?= number_format((float) $reservation['cena_historyczna'], 2, ',', ' ') ?> zł
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <?= panel_badge(
                                    $currentLabel,
                                    $statusVariants[$currentStatus] ?? 'neutral'
                                ) ?>
                                <?php if ($reservation['anulowano'] !== null): ?>
                                    <span class="mt-1 block text-xs text-zinc-500 dark:text-zinc-400">
                                        Anulowano <?= htmlspecialchars(date('d.m.Y H:i', strtotime($reservation['anulowano'])), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endif; ?>
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
                                            <button type="button" class="<?= panel_button_classes($targetVariant) ?>"
                                                data-reservation-status
                                                data-id="<?= $reservationId ?>"
                                                data-current="<?= htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8') ?>"
                                                data-target="<?= htmlspecialchars($target, ENT_QUOTES, 'UTF-8') ?>"
                                                data-current-label="<?= htmlspecialchars($currentLabel, ENT_QUOTES, 'UTF-8') ?>"
                                                data-target-label="<?= htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8') ?>
                                            </button>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <?= panel_field_error('reservation-error-' . $reservationId) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php panel_pagination($basePath, '/rezerwacje', $paginationParams, $reservationPage); ?>
    <?php endif; ?>

    <p id="reservations-error" role="alert" class="mt-3 hidden text-sm text-red-600 dark:text-red-400"></p>

    <noscript>
        <p class="mt-6 text-sm text-red-600 dark:text-red-400">Włącz JavaScript, aby zmieniać status rezerwacji.</p>
    </noscript>
</section>

<script>
(function () {
    const reservationsApi = <?= json_encode($reservationsApi, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const generalError = document.getElementById('reservations-error');
    let busy = false;

    function clearErrors() {
        if (generalError) {
            generalError.textContent = '';
            generalError.classList.add('hidden');
        }

        document.querySelectorAll('[id^="reservation-error-"]').forEach(function (element) {
            element.textContent = '';
            element.classList.add('hidden');
        });
    }

    function showGeneralError(message) {
        if (!generalError) return;

        generalError.textContent = message;
        generalError.classList.remove('hidden');
    }

    function showRowError(id, data) {
        const element = document.getElementById('reservation-error-' + id);
        const message = data.errors && typeof data.errors.status === 'string' ? data.errors.status : '';

        if (element && message !== '') {
            element.textContent = message;
            element.classList.remove('hidden');
            return;
        }

        showGeneralError(typeof data.error === 'string' && data.error !== ''
            ? data.error
            : 'Nie udało się zmienić statusu rezerwacji.');
    }

    async function sendStatus(id, status) {
        const response = await fetch(reservationsApi, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ id: id, status: status })
        });
        let data = {};

        try {
            data = await response.json();
        } catch {
            data = {};
        }

        return { response: response, data: data };
    }

    document.querySelectorAll('[data-reservation-status]').forEach(function (button) {
        button.addEventListener('click', async function () {
            if (busy) return;

            const id = Number(button.dataset.id);
            const current = button.dataset.current || '';
            const target = button.dataset.target || '';
            const currentLabel = button.dataset.currentLabel || current;
            const targetLabel = button.dataset.targetLabel || target;

            if (id <= 0 || target === '') return;

            let title = 'Zmienić status rezerwacji?';
            let message = 'Status rezerwacji zmieni się z „' + currentLabel + '” na „' + targetLabel + '”.';
            let confirmLabel = 'Zmień status';
            let danger = false;

            if (target === 'anulowana') {
                title = 'Anulować rezerwację?';
                message = 'Rezerwacja pozostanie w bazie jako anulowana. Nie zostanie usunięta.';
                confirmLabel = 'Anuluj rezerwację';
                danger = true;
            } else if (current === 'anulowana') {
                title = 'Cofnąć anulowanie?';
                message = 'Rezerwacja wróci do statusu „Oczekująca”.';
                confirmLabel = 'Cofnij anulowanie';
            }

            const confirmed = await window.panelConfirm({
                title: title,
                message: message,
                confirmLabel: confirmLabel,
                danger: danger
            });

            if (!confirmed) return;

            clearErrors();
            busy = true;
            button.disabled = true;
            const submitLabel = button.textContent;
            let completed = false;
            button.textContent = 'Zapisywanie…';

            try {
                const result = await sendStatus(id, target);

                if (!result.response.ok || result.data.success !== true) {
                    showRowError(id, result.data);
                    return;
                }

                completed = true;
                let toastMessage = 'Status rezerwacji został zmieniony.';

                if (target === 'anulowana') {
                    toastMessage = 'Rezerwacja została anulowana.';
                } else if (current === 'anulowana') {
                    toastMessage = 'Anulowanie rezerwacji zostało cofnięte.';
                }

                window.showToast(toastMessage, 'success');
                window.setTimeout(function () {
                    window.location.reload();
                }, 600);
            } catch {
                showGeneralError('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
            } finally {
                if (!completed) {
                    busy = false;
                    button.disabled = false;
                    button.textContent = submitLabel;
                }
            }
        });
    });
})();
</script>
<?php panel_layout_foot(); ?>
