<?php
declare(strict_types=1);

/*
    Szkielet zalogowanego panelu: sidebar z menu oraz sekcją użytkownika na dole.

    Sidebar jest menu całego panelu. Na wąskich ekranach jest schowany i wysuwany
    przyciskiem (hamburger); wylogowanie obsługuje fetch() z tokenem CSRF.
*/
require_once __DIR__ . '/layout.php';

// Klasy tła i karty panelu wspólne dla wszystkich stron zalogowanych.
const PANEL_BODY_CLASS = 'min-h-screen bg-gray-100 text-gray-900 dark:bg-gray-900 dark:text-gray-100 lg:flex';
const PANEL_CARD_CLASS = 'mx-auto w-full max-w-2xl rounded-lg bg-white p-6 shadow-sm dark:bg-gray-800';

function panel_layout_head(array $user, string $title, string $active): void
{
    $basePath = auth_base_path();
    $logoutUrl = htmlspecialchars($basePath . '/api/auth/logout', ENT_QUOTES, 'UTF-8');

    $fullName = htmlspecialchars($user['imie'] . ' ' . $user['nazwisko'], ENT_QUOTES, 'UTF-8');
    $roleLabel = htmlspecialchars(auth_role_label($user['rola']), ENT_QUOTES, 'UTF-8');

    $menu = [
        [
            'key' => 'home',
            'label' => 'Panel',
            'href' => htmlspecialchars($basePath . '/' . auth_role_home_path($user['rola']), ENT_QUOTES, 'UTF-8'),
        ],
        [
            'key' => 'profil',
            'label' => 'Mój profil',
            'href' => htmlspecialchars($basePath . '/profil', ENT_QUOTES, 'UTF-8'),
        ],
    ];

    layout_head($title, PANEL_BODY_CLASS);
    ?>
    <button id="sidebar-toggle" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="Otwórz menu"
        class="fixed left-4 top-4 z-40 rounded border border-gray-300 bg-white p-2 text-gray-700 lg:hidden dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
        </svg>
    </button>

    <div id="sidebar-overlay" class="fixed inset-0 z-30 hidden bg-black/50 lg:hidden"></div>

    <aside id="sidebar"
        class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-gray-200 bg-white transition-transform lg:static lg:translate-x-0 dark:border-gray-700 dark:bg-gray-800">
        <div class="flex h-16 shrink-0 items-center border-b border-gray-200 px-4 text-lg font-semibold dark:border-gray-700">
            Panel WeFixIT
        </div>

        <nav class="flex-1 space-y-1 overflow-y-auto p-3">
            <?php foreach ($menu as $item): ?>
                <?php $isActive = $item['key'] === $active; ?>
                <a href="<?= $item['href'] ?>"
                    class="block rounded px-3 py-2 text-sm font-medium <?= $isActive
                        ? 'bg-blue-600 text-white'
                        : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' ?>"
                    <?= $isActive ? 'aria-current="page"' : '' ?>>
                    <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="flex items-center gap-3 border-t border-gray-200 p-3 dark:border-gray-700">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                class="h-8 w-8 shrink-0 text-gray-400" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium"><?= $fullName ?></p>
                <p class="truncate text-xs text-gray-500 dark:text-gray-400"><?= $roleLabel ?></p>
            </div>
            <form id="logout-form" action="<?= $logoutUrl ?>" method="post">
                <button type="submit" title="Wyloguj" aria-label="Wyloguj"
                    class="rounded p-2 text-gray-500 hover:bg-gray-100 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-red-400 dark:focus:ring-offset-gray-800">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5" aria-hidden="true">
                        <path d="m16 17 5-5-5-5" />
                        <path d="M21 12H9" />
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                    </svg>
                </button>
            </form>
        </div>

        <p id="logout-error" role="alert" class="hidden px-3 pb-3 text-xs text-red-600 dark:text-red-400"></p>
    </aside>

    <main class="flex-1 p-6 pt-16 lg:p-8">
    <?php
}

function panel_layout_foot(): void
{
    $csrfToken = json_encode(
        csrf_token(),
        JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    $loginUrl = htmlspecialchars(auth_base_path() . '/login', ENT_QUOTES, 'UTF-8');

    echo <<<HTML
            </main>
            <script>
                (function () {
                    const sidebar = document.getElementById('sidebar');
                    const toggle = document.getElementById('sidebar-toggle');
                    const overlay = document.getElementById('sidebar-overlay');

                    function setSidebar(open) {
                        sidebar.classList.toggle('-translate-x-full', !open);
                        overlay.classList.toggle('hidden', !open);
                        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                    }

                    toggle.addEventListener('click', function () {
                        setSidebar(sidebar.classList.contains('-translate-x-full'));
                    });

                    overlay.addEventListener('click', function () {
                        setSidebar(false);
                    });

                    const form = document.getElementById('logout-form');
                    const error = document.getElementById('logout-error');
                    const button = form.querySelector('button[type="submit"]');
                    const csrfToken = {$csrfToken};

                    form.addEventListener('submit', async function (event) {
                        event.preventDefault();

                        if (button.disabled) return;

                        error.textContent = '';
                        error.classList.add('hidden');
                        button.disabled = true;
                        button.setAttribute('aria-busy', 'true');

                        try {
                            const response = await fetch(form.action, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: { 'X-CSRF-Token': csrfToken }
                            });
                            const data = await response.json();

                            if (!response.ok || data.success !== true) {
                                error.textContent = data.error || 'Nie udało się wylogować.';
                                error.classList.remove('hidden');
                                return;
                            }

                            window.location.href = '{$loginUrl}';
                        } catch {
                            error.textContent = 'Nie udało się połączyć z serwerem. Spróbuj ponownie.';
                            error.classList.remove('hidden');
                        } finally {
                            button.disabled = false;
                            button.removeAttribute('aria-busy');
                        }
                    });
                })();
            </script>
        HTML;

    layout_foot();
}
