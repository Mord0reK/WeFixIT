<?php
declare(strict_types=1);

/*
    Szkielet zalogowanego panelu: sidebar w stylu shadcn/ui oraz wspólne
    elementy widoków.

    Sidebar grupuje nawigację (z możliwością zwijania grup), pokazuje kartę
    użytkownika i pozostaje stale rozwinięty na desktopie. Na wąskich ekranach
    jest wysuwany przyciskiem, a wylogowanie obsługuje fetch() z tokenem CSRF.
*/
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/panel_ui.php';

// Sekcje panelu nie mają własnego tła — tło pozostaje zarezerwowane dla
// elementów takich jak tabele czy karty statystyk.
const PANEL_BODY_CLASS = 'min-h-screen bg-zinc-50 text-zinc-950 antialiased dark:bg-zinc-950 dark:text-zinc-50';
const PANEL_CARD_CLASS = 'w-full';
const PANEL_CARD_WIDE_CLASS = 'w-full';

// Stany pozycji menu trzymamy jako pełne literały, żeby Tailwind wykrył klasy.
const PANEL_ACTIVE_ITEM_CLASS = 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800/80 dark:text-zinc-50';
const PANEL_IDLE_ITEM_CLASS = 'text-zinc-600 hover:bg-zinc-100/70 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-900 dark:hover:text-zinc-50';

// Menu panelu jest jednym źródłem nawigacji; role decydują, które pozycje są widoczne.
function panel_menu_items(string $role): array
{
    $homeLabel = match ($role) {
        'admin' => 'Strona główna',
        'pracownik' => 'Panel pracownika',
        default => 'Panel klienta',
    };

    $items = [
        [
            'key' => 'home',
            'label' => $homeLabel,
            'path' => '/' . auth_role_home_path($role),
            'roles' => ['klient', 'pracownik', 'admin'],
            'group' => '',
            'icon' => 'home',
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
            'key' => 'categories',
            'label' => 'Kategorie',
            'path' => '/kategorie',
            'roles' => ['admin'],
            'group' => 'Zarządzanie',
            'icon' => 'folder',
        ],
        [
            'key' => 'services',
            'label' => 'Usługi',
            'path' => '/uslugi',
            'roles' => ['admin'],
            'group' => 'Zarządzanie',
            'icon' => 'wrench',
        ],
        [
            'key' => 'employees',
            'label' => 'Pracownicy',
            'path' => '/pracownicy',
            'roles' => ['admin'],
            'group' => 'Zarządzanie',
            'icon' => 'briefcase',
        ],
        [
            'key' => 'users',
            'label' => 'Użytkownicy',
            'path' => '/uzytkownicy',
            'roles' => ['admin'],
            'group' => 'Zarządzanie',
            'icon' => 'user',
        ],
        [
            'key' => 'reservations',
            'label' => 'Rezerwacje',
            'path' => '/rezerwacje',
            'roles' => ['admin'],
            'group' => 'Zarządzanie',
            'icon' => 'calendar',
        ],
        [
            'key' => 'log',
            'label' => 'Dziennik zdarzeń',
            'path' => '/log',
            'roles' => ['admin'],
            'group' => 'Zarządzanie',
            'icon' => 'list',
        ],
        [
            'key' => 'profil',
            'label' => 'Mój profil',
            'path' => '/profil',
            'roles' => ['klient', 'pracownik', 'admin'],
            'group' => 'Konto',
            'icon' => 'user',
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
                    $drawer = $action['drawer'] ?? '';
                    $hasDrawer = is_string($drawer) && $drawer !== '';

                    if (!is_string($label) || $label === ''
                        || (!$hasDrawer && (!is_string($href) || $href === ''))) {
                        continue;
                    }

                    $actionHref = $hasDrawer ? '#' . $drawer : $href;
                    ?>
                    <a href="<?= htmlspecialchars($actionHref, ENT_QUOTES, 'UTF-8') ?>"
                        class="<?= panel_button_classes('primary') ?>"
                        <?php if ($hasDrawer): ?>
                            data-drawer-open="<?= htmlspecialchars($drawer, ENT_QUOTES, 'UTF-8') ?>"
                            aria-haspopup="dialog" aria-controls="<?= htmlspecialchars($drawer, ENT_QUOTES, 'UTF-8') ?>"
                        <?php endif; ?>>
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

// Pojedyncza pozycja menu; wspólna dla strony głównej i pozycji w grupach.
function panel_menu_link(array $item, string $active): void
{
    $isActive = $item['key'] === $active;
    $itemClass = $isActive ? PANEL_ACTIVE_ITEM_CLASS : PANEL_IDLE_ITEM_CLASS;
    $iconClass = $isActive
        ? 'h-4 w-4 shrink-0 text-zinc-900 dark:text-zinc-50'
        : 'h-4 w-4 shrink-0 text-zinc-400 transition-colors group-hover/item:text-zinc-600 dark:text-zinc-500 dark:group-hover/item:text-zinc-300';
    ?>
    <li>
        <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
            title="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>"
            class="group/item flex items-center gap-3 rounded-md px-2.5 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 <?= $itemClass ?>"
            <?= $isActive ? 'aria-current="page"' : '' ?>>
            <?= panel_icon($item['icon'], $iconClass) ?>
            <span class="truncate">
                <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
            </span>
        </a>
    </li>
    <?php
}

function panel_layout_head(array $user, string $title, string $active): void
{
    $basePath = auth_base_path();
    $logoutUrl = htmlspecialchars($basePath . '/api/auth/logout', ENT_QUOTES, 'UTF-8');

    $fullName = htmlspecialchars(trim($user['imie'] . ' ' . $user['nazwisko']), ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $initials = htmlspecialchars(panel_user_initials($user), ENT_QUOTES, 'UTF-8');
    $menu = panel_menu_items((string) $user['rola']);

    $homeItem = null;
    $profileItem = null;
    $menuGroups = [];

    foreach ($menu as $item) {
        // Strona główna nie należy do żadnej sekcji — renderujemy ją osobno.
        if ($item['group'] === '') {
            $homeItem = $item;
            continue;
        }

        if ($item['key'] === 'profil') {
            $profileItem = $item;
        }

        $menuGroups[$item['group']][] = $item;
    }

    // Karta użytkownika na dole sidebaru prowadzi do sekcji profilu.
    $profileHref = $profileItem !== null
        ? htmlspecialchars($profileItem['href'], ENT_QUOTES, 'UTF-8')
        : htmlspecialchars($basePath . '/profil', ENT_QUOTES, 'UTF-8');
    $profileIsActive = $active === 'profil';

    $roleKey = htmlspecialchars((string) $user['rola'], ENT_QUOTES, 'UTF-8');

    layout_head($title, PANEL_BODY_CLASS);
    ?>
    <div id="panel-shell" class="min-h-screen lg:grid lg:grid-cols-[18rem_1fr]">
        <div id="sidebar-overlay" class="fixed inset-0 z-30 hidden bg-zinc-950/50 backdrop-blur-sm lg:hidden"></div>

        <aside id="sidebar"
            class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col border-r border-zinc-200 bg-white transition-transform duration-200 ease-out lg:sticky lg:top-0 lg:bottom-auto lg:h-screen lg:translate-x-0 dark:border-zinc-800 dark:bg-zinc-950">
            <div class="flex h-16 shrink-0 items-center gap-2.5 border-b border-zinc-200 px-4 dark:border-zinc-800">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-zinc-900 text-white shadow-sm dark:bg-zinc-50 dark:text-zinc-900">
                    <?= layout_logo('h-5 w-5') ?>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold tracking-tight">WeFixIT</span>
                    <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">Serwis komputerowy</span>
                </span>
            </div>

            <nav aria-label="Menu panelu" class="flex-1 overflow-y-auto px-3 py-4">
                <?php if ($homeItem !== null): ?>
                    <div class="mb-5">
                        <ul class="space-y-0.5">
                            <?php panel_menu_link($homeItem, $active); ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php $groupIndex = 0; ?>
                <?php foreach ($menuGroups as $groupLabel => $groupItems): ?>
                    <?php
                    $groupId = 'sidebar-group-' . $groupIndex;
                    $groupKey = $roleKey . '-' . $groupIndex;
                    $groupIndex++;
                    ?>
                    <div class="mb-5 last:mb-0" data-sidebar-group data-group-id="<?= $groupId ?>"
                        data-group-key="<?= $groupKey ?>">
                        <button type="button" data-sidebar-group-toggle aria-expanded="true"
                            aria-controls="<?= $groupId ?>"
                            class="mb-1.5 flex w-full items-center justify-between gap-2 rounded-md px-2 py-1 text-[11px] font-medium uppercase tracking-wider text-zinc-400 transition-colors hover:text-zinc-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 dark:text-zinc-500 dark:hover:text-zinc-300">
                            <span class="truncate"><?= htmlspecialchars((string) $groupLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            <?= panel_icon('chevron-down', 'h-3.5 w-3.5 shrink-0 transition-transform duration-200') ?>
                        </button>
                        <ul id="<?= $groupId ?>" class="space-y-0.5" data-sidebar-group-items>
                            <?php foreach ($groupItems as $item): ?>
                                <?php panel_menu_link($item, $active); ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </nav>

            <div class="border-t border-zinc-200 p-3 dark:border-zinc-800">
                <div class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 p-1.5 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <a href="<?= $profileHref ?>" title="Przejdź do Mojego profilu"
                        class="group/profile flex min-w-0 flex-1 items-center gap-3 rounded-md px-1.5 py-1 transition-colors hover:bg-zinc-200/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 dark:hover:bg-zinc-800 dark:focus-visible:ring-zinc-700"
                        <?= $profileIsActive ? 'aria-current="page"' : '' ?>>
                        <span title="<?= $fullName ?>"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                            <?= $initials ?>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium"><?= $fullName ?></span>
                            <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400"><?= $email ?></span>
                        </span>
                    </a>
                    <form id="logout-form" action="<?= $logoutUrl ?>" method="post" class="shrink-0">
                        <button type="submit" title="Wyloguj" aria-label="Wyloguj"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-md text-zinc-500 transition-colors hover:bg-red-50 hover:text-red-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-300 disabled:cursor-wait disabled:opacity-60 dark:text-zinc-400 dark:hover:bg-red-950/30 dark:hover:text-red-400">
                            <?= panel_icon('logout', 'h-4 w-4 shrink-0') ?>
                        </button>
                    </form>
                </div>

                <p id="logout-error" role="alert" class="mt-2 hidden px-2 text-xs text-red-600 dark:text-red-400"></p>
            </div>
        </aside>

        <div class="flex min-w-0 flex-col">
            <main class="flex-1 p-4 sm:p-6 lg:p-8">
                <div class="mb-4 lg:hidden">
                    <button id="sidebar-toggle" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="Otwórz menu"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-zinc-200 bg-white text-zinc-600 shadow-sm transition-colors hover:bg-zinc-100 hover:text-zinc-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-50">
                        <?= panel_icon('menu', 'h-5 w-5') ?>
                    </button>
                </div>
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
        class="m-auto w-[calc(100%-2rem)] max-w-md rounded-xl border border-zinc-200 bg-white p-0 text-zinc-950 shadow-xl backdrop:bg-zinc-950/50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-50">
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

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    setSidebar(false);
                }
            });

            // Grupy sidebaru można zwijać; wybór zapamiętujemy w localStorage.
            const groupStorageKey = 'panel-sidebar-groups';
            let storedGroups = {};

            try {
                storedGroups = JSON.parse(window.localStorage.getItem(groupStorageKey) || '{}') || {};
            } catch {
                storedGroups = {};
            }

            for (const group of document.querySelectorAll('[data-sidebar-group]')) {
                const groupToggle = group.querySelector('[data-sidebar-group-toggle]');
                const groupItems = group.querySelector('[data-sidebar-group-items]');
                const groupIcon = groupToggle ? groupToggle.querySelector('svg') : null;
                const groupKey = group.dataset.groupKey || group.dataset.groupId || '';
                const hasActiveItem = group.querySelector('[aria-current="page"]') !== null;

                if (!groupToggle || !groupItems) continue;

                const applyGroupState = function (collapsed) {
                    groupItems.classList.toggle('hidden', collapsed);
                    groupToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

                    if (groupIcon) {
                        groupIcon.style.transform = collapsed ? 'rotate(-90deg)' : '';
                    }
                };

                applyGroupState(hasActiveItem ? false : storedGroups[groupKey] === true);

                groupToggle.addEventListener('click', function () {
                    const collapsed = !groupItems.classList.contains('hidden');
                    applyGroupState(collapsed);
                    storedGroups[groupKey] = collapsed;

                    try {
                        window.localStorage.setItem(groupStorageKey, JSON.stringify(storedGroups));
                    } catch {
                        // Brak dostępu do localStorage nie może blokować nawigacji.
                    }
                });
            }

            // Filtry w nagłówkach tabel wysyłają formularz od razu po zmianie wyboru.
            for (const filter of document.querySelectorAll('[data-filter-auto-submit]')) {
                filter.addEventListener('change', function () {
                    if (filter.form) {
                        filter.form.requestSubmit();
                    }
                });
            }

            /*
                Menu filtra jest pozycjonowane jako fixed względem przycisku.
                Dzięki temu nie ucina go kontener tabeli z overflow-x-auto.
            */
            let openFilterDropdown = null;

            function closeFilterDropdown(dropdown, focusToggle) {
                const toggle = dropdown.querySelector('[data-filter-toggle]');
                const menu = dropdown.querySelector('[data-filter-menu]');

                if (!toggle || !menu) return;

                menu.classList.add('hidden');
                menu.style.top = '';
                menu.style.left = '';
                toggle.setAttribute('aria-expanded', 'false');

                if (openFilterDropdown === dropdown) {
                    openFilterDropdown = null;
                }

                if (focusToggle) {
                    toggle.focus();
                }
            }

            function openFilterDropdownMenu(dropdown) {
                const toggle = dropdown.querySelector('[data-filter-toggle]');
                const menu = dropdown.querySelector('[data-filter-menu]');

                if (!toggle || !menu) return;

                if (openFilterDropdown !== null && openFilterDropdown !== dropdown) {
                    closeFilterDropdown(openFilterDropdown, false);
                }

                menu.classList.remove('hidden');

                const rect = toggle.getBoundingClientRect();
                const menuWidth = menu.offsetWidth;
                const menuHeight = menu.offsetHeight;
                const left = Math.max(8, Math.min(rect.left, window.innerWidth - menuWidth - 8));
                let top = rect.bottom + 4;

                // Brak miejsca pod nagłówkiem odwraca menu nad przycisk.
                if (top + menuHeight > window.innerHeight - 8) {
                    top = Math.max(8, rect.top - menuHeight - 4);
                }

                menu.style.top = top + 'px';
                menu.style.left = left + 'px';
                toggle.setAttribute('aria-expanded', 'true');
                openFilterDropdown = dropdown;
            }

            for (const dropdown of document.querySelectorAll('[data-filter-dropdown]')) {
                const toggle = dropdown.querySelector('[data-filter-toggle]');
                const menu = dropdown.querySelector('[data-filter-menu]');

                if (!toggle || !menu) continue;

                toggle.addEventListener('click', function (event) {
                    event.stopPropagation();

                    if (openFilterDropdown === dropdown) {
                        closeFilterDropdown(dropdown, false);
                        return;
                    }

                    openFilterDropdownMenu(dropdown);
                });

                menu.addEventListener('click', function (event) {
                    event.stopPropagation();
                });
            }

            document.addEventListener('click', function () {
                if (openFilterDropdown !== null) {
                    closeFilterDropdown(openFilterDropdown, false);
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && openFilterDropdown !== null) {
                    closeFilterDropdown(openFilterDropdown, true);
                }
            });

            window.addEventListener('resize', function () {
                if (openFilterDropdown !== null) {
                    closeFilterDropdown(openFilterDropdown, false);
                }
            });

            window.addEventListener('scroll', function (event) {
                if (openFilterDropdown === null) return;

                // Przewijanie samego menu nie może go zamykać.
                const menu = openFilterDropdown.querySelector('[data-filter-menu]');

                if (menu !== null && event.target instanceof Node && menu.contains(event.target)) {
                    return;
                }

                closeFilterDropdown(openFilterDropdown, false);
            }, true);

            /*
                Wysuwane panele edycji. Stan trzymamy w jednym miejscu, żeby
                widoki nie powielały obsługi overlaya, Escape i blokady scrolla.
            */
            let openDrawer = null;
            let drawerTrigger = null;

            // Musi odpowiadać czasowi animacji wyjścia z app.css.
            const drawerCloseDelay = 180;

            function openPanelDrawer(drawer) {
                if (!(drawer instanceof HTMLElement)) return;

                if (openDrawer !== null && openDrawer !== drawer) {
                    closePanelDrawer(openDrawer, false);
                }

                if (drawer.closeTimer) {
                    window.clearTimeout(drawer.closeTimer);
                    drawer.closeTimer = null;
                }

                drawerTrigger = document.activeElement;
                drawer.classList.remove('hidden', 'app-drawer-closing');
                drawer.setAttribute('aria-hidden', 'false');
                document.body.classList.add('overflow-hidden');
                openDrawer = drawer;

                const focusTarget = drawer.querySelector(
                    'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])'
                );

                if (focusTarget) {
                    focusTarget.focus();
                    return;
                }

                const closeButton = drawer.querySelector('[data-drawer-close]');
                if (closeButton) closeButton.focus();
            }

            function closePanelDrawer(drawer, restoreFocus) {
                if (!(drawer instanceof HTMLElement) || drawer.classList.contains('hidden')) return;
                if (drawer.classList.contains('app-drawer-closing')) return;

                drawer.classList.add('app-drawer-closing');
                drawer.setAttribute('aria-hidden', 'true');

                drawer.closeTimer = window.setTimeout(function () {
                    drawer.classList.add('hidden');
                    drawer.classList.remove('app-drawer-closing');
                    drawer.closeTimer = null;

                    if (openDrawer === drawer) {
                        openDrawer = null;
                        document.body.classList.remove('overflow-hidden');
                    }

                    drawer.dispatchEvent(new CustomEvent('panel:drawer-close'));

                    if (restoreFocus && drawerTrigger) {
                        drawerTrigger.focus();
                        drawerTrigger = null;
                    }
                }, drawerCloseDelay);
            }

            window.panelOpenDrawer = openPanelDrawer;
            window.panelCloseDrawer = closePanelDrawer;

            for (const trigger of document.querySelectorAll('[data-drawer-open]')) {
                trigger.addEventListener('click', function (event) {
                    const drawerId = trigger.getAttribute('data-drawer-open');
                    const drawer = drawerId ? document.getElementById(drawerId) : null;

                    if (!drawer) return;

                    event.preventDefault();
                    openPanelDrawer(drawer);
                });
            }

            for (const closeButton of document.querySelectorAll('[data-drawer-close]')) {
                closeButton.addEventListener('click', function () {
                    const drawer = closeButton.closest('[data-drawer]');

                    if (drawer) closePanelDrawer(drawer, true);
                });
            }

            for (const overlay of document.querySelectorAll('[data-drawer-overlay]')) {
                overlay.addEventListener('click', function () {
                    const drawer = overlay.closest('[data-drawer]');

                    if (drawer) closePanelDrawer(drawer, true);
                });
            }

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && openDrawer !== null) {
                    closePanelDrawer(openDrawer, true);
                }
            });

            const confirmDialog = document.getElementById('app-confirm');
            const confirmTitle = document.getElementById('app-confirm-title');
            const confirmMessage = document.getElementById('app-confirm-message');
            const confirmCancel = document.getElementById('app-confirm-cancel');
            const confirmOk = document.getElementById('app-confirm-ok');
            const confirmDangerClasses = {$confirmDangerClassJson};
            const confirmPrimaryClasses = {$confirmPrimaryClassJson};
            let confirmResolve = null;
            let confirmClosing = false;

            // Musi odpowiadać czasowi animacji wyjścia z app.css.
            const confirmCloseDelay = 160;

            function setConfirmVariant(danger) {
                confirmOk.className = danger ? confirmDangerClasses : confirmPrimaryClasses;
            }

            function closeConfirm(returnValue) {
                if (confirmClosing || !confirmDialog.open) return;

                confirmClosing = true;
                confirmDialog.classList.add('app-confirm-closing');

                // Zamykamy dialog dopiero po animacji, inaczej zniknąłby natychmiast.
                window.setTimeout(function () {
                    confirmDialog.close(returnValue);
                }, confirmCloseDelay);
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
                confirmClosing = false;
                confirmDialog.classList.remove('app-confirm-closing');

                return new Promise(function (resolve) {
                    confirmResolve = resolve;
                    confirmDialog.showModal();
                    confirmCancel.focus();
                });
            };

            confirmCancel.addEventListener('click', function () {
                closeConfirm('cancel');
            });

            confirmDialog.querySelector('form').addEventListener('submit', function (event) {
                // Przechwytujemy domyślne zamknięcie formularza, żeby również
                // potwierdzenie miało animację wyjścia.
                event.preventDefault();
                closeConfirm('confirm');
            });

            confirmDialog.addEventListener('cancel', function (event) {
                // Escape zamyka dialog natywnie, więc wymuszamy własne zamknięcie.
                event.preventDefault();
                closeConfirm('cancel');
            });

            confirmDialog.addEventListener('click', function (event) {
                // Kliknięcie poza panelem trafia bezpośrednio w element dialogu.
                if (event.target !== confirmDialog) return;

                closeConfirm('cancel');
            });

            confirmDialog.addEventListener('close', function () {
                confirmClosing = false;
                confirmDialog.classList.remove('app-confirm-closing');

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
