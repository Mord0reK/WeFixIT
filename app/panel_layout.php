<?php
declare(strict_types=1);

/*
    Szkielet zalogowanego panelu: sidebar w stylu shadcn/ui, nagłówek treści
    oraz wspólne elementy widoków.

    Sidebar grupuje nawigację, pokazuje kartę użytkownika i zwija się na
    desktopie do kolumny ikon. Na wąskich ekranach jest wysuwany przyciskiem,
    a wylogowanie obsługuje fetch() z tokenem CSRF.
*/
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/panel_ui.php';

// Klasy tła i karty panelu wspólne dla wszystkich stron zalogowanych.
const PANEL_BODY_CLASS = 'min-h-screen bg-zinc-50 text-zinc-950 antialiased dark:bg-zinc-950 dark:text-zinc-50';
const PANEL_CARD_CLASS = 'mx-auto w-full max-w-2xl rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-8';
const PANEL_CARD_WIDE_CLASS = 'mx-auto w-full max-w-6xl rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-8';

// Stany pozycji menu trzymamy jako pełne literały, żeby Tailwind wykrył klasy.
const PANEL_ACTIVE_ITEM_CLASS = 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800/80 dark:text-zinc-50';
const PANEL_IDLE_ITEM_CLASS = 'text-zinc-600 hover:bg-zinc-100/70 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-900 dark:hover:text-zinc-50';

// Menu panelu jest jednym źródłem nawigacji; role decydują, które pozycje są widoczne.
function panel_menu_items(string $role): array
{
    $homeLabel = match ($role) {
        'admin' => 'Dashboard',
        'pracownik' => 'Panel pracownika',
        default => 'Panel klienta',
    };

    $items = [
        [
            'key' => 'home',
            'label' => $homeLabel,
            'path' => '/' . auth_role_home_path($role),
            'roles' => ['klient', 'pracownik', 'admin'],
            'group' => 'Przegląd',
            'icon' => $role === 'admin' ? 'dashboard' : 'home',
        ],
        [
            'key' => 'client_panel',
            'label' => 'Panel klienta',
            'path' => '/panel',
            'roles' => ['admin'],
            'group' => 'Podgląd',
            'icon' => 'home',
        ],
        [
            'key' => 'employee_panel',
            'label' => 'Panel pracownika',
            'path' => '/pracownik',
            'roles' => ['admin'],
            'group' => 'Podgląd',
            'icon' => 'briefcase',
        ],
        [
            'key' => 'profil',
            'label' => 'Mój profil',
            'path' => '/profil',
            'roles' => ['klient', 'pracownik', 'admin'],
            'group' => 'Konto',
            'icon' => 'user',
        ],
        [
            'key' => 'categories',
            'label' => 'Kategorie',
            'path' => '/kategorie',
            'roles' => ['admin'],
            'group' => 'Zarządzanie',
            'icon' => 'folder',
        ],
    ];

    $basePath = auth_base_path();
    $menu = [];

    foreach ($items as $item) {
        if (!in_array($role, $item['roles'], true)) {
            continue;
        }

        $item['href'] = $basePath . $item['path'];
        $menu[] = $item;
    }

    return $menu;
}

// Inicjały z imienia i nazwiska trafiają do awatara w sidebarze.
function panel_user_initials(array $user): string
{
    $first = mb_substr((string) ($user['imie'] ?? ''), 0, 1);
    $last = mb_substr((string) ($user['nazwisko'] ?? ''), 0, 1);

    return mb_strtoupper($first . $last);
}

// Spójny nagłówek sekcji modułu; akcje są linkami przygotowanymi przez widok.
function panel_section_header(string $title, string $description = '', array $actions = []): void
{
    $titleSafe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $descriptionSafe = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight"><?= $titleSafe ?></h1>
            <?php if ($descriptionSafe !== ''): ?>
                <p class="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400"><?= $descriptionSafe ?></p>
            <?php endif; ?>
        </div>
        <?php if ($actions !== []): ?>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <?php foreach ($actions as $action): ?>
                    <?php
                    if (!is_array($action)) {
                        continue;
                    }

                    $label = $action['label'] ?? '';
                    $href = $action['href'] ?? '';

                    if (!is_string($label) || !is_string($href) || $label === '' || $href === '') {
                        continue;
                    }
                    ?>
                    <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
                        class="<?= panel_button_classes('primary') ?>">
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

function panel_layout_head(array $user, string $title, string $active): void
{
    $basePath = auth_base_path();
    $logoutUrl = htmlspecialchars($basePath . '/api/auth/logout', ENT_QUOTES, 'UTF-8');

    $fullName = htmlspecialchars(trim($user['imie'] . ' ' . $user['nazwisko']), ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $roleLabel = htmlspecialchars(auth_role_label((string) $user['rola']), ENT_QUOTES, 'UTF-8');
    $initials = htmlspecialchars(panel_user_initials($user), ENT_QUOTES, 'UTF-8');
    $menu = panel_menu_items((string) $user['rola']);

    $menuGroups = [];
    $pageLabel = 'Panel';

    foreach ($menu as $item) {
        $menuGroups[$item['group']][] = $item;

        if ($item['key'] === $active) {
            $pageLabel = $item['label'];
        }
    }

    $pageLabelSafe = htmlspecialchars($pageLabel, ENT_QUOTES, 'UTF-8');

    layout_head($title, PANEL_BODY_CLASS);
    ?>
    <div id="panel-shell" class="min-h-screen lg:grid lg:grid-cols-[18rem_1fr] lg:transition-[grid-template-columns] lg:duration-200 lg:ease-out">
        <div id="sidebar-overlay" class="fixed inset-0 z-30 hidden bg-zinc-950/50 backdrop-blur-sm lg:hidden"></div>

        <aside id="sidebar" data-state="expanded"
            class="group fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col border-r border-zinc-200 bg-white transition-[width,transform,box-shadow] duration-200 ease-out lg:sticky lg:top-0 lg:bottom-auto lg:h-screen lg:w-auto lg:translate-x-0 lg:data-[state=hover]:z-50 lg:data-[state=hover]:w-72 lg:data-[state=hover]:shadow-xl dark:border-zinc-800 dark:bg-zinc-950">
            <div class="flex h-16 shrink-0 items-center gap-2.5 border-b border-zinc-200 px-4 dark:border-zinc-800 lg:group-data-[state=collapsed]:justify-center lg:group-data-[state=collapsed]:px-2">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-zinc-900 text-white shadow-sm dark:bg-zinc-50 dark:text-zinc-900">
                    <?= layout_logo('h-5 w-5') ?>
                </span>
                <span class="min-w-0 flex-1 lg:group-data-[state=collapsed]:hidden">
                    <span class="block truncate text-sm font-semibold tracking-tight">WeFixIT</span>
                    <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">Serwis komputerowy</span>
                </span>
                <button id="sidebar-collapse" type="button" aria-controls="sidebar" aria-expanded="true" aria-label="Zwiń menu" title="Zwiń menu"
                    class="hidden h-8 w-8 shrink-0 items-center justify-center rounded-md text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 lg:inline-flex lg:group-data-[state=collapsed]:hidden dark:text-zinc-400 dark:hover:bg-zinc-900 dark:hover:text-zinc-50">
                    <span id="sidebar-collapse-icon" class="inline-flex transition-transform duration-200">
                        <?= panel_icon('chevron-left', 'h-4 w-4') ?>
                    </span>
                    <span id="sidebar-pin-icon" class="hidden">
                        <?= panel_icon('pin', 'h-4 w-4') ?>
                    </span>
                </button>
            </div>

            <nav aria-label="Menu panelu" class="flex-1 overflow-y-auto px-3 py-4">
                <?php foreach ($menuGroups as $groupLabel => $groupItems): ?>
                    <div class="mb-5 last:mb-0">
                        <p class="mb-1.5 px-2 text-[11px] font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500 lg:group-data-[state=collapsed]:hidden">
                            <?= htmlspecialchars((string) $groupLabel, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <ul class="space-y-0.5">
                            <?php foreach ($groupItems as $item): ?>
                                <?php
                                $isActive = $item['key'] === $active;
                                $itemClass = $isActive ? PANEL_ACTIVE_ITEM_CLASS : PANEL_IDLE_ITEM_CLASS;
                                $iconClass = $isActive
                                    ? 'h-4 w-4 shrink-0 text-zinc-900 dark:text-zinc-50'
                                    : 'h-4 w-4 shrink-0 text-zinc-400 transition-colors group-hover/item:text-zinc-600 dark:text-zinc-500 dark:group-hover/item:text-zinc-300';
                                ?>
                                <li>
                                    <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
                                        title="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>"
                                        class="group/item flex items-center gap-3 rounded-md px-2.5 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 lg:group-data-[state=collapsed]:justify-center lg:group-data-[state=collapsed]:px-2 <?= $itemClass ?>"
                                        <?= $isActive ? 'aria-current="page"' : '' ?>>
                                        <?= panel_icon($item['icon'], $iconClass) ?>
                                        <span class="truncate lg:group-data-[state=collapsed]:hidden">
                                            <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </nav>

            <div class="border-t border-zinc-200 p-3 dark:border-zinc-800">
                <div class="flex items-center gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-2.5 dark:border-zinc-800 dark:bg-zinc-900/60 lg:group-data-[state=collapsed]:flex-col lg:group-data-[state=collapsed]:justify-center lg:group-data-[state=collapsed]:gap-2 lg:group-data-[state=collapsed]:border-transparent lg:group-data-[state=collapsed]:bg-transparent lg:group-data-[state=collapsed]:p-0">
                    <span title="<?= $fullName ?>"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                        <?= $initials ?>
                    </span>
                    <div class="min-w-0 flex-1 lg:group-data-[state=collapsed]:hidden">
                        <p class="truncate text-sm font-medium"><?= $fullName ?></p>
                        <p class="truncate text-xs text-zinc-500 dark:text-zinc-400"><?= $email ?></p>
                    </div>
                    <form id="logout-form" action="<?= $logoutUrl ?>" method="post" class="shrink-0 lg:group-data-[state=collapsed]:w-full">
                        <button type="submit" title="Wyloguj" aria-label="Wyloguj"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-md text-zinc-500 transition-colors hover:bg-red-50 hover:text-red-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-300 disabled:cursor-wait disabled:opacity-60 dark:text-zinc-400 dark:hover:bg-red-950/30 dark:hover:text-red-400 lg:group-data-[state=collapsed]:w-full">
                            <?= panel_icon('logout', 'h-4 w-4 shrink-0') ?>
                        </button>
                    </form>
                </div>

                <p id="logout-error" role="alert" class="mt-2 hidden px-2 text-xs text-red-600 dark:text-red-400"></p>
            </div>
        </aside>

        <div class="flex min-w-0 flex-col">
            <header class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b border-zinc-200 bg-white/80 px-4 backdrop-blur supports-[backdrop-filter]:bg-white/60 dark:border-zinc-800 dark:bg-zinc-950/80 dark:supports-[backdrop-filter]:bg-zinc-950/60 lg:px-8">
                <button id="sidebar-toggle" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="Otwórz menu"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-zinc-200 bg-white text-zinc-600 shadow-sm transition-colors hover:bg-zinc-100 hover:text-zinc-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 lg:hidden dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-50">
                    <?= panel_icon('menu', 'h-5 w-5') ?>
                </button>

                <div class="flex min-w-0 items-center gap-2 text-sm">
                    <span class="hidden text-zinc-500 dark:text-zinc-400 sm:inline">WeFixIT</span>
                    <span class="hidden text-zinc-300 dark:text-zinc-700 sm:inline">/</span>
                    <span class="truncate font-medium"><?= $pageLabelSafe ?></span>
                </div>

                <div class="ml-auto flex items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                        <?= $roleLabel ?>
                    </span>
                </div>
            </header>

            <main class="flex-1 p-4 sm:p-6 lg:p-8">
    <?php
}

function panel_layout_foot(): void
{
    $csrfToken = json_encode(
        csrf_token(),
        JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    $loginUrl = htmlspecialchars(auth_base_path() . '/login', ENT_QUOTES, 'UTF-8');
    $confirmCancelClass = panel_button_classes('secondary');
    $confirmDangerClass = panel_button_classes('danger');
    $confirmDangerClassJson = json_encode(
        panel_button_classes('danger'),
        JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    $confirmPrimaryClassJson = json_encode(
        panel_button_classes('primary'),
        JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );

    echo <<<HTML
            </main>
        </div>
    </div>
    <dialog id="app-confirm" aria-labelledby="app-confirm-title" aria-describedby="app-confirm-message"
        class="w-[calc(100%-2rem)] max-w-md rounded-xl border border-zinc-200 bg-white p-0 text-zinc-950 shadow-xl backdrop:bg-zinc-950/50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-50">
        <form method="dialog" class="p-6">
            <h2 id="app-confirm-title" class="text-lg font-semibold tracking-tight">Potwierdź operację</h2>
            <p id="app-confirm-message" class="mt-2 text-sm text-zinc-600 dark:text-zinc-400"></p>
            <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" id="app-confirm-cancel" class="{$confirmCancelClass}">Anuluj</button>
                <button type="submit" id="app-confirm-ok" value="confirm" class="{$confirmDangerClass}">Potwierdź</button>
            </div>
        </form>
    </dialog>
    <script>
        (function () {
            const shell = document.getElementById('panel-shell');
            const sidebar = document.getElementById('sidebar');
            const toggle = document.getElementById('sidebar-toggle');
            const overlay = document.getElementById('sidebar-overlay');
            const collapse = document.getElementById('sidebar-collapse');
            const collapseIcon = document.getElementById('sidebar-collapse-icon');
            const storageKey = 'wefixit.sidebar.collapsed';

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

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    setSidebar(false);
                }
            });

            const pinIcon = document.getElementById('sidebar-pin-icon');
            const desktopQuery = window.matchMedia('(min-width: 1024px)');
            const hoverQuery = window.matchMedia('(hover: hover)');

            function applyState(state) {
                const hovering = state === 'hover';
                const collapsed = state === 'collapsed';

                sidebar.dataset.state = state;
                shell.classList.toggle('lg:grid-cols-[18rem_1fr]', state === 'expanded');
                shell.classList.toggle('lg:grid-cols-[6rem_1fr]', state !== 'expanded');
                collapse.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                collapse.setAttribute('aria-label', hovering ? 'Przypnij menu' : 'Zwiń menu');
                collapse.setAttribute('title', hovering ? 'Przypnij menu' : 'Zwiń menu');
                collapseIcon.classList.toggle('hidden', hovering);
                pinIcon.classList.toggle('hidden', !hovering);
            }

            function setCollapsed(collapsed) {
                applyState(collapsed ? 'collapsed' : 'expanded');

                try {
                    localStorage.setItem(storageKey, collapsed ? '1' : '0');
                } catch {
                    // Brak dostępu do localStorage nie może blokować panelu.
                }
            }

            collapse.addEventListener('click', function () {
                setCollapsed(sidebar.dataset.state === 'expanded');
            });

            sidebar.addEventListener('mouseenter', function () {
                if (!desktopQuery.matches || !hoverQuery.matches) return;
                if (sidebar.dataset.state !== 'collapsed') return;

                applyState('hover');
            });

            sidebar.addEventListener('mouseleave', function () {
                if (sidebar.dataset.state !== 'hover') return;

                applyState('collapsed');
            });

            desktopQuery.addEventListener('change', function (event) {
                if (!event.matches && sidebar.dataset.state === 'hover') {
                    applyState('collapsed');
                }
            });

            try {
                setCollapsed(localStorage.getItem(storageKey) === '1');
            } catch {
                setCollapsed(false);
            }

            const confirmDialog = document.getElementById('app-confirm');
            const confirmTitle = document.getElementById('app-confirm-title');
            const confirmMessage = document.getElementById('app-confirm-message');
            const confirmCancel = document.getElementById('app-confirm-cancel');
            const confirmOk = document.getElementById('app-confirm-ok');
            const confirmDangerClasses = {$confirmDangerClassJson};
            const confirmPrimaryClasses = {$confirmPrimaryClassJson};
            let confirmResolve = null;

            function setConfirmVariant(danger) {
                confirmOk.className = danger ? confirmDangerClasses : confirmPrimaryClasses;
            }

            window.panelConfirm = function (options) {
                // Drugie wywołanie w trakcie otwartego dialogu nie tworzy kolejnego okna.
                if (confirmResolve !== null || confirmDialog.open) {
                    return Promise.resolve(false);
                }

                const settings = options || {};

                confirmTitle.textContent = typeof settings.title === 'string' && settings.title !== ''
                    ? settings.title
                    : 'Potwierdź operację';
                confirmMessage.textContent = typeof settings.message === 'string' ? settings.message : '';
                confirmOk.textContent = typeof settings.confirmLabel === 'string' && settings.confirmLabel !== ''
                    ? settings.confirmLabel
                    : 'Potwierdź';
                setConfirmVariant(settings.danger === true);
                confirmDialog.returnValue = '';

                return new Promise(function (resolve) {
                    confirmResolve = resolve;
                    confirmDialog.showModal();
                    confirmCancel.focus();
                });
            };

            confirmCancel.addEventListener('click', function () {
                confirmDialog.close('cancel');
            });

            confirmDialog.addEventListener('close', function () {
                if (confirmResolve === null) return;

                const resolve = confirmResolve;
                confirmResolve = null;
                resolve(confirmDialog.returnValue === 'confirm');
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
