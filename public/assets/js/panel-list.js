/*
    Wspólny mechanizm list panelu: pobieranie danych z API przez fetch(),
    renderowanie wierszy, paginacja i filtry bez przeładowania strony.

    Skrypt udostępnia window.panelList(options) oraz helpery odtwarzające
    komponenty PHP (ikony, plakietki, przyciski, pusty stan), dzięki czemu
    wiersze budowane w JavaScript wyglądają identycznie jak te renderowane
    przez serwer przy pierwszym wejściu na stronę.
*/
(function () {
    // Ikony to stałe, zaufane fragmenty SVG — odpowiedniki app/panel_ui.php.
    const ICON_PATHS = {
        'trash': '<path d="M10 11v6"/><path d="M14 11v6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'circle-slash': '<circle cx="12" cy="12" r="10"/><line x1="9" x2="15" y1="15" y2="9"/>',
        'circle-check': '<circle cx="12" cy="12" r="10"/><path d="m16 9-5.5 5.5L8 12"/>',
        'inbox': '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>'
    };

    // Pełne literały klas, żeby Tailwind CLI wykrył je w plikach JavaScript.
    const BUTTON_CLASSES = {
        primary: 'inline-flex h-9 items-center justify-center gap-2 rounded-md bg-zinc-900 px-4 text-sm font-medium text-white shadow-sm transition-colors hover:bg-zinc-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-zinc-50 dark:text-zinc-900 dark:hover:bg-zinc-200 dark:focus-visible:ring-offset-zinc-900',
        secondary: 'inline-flex h-9 items-center justify-center gap-2 rounded-md border border-zinc-200 bg-white px-4 text-sm font-medium text-zinc-700 shadow-sm transition-colors hover:bg-zinc-100 hover:text-zinc-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800 dark:hover:text-zinc-50 dark:focus-visible:ring-offset-zinc-900',
        danger: 'inline-flex h-9 items-center justify-center gap-2 rounded-md bg-red-600 px-4 text-sm font-medium text-white shadow-sm transition-colors hover:bg-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-300 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-red-600 dark:hover:bg-red-500 dark:focus-visible:ring-offset-zinc-900',
        ghost: 'inline-flex h-9 items-center justify-center gap-2 rounded-md px-4 text-sm font-medium text-zinc-600 transition-colors hover:bg-zinc-100 hover:text-zinc-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-50 dark:focus-visible:ring-offset-zinc-900'
    };

    const BADGE_STYLES = {
        success: {
            badge: 'inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-300',
            dot: 'bg-emerald-500'
        },
        warning: {
            badge: 'inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200',
            dot: 'bg-amber-500'
        },
        danger: {
            badge: 'inline-flex items-center gap-1.5 rounded-full border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300',
            dot: 'bg-red-500'
        },
        neutral: {
            badge: 'inline-flex items-center gap-1.5 rounded-full border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300',
            dot: 'bg-zinc-400'
        }
    };

    const TD_CLASS = 'border-b border-zinc-100 px-4 py-3 align-middle text-zinc-700 dark:border-zinc-800 dark:text-zinc-300';
    const TR_CLASS = 'transition-colors hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40';
    const INPUT_CLASS = 'flex h-10 w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950 shadow-xs transition-colors placeholder:text-zinc-400 focus-visible:border-zinc-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-200 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-50 dark:focus-visible:border-zinc-600 dark:focus-visible:ring-zinc-800';
    const HINT_CLASS = 'mt-1.5 text-xs text-zinc-500 dark:text-zinc-400';
    const FILTER_DOT_CLASS = 'h-1.5 w-1.5 shrink-0 rounded-full bg-zinc-900 dark:bg-zinc-50';

    // Mały pomocnik budowania DOM; teksty zawsze przez textContent.
    function el(tag, className, text) {
        const element = document.createElement(tag);

        if (className) {
            element.className = className;
        }

        if (text !== undefined && text !== null) {
            element.textContent = text;
        }

        return element;
    }

    window.panelIcon = function (name, classes) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.75');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        svg.className = classes || 'h-4 w-4 shrink-0';

        const paths = ICON_PATHS[name] || '<circle cx="12" cy="12" r="9"/>';

        // Ścieżki są stałymi literałami, nie danymi użytkownika.
        svg.innerHTML = paths;

        return svg;
    };

    window.panelButtonClasses = function (variant) {
        return BUTTON_CLASSES[variant] || BUTTON_CLASSES.primary;
    };

    window.panelTableClasses = function () {
        return { td: TD_CLASS, tr: TR_CLASS, input: INPUT_CLASS, hint: HINT_CLASS };
    };

    window.panelBadge = function (label, variant) {
        const style = BADGE_STYLES[variant] || BADGE_STYLES.neutral;
        const badge = el('span', style.badge);
        const dot = el('span', style.dot + ' h-1.5 w-1.5 rounded-full');
        dot.setAttribute('aria-hidden', 'true');

        badge.appendChild(dot);
        badge.appendChild(el('span', '', label));

        return badge;
    };

    window.panelFieldError = function (id) {
        const error = el('p', 'mt-1.5 hidden text-sm text-red-600 dark:text-red-400');
        error.id = id;

        return error;
    };

    window.panelEmptyState = function (options) {
        const settings = options || {};
        const box = el('div', 'rounded-xl border border-dashed border-zinc-200 bg-zinc-50/60 p-8 text-center dark:border-zinc-800 dark:bg-zinc-950/50');
        const iconWrap = el('span', 'mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300');
        iconWrap.appendChild(window.panelIcon('inbox', 'h-5 w-5'));
        box.appendChild(iconWrap);
        box.appendChild(el('p', 'mt-3 text-sm font-medium text-zinc-900 dark:text-zinc-50', settings.title));
        box.appendChild(el('p', 'mt-1 text-sm text-zinc-500 dark:text-zinc-400', settings.description));

        const action = settings.action;

        if (action && typeof action.label === 'string' && action.label !== '') {
            const hasDrawer = typeof action.drawer === 'string' && action.drawer !== '';
            const link = el('a', window.panelButtonClasses('secondary') + ' mt-4', action.label);
            link.href = hasDrawer
                ? '#' + action.drawer
                : (typeof action.href === 'string' && action.href !== '' ? action.href : '#');

            if (action.clear === true) {
                link.setAttribute('data-clear-filters', '1');
            }

            if (hasDrawer) {
                link.setAttribute('data-drawer-open', action.drawer);
                link.setAttribute('aria-haspopup', 'dialog');
                link.setAttribute('aria-controls', action.drawer);
            }

            box.appendChild(link);
        }

        return box;
    };

    // Filtr jest aktywny, gdy wartość różni się od pierwszej (neutralnej) opcji.
    function filterGroups(form) {
        const groups = new Map();

        for (const control of form.elements) {
            if (control.type !== 'radio') continue;

            if (!groups.has(control.name)) {
                groups.set(control.name, []);
            }

            groups.get(control.name).push(control);
        }

        return groups;
    }

    function groupHasFilter(radios) {
        const neutral = radios.length > 0 ? radios[0].value : '';

        for (const radio of radios) {
            if (radio.checked) {
                return radio.value !== neutral;
            }
        }

        return false;
    }

    /*
        Tworzy kontroler listy. Opcje:
        - section: element strony (nasłuch linków czyszczenia),
        - api: adres endpointu JSON,
        - form: formularz filtrów,
        - table: tabela listy,
        - pageUrl: adres strony bez zapytania (linki paginacji),
        - itemsKey: klucz z wierszami w odpowiedzi,
        - paginationSlot: kontener paska paginacji,
        - colspan: liczba kolumn tabeli,
        - renderRow: funkcja zwracająca <tr> (lub tablicę <tr>) dla rekordu,
        - emptyState: funkcja zwrająca element pustego stanu,
        - onError: funkcja przyjmująca komunikat błędu,
        - onLoaded: funkcja wołana po udanym odświeżeniu z danymi odpowiedzi.
    */
    window.panelList = function (options) {
        const section = options.section || null;
        const form = options.form || null;
        const table = options.table || null;
        const tbody = table !== null && table.tBodies.length > 0 ? table.tBodies[0] : null;

        if (section === null || form === null || table === null || tbody === null) {
            return null;
        }

        const initialPage = Number(new URLSearchParams(window.location.search).get('page') || '1');
        let currentPage = initialPage >= 1 ? initialPage : 1;
        let pending = null;

        function currentParams() {
            return new URLSearchParams(new FormData(form));
        }

        function hasFilters() {
            for (const radios of filterGroups(form).values()) {
                if (groupHasFilter(radios)) {
                    return true;
                }
            }

            for (const control of form.elements) {
                if (control.type === 'search' || control.type === 'text' || control.type === 'date') {
                    if (control.value !== '') {
                        return true;
                    }
                }
            }

            return false;
        }

        function resetFilters() {
            for (const radios of filterGroups(form).values()) {
                if (radios.length === 0) continue;

                for (const radio of radios) {
                    radio.checked = radio === radios[0];
                }
            }

            for (const control of form.elements) {
                if (control.type === 'search' || control.type === 'text' || control.type === 'date') {
                    control.value = '';
                }
            }
        }

        function updateFilterDots() {
            for (const dropdown of section.querySelectorAll('[data-filter-dropdown]')) {
                const toggle = dropdown.querySelector('[data-filter-toggle]');
                const menu = dropdown.querySelector('[data-filter-menu]');

                if (!toggle || !menu) continue;

                let active = false;

                for (const radios of filterGroups(form).values()) {
                    if (radios.length === 0 || !menu.contains(radios[0])) continue;

                    if (groupHasFilter(radios)) {
                        active = true;
                    }
                }

                for (const control of form.elements) {
                    if (control.type !== 'date' || !menu.contains(control)) continue;

                    if (control.value !== '') {
                        active = true;
                    }
                }

                let dot = toggle.querySelector('[data-filter-dot]');

                if (dot === null) {
                    dot = el('span', FILTER_DOT_CLASS);
                    dot.setAttribute('aria-hidden', 'true');
                    dot.setAttribute('data-filter-dot', '1');
                    dot.hidden = true;
                    toggle.querySelector('span').appendChild(dot);
                }

                dot.hidden = !active;

                // Etykieta dla czytników ekranu zmienia się razem z kropką.
                const label = toggle.querySelector('[data-filter-active-label]');

                if (label !== null) {
                    label.hidden = !active;
                }
            }
        }

        function updateClearLinks(active) {
            for (const link of section.querySelectorAll('[data-clear-filters]')) {
                link.hidden = !active;
            }
        }

        function buildPageUrl(targetPage) {
            const query = currentParams();
            query.set('page', String(targetPage));

            return options.pageUrl + '?' + query.toString();
        }

        function buildPagination(pagination) {
            const page = Math.max(1, Math.min(pagination.strona || 1, pagination.stron || 1));
            const pages = Math.max(1, pagination.stron || 1);
            const total = pagination.razem || 0;
            const size = pagination.rozmiar || 25;
            const slot = options.paginationSlot;

            if (!slot) return;

            if (total <= size || pages <= 1) {
                slot.replaceChildren();
                return;
            }

            const wrap = el('div', 'mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between');
            wrap.setAttribute('data-pagination', '1');
            wrap.appendChild(el('p', 'text-sm text-zinc-500 dark:text-zinc-400',
                'Strona ' + page + ' z ' + pages + ' (' + total + ' rekordów)'));

            const nav = el('nav', 'flex flex-wrap items-center gap-1');
            nav.setAttribute('aria-label', 'Paginacja');

            if (page > 1) {
                const prev = el('a', window.panelButtonClasses('ghost'), 'Poprzednia');
                prev.href = buildPageUrl(page - 1);
                prev.setAttribute('data-page-link', String(page - 1));
                prev.setAttribute('rel', 'prev');
                nav.appendChild(prev);
            } else {
                const prev = el('span', window.panelButtonClasses('ghost') + ' opacity-50', 'Poprzednia');
                prev.setAttribute('aria-disabled', 'true');
                nav.appendChild(prev);
            }

            // Zakres widocznych numerów odpowiada helperowi PHP.
            const visiblePages = 7;
            let start = Math.max(1, page - 3);
            let end = Math.min(pages, start + visiblePages - 1);
            start = Math.max(1, end - visiblePages + 1);

            for (let number = start; number <= end; number++) {
                if (number === page) {
                    const current = el('span', window.panelButtonClasses('primary'), String(number));
                    current.setAttribute('aria-current', 'page');
                    nav.appendChild(current);
                } else {
                    const link = el('a', window.panelButtonClasses('secondary'), String(number));
                    link.href = buildPageUrl(number);
                    link.setAttribute('data-page-link', String(number));
                    nav.appendChild(link);
                }
            }

            if (page < pages) {
                const next = el('a', window.panelButtonClasses('ghost'), 'Następna');
                next.href = buildPageUrl(page + 1);
                next.setAttribute('data-page-link', String(page + 1));
                next.setAttribute('rel', 'next');
                nav.appendChild(next);
            } else {
                const next = el('span', window.panelButtonClasses('ghost') + ' opacity-50', 'Następna');
                next.setAttribute('aria-disabled', 'true');
                nav.appendChild(next);
            }

            wrap.appendChild(nav);
            slot.replaceChildren(wrap);
        }

        function renderRows(items, pagination) {
            if (items.length === 0) {
                const row = el('tr', null);
                const cell = el('td', 'p-4');
                cell.colSpan = options.colspan;
                cell.appendChild(options.emptyState(hasFilters(), lastData));
                row.appendChild(cell);
                tbody.replaceChildren(row);
            } else {
                const rows = [];

                for (const item of items) {
                    const rendered = options.renderRow(item);

                    if (Array.isArray(rendered)) {
                        for (const single of rendered) {
                            rows.push(single);
                        }
                    } else if (rendered) {
                        rows.push(rendered);
                    }
                }

                tbody.replaceChildren.apply(tbody, rows);
            }

            buildPagination(pagination);
            updateFilterDots();
            updateClearLinks(hasFilters());

            if (typeof options.onLoaded === 'function') {
                options.onLoaded(lastData);
            }
        }

        let lastData = {};

        async function fetchList() {
            const params = currentParams();

            if (currentPage > 1) {
                params.set('page', String(currentPage));
            }

            const response = await fetch(options.api + '?' + params.toString(), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            let data = {};

            try {
                data = await response.json();
            } catch {
                data = {};
            }

            if (!response.ok || data.success !== true || !Array.isArray(data[options.itemsKey])) {
                const message = typeof data.error === 'string' && data.error !== ''
                    ? data.error
                    : 'Nie udało się pobrać listy. Spróbuj ponownie później.';

                if (typeof options.onError === 'function') {
                    options.onError(message);
                }

                return;
            }

            lastData = data;
            currentPage = (data.paginacja && data.paginacja.strona) || currentPage;
            renderRows(data[options.itemsKey], data.paginacja || {});
        }

        function refresh() {
            if (pending !== null) {
                return pending;
            }

            pending = fetchList().finally(function () {
                pending = null;
            });

            return pending;
        }

        // Zmiana filtrów i wyszukiwanie wracają na pierwszą stronę wyników.
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            currentPage = 1;
            refresh();
        });

        section.addEventListener('click', function (event) {
            const target = event.target instanceof Element ? event.target : null;

            if (target === null) return;

            const clearLink = target.closest('[data-clear-filters]');

            if (clearLink !== null && section.contains(clearLink)) {
                event.preventDefault();
                resetFilters();
                currentPage = 1;
                refresh();
                return;
            }

            const pageLink = target.closest('[data-page-link]');

            if (pageLink !== null && section.contains(pageLink)) {
                event.preventDefault();
                const targetPage = Number(pageLink.getAttribute('data-page-link'));

                if (targetPage >= 1) {
                    currentPage = targetPage;
                    refresh();
                }
            }
        });

        updateFilterDots();

        return {
            refresh: refresh,
            hasFilters: hasFilters
        };
    };
})();

