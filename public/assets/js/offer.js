/*
    Filtr kategorii i wyszukiwanie w ofercie panelu: lista odświeża się
    przez fetch().

    Dropdown obsługuje wspólny panel.js — otwieranie, pozycjonowanie
    i automatyczne wysłanie formularza po zmianie wyboru. Ten skrypt
    przechwytuje wysłanie, pobiera ofertę z /api/oferta i przebudowuje
    listę węzłami DOM (teksty wyłącznie przez textContent).
*/
(function () {
    const form = document.getElementById('offer-filter-form');
    const list = document.getElementById('offer-list');
    const errorBox = document.getElementById('offer-error');
    const valueLabel = document.querySelector('[data-offer-category-value]');
    const searchInput = form !== null ? form.querySelector('input[name="szukaj"]') : null;
    const clearLink = form !== null ? form.querySelector('[data-clear-filters]') : null;
    const menu = form !== null ? form.querySelector('[data-filter-menu]') : null;

    if (form === null || list === null || errorBox === null || menu === null) {
        return;
    }

    let pending = null;
    let queued = false;

    /*
        Przyciski rezerwacji dodajemy tylko wtedy, gdy serwer przekazał adres
        formularza przez data-reservation-url — robi to wyłącznie dla ról,
        które mogą tworzyć rezerwacje.
    */
    const reservationUrl = list.dataset.reservationUrl || '';

    // Pełne literały klas, żeby Tailwind CLI wykrył je w plikach JavaScript.
    const PRIMARY_BUTTON_CLASS = 'inline-flex h-9 items-center justify-center gap-2 rounded-md bg-zinc-900 px-4 text-sm font-medium text-white shadow-sm transition-colors hover:bg-zinc-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-zinc-50 dark:text-zinc-900 dark:hover:bg-zinc-200 dark:focus-visible:ring-offset-zinc-900';

    // Odpowiednik number_format() z widoku: przecinek dziesiętny i spacja
    // jako separator tysięcy, żeby cena wyglądała identycznie jak w SSR.
    function formatPrice(value) {
        const parts = Number(value).toFixed(2).split('.');

        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

        return parts.join(',');
    }

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

    function selectedCategoryId() {
        const checked = menu.querySelector('input[name="kategoria"]:checked');

        return checked !== null ? checked.value : '0';
    }

    function searchValue() {
        return searchInput !== null ? searchInput.value.trim() : '';
    }

    function hasFilters() {
        return selectedCategoryId() !== '0' || searchValue() !== '';
    }

    function emptyState() {
        const box = el('div', 'rounded-xl border border-dashed border-zinc-200 bg-zinc-50/60 p-8 text-center dark:border-zinc-800 dark:bg-zinc-950/50');

        if (hasFilters()) {
            box.appendChild(el('p', 'text-sm font-medium text-zinc-900 dark:text-zinc-50', 'Brak usług dla wybranych filtrów'));
            box.appendChild(el('p', 'mt-1 text-sm text-zinc-500 dark:text-zinc-400',
                'Zmień kategorię lub frazę wyszukiwania, aby zobaczyć inne usługi.'));
        } else {
            box.appendChild(el('p', 'text-sm font-medium text-zinc-900 dark:text-zinc-50', 'Brak usług w ofercie'));
            box.appendChild(el('p', 'mt-1 text-sm text-zinc-500 dark:text-zinc-400',
                'Wybierz inną kategorię albo wróć do pełnej oferty.'));
        }

        return box;
    }

    // Sekcja kategorii zbudowana z tych samych klas co widok PHP, dzięki
    // czemu lista po pobraniu wygląda identycznie jak przy pierwszym wejściu.
    function categorySection(category, services) {
        const section = el('section', 'rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900');
        section.appendChild(el('h2', 'text-lg font-semibold tracking-tight', category.nazwa));

        if (typeof category.opis === 'string' && category.opis !== '') {
            section.appendChild(el('p', 'mt-1 text-sm text-zinc-500 dark:text-zinc-400', category.opis));
        }

        const items = el('ul', 'mt-4 space-y-3');

        for (const service of services) {
            const item = el('li', 'rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950/50');
            const link = el('a', 'font-medium text-zinc-900 underline-offset-4 hover:underline dark:text-zinc-100', service.nazwa);

            link.href = list.dataset.serviceUrl + '?id=' + encodeURIComponent(service.id);

            item.appendChild(link);
            item.appendChild(el('p', 'mt-1 text-sm text-zinc-500 dark:text-zinc-400',
                formatPrice(service.cena) + ' zł · ' + service.czas_trwania_minuty + ' min'));

            // Jawny skrót do formularza rezerwacji z preselekcją tej usługi.
            if (reservationUrl !== '') {
                const reserve = el('a', PRIMARY_BUTTON_CLASS + ' mt-3', 'Zarezerwuj');

                reserve.href = reservationUrl + '?usluga=' + encodeURIComponent(service.id);
                item.appendChild(reserve);
            }

            items.appendChild(item);
        }

        section.appendChild(items);

        return section;
    }

    // Etykieta przycisku pokazuje wybraną kategorię; neutralny wybór traktujemy
    // jak placeholder, tak jak dropdown kategorii w formularzu usług.
    function updateValueLabel() {
        if (valueLabel === null) {
            return;
        }

        const checked = menu.querySelector('input[name="kategoria"]:checked');

        if (checked === null) {
            return;
        }

        const optionLabel = checked.closest('label');

        valueLabel.textContent = optionLabel !== null ? optionLabel.textContent.trim() : 'Wszystkie';

        if (checked.value === '0') {
            valueLabel.classList.add('text-zinc-400', 'dark:text-zinc-500');
        } else {
            valueLabel.classList.remove('text-zinc-400', 'dark:text-zinc-500');
        }
    }

    // Link czyszczenia pokazujemy tylko wtedy, gdy filtr lub fraza są aktywne.
    function updateClearLink() {
        if (clearLink !== null) {
            clearLink.hidden = !hasFilters();
        }
    }

    function showError(message) {
        errorBox.textContent = message;
        errorBox.classList.remove('hidden');
    }

    function renderOffer(data) {
        errorBox.classList.add('hidden');
        errorBox.textContent = '';

        const services = Array.isArray(data.uslugi) ? data.uslugi : [];
        const categories = Array.isArray(data.kategorie) ? data.kategorie : [];

        if (services.length === 0) {
            list.replaceChildren(emptyState());
            updateValueLabel();
            updateClearLink();
            return;
        }

        const byCategory = new Map();

        for (const service of services) {
            const categoryId = service.kategoria_id;

            if (!byCategory.has(categoryId)) {
                byCategory.set(categoryId, []);
            }

            byCategory.get(categoryId).push(service);
        }

        const wrap = el('div', 'space-y-6');

        for (const category of categories) {
            const servicesInCategory = byCategory.get(category.id) || [];

            if (servicesInCategory.length === 0) {
                continue;
            }

            wrap.appendChild(categorySection(category, servicesInCategory));
        }

        list.replaceChildren(wrap);
        updateValueLabel();
        updateClearLink();
    }

    async function fetchOffer(expectedKey) {
        const params = new URLSearchParams(new FormData(form));
        let response;

        // Błąd sieci nie może usuwać listy z ekranu — zostaje poprzedni stan.
        try {
            response = await fetch(form.dataset.api + '?' + params.toString(), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
        } catch {
            showError('Nie udało się połączyć z serwerem. Spróbuj ponownie później.');
            return;
        }

        let data = {};

        try {
            data = await response.json();
        } catch {
            data = {};
        }

        // Kryteria mogły się zmienić w trakcie żądania — taki wynik odrzucamy.
        if (new URLSearchParams(new FormData(form)).toString() !== expectedKey) {
            return;
        }

        if (!response.ok || data.success !== true) {
            const message = typeof data.error === 'string' && data.error !== ''
                ? data.error
                : 'Nie udało się pobrać oferty. Spróbuj ponownie później.';

            showError(message);
            return;
        }

        renderOffer(data);
    }

    function refresh() {
        if (pending !== null) {
            // Kryteria zmieniły się w trakcie pobierania — powtórzymy po nim.
            queued = true;
            return pending;
        }

        const key = new URLSearchParams(new FormData(form)).toString();

        pending = fetchOffer(key).finally(function () {
            pending = null;

            if (queued) {
                queued = false;
                refresh();
            }
        });

        return pending;
    }

    // panel.js wysyła formularz po zmianie radiosa; my tylko pobieramy dane.
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        refresh();
    });

    // Czyszczenie przywraca neutralną kategorię i pustą frazę, po czym
    // od razu pobieramy pełną ofertę.
    form.addEventListener('click', function (event) {
        if (!(event.target instanceof Element)) {
            return;
        }

        const link = event.target.closest('[data-clear-filters]');

        if (link === null || !form.contains(link)) {
            return;
        }

        event.preventDefault();

        if (searchInput !== null) {
            searchInput.value = '';
        }

        const neutral = menu.querySelector('input[name="kategoria"]');

        if (neutral !== null) {
            neutral.checked = true;
        }

        refresh();
    });

    updateValueLabel();
    updateClearLink();
})();
