<?php
declare(strict_types=1);

/*
    Strona dostępności pracownika w panelu administratora.

    Sekcja grafiku pokazuje siedem dni tygodnia, przedziały i formularz dodania
    przedziału. Sekcja nieobecności pokazuje wnioski, historię rozpatrzenia
    i formularz nowego wniosku. Zmiany wysyłają fetch() do dwóch endpointów.
    Widok nie wykonuje SQL ani operacji zapisu — korzysta wyłącznie z modułów.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';
require_once dirname(__DIR__) . '/app/pracownicy.php';
require_once dirname(__DIR__) . '/app/pracownicy_dostepnosc.php';

$user = require_role(['admin']);
$basePath = auth_base_path();
$employeesUrl = $basePath . '/pracownicy';
$scheduleApi = $basePath . '/api/admin/employee-schedule';
$absencesApi = $basePath . '/api/admin/employee-absences';
$employeeId = null;

if (isset($_GET['id']) && is_string($_GET['id']) && $_GET['id'] !== '' && ctype_digit($_GET['id'])) {
    $employeeId = (int) $_GET['id'];

    if ($employeeId <= 0) {
        $employeeId = null;
    }
}

$employee = $employeeId !== null ? pracownik_find_by_id($employeeId) : null;

if ($employee === null) {
    http_response_code(404);
    panel_layout_head($user, 'Nie znaleziono pracownika — WeFixIT', 'employees');
    $employeesUrlSafe = htmlspecialchars($employeesUrl, ENT_QUOTES, 'UTF-8');
    ?>
    <section class="<?= PANEL_CARD_WIDE_CLASS ?>">
        <?php
        panel_section_header(
            'Nie znaleziono pracownika',
            'Wybrany profil pracownika nie istnieje lub identyfikator jest nieprawidłowy.'
        );
        ?>
        <a href="<?= $employeesUrlSafe ?>" class="<?= panel_button_classes('secondary') ?>">Wróć do pracowników</a>
    </section>
    <?php
    panel_layout_foot();
    exit;
}

$schedule = pracownik_schedule_list($employee['id']);
$absences = pracownik_absences_list($employee['id']);
$employeeName = $employee['imie'] . ' ' . $employee['nazwisko'];
$csrfToken = csrf_token();
$dayNames = [
    1 => 'Poniedziałek',
    2 => 'Wtorek',
    3 => 'Środa',
    4 => 'Czwartek',
    5 => 'Piątek',
    6 => 'Sobota',
    7 => 'Niedziela',
];
$typeLabels = [
    'urlop' => 'Urlop',
    'choroba' => 'Choroba',
    'inny' => 'Inny',
];
$statusLabels = [
    'oczekujaca' => 'Oczekująca',
    'zatwierdzona' => 'Zatwierdzona',
    'odrzucona' => 'Odrzucona',
    'anulowana' => 'Anulowana',
];
$statusVariants = [
    'oczekujaca' => 'warning',
    'zatwierdzona' => 'success',
    'odrzucona' => 'danger',
    'anulowana' => 'neutral',
];
$scheduleTotal = 0;

foreach ($schedule ?? [] as $dayEntries) {
    $scheduleTotal += count($dayEntries);
}

panel_layout_head($user, 'Dostępność pracownika — WeFixIT', 'employees');
?>
<section class="<?= PANEL_CARD_WIDE_CLASS ?>">
    <?php
    panel_section_header(
        'Dostępność pracownika: ' . $employeeName,
        'Zarządzaj tygodniowym grafikiem i nieobecnościami pracownika.',
        [
            ['label' => 'Pracownicy', 'href' => $employeesUrl],
        ]
    );
    ?>

    <?php if (!$employee['aktywny']): ?>
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200">
            <div class="mb-2"><?= panel_badge('Nieaktywny', 'neutral') ?></div>
            <p>Profil pracownika jest nieaktywny. Grafik i nieobecności nadal można edytować.</p>
        </div>
    <?php endif; ?>

    <section aria-labelledby="schedule-heading">
        <div class="flex flex-col gap-1">
            <h2 id="schedule-heading" class="text-lg font-semibold text-zinc-900 dark:text-zinc-50">Grafik tygodniowy</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Przedziały są grupowane od poniedziałku do niedzieli. Nowy przedział jest aktywny od razu.
            </p>
        </div>

        <?php if ($schedule === null): ?>
            <div role="alert" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
                Nie udało się pobrać grafiku pracownika. Spróbuj ponownie później.
            </div>
        <?php else: ?>
            <?php if ($scheduleTotal === 0): ?>
                <div class="mt-4">
                    <?php
                    panel_empty_state(
                        'Brak godzin pracy',
                        'Dodaj pierwszy przedział w formularzu wybranego dnia poniżej.'
                    );
                    ?>
                </div>
            <?php endif; ?>

            <div class="mt-4 space-y-4">
                <?php foreach ($dayNames as $day => $dayName): ?>
                    <?php $dayEntries = $schedule[$day] ?? []; ?>
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-800 dark:bg-zinc-950/30">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-700 dark:text-zinc-300">
                            <?= htmlspecialchars($dayName, ENT_QUOTES, 'UTF-8') ?>
                        </h3>

                        <?php if ($dayEntries === []): ?>
                            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">brak godzin pracy</p>
                        <?php else: ?>
                            <ul class="mt-2 space-y-2">
                                <?php foreach ($dayEntries as $entry): ?>
                                    <li class="flex flex-col gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-zinc-900 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-medium text-zinc-900 dark:text-zinc-50">
                                                <?= htmlspecialchars(substr($entry['czas_rozpoczecia'], 0, 5), ENT_QUOTES, 'UTF-8') ?>
                                                –
                                                <?= htmlspecialchars(substr($entry['czas_zakonczenia'], 0, 5), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                            <?= panel_badge(
                                                $entry['aktywne'] ? 'Aktywny' : 'Nieaktywny',
                                                $entry['aktywne'] ? 'success' : 'neutral'
                                            ) ?>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button"
                                                class="<?= panel_button_classes('ghost') ?>"
                                                data-schedule-toggle
                                                data-id="<?= $entry['id'] ?>"
                                                data-active="<?= $entry['aktywne'] ? '1' : '0' ?>"
                                                title="<?= $entry['aktywne'] ? 'Dezaktywuj' : 'Aktywuj' ?>"
                                                aria-label="<?= $entry['aktywne'] ? 'Dezaktywuj' : 'Aktywuj' ?>">
                                                <?= $entry['aktywne']
                                                    ? 'Dezaktywuj'
                                                    : panel_icon('circle-check', 'h-4 w-4 shrink-0') ?>
                                            </button>
                                            <button type="button"
                                                class="<?= panel_button_classes('danger') ?>"
                                                data-schedule-delete
                                                data-id="<?= $entry['id'] ?>"
                                                data-label="<?= htmlspecialchars($dayName . ' ' . substr($entry['czas_rozpoczecia'], 0, 5) . '–' . substr($entry['czas_zakonczenia'], 0, 5), ENT_QUOTES, 'UTF-8') ?>">
                                                Usuń
                                            </button>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <form class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-start"
                            data-schedule-form
                            data-day="<?= $day ?>"
                            method="post"
                            action="<?= htmlspecialchars($scheduleApi, ENT_QUOTES, 'UTF-8') ?>"
                            novalidate>
                            <div>
                                <label for="schedule-<?= $day ?>-start" class="<?= LAYOUT_LABEL_CLASS ?>">Od</label>
                                <input id="schedule-<?= $day ?>-start" name="czas_rozpoczecia" type="time" step="60"
                                    class="<?= LAYOUT_INPUT_CLASS ?>" required>
                                <div data-schedule-field="czas_rozpoczecia">
                                    <?= panel_field_error('schedule-error-' . $day . '-czas_rozpoczecia') ?>
                                </div>
                            </div>
                            <div>
                                <label for="schedule-<?= $day ?>-end" class="<?= LAYOUT_LABEL_CLASS ?>">Do</label>
                                <input id="schedule-<?= $day ?>-end" name="czas_zakonczenia" type="time" step="60"
                                    class="<?= LAYOUT_INPUT_CLASS ?>" required>
                                <div data-schedule-field="czas_zakonczenia">
                                    <?= panel_field_error('schedule-error-' . $day . '-czas_zakonczenia') ?>
                                </div>
                            </div>
                            <div class="sm:pt-6">
                                <button type="submit" class="<?= panel_button_classes('secondary') ?>">
                                    Dodaj przedział
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

            <p id="schedule-form-error" role="alert"
                class="mt-3 hidden text-sm text-red-600 dark:text-red-400"></p>
        <?php endif; ?>
    </section>

    <section aria-labelledby="absences-heading" class="mt-10">
        <div class="flex flex-col gap-1">
            <h2 id="absences-heading" class="text-lg font-semibold text-zinc-900 dark:text-zinc-50">Nieobecności</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Wniosek powstaje jako oczekujący. Zatwierdzenie i odrzucenie zapisują osobę oraz czas rozpatrzenia.
            </p>
        </div>

        <?php if ($absences === null): ?>
            <div role="alert" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
                Nie udało się pobrać nieobecności pracownika. Spróbuj ponownie później.
            </div>
        <?php elseif ($absences === []): ?>
            <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">Brak nieobecności.</p>
        <?php else: ?>
            <div class="mt-4 space-y-3">
                <?php foreach ($absences as $absence): ?>
                    <?php
                    $absenceStatus = $absence['status'];
                    $statusLabel = $statusLabels[$absenceStatus] ?? $absenceStatus;
                    $statusVariant = $statusVariants[$absenceStatus] ?? 'neutral';
                    $typeLabel = $typeLabels[$absence['rodzaj']] ?? $absence['rodzaj'];
                    $dateFrom = date('d.m.Y', strtotime($absence['data_od']));
                    $dateTo = date('d.m.Y', strtotime($absence['data_do']));
                    $resolvedBy = trim(
                        (string) ($absence['rozpatrzyl_imie'] ?? '') . ' ' . (string) ($absence['rozpatrzyl_nazwisko'] ?? '')
                    );
                    $resolvedAt = $absence['rozpatrzono'] !== null
                        ? date('d.m.Y H:i', strtotime($absence['rozpatrzono']))
                        : null;
                    $reservationCount = 0;

                    if ($absenceStatus === 'oczekujaca') {
                        $reservationCount = pracownik_absence_reservation_count(
                            $employee['id'],
                            $absence['data_od'],
                            $absence['data_do']
                        ) ?? 0;
                    }
                    ?>
                    <article class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="font-medium text-zinc-900 dark:text-zinc-50">
                                    <?= htmlspecialchars($dateFrom . ' – ' . $dateTo, ENT_QUOTES, 'UTF-8') ?>
                                </p>
                                <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                                    <?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            </div>
                            <?= panel_badge($statusLabel, $statusVariant) ?>
                        </div>

                        <?php if ($absence['powod'] !== null && $absence['powod'] !== ''): ?>
                            <p class="mt-3 text-sm text-zinc-700 dark:text-zinc-300">
                                <span class="font-medium">Powód:</span>
                                <?= htmlspecialchars($absence['powod'], ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        <?php endif; ?>

                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            <?php if ($resolvedBy !== '' && $resolvedAt !== null): ?>
                                Rozpatrzył: <?= htmlspecialchars($resolvedBy, ENT_QUOTES, 'UTF-8') ?>
                                · <?= htmlspecialchars($resolvedAt, ENT_QUOTES, 'UTF-8') ?>
                            <?php else: ?>
                                Jeszcze nierozpatrzona.
                            <?php endif; ?>
                        </p>

                        <?php if ($reservationCount > 0): ?>
                            <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200">
                                <?= htmlspecialchars(pracownik_absence_warning_message($reservationCount), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        <?php endif; ?>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <?php if (pracownik_absence_transition_allowed($absenceStatus, 'zatwierdzona')): ?>
                                <button type="button" class="<?= panel_button_classes('primary') ?>"
                                    data-absence-status="zatwierdzona"
                                    data-id="<?= $absence['id'] ?>"
                                    data-dates="<?= htmlspecialchars($dateFrom . ' – ' . $dateTo, ENT_QUOTES, 'UTF-8') ?>"
                                    data-reservations="<?= $reservationCount ?>"
                                    data-warning="<?= htmlspecialchars($reservationCount > 0 ? pracownik_absence_warning_message($reservationCount) : '', ENT_QUOTES, 'UTF-8') ?>">
                                    Zatwierdź
                                </button>
                            <?php endif; ?>

                            <?php if (pracownik_absence_transition_allowed($absenceStatus, 'odrzucona')): ?>
                                <button type="button" class="<?= panel_button_classes('danger') ?>"
                                    data-absence-status="odrzucona"
                                    data-id="<?= $absence['id'] ?>"
                                    data-dates="<?= htmlspecialchars($dateFrom . ' – ' . $dateTo, ENT_QUOTES, 'UTF-8') ?>"
                                    data-reservations="0">
                                    Odrzuć
                                </button>
                            <?php endif; ?>

                            <?php if (pracownik_absence_transition_allowed($absenceStatus, 'anulowana')): ?>
                                <button type="button" class="<?= panel_button_classes('ghost') ?>"
                                    data-absence-status="anulowana"
                                    data-id="<?= $absence['id'] ?>"
                                    data-dates="<?= htmlspecialchars($dateFrom . ' – ' . $dateTo, ENT_QUOTES, 'UTF-8') ?>"
                                    data-reservations="0">
                                    Anuluj
                                </button>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form id="absence-form" class="mt-6 rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-800 dark:bg-zinc-950/30"
            method="post"
            action="<?= htmlspecialchars($absencesApi, ENT_QUOTES, 'UTF-8') ?>"
            novalidate>
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-50">Nowy wniosek o nieobecność</h3>

            <div class="mt-3 grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="absence-data-od" class="<?= LAYOUT_LABEL_CLASS ?>">Data od</label>
                    <input id="absence-data-od" name="data_od" type="date" class="<?= LAYOUT_INPUT_CLASS ?>" required>
                    <?= panel_field_error('absence-error-data_od') ?>
                </div>
                <div>
                    <label for="absence-data-do" class="<?= LAYOUT_LABEL_CLASS ?>">Data do</label>
                    <input id="absence-data-do" name="data_do" type="date" class="<?= LAYOUT_INPUT_CLASS ?>" required>
                    <?= panel_field_error('absence-error-data_do') ?>
                </div>
                <div>
                    <label for="absence-rodzaj" class="<?= LAYOUT_LABEL_CLASS ?>">Rodzaj</label>
                    <select id="absence-rodzaj" name="rodzaj" class="<?= LAYOUT_INPUT_CLASS ?>" required>
                        <option value="">Wybierz rodzaj</option>
                        <?php foreach ($typeLabels as $typeValue => $typeLabel): ?>
                            <option value="<?= htmlspecialchars($typeValue, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= panel_field_error('absence-error-rodzaj') ?>
                </div>
            </div>

            <div class="mt-4">
                <label for="absence-powod" class="<?= LAYOUT_LABEL_CLASS ?>">Powód</label>
                <textarea id="absence-powod" name="powod" rows="3" maxlength="500"
                    class="<?= LAYOUT_TEXTAREA_CLASS ?>"></textarea>
                <p class="<?= LAYOUT_HINT_CLASS ?>">Opcjonalnie, maksymalnie 500 znaków.</p>
                <?= panel_field_error('absence-error-powod') ?>
            </div>

            <p id="absence-form-error" role="alert"
                class="mt-3 hidden text-sm text-red-600 dark:text-red-400"></p>
            <p id="absence-action-warning" role="alert"
                class="mt-3 hidden rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200"></p>

            <div class="mt-3">
                <button type="submit" id="absence-submit" class="<?= panel_button_classes('primary') ?>">
                    Dodaj wniosek
                </button>
            </div>
        </form>
    </section>

    <noscript>
        <p class="mt-6 text-sm text-red-600 dark:text-red-400">Włącz JavaScript, aby zapisywać grafik i nieobecności.</p>
    </noscript>
</section>

<script>
(function () {
    const scheduleApi = <?= json_encode($scheduleApi, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const employeeId = <?= $employee['id'] ?>;
    const formError = document.getElementById('schedule-form-error');
    let busy = false;

    function clearMessages() {
        if (!formError) return;
        formError.textContent = '';
        formError.classList.add('hidden');
    }

    function showMessage(message) {
        if (!formError) return;
        formError.textContent = message;
        formError.classList.remove('hidden');
    }

    function clearFieldErrors(form) {
        form.querySelectorAll('[data-schedule-field] p').forEach(function (element) {
            element.textContent = '';
            element.classList.add('hidden');
        });
    }

    function showFieldErrors(form, errors) {
        let shown = false;

        for (const [field, message] of Object.entries(errors || {})) {
            const element = form.querySelector('[data-schedule-field="' + field + '"] p');

            if (!element || typeof message !== 'string') continue;

            element.textContent = message;
            element.classList.remove('hidden');
            shown = true;
        }

        return shown;
    }

    async function send(method, payload) {
        const response = await fetch(scheduleApi, {
            method: method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify(payload)
        });
        let data = {};

        try {
            data = await response.json();
        } catch {
            data = {};
        }

        return { response: response, data: data };
    }

    function showRequestError(data) {
        showMessage(typeof data.error === 'string' && data.error !== ''
            ? data.error
            : 'Nie udało się zapisać grafiku.');
    }

    document.querySelectorAll('[data-schedule-form]').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (busy) return;

            clearMessages();
            clearFieldErrors(form);
            const button = form.querySelector('button[type="submit"]');
            const label = button ? button.textContent : '';
            busy = true;
            if (button) button.disabled = true;
            let completed = false;

            try {
                const result = await send('POST', {
                    pracownik_id: employeeId,
                    dzien_tygodnia: Number(form.dataset.day),
                    czas_rozpoczecia: form.elements.czas_rozpoczecia.value,
                    czas_zakonczenia: form.elements.czas_zakonczenia.value
                });

                if (!result.response.ok || result.data.success !== true) {
                    if (!showFieldErrors(form, result.data.errors)) {
                        showRequestError(result.data);
                    }
                    return;
                }

                completed = true;
                window.showToast('Przedział grafiku został dodany.', 'success');
                window.setTimeout(function () {
                    window.location.reload();
                }, 600);
            } catch {
                showMessage('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
            } finally {
                if (!completed) {
                    busy = false;
                    if (button) {
                        button.disabled = false;
                        button.textContent = label;
                    }
                }
            }
        });
    });

    document.querySelectorAll('[data-schedule-toggle]').forEach(function (button) {
        button.addEventListener('click', async function () {
            if (busy) return;

            clearMessages();
            busy = true;
            button.disabled = true;
            const active = button.dataset.active === '1';
            let completed = false;

            try {
                const result = await send('PATCH', {
                    id: Number(button.dataset.id),
                    pracownik_id: employeeId,
                    aktywne: !active
                });

                if (!result.response.ok || result.data.success !== true) {
                    showRequestError(result.data);
                    return;
                }

                completed = true;
                window.showToast(active ? 'Przedział został dezaktywowany.' : 'Przedział został aktywowany.', 'success');
                window.setTimeout(function () {
                    window.location.reload();
                }, 600);
            } catch {
                showMessage('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
            } finally {
                if (!completed) {
                    busy = false;
                    button.disabled = false;
                }
            }
        });
    });

    document.querySelectorAll('[data-schedule-delete]').forEach(function (button) {
        button.addEventListener('click', async function () {
            if (busy) return;

            const confirmed = await window.panelConfirm({
                title: 'Usuń przedział grafiku',
                message: 'Czy usunąć przedział ' + (button.dataset.label || '') + '? Tej operacji nie można cofnąć.',
                confirmLabel: 'Usuń',
                danger: true
            });

            if (!confirmed) return;

            clearMessages();
            busy = true;
            button.disabled = true;
            let completed = false;

            try {
                const result = await send('DELETE', {
                    id: Number(button.dataset.id),
                    pracownik_id: employeeId
                });

                if (!result.response.ok || result.data.success !== true) {
                    showRequestError(result.data);
                    return;
                }

                completed = true;
                window.showToast('Przedział grafiku został usunięty.', 'success');
                window.setTimeout(function () {
                    window.location.reload();
                }, 600);
            } catch {
                showMessage('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
            } finally {
                if (!completed) {
                    busy = false;
                    button.disabled = false;
                }
            }
        });
    });
})();

(function () {
    const absencesApi = <?= json_encode($absencesApi, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const employeeId = <?= $employee['id'] ?>;
    const form = document.getElementById('absence-form');
    const formError = document.getElementById('absence-form-error');
    const actionWarning = document.getElementById('absence-action-warning');
    const fieldErrors = {
        data_od: document.getElementById('absence-error-data_od'),
        data_do: document.getElementById('absence-error-data_do'),
        rodzaj: document.getElementById('absence-error-rodzaj'),
        powod: document.getElementById('absence-error-powod')
    };
    let busy = false;

    function clearMessages() {
        formError.textContent = '';
        formError.classList.add('hidden');
        actionWarning.textContent = '';
        actionWarning.classList.add('hidden');

        for (const element of Object.values(fieldErrors)) {
            element.textContent = '';
            element.classList.add('hidden');
        }
    }

    function showFormError(message) {
        formError.textContent = message;
        formError.classList.remove('hidden');
    }

    function showErrors(data) {
        let shown = false;

        for (const [field, message] of Object.entries(data.errors || {})) {
            if (!fieldErrors[field] || typeof message !== 'string') continue;

            fieldErrors[field].textContent = message;
            fieldErrors[field].classList.remove('hidden');
            shown = true;
        }

        if (!shown) {
            showFormError(typeof data.error === 'string' && data.error !== ''
                ? data.error
                : 'Nie udało się zapisać nieobecności.');
        }
    }

    async function send(method, payload) {
        const response = await fetch(absencesApi, {
            method: method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify(payload)
        });
        let data = {};

        try {
            data = await response.json();
        } catch {
            data = {};
        }

        return { response: response, data: data };
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (busy) return;

        clearMessages();
        const button = document.getElementById('absence-submit');
        busy = true;
        button.disabled = true;
        let completed = false;

        try {
            const result = await send('POST', {
                pracownik_id: employeeId,
                data_od: form.elements.data_od.value,
                data_do: form.elements.data_do.value,
                rodzaj: form.elements.rodzaj.value,
                powod: form.elements.powod.value
            });

            if (!result.response.ok || result.data.success !== true) {
                showErrors(result.data);
                return;
            }

            completed = true;
            window.showToast('Wniosek o nieobecność został utworzony.', 'success');
            window.setTimeout(function () {
                window.location.reload();
            }, 600);
        } catch {
            showFormError('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
        } finally {
            if (!completed) {
                busy = false;
                button.disabled = false;
            }
        }
    });

    document.querySelectorAll('[data-absence-status]').forEach(function (button) {
        button.addEventListener('click', async function () {
            if (busy) return;

            const target = button.dataset.absenceStatus || '';
            const dates = button.dataset.dates || '';
            const warning = button.dataset.warning || '';
            let message = 'Czy zmienić status nieobecności' + (dates !== '' ? ' ' + dates : '') + '?';

            if (target === 'zatwierdzona' && warning !== '') {
                message += ' ' + warning;
            }

            const labels = {
                zatwierdzona: 'Zatwierdź',
                odrzucona: 'Odrzuć',
                anulowana: 'Anuluj'
            };
            const confirmed = await window.panelConfirm({
                title: 'Zmień status nieobecności',
                message: message,
                confirmLabel: labels[target] || 'Potwierdź',
                danger: target === 'odrzucona' || target === 'anulowana'
            });

            if (!confirmed) return;

            clearMessages();
            busy = true;
            button.disabled = true;
            let completed = false;

            try {
                const result = await send('PATCH', {
                    id: Number(button.dataset.id),
                    status: target
                });

                if (!result.response.ok || result.data.success !== true) {
                    showErrors(result.data);
                    return;
                }

                completed = true;

                if (result.data.ostrzezenie && Number(result.data.ostrzezenie.liczba_rezerwacji) > 0) {
                    actionWarning.textContent = result.data.ostrzezenie.komunikat;
                    actionWarning.classList.remove('hidden');
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 2500);
                    return;
                }

                window.showToast('Status nieobecności został zmieniony.', 'success');
                window.setTimeout(function () {
                    window.location.reload();
                }, 600);
            } catch {
                showFormError('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
            } finally {
                if (!completed) {
                    busy = false;
                    button.disabled = false;
                }
            }
        });
    });
})();
</script>
<?php panel_layout_foot(); ?>
