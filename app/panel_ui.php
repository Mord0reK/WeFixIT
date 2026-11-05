<?php
declare(strict_types=1);

/*
    Komponenty widoków panelu: ikony, przyciski, plakietki, tabele, pusty stan
    i komunikaty błędów pól.

    Plik zawiera wyłącznie funkcje i stałe renderujące HTML. Nie wykonuje
    zapytań SQL ani operacji zapisu.
*/

// Ikony panelu to stałe, zaufane fragmenty SVG — żadnych danych użytkownika.
function panel_icon(string $name, string $classes = 'h-4 w-4 shrink-0'): string
{
    $paths = match ($name) {
        'home' => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
        'dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'briefcase' => '<path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/>',
        'user' => '<path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/>',
        'logout' => '<path d="m16 17 5-5-5-5"/><path d="M21 12H9"/><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>',
        'menu' => '<path d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>',
        'folder' => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.9 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
        'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'calendar' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'trash' => '<path d="M10 11v6"/><path d="M14 11v6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'circle-slash' => '<circle cx="12" cy="12" r="10"/><line x1="9" x2="15" y1="15" y2="9"/>',
        'circle-check' => '<circle cx="12" cy="12" r="10"/><path d="m16 9-5.5 5.5L8 12"/>',
        'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        default => '<circle cx="12" cy="12" r="9"/>',
    };

    $classesSafe = htmlspecialchars($classes, ENT_QUOTES, 'UTF-8');

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="' . $classesSafe . '" aria-hidden="true">'
        . $paths . '</svg>';
}

// Kompletne warianty klas przycisków są literałami, żeby Tailwind je wykrył.
function panel_button_classes(string $variant): string
{
    return match ($variant) {
        'secondary' => 'inline-flex h-9 items-center justify-center gap-2 rounded-md border border-zinc-200 bg-white px-4 text-sm font-medium text-zinc-700 shadow-sm transition-colors hover:bg-zinc-100 hover:text-zinc-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800 dark:hover:text-zinc-50 dark:focus-visible:ring-offset-zinc-900',
        'danger' => 'inline-flex h-9 items-center justify-center gap-2 rounded-md bg-red-600 px-4 text-sm font-medium text-white shadow-sm transition-colors hover:bg-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-300 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-red-600 dark:hover:bg-red-500 dark:focus-visible:ring-offset-zinc-900',
        'ghost' => 'inline-flex h-9 items-center justify-center gap-2 rounded-md px-4 text-sm font-medium text-zinc-600 transition-colors hover:bg-zinc-100 hover:text-zinc-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-50 dark:focus-visible:ring-offset-zinc-900',
        default => 'inline-flex h-9 items-center justify-center gap-2 rounded-md bg-zinc-900 px-4 text-sm font-medium text-white shadow-sm transition-colors hover:bg-zinc-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-zinc-50 dark:text-zinc-900 dark:hover:bg-zinc-200 dark:focus-visible:ring-offset-zinc-900',
    };
}

// Plakietka statusu spójna z oznaczeniem roli w nagłówku panelu.
function panel_badge(string $label, string $variant): string
{
    $labelSafe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

    $style = match ($variant) {
        'success' => [
            'badge' => 'inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-300',
            'dot' => 'bg-emerald-500',
        ],
        'warning' => [
            'badge' => 'inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200',
            'dot' => 'bg-amber-500',
        ],
        'danger' => [
            'badge' => 'inline-flex items-center gap-1.5 rounded-full border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300',
            'dot' => 'bg-red-500',
        ],
        default => [
            'badge' => 'inline-flex items-center gap-1.5 rounded-full border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300',
            'dot' => 'bg-zinc-400',
        ],
    };

    return '<span class="' . $style['badge'] . '">'
        . '<span class="' . $style['dot'] . ' h-1.5 w-1.5 rounded-full" aria-hidden="true"></span>'
        . $labelSafe
        . '</span>';
}

// Klasy tabel panelu trzymamy jako stałe, żeby wygląd był spójny między modułami.
const PANEL_TABLE_WRAP_CLASS = 'overflow-x-auto rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900';
const PANEL_TABLE_CLASS = 'w-full min-w-[40rem] border-collapse text-left text-sm';
const PANEL_TH_CLASS = 'whitespace-nowrap border-b border-zinc-200 px-4 py-3 text-xs font-medium uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:text-zinc-400';
const PANEL_TD_CLASS = 'border-b border-zinc-100 px-4 py-3 align-middle text-zinc-700 dark:border-zinc-800 dark:text-zinc-300';
const PANEL_TR_CLASS = 'transition-colors hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40';

/*
    Filtr kolumny jest przyciskiem w nagłówku tabeli. Menu jest pozycjonowane
    przez JavaScript jako fixed, żeby nie ucinał go poziomy scroll tabeli.
*/
const PANEL_FILTER_TRIGGER_CLASS = '-mx-2 flex w-full items-center justify-between gap-1.5 rounded-md px-2 py-1 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-50';
const PANEL_FILTER_MENU_CLASS = 'panel-filter-scrollbar fixed z-50 hidden max-h-[18.5rem] w-max min-w-56 max-w-[min(24rem,calc(100vw-2rem))] overflow-y-auto overscroll-contain whitespace-normal rounded-lg border border-zinc-200 bg-white p-1.5 shadow-lg dark:border-zinc-800 dark:bg-zinc-900';
const PANEL_FILTER_MENU_TITLE_CLASS = 'px-2 pb-1 pt-1 text-[11px] font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500';
const PANEL_FILTER_OPTION_CLASS = 'flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm font-normal normal-case tracking-normal text-zinc-700 transition-colors hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800';
const PANEL_FILTER_RADIO_CLASS = 'h-4 w-4 shrink-0 accent-zinc-900 dark:accent-zinc-50';
const PANEL_FILTER_ACTIVE_DOT_CLASS = 'h-1.5 w-1.5 shrink-0 rounded-full bg-zinc-900 dark:bg-zinc-50';
const PANEL_FILTER_DATE_FIELD_CLASS = 'block px-2 py-1';
const PANEL_FILTER_DATE_LABEL_CLASS = 'mb-1 block text-[11px] font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500';
const PANEL_FILTER_DATE_INPUT_CLASS = 'block h-9 w-full rounded-md border border-zinc-200 bg-white px-2 text-sm text-zinc-700 shadow-xs focus-visible:border-zinc-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-200 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:focus-visible:border-zinc-600 dark:focus-visible:ring-zinc-800';

// Początek komórki nagłówka z przyciskiem otwierającym menu filtra.
function panel_filter_start(string $id, string $label, bool $active = false): void
{
    $idSafe = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
    $labelSafe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="relative" data-filter-dropdown>
        <button type="button" class="<?= PANEL_FILTER_TRIGGER_CLASS ?>" data-filter-toggle
            aria-expanded="false" aria-haspopup="true" aria-controls="<?= $idSafe ?>-menu">
            <span class="flex items-center gap-1.5">
                <span><?= $labelSafe ?></span>
                <?php if ($active): ?>
                    <span class="<?= PANEL_FILTER_ACTIVE_DOT_CLASS ?>" aria-hidden="true"></span>
                    <span class="sr-only">Filtr aktywny</span>
                <?php endif; ?>
            </span>
            <?= panel_icon('chevron-down', 'h-3.5 w-3.5 shrink-0') ?>
        </button>
        <div id="<?= $idSafe ?>-menu" class="<?= PANEL_FILTER_MENU_CLASS ?>" data-filter-menu>
    <?php
}

// Zamknięcie markupu rozpoczętego przez panel_filter_start().
function panel_filter_end(): void
{
    ?>
        </div>
    </div>
    <?php
}

/*
    Opcje filtra renderujemy jako radios powiązane z ukrytym formularzem przez
    atrybut form. Domyślnie zmiana zaznaczenia od razu wysyła formularz
    (data-filter-auto-submit); w dropdownie formularza autoSubmit wyłączamy.
*/
function panel_filter_options(
    string $formId,
    string $name,
    string $legend,
    array $options,
    string $selected,
    bool $autoSubmit = true
): void
{
    $formIdSafe = htmlspecialchars($formId, ENT_QUOTES, 'UTF-8');
    $nameSafe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $legendSafe = htmlspecialchars($legend, ENT_QUOTES, 'UTF-8');
    ?>
    <p class="<?= PANEL_FILTER_MENU_TITLE_CLASS ?>" aria-hidden="true"><?= $legendSafe ?></p>
    <fieldset>
        <legend class="sr-only"><?= $legendSafe ?></legend>
        <?php foreach ($options as $option): ?>
            <?php
            $value = (string) ($option['value'] ?? '');
            $optionLabel = (string) ($option['label'] ?? $value);
            $disabled = (bool) ($option['disabled'] ?? false);
            $inactive = (bool) ($option['inactive'] ?? false);
            $optionClasses = PANEL_FILTER_OPTION_CLASS . ($disabled ? ' cursor-not-allowed opacity-50' : '');
            ?>
            <label class="<?= $optionClasses ?>">
                <input type="radio" form="<?= $formIdSafe ?>" name="<?= $nameSafe ?>"
                    value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                    class="<?= PANEL_FILTER_RADIO_CLASS ?>"
                    <?= $value === $selected ? 'checked' : '' ?>
                    <?= $disabled ? 'disabled' : '' ?>
                    <?= $inactive ? 'data-inactive="1"' : '' ?>
                    <?= $autoSubmit ? 'data-filter-auto-submit' : '' ?>>
                <span class="min-w-0 break-words"><?= htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8') ?></span>
            </label>
        <?php endforeach; ?>
    </fieldset>
    <?php
}

// Pole daty w menu filtra; używane przez kolumny z zakresem terminów.
function panel_filter_date(string $formId, string $name, string $label, ?string $value, string $ariaLabel): void
{
    $formIdSafe = htmlspecialchars($formId, ENT_QUOTES, 'UTF-8');
    $nameSafe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $labelSafe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $ariaLabelSafe = htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8');
    $valueSafe = $value !== null ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : '';
    ?>
    <label class="<?= PANEL_FILTER_DATE_FIELD_CLASS ?>">
        <span class="<?= PANEL_FILTER_DATE_LABEL_CLASS ?>"><?= $labelSafe ?></span>
        <input form="<?= $formIdSafe ?>" name="<?= $nameSafe ?>" type="date" value="<?= $valueSafe ?>"
            aria-label="<?= $ariaLabelSafe ?>" class="<?= PANEL_FILTER_DATE_INPUT_CLASS ?>"
            data-filter-auto-submit>
    </label>
    <?php
}

// Pusty stan listy z ikoną, tytułem, opisem i opcjonalną akcją (link lub drawer).
function panel_empty_state(string $title, string $description, array $action = []): void
{
    $titleSafe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $descriptionSafe = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
    $label = $action['label'] ?? '';
    $href = $action['href'] ?? '';
    $drawer = $action['drawer'] ?? '';
    $hasDrawer = is_string($drawer) && $drawer !== '';
    $hasAction = is_string($label) && $label !== ''
        && ($hasDrawer || (is_string($href) && $href !== ''));
    $actionHref = $hasDrawer ? '#' . $drawer : $href;
    ?>
    <div class="rounded-xl border border-dashed border-zinc-200 bg-zinc-50/60 p-8 text-center dark:border-zinc-800 dark:bg-zinc-950/50">
        <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300">
            <?= panel_icon('inbox', 'h-5 w-5') ?>
        </span>
        <p class="mt-3 text-sm font-medium text-zinc-900 dark:text-zinc-50"><?= $titleSafe ?></p>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"><?= $descriptionSafe ?></p>
        <?php if ($hasAction): ?>
            <a href="<?= htmlspecialchars($actionHref, ENT_QUOTES, 'UTF-8') ?>"
                class="<?= panel_button_classes('secondary') ?> mt-4"
                <?php if ($hasDrawer): ?>
                    data-drawer-open="<?= htmlspecialchars($drawer, ENT_QUOTES, 'UTF-8') ?>"
                    aria-haspopup="dialog" aria-controls="<?= htmlspecialchars($drawer, ENT_QUOTES, 'UTF-8') ?>"
                <?php endif; ?>>
                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endif; ?>
    </div>
    <?php
}

// Wysuwany panel z prawej strony; treść formularza wstawia widok między start i end.
function panel_drawer_start(
    string $id,
    string $titleId,
    string $title,
    string $description = '',
    string $descriptionId = ''
): void
{
    $idSafe = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
    $titleIdSafe = htmlspecialchars($titleId, ENT_QUOTES, 'UTF-8');
    $titleSafe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $descriptionSafe = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
    $descriptionIdSafe = htmlspecialchars($descriptionId, ENT_QUOTES, 'UTF-8');
    ?>
    <div id="<?= $idSafe ?>" class="fixed inset-0 z-50 hidden" data-drawer aria-hidden="true">
        <div class="absolute inset-0 bg-zinc-950/50 backdrop-blur-sm" data-drawer-overlay></div>
        <div class="absolute inset-y-0 right-0 flex w-full max-w-xl flex-col border-l border-zinc-200 bg-white shadow-xl dark:border-zinc-800 dark:bg-zinc-950"
            role="dialog" aria-modal="true" aria-labelledby="<?= $titleIdSafe ?>" data-drawer-panel>
            <div class="flex items-start justify-between gap-4 border-b border-zinc-200 px-6 py-5 dark:border-zinc-800">
                <div class="min-w-0">
                    <h2 id="<?= $titleIdSafe ?>" class="text-lg font-semibold tracking-tight"><?= $titleSafe ?></h2>
                    <?php if ($descriptionSafe !== ''): ?>
                        <p <?= $descriptionIdSafe !== '' ? 'id="' . $descriptionIdSafe . '"' : '' ?>
                            class="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400">
                            <?= $descriptionSafe ?>
                        </p>
                    <?php endif; ?>
                </div>
                <button type="button" data-drawer-close aria-label="Zamknij panel"
                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-50 dark:focus-visible:ring-zinc-700">
                    <?= panel_icon('x', 'h-4 w-4 shrink-0') ?>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto px-6 py-5">
    <?php
}

// Zamknięcie markupu rozpoczętego przez panel_drawer_start().
function panel_drawer_end(): void
{
    ?>
            </div>
        </div>
    </div>
    <?php
}

/*
    Pasek paginacji listy. Linki budujemy z tej samej tablicy parametrów co
    filtry, więc przejście na kolejną stronę nie gubi wyszukiwania ani filtrów.
*/
function panel_pagination(string $basePath, string $path, array $params, array $pagination): void
{
    $page = (int) ($pagination['page'] ?? 1);
    $pages = max(1, (int) ($pagination['pages'] ?? 1));
    $total = (int) ($pagination['total'] ?? 0);
    $size = (int) ($pagination['size'] ?? 25);
    $page = max(1, min($page, $pages));

    if ($total <= $size || $pages <= 1) {
        return;
    }

    $buildUrl = static function (int $targetPage) use ($basePath, $path, $params): string {
        $query = $params;
        $query['page'] = $targetPage;

        return $basePath . $path . '?' . http_build_query($query);
    };

    $visiblePages = 7;
    $start = max(1, $page - 3);
    $end = min($pages, $start + $visiblePages - 1);
    $start = max(1, $end - $visiblePages + 1);
    ?>
    <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        data-pagination>
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            Strona <?= $page ?> z <?= $pages ?> (<?= $total ?> rekordów)
        </p>
        <nav aria-label="Paginacja" class="flex flex-wrap items-center gap-1">
            <?php if ($page > 1): ?>
                <a href="<?= htmlspecialchars($buildUrl($page - 1), ENT_QUOTES, 'UTF-8') ?>"
                    class="<?= panel_button_classes('ghost') ?>" rel="prev">Poprzednia</a>
            <?php else: ?>
                <span class="<?= panel_button_classes('ghost') ?> opacity-50" aria-disabled="true">Poprzednia</span>
            <?php endif; ?>

            <?php for ($number = $start; $number <= $end; $number++): ?>
                <?php if ($number === $page): ?>
                    <span class="<?= panel_button_classes('primary') ?>" aria-current="page"><?= $number ?></span>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($buildUrl($number), ENT_QUOTES, 'UTF-8') ?>"
                        class="<?= panel_button_classes('secondary') ?>"><?= $number ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $pages): ?>
                <a href="<?= htmlspecialchars($buildUrl($page + 1), ENT_QUOTES, 'UTF-8') ?>"
                    class="<?= panel_button_classes('ghost') ?>" rel="next">Następna</a>
            <?php else: ?>
                <span class="<?= panel_button_classes('ghost') ?> opacity-50" aria-disabled="true">Następna</span>
            <?php endif; ?>
        </nav>
    </div>
    <?php
}

// Komunikat błędu pola ukryty do czasu odsłonięcia przez JavaScript.
function panel_field_error(string $id): string
{
    $idSafe = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');

    return '<p id="' . $idSafe . '" class="mt-1.5 hidden text-sm text-red-600 dark:text-red-400"></p>';
}
