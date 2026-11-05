/*
    Wspólne skrypty panelu: sidebar, filtry, wysuwane panele, dialog
    potwierdzenia i wylogowanie.

    Plik obsługuje wyłącznie elementy wspólne dla wszystkich stron panelu.
    Skrypty konkretnych widoków ładują się po nim i korzystają z funkcji
    window.panelOpenDrawer, window.panelCloseDrawer i window.panelConfirm.
*/
(function () {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';

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

    // Musi odpowiadać czasowi animacji .panel-dropdown-out w app.css.
    const dropdownCloseDelay = window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ? 0
        : 120;

    function closeFilterDropdown(dropdown, focusToggle) {
        const toggle = dropdown.querySelector('[data-filter-toggle]');
        const menu = dropdown.querySelector('[data-filter-menu]');

        if (!toggle || !menu) return;

        menu.classList.remove('panel-dropdown-in');
        menu.classList.add('panel-dropdown-out');
        toggle.setAttribute('aria-expanded', 'false');

        if (openFilterDropdown === dropdown) {
            openFilterDropdown = null;
        }

        if (focusToggle) {
            toggle.focus();
        }

        // Menu chowamy dopiero po animacji wyjścia, jak przy zamykaniu drawera.
        if (menu.closeTimer) {
            window.clearTimeout(menu.closeTimer);
        }

        menu.closeTimer = window.setTimeout(function () {
            menu.classList.add('hidden');
            menu.classList.remove('panel-dropdown-out');
            menu.style.top = '';
            menu.style.left = '';
            menu.closeTimer = null;
        }, dropdownCloseDelay);
    }

    function openFilterDropdownMenu(dropdown) {
        const toggle = dropdown.querySelector('[data-filter-toggle]');
        const menu = dropdown.querySelector('[data-filter-menu]');

        if (!toggle || !menu) return;

        if (openFilterDropdown !== null && openFilterDropdown !== dropdown) {
            closeFilterDropdown(openFilterDropdown, false);
        }

        if (menu.closeTimer) {
            window.clearTimeout(menu.closeTimer);
            menu.closeTimer = null;
        }

        menu.classList.remove('hidden');
        menu.classList.remove('panel-dropdown-in', 'panel-dropdown-out');

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
        // Wymuszenie reflow restartuje animację przy szybkim ponownym otwarciu.
        void menu.offsetWidth;
        menu.classList.add('panel-dropdown-in');
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

        // Po wybraniu opcji menu zamyka się; filtry dodatkowo wysyłają formularz.
        menu.addEventListener('change', function () {
            closeFilterDropdown(dropdown, false);
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
    const confirmDangerClasses = confirmDialog.dataset.dangerClass || '';
    const confirmPrimaryClasses = confirmDialog.dataset.primaryClass || '';
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
    const loginUrl = form.dataset.loginUrl || '';

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

            window.location.href = loginUrl;
        } catch {
            error.textContent = 'Nie udało się połączyć z serwerem. Spróbuj ponownie.';
            error.classList.remove('hidden');
        } finally {
            button.disabled = false;
            button.removeAttribute('aria-busy');
        }
    });
})();
