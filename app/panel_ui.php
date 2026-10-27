<?php
declare(strict_types=1);

/*
    Komponenty widoków panelu: przyciski, plakietki, tabele, pusty stan
    i komunikaty błędów pól.

    Plik zawiera wyłącznie funkcje i stałe renderujące HTML. Nie wykonuje
    zapytań SQL ani operacji zapisu. Korzysta z ikon zdefiniowanych
    w panel_layout.php.
*/

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
