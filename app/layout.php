<?php
declare(strict_types=1);

/*
    Wspólny szkielet stron HTML: <head> i otwarcie <body>.

    Trzymamy go w jednym miejscu, żeby klasy i kolory nie rozjeżdżały się między
    stronami. Motyw ciemny włącza się na podstawie preferencji systemu
    (variant "dark:" w Tailwindzie działa na prefers-color-scheme).
*/
require_once __DIR__ . '/auth.php';

// Wspólne klasy powtarzanych elementów, żeby wygląd był spójny między stronami.
const LAYOUT_CARD_CLASS = 'w-full max-w-md rounded-xl border border-zinc-200 bg-white p-6 text-zinc-950 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-50 sm:p-8';
const LAYOUT_INPUT_CLASS = 'flex h-10 w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950 shadow-xs transition-colors placeholder:text-zinc-400 focus-visible:border-zinc-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-200 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50 dark:focus-visible:border-zinc-600 dark:focus-visible:ring-zinc-800';
const LAYOUT_TEXTAREA_CLASS = 'min-h-24 w-full resize-y rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950 shadow-xs transition-colors placeholder:text-zinc-400 focus-visible:border-zinc-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-200 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50 dark:focus-visible:border-zinc-600 dark:focus-visible:ring-zinc-800';
const LAYOUT_LABEL_CLASS = 'mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300';
const LAYOUT_HINT_CLASS = 'mt-1.5 text-xs text-zinc-500 dark:text-zinc-400';
const LAYOUT_BUTTON_CLASS = 'inline-flex h-10 w-full items-center justify-center rounded-md bg-zinc-900 px-4 text-sm font-medium text-white shadow-sm transition-colors hover:bg-zinc-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60 dark:bg-zinc-50 dark:text-zinc-900 dark:hover:bg-zinc-200 dark:focus-visible:ring-offset-zinc-900';

// Wyśrodkowane tło dla stron przed zalogowaniem (login, rejestracja).
const LAYOUT_PAGE_BODY_CLASS = 'flex min-h-screen items-center justify-center bg-zinc-50 p-4 text-zinc-950 antialiased dark:bg-zinc-950 dark:text-zinc-50';

// Znak serwisu jest stałym, zaufanym fragmentem SVG używanym w nawigacji i formularzach.
function layout_logo(string $classes = 'h-5 w-5'): string
{
    $classesSafe = htmlspecialchars($classes, ENT_QUOTES, 'UTF-8');

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" class="' . $classesSafe . '" aria-hidden="true">'
        . '<path d="M14.7 6.3a4.5 4.5 0 0 0-6.4 6.4l-4.6 4.6a2.1 2.1 0 0 0 3 3l4.6-4.6a4.5 4.5 0 0 0 6.4-6.4l-2.3 2.3-2.5-.4-.4-2.5z"/>'
        . '</svg>';
}

function layout_head(string $title, string $bodyClass = LAYOUT_PAGE_BODY_CLASS): void
{
    $cssHref = htmlspecialchars(auth_base_path() . '/assets/css/app.css', ENT_QUOTES, 'UTF-8');
    $toastJsHref = htmlspecialchars(auth_base_path() . '/assets/js/toast.js', ENT_QUOTES, 'UTF-8');
    $titleSafe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $bodyClassSafe = htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8');

    echo <<<HTML
        <!DOCTYPE html>
        <html lang="pl">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <meta name="color-scheme" content="light dark">
            <title>{$titleSafe}</title>
            <link rel="stylesheet" href="{$cssHref}">
            <script src="{$toastJsHref}" defer></script>
        </head>
        <body class="{$bodyClassSafe}">
            <div id="toast-container" class="pointer-events-none fixed bottom-4 right-4 z-50 flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2"></div>
        HTML;
}

function layout_foot(): void
{
    echo <<<HTML
        </body>
        </html>
        HTML;
}
