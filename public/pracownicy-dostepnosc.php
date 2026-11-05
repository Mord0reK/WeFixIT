<?php
declare(strict_types=1);

/*
    Strona dostępności pracownika w panelu administratora.

    Sekcja grafiku pokazuje siedem dni tygodnia, przedziały i panel dodawania
    przedziału. Sekcja nieobecności pokazuje wnioski, historię rozpatrzenia
    i panel nowego wniosku. Zmiany wysyłają fetch() do dwóch endpointów.
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
$timeOptions = [];

for ($minutes = 0; $minutes < 24 * 60; $minutes += 15) {
    $time = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    $timeOptions[] = ['value' => $time, 'label' => $time];
}

$reasonOptions = [
    ['value' => 'Urlop wypoczynkowy', 'label' => 'Urlop wypoczynkowy'],
    ['value' => 'Choroba', 'label' => 'Choroba'],
    ['value' => 'Sprawy rodzinne', 'label' => 'Sprawy rodzinne'],
    ['value' => 'Szkolenie', 'label' => 'Szkolenie'],
    ['value' => 'Badania lekarskie', 'label' => 'Badania lekarskie'],
    ['value' => '__custom__', 'label' => 'Inne (wpisz poniżej)'],
];
$scheduleTotal = 0;
$defaultDay = (int) date('N');

foreach ($schedule ?? [] as $dayEntries) {
    $scheduleTotal += count($dayEntries);
}

panel_layout_head($user, 'Dostępność pracownika — WeFixIT', 'employees');
?>
<section id="employee-availability" class="<?= PANEL_CARD_WIDE_CLASS ?>"
    data-schedule-api="<?= htmlspecialchars($scheduleApi, ENT_QUOTES, 'UTF-8') ?>"
    data-absences-api="<?= htmlspecialchars($absencesApi, ENT_QUOTES, 'UTF-8') ?>"
    data-employee-id="<?= $employee['id'] ?>"
    data-default-day="<?= $defaultDay ?>">
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
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-1">
                <h2 id="schedule-heading" class="text-lg font-semibold text-zinc-900 dark:text-zinc-50">Grafik tygodniowy</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Przedziały są grupowane od poniedziałku do niedzieli. Nowy przedział jest aktywny od razu.
                </p>
            </div>
            <button type="button" class="<?= panel_button_classes('primary') ?>"
                data-schedule-add
                data-drawer-open="schedule-drawer"
                aria-haspopup="dialog"
                aria-controls="schedule-drawer">
                Dodaj przedział
            </button>
        </div>

        <p id="schedule-action-error" role="alert"
            class="mt-3 hidden text-sm text-red-600 dark:text-red-400"></p>

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
                        'Dodaj pierwszy przedział grafiku, aby pracownik był dostępny.',
                        ['label' => 'Dodaj przedział', 'drawer' => 'schedule-drawer']
                    );
                    ?>
                </div>
            <?php endif; ?>

            <div class="mt-4 space-y-4">
                <?php foreach ($dayNames as $day => $dayName): ?>
                    <?php $dayEntries = $schedule[$day] ?? []; ?>
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-800 dark:bg-zinc-950/30">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-700 dark:text-zinc-300">
                                <?= htmlspecialchars($dayName, ENT_QUOTES, 'UTF-8') ?>
                            </h3>
                            <button type="button" class="<?= panel_button_classes('ghost') ?>"
                                data-schedule-add
                                data-day="<?= $day ?>"
                                data-drawer-open="schedule-drawer"
                                aria-haspopup="dialog"
                                aria-controls="schedule-drawer"
                                aria-label="Dodaj przedział w dniu <?= htmlspecialchars($dayName, ENT_QUOTES, 'UTF-8') ?>">
                                Dodaj
                            </button>
                        </div>

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
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section aria-labelledby="absences-heading" class="mt-10">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-1">
                <h2 id="absences-heading" class="text-lg font-semibold text-zinc-900 dark:text-zinc-50">Nieobecności</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Wniosek powstaje jako oczekujący. Zatwierdzenie i odrzucenie zapisują osobę oraz czas rozpatrzenia.
                </p>
            </div>
            <button type="button" class="<?= panel_button_classes('primary') ?>"
                data-drawer-open="absence-drawer"
                aria-haspopup="dialog"
                aria-controls="absence-drawer">
                Dodaj nieobecność
            </button>
        </div>

        <p id="absence-action-error" role="alert"
            class="mt-3 hidden text-sm text-red-600 dark:text-red-400"></p>
        <p id="absence-action-warning" role="alert"
            class="mt-3 hidden rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200"></p>

        <?php if ($absences === null): ?>
            <div role="alert" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
                Nie udało się pobrać nieobecności pracownika. Spróbuj ponownie później.
            </div>
        <?php elseif ($absences === []): ?>
            <div class="mt-4">
                <?php
                panel_empty_state(
                    'Brak nieobecności',
                    'Dodaj wniosek, aby zaplanować urlop, chorobę lub inną nieobecność.',
                    ['label' => 'Dodaj nieobecność', 'drawer' => 'absence-drawer']
                );
                ?>
            </div>
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
    </section>

    <noscript>
        <p class="mt-6 text-sm text-red-600 dark:text-red-400">Włącz JavaScript, aby zapisywać grafik i nieobecności.</p>
    </noscript>
</section>

<?php
panel_drawer_start(
    'schedule-drawer',
    'schedule-drawer-title',
    'Dodaj przedział grafiku',
    'Nowy przedział będzie aktywny od razu.'
);
?>
    <form id="schedule-form" class="space-y-4" novalidate>
        <div>
            <label for="schedule-dzien" class="<?= LAYOUT_LABEL_CLASS ?>">Dzień tygodnia</label>
            <select id="schedule-dzien" name="dzien_tygodnia" class="<?= LAYOUT_INPUT_CLASS ?>" required>
                <?php foreach ($dayNames as $day => $dayName): ?>
                    <option value="<?= $day ?>" <?= $day === $defaultDay ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dayName, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?= panel_field_error('schedule-error-dzien_tygodnia') ?>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <span id="schedule-start-label" class="<?= LAYOUT_LABEL_CLASS ?>">Od</span>
                <div class="relative" data-picker-field data-filter-dropdown>
                    <button type="button"
                        class="<?= LAYOUT_INPUT_CLASS ?> items-center justify-between gap-2 text-left"
                        data-filter-toggle aria-expanded="false" aria-haspopup="true"
                        aria-labelledby="schedule-start-label schedule-start-value">
                        <span id="schedule-start-value"
                            class="truncate text-zinc-400 dark:text-zinc-500"
                            data-picker-value data-placeholder="Wybierz godzinę">Wybierz godzinę</span>
                        <?= panel_icon('chevron-down', 'h-4 w-4 shrink-0 text-zinc-500') ?>
                    </button>
                    <div class="<?= PANEL_FILTER_MENU_CLASS ?>" data-filter-menu>
                        <?php panel_filter_options(
                            'schedule-form',
                            'czas_rozpoczecia',
                            'Godzina rozpoczęcia',
                            $timeOptions,
                            '',
                            false
                        ); ?>
                    </div>
                </div>
                <?= panel_field_error('schedule-error-czas_rozpoczecia') ?>
            </div>
            <div>
                <span id="schedule-end-label" class="<?= LAYOUT_LABEL_CLASS ?>">Do</span>
                <div class="relative" data-picker-field data-filter-dropdown>
                    <button type="button"
                        class="<?= LAYOUT_INPUT_CLASS ?> items-center justify-between gap-2 text-left"
                        data-filter-toggle aria-expanded="false" aria-haspopup="true"
                        aria-labelledby="schedule-end-label schedule-end-value">
                        <span id="schedule-end-value"
                            class="truncate text-zinc-400 dark:text-zinc-500"
                            data-picker-value data-placeholder="Wybierz godzinę">Wybierz godzinę</span>
                        <?= panel_icon('chevron-down', 'h-4 w-4 shrink-0 text-zinc-500') ?>
                    </button>
                    <div class="<?= PANEL_FILTER_MENU_CLASS ?>" data-filter-menu>
                        <?php panel_filter_options(
                            'schedule-form',
                            'czas_zakonczenia',
                            'Godzina zakończenia',
                            $timeOptions,
                            '',
                            false
                        ); ?>
                    </div>
                </div>
                <?= panel_field_error('schedule-error-czas_zakonczenia') ?>
            </div>
        </div>

        <p id="schedule-form-error" role="alert" class="hidden text-sm text-red-600 dark:text-red-400"></p>

        <div class="flex flex-wrap items-center gap-2">
            <button type="submit" id="schedule-submit" class="<?= panel_button_classes('primary') ?>">
                Dodaj przedział
            </button>
            <button type="button" data-drawer-close class="<?= panel_button_classes('secondary') ?>">
                Anuluj
            </button>
        </div>
    </form>
<?php panel_drawer_end(); ?>

<?php
panel_drawer_start(
    'absence-drawer',
    'absence-drawer-title',
    'Dodaj wniosek o nieobecność',
    'Wniosek powstanie jako oczekujący na rozpatrzenie.'
);
?>
    <form id="absence-form" class="space-y-4" novalidate>
        <div class="grid gap-4 sm:grid-cols-2">
            <?php foreach ([
                ['id' => 'absence-data-od', 'name' => 'data_od', 'label' => 'Data od'],
                ['id' => 'absence-data-do', 'name' => 'data_do', 'label' => 'Data do'],
            ] as $dateField): ?>
                <?php
                $dateFieldId = $dateField['id'];
                $dateFieldName = $dateField['name'];
                $dateFieldLabelId = $dateFieldId . '-label';
                $dateFieldValueId = $dateFieldId . '-value';
                ?>
                <div>
                    <span id="<?= $dateFieldLabelId ?>" class="<?= LAYOUT_LABEL_CLASS ?>">
                        <?= htmlspecialchars($dateField['label'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <div class="relative" data-picker-field data-date-picker data-filter-dropdown>
                        <button type="button"
                            class="<?= LAYOUT_INPUT_CLASS ?> items-center justify-between gap-2 text-left"
                            data-filter-toggle aria-expanded="false" aria-haspopup="true"
                            aria-labelledby="<?= $dateFieldLabelId . ' ' . $dateFieldValueId ?>">
                            <span id="<?= $dateFieldValueId ?>"
                                class="truncate text-zinc-400 dark:text-zinc-500"
                                data-picker-value data-placeholder="Wybierz datę">Wybierz datę</span>
                            <?= panel_icon('chevron-down', 'h-4 w-4 shrink-0 text-zinc-500') ?>
                        </button>
                        <div class="<?= PANEL_FILTER_MENU_CLASS ?>" data-filter-menu>
                            <input type="hidden" id="<?= $dateFieldId ?>" name="<?= $dateFieldName ?>" value="">
                            <div class="p-1" data-date-calendar>
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <button type="button" data-date-prev aria-label="Poprzedni miesiąc"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-md text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-50">
                                        <?= panel_icon('chevron-left', 'h-4 w-4 shrink-0') ?>
                                    </button>
                                    <span data-date-month class="text-sm font-medium text-zinc-900 dark:text-zinc-50"></span>
                                    <button type="button" data-date-next aria-label="Następny miesiąc"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-md text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-50">
                                        <?= panel_icon('chevron-right', 'h-4 w-4 shrink-0') ?>
                                    </button>
                                </div>
                                <div class="grid grid-cols-7 gap-1 text-center">
                                    <?php foreach (['Pn', 'Wt', 'Śr', 'Cz', 'Pt', 'So', 'Nd'] as $weekday): ?>
                                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                                            <?= $weekday ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mt-1 grid grid-cols-7 gap-1" data-date-days></div>
                            </div>
                        </div>
                    </div>
                    <?= panel_field_error('absence-error-' . $dateFieldName) ?>
                </div>
            <?php endforeach; ?>
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

        <div data-reason-picker>
            <span id="absence-powod-label" class="<?= LAYOUT_LABEL_CLASS ?>">Powód</span>
            <div class="relative" data-picker-field data-filter-dropdown>
                <button type="button"
                    class="<?= LAYOUT_INPUT_CLASS ?> items-center justify-between gap-2 text-left"
                    data-filter-toggle aria-expanded="false" aria-haspopup="true"
                    aria-labelledby="absence-powod-label absence-powod-value">
                    <span id="absence-powod-value"
                        class="truncate text-zinc-400 dark:text-zinc-500"
                        data-picker-value data-placeholder="Wybierz powód">Wybierz powód</span>
                    <?= panel_icon('chevron-down', 'h-4 w-4 shrink-0 text-zinc-500') ?>
                </button>
                <div class="<?= PANEL_FILTER_MENU_CLASS ?>" data-filter-menu>
                    <?php panel_filter_options(
                        'absence-form',
                        'powod',
                        'Powód nieobecności',
                        $reasonOptions,
                        '',
                        false
                    ); ?>
                </div>
            </div>
            <div class="mt-3 hidden" data-reason-custom>
                <label for="absence-powod-inne" class="<?= LAYOUT_LABEL_CLASS ?>">Opis powodu</label>
                <textarea id="absence-powod-inne" name="powod_inne" rows="3" maxlength="500"
                    class="<?= LAYOUT_TEXTAREA_CLASS ?>"></textarea>
                <p class="<?= LAYOUT_HINT_CLASS ?>">Opcjonalnie, maksymalnie 500 znaków.</p>
            </div>
            <p class="<?= LAYOUT_HINT_CLASS ?>">Opcjonalnie wybierz powód z listy.</p>
            <?= panel_field_error('absence-error-powod') ?>
        </div>

        <p id="absence-form-error" role="alert" class="hidden text-sm text-red-600 dark:text-red-400"></p>

        <div class="flex flex-wrap items-center gap-2">
            <button type="submit" id="absence-submit" class="<?= panel_button_classes('primary') ?>">
                Dodaj wniosek
            </button>
            <button type="button" data-drawer-close class="<?= panel_button_classes('secondary') ?>">
                Anuluj
            </button>
        </div>
    </form>
<?php panel_drawer_end(); ?>

<?php panel_layout_foot('employee-availability.js'); ?>
