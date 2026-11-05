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

<script>
(function () {
    const monthNames = [
        'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec',
        'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'
    ];

    function padNumber(value) {
        return String(value).padStart(2, '0');
    }

    function dateIso(date) {
        return date.getFullYear() + '-' + padNumber(date.getMonth() + 1) + '-' + padNumber(date.getDate());
    }

    function dateLabel(date) {
        return padNumber(date.getDate()) + '.' + padNumber(date.getMonth() + 1) + '.' + date.getFullYear();
    }

    function syncPickerDisplay(field) {
        const display = field.querySelector('[data-picker-value]');

        if (!display) return;

        const placeholder = display.dataset.placeholder || '';

        if (field.hasAttribute('data-date-picker')) {
            const input = field.querySelector('input[type="hidden"]');

            if (input && input.value !== '') {
                const selected = new Date(input.value + 'T00:00:00');

                if (!Number.isNaN(selected.getTime())) {
                    display.textContent = dateLabel(selected);
                    display.classList.remove('text-zinc-400', 'dark:text-zinc-500');
                    return;
                }
            }

            display.textContent = placeholder;
            display.classList.add('text-zinc-400', 'dark:text-zinc-500');
            return;
        }

        const checked = field.querySelector('input[type="radio"]:checked');

        if (!checked) {
            display.textContent = placeholder;
            display.classList.add('text-zinc-400', 'dark:text-zinc-500');
            return;
        }

        const optionLabel = checked.closest('label');
        const optionText = optionLabel ? optionLabel.querySelector('span') : null;
        display.textContent = optionText ? optionText.textContent.trim() : checked.value;
        display.classList.remove('text-zinc-400', 'dark:text-zinc-500');
    }

    function resetPickers(form) {
        if (!form) return;

        for (const field of form.querySelectorAll('[data-picker-field]')) {
            syncPickerDisplay(field);

            if (typeof field.resetDatePicker === 'function') {
                field.resetDatePicker();
            }
        }
    }

    window.panelResetPickers = resetPickers;

    for (const field of document.querySelectorAll('[data-picker-field]')) {
        for (const radio of field.querySelectorAll('input[type="radio"]')) {
            radio.addEventListener('change', function () {
                syncPickerDisplay(field);
            });
        }

        syncPickerDisplay(field);
    }

    function attachDatePicker(field) {
        const input = field.querySelector('input[type="hidden"]');
        const calendar = field.querySelector('[data-date-calendar]');
        const display = field.querySelector('[data-picker-value]');
        const monthLabel = calendar ? calendar.querySelector('[data-date-month]') : null;
        const days = calendar ? calendar.querySelector('[data-date-days]') : null;
        const previous = calendar ? calendar.querySelector('[data-date-prev]') : null;
        const next = calendar ? calendar.querySelector('[data-date-next]') : null;

        if (!input || !calendar || !display || !monthLabel || !days || !previous || !next) return;

        const today = dateIso(new Date());
        let view = new Date();
        view = new Date(view.getFullYear(), view.getMonth(), 1);

        function selectedDate() {
            if (input.value === '') return null;

            const selected = new Date(input.value + 'T00:00:00');

            return Number.isNaN(selected.getTime()) ? null : selected;
        }

        function setDisplay(date) {
            if (date === null) {
                display.textContent = display.dataset.placeholder || 'Wybierz datę';
                display.classList.add('text-zinc-400', 'dark:text-zinc-500');
                return;
            }

            display.textContent = dateLabel(date);
            display.classList.remove('text-zinc-400', 'dark:text-zinc-500');
        }

        function render() {
            monthLabel.textContent = monthNames[view.getMonth()] + ' ' + view.getFullYear();
            days.replaceChildren();

            const firstDay = new Date(view.getFullYear(), view.getMonth(), 1);
            const offset = (firstDay.getDay() + 6) % 7;

            for (let index = 0; index < offset; index += 1) {
                days.appendChild(document.createElement('span'));
            }

            const daysInMonth = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
            const selected = selectedDate();
            const selectedIso = selected === null ? '' : dateIso(selected);

            for (let day = 1; day <= daysInMonth; day += 1) {
                const date = new Date(view.getFullYear(), view.getMonth(), day);
                const iso = dateIso(date);
                const button = document.createElement('button');

                button.type = 'button';
                button.textContent = String(day);
                button.className = 'inline-flex h-8 w-8 items-center justify-center rounded-md text-sm text-zinc-700 transition-colors hover:bg-zinc-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 dark:text-zinc-200 dark:hover:bg-zinc-800';

                if (iso === selectedIso) {
                    button.classList.add(
                        'bg-zinc-900', 'text-white', 'hover:bg-zinc-800',
                        'dark:bg-zinc-50', 'dark:text-zinc-900', 'dark:hover:bg-zinc-200'
                    );
                } else if (iso === today) {
                    button.classList.add('ring-1', 'ring-inset', 'ring-zinc-300', 'dark:ring-zinc-600');
                }

                button.addEventListener('click', function () {
                    input.value = iso;
                    setDisplay(date);
                    render();
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                });

                days.appendChild(button);
            }
        }

        function reset() {
            input.value = '';
            const now = new Date();
            view = new Date(now.getFullYear(), now.getMonth(), 1);
            setDisplay(null);
            render();
        }

        field.resetDatePicker = reset;

        previous.addEventListener('click', function () {
            view = new Date(view.getFullYear(), view.getMonth() - 1, 1);
            render();
        });

        next.addEventListener('click', function () {
            view = new Date(view.getFullYear(), view.getMonth() + 1, 1);
            render();
        });

        const selected = selectedDate();

        if (selected !== null) {
            view = new Date(selected.getFullYear(), selected.getMonth(), 1);
        }

        setDisplay(selected);
        render();
    }

    for (const field of document.querySelectorAll('[data-date-picker]')) {
        attachDatePicker(field);
    }
})();

(function () {
    const scheduleApi = <?= json_encode($scheduleApi, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const employeeId = <?= $employee['id'] ?>;
    const drawer = document.getElementById('schedule-drawer');
    const form = document.getElementById('schedule-form');
    const formError = document.getElementById('schedule-form-error');
    const actionError = document.getElementById('schedule-action-error');
    const daySelect = document.getElementById('schedule-dzien');
    const submitButton = document.getElementById('schedule-submit');
    const defaultDay = <?= $defaultDay ?>;
    const fieldErrors = {
        dzien_tygodnia: document.getElementById('schedule-error-dzien_tygodnia'),
        czas_rozpoczecia: document.getElementById('schedule-error-czas_rozpoczecia'),
        czas_zakonczenia: document.getElementById('schedule-error-czas_zakonczenia')
    };
    let busy = false;

    function hideMessage(element) {
        if (!element) return;
        element.textContent = '';
        element.classList.add('hidden');
    }

    function showMessage(element, message) {
        if (!element) return;
        element.textContent = message;
        element.classList.remove('hidden');
    }

    function clearFormMessages() {
        hideMessage(formError);

        for (const element of Object.values(fieldErrors)) {
            hideMessage(element);
        }
    }

    function clearActionMessage() {
        hideMessage(actionError);
    }

    function showFieldErrors(errors) {
        let shown = false;

        for (const [field, message] of Object.entries(errors || {})) {
            if (!fieldErrors[field] || typeof message !== 'string') continue;

            showMessage(fieldErrors[field], message);
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
        showMessage(actionError, typeof data.error === 'string' && data.error !== ''
            ? data.error
            : 'Nie udało się zapisać grafiku.');
    }

    function resetScheduleForm() {
        if (!form) return;

        form.reset();

        if (window.panelResetPickers) {
            window.panelResetPickers(form);
        }

        if (daySelect) {
            daySelect.value = String(defaultDay);
        }

        clearFormMessages();
    }

    if (drawer) {
        drawer.addEventListener('panel:drawer-close', resetScheduleForm);
    }

    if (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (busy) return;

            clearFormMessages();
            busy = true;
            if (submitButton) submitButton.disabled = true;
            let completed = false;
            const startInput = form.querySelector('input[name="czas_rozpoczecia"]:checked');
            const endInput = form.querySelector('input[name="czas_zakonczenia"]:checked');

            try {
                const result = await send('POST', {
                    pracownik_id: employeeId,
                    dzien_tygodnia: Number(daySelect ? daySelect.value : 0),
                    czas_rozpoczecia: startInput === null ? '' : startInput.value,
                    czas_zakonczenia: endInput === null ? '' : endInput.value
                });

                if (!result.response.ok || result.data.success !== true) {
                    if (!showFieldErrors(result.data.errors)) {
                        showMessage(formError, typeof result.data.error === 'string' && result.data.error !== ''
                            ? result.data.error
                            : 'Nie udało się zapisać grafiku.');
                    }
                    return;
                }

                completed = true;
                window.showToast('Przedział grafiku został dodany.', 'success');
                window.setTimeout(function () {
                    window.location.reload();
                }, 600);
            } catch {
                showMessage(formError, 'Nie udało się połączyć z serwerem. Spróbuj ponownie.');
            } finally {
                if (!completed) {
                    busy = false;
                    if (submitButton) submitButton.disabled = false;
                }
            }
        });
    }

    for (const button of document.querySelectorAll('[data-schedule-add]')) {
        button.addEventListener('click', function () {
            clearFormMessages();

            if (daySelect && button.dataset.day) {
                daySelect.value = button.dataset.day;
            }
        });
    }

    document.querySelectorAll('[data-schedule-toggle]').forEach(function (button) {
        button.addEventListener('click', async function () {
            if (busy) return;

            clearActionMessage();
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
                showMessage(actionError, 'Nie udało się połączyć z serwerem. Spróbuj ponownie.');
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

            clearActionMessage();
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
                showMessage(actionError, 'Nie udało się połączyć z serwerem. Spróbuj ponownie.');
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
    const drawer = document.getElementById('absence-drawer');
    const form = document.getElementById('absence-form');
    const formError = document.getElementById('absence-form-error');
    const actionError = document.getElementById('absence-action-error');
    const actionWarning = document.getElementById('absence-action-warning');
    const submitButton = document.getElementById('absence-submit');
    const reasonPicker = document.querySelector('[data-reason-picker]');
    const customReasonWrap = reasonPicker ? reasonPicker.querySelector('[data-reason-custom]') : null;
    const customReason = document.getElementById('absence-powod-inne');
    const fieldErrors = {
        data_od: document.getElementById('absence-error-data_od'),
        data_do: document.getElementById('absence-error-data_do'),
        rodzaj: document.getElementById('absence-error-rodzaj'),
        powod: document.getElementById('absence-error-powod')
    };
    let busy = false;

    function hideMessage(element) {
        if (!element) return;
        element.textContent = '';
        element.classList.add('hidden');
    }

    function showMessage(element, message) {
        if (!element) return;
        element.textContent = message;
        element.classList.remove('hidden');
    }

    function clearFormMessages() {
        hideMessage(formError);

        for (const element of Object.values(fieldErrors)) {
            hideMessage(element);
        }
    }

    function clearActionMessages() {
        hideMessage(actionError);
        hideMessage(actionWarning);
    }

    function clearMessages() {
        clearFormMessages();
        clearActionMessages();
    }

    function showFormError(message) {
        showMessage(formError, message);
    }

    function showActionError(message) {
        showMessage(actionError, message);
    }

    function showErrors(data) {
        let shown = false;

        for (const [field, message] of Object.entries(data.errors || {})) {
            if (!fieldErrors[field] || typeof message !== 'string') continue;

            showMessage(fieldErrors[field], message);
            shown = true;
        }

        if (!shown) {
            showFormError(typeof data.error === 'string' && data.error !== ''
                ? data.error
                : 'Nie udało się zapisać nieobecności.');
        }
    }

    function syncCustomReason() {
        if (!reasonPicker || !customReasonWrap) return;

        const selected = reasonPicker.querySelector('input[name="powod"]:checked');
        const isCustom = selected !== null && selected.value === '__custom__';

        customReasonWrap.classList.toggle('hidden', !isCustom);

        if (!isCustom && customReason) {
            customReason.value = '';
        }
    }

    if (reasonPicker) {
        for (const radio of reasonPicker.querySelectorAll('input[name="powod"]')) {
            radio.addEventListener('change', syncCustomReason);
        }

        syncCustomReason();
    }

    function resetAbsenceForm() {
        if (!form) return;

        form.reset();

        if (window.panelResetPickers) {
            window.panelResetPickers(form);
        }

        syncCustomReason();
        clearFormMessages();
    }

    if (drawer) {
        drawer.addEventListener('panel:drawer-close', resetAbsenceForm);
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

    if (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (busy) return;

            clearMessages();
            busy = true;
            if (submitButton) submitButton.disabled = true;
            let completed = false;
            const selectedReasonInput = form.querySelector('input[name="powod"]:checked');
            const selectedReason = selectedReasonInput === null ? '' : selectedReasonInput.value;
            const reason = selectedReason === '__custom__'
                ? (customReason ? customReason.value.trim() : '')
                : selectedReason;

            try {
                const result = await send('POST', {
                    pracownik_id: employeeId,
                    data_od: form.elements.data_od.value,
                    data_do: form.elements.data_do.value,
                    rodzaj: form.elements.rodzaj.value,
                    powod: reason
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
                    if (submitButton) submitButton.disabled = false;
                }
            }
        });
    }

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
                    const statusError = result.data.errors && typeof result.data.errors.status === 'string'
                        ? result.data.errors.status
                        : (typeof result.data.error === 'string' && result.data.error !== ''
                            ? result.data.error
                            : 'Nie udało się zmienić statusu nieobecności.');
                    showActionError(statusError);
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
                showActionError('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
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
