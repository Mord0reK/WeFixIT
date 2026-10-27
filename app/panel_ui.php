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
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'pin' => '<path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/>',
        'folder' => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.9 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
        'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
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
const PANEL_TD_CLASS = 'border-b border-zinc-100 px-4 py-3 align-middle text-zinc-700 last:border-b-0 dark:border-zinc-800 dark:text-zinc-300';
const PANEL_TR_CLASS = 'transition-colors hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40';

// Pusty stan listy z ikoną, tytułem, opisem i opcjonalnym linkiem akcji.
function panel_empty_state(string $title, string $description, array $action = []): void
{
    $titleSafe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $descriptionSafe = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
    $label = $action['label'] ?? '';
    $href = $action['href'] ?? '';
    $hasAction = is_string($label) && is_string($href) && $label !== '' && $href !== '';
    ?>
    <div class="rounded-xl border border-dashed border-zinc-200 bg-zinc-50/60 p-8 text-center dark:border-zinc-800 dark:bg-zinc-950/50">
        <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300">
            <?= panel_icon('inbox', 'h-5 w-5') ?>
        </span>
        <p class="mt-3 text-sm font-medium text-zinc-900 dark:text-zinc-50"><?= $titleSafe ?></p>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400"><?= $descriptionSafe ?></p>
        <?php if ($hasAction): ?>
            <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" class="<?= panel_button_classes('secondary') ?> mt-4">
                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endif; ?>
    </div>
    <?php
}

// Komunikat błędu pola ukryty do czasu odsłonięcia przez JavaScript.
function panel_field_error(string $id): string
{
    $idSafe = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');

    return '<p id="' . $idSafe . '" class="mt-1.5 hidden text-sm text-red-600 dark:text-red-400"></p>';
}
