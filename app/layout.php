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
const LAYOUT_CARD_CLASS = 'w-full max-w-sm rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800';
const LAYOUT_INPUT_CLASS = 'w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';

// Wyśrodkowane tło dla stron przed zalogowaniem (login, rejestracja).
const LAYOUT_PAGE_BODY_CLASS = 'flex min-h-screen items-center justify-center bg-gray-100 p-4 text-gray-900 dark:bg-gray-900 dark:text-gray-100';

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
