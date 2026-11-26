/*
    Zmiana statusu rezerwacji przez fetch() oraz lista pobierana z API.

    Adres API pochodzi z data-api sekcji strony, dzięki czemu działa również
    w instalacji w podkatalogu. Potwierdzenie operacji zapewnia panelConfirm,
    a po udanej zmianie lista jest odświeżana przez panelList zamiast
    przeładowywania karty. Mapy statusów pochodzą z data-statuses.
*/
(function () {
    const page = document.getElementById('reservations-page');

    if (!page) return;

    const reservationsApi = page.dataset.api || '';
    const reservationsUrl = page.dataset.reservationsUrl || '';
    const statuses = JSON.parse(page.dataset.statuses || '{}');
    const form = document.getElementById('reservations-filter-form');
    const table = document.getElementById('reservations-table');
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const generalError = document.getElementById('reservations-error');
    const classes = window.panelTableClasses();
    const exportLink = page.querySelector('a[href*="eksport"]');
    const exportBaseUrl = exportLink ? exportLink.getAttribute('href').split('?')[0] : '';
    let busy = false;

    // Parsuje datę z bazy bez zmieniania strefy czasowej.
    function formatDateTime(value) {
        const parts = String(value).split(/[- :]/);

        if (parts.length < 5) {
            return String(value);
        }

        return parts[2] + '.' + parts[1] + '.' + parts[0] + ' ' + parts[3] + ':' + parts[4];
    }

    function formatTime(value) {
        const parts = String(value).split(/[- :]/);

        if (parts.length < 5) {
            return String(value);
        }

        return parts[3] + ':' + parts[4];
    }

    function isSameDay(start, end) {
        const startParts = String(start).split(/[- :]/);
        const endParts = String(end).split(/[- :]/);

        return startParts[0] === endParts[0] && startParts[1] === endParts[1] && startParts[2] === endParts[2];
    }

    function formatPrice(value) {
        const number = Number(value);

        if (!isFinite(number)) {
            return '0,00';
        }

        const parts = number.toFixed(2).split('.');
        const integer = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

        return integer + ',' + parts[1];
    }

    function clearErrors() {
        if (generalError) {
            generalError.textContent = '';
            generalError.classList.add('hidden');
        }

        for (const element of page.querySelectorAll('[id^="reservation-error-"]')) {
            element.textContent = '';
            element.classList.add('hidden');
        }
    }

    function showGeneralError(message) {
        if (!generalError) return;

        generalError.textContent = message;
        generalError.classList.remove('hidden');
    }

    function showRowError(id, data) {
        const element = document.getElementById('reservation-error-' + id);
        const message = data.errors && typeof data.errors.status === 'string' ? data.errors.status : '';

        if (element && message !== '') {
            element.textContent = message;
            element.classList.remove('hidden');
            return;
        }

        showGeneralError(typeof data.error === 'string' && data.error !== ''
            ? data.error
            : 'Nie udało się zmienić statusu rezerwacji.');
    }

    function renderReservation(reservation) {
        const id = Number(reservation.id);
        const currentStatus = String(reservation.status_rezerwacji);
        const statusInfo = statuses[currentStatus] || { label: currentStatus, variant: 'neutral', targets: [] };
        const row = document.createElement('tr');
        row.className = classes.tr;
        row.setAttribute('data-reservation-row', '1');

        const startLabel = formatDateTime(reservation.czas_rozpoczecia);
        const endLabel = isSameDay(reservation.czas_rozpoczecia, reservation.czas_zakonczenia)
            ? formatTime(reservation.czas_zakonczenia)
            : formatDateTime(reservation.czas_zakonczenia);

        const termCell = document.createElement('td');
        termCell.className = classes.td + ' whitespace-nowrap';
        const term = document.createElement('span');
        term.className = 'block font-medium text-zinc-900 dark:text-zinc-50';
        term.textContent = startLabel + '–' + endLabel;
        termCell.appendChild(term);

        if (reservation.komentarz_klienta !== null && reservation.komentarz_klienta !== undefined) {
            const comment = document.createElement('span');
            comment.className = 'mt-0.5 block max-w-xs text-xs text-zinc-500 dark:text-zinc-400';
            comment.textContent = reservation.komentarz_klienta;
            termCell.appendChild(comment);
        }

        row.appendChild(termCell);

        const clientCell = document.createElement('td');
        clientCell.className = classes.td;
        const clientName = document.createElement('span');
        clientName.className = 'block font-medium text-zinc-900 dark:text-zinc-50';
        clientName.textContent = (reservation.klient_imie || '') + ' ' + (reservation.klient_nazwisko || '');
        clientCell.appendChild(clientName);
        const clientEmail = document.createElement('span');
        clientEmail.className = 'mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400';
        clientEmail.textContent = reservation.klient_email || '';
        clientCell.appendChild(clientEmail);
        row.appendChild(clientCell);

        const employeeCell = document.createElement('td');
        employeeCell.className = classes.td;
        employeeCell.textContent = (reservation.pracownik_imie || '') + ' ' + (reservation.pracownik_nazwisko || '');
        row.appendChild(employeeCell);

        const serviceCell = document.createElement('td');
        serviceCell.className = classes.td;
        const serviceName = document.createElement('span');
        serviceName.className = 'block';
        serviceName.textContent = reservation.usluga_nazwa || '';
        serviceCell.appendChild(serviceName);
        const serviceCategory = document.createElement('span');
        serviceCategory.className = 'mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400';
        serviceCategory.textContent = reservation.usluga_kategoria || '';
        serviceCell.appendChild(serviceCategory);
        row.appendChild(serviceCell);

        const priceCell = document.createElement('td');
        priceCell.className = classes.td + ' whitespace-nowrap';
        priceCell.textContent = formatPrice(reservation.cena_historyczna) + ' zł';
        row.appendChild(priceCell);

        const statusCell = document.createElement('td');
        statusCell.className = classes.td;
        statusCell.appendChild(window.panelBadge(statusInfo.label, statusInfo.variant));

        if (reservation.anulowano !== null && reservation.anulowano !== undefined) {
            const cancelled = document.createElement('span');
            cancelled.className = 'mt-1 block text-xs text-zinc-500 dark:text-zinc-400';
            cancelled.textContent = 'Anulowano ' + formatDateTime(reservation.anulowano);
            statusCell.appendChild(cancelled);
        }

        row.appendChild(statusCell);

        const actionsCell = document.createElement('td');
        actionsCell.className = classes.td;
        const actionsWrap = document.createElement('div');
        actionsWrap.className = 'flex flex-wrap justify-end gap-2';

        if (statusInfo.targets.length === 0) {
            const empty = document.createElement('span');
            empty.className = 'text-xs text-zinc-500 dark:text-zinc-400';
            empty.textContent = 'Brak dostępnych zmian';
            actionsWrap.appendChild(empty);
        } else {
            for (const target of statusInfo.targets) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = window.panelButtonClasses(target.value === 'anulowana' ? 'danger' : 'secondary');
                button.setAttribute('data-reservation-status', '1');
                button.dataset.id = String(id);
                button.dataset.current = currentStatus;
                button.dataset.target = target.value;
                button.dataset.currentLabel = statusInfo.label;
                button.dataset.targetLabel = target.label;
                button.textContent = target.label;
                actionsWrap.appendChild(button);
            }
        }

        actionsCell.appendChild(actionsWrap);
        actionsCell.appendChild(window.panelFieldError('reservation-error-' + id));
        row.appendChild(actionsCell);

        return row;
    }

    // Eksport ma korzystać z filtrów widocznej listy, nie ze stanu z wejścia na stronę.
    // Neutralna wartość "0" dotyczy wyłącznie filtrów identyfikatorów — fraza "0" jest poprawna.
    const neutralIdFilters = new Set(['pracownik_id', 'usluga_id', 'uzytkownik_id']);

    function buildExportUrl(params) {
        const exportParams = new URLSearchParams();
        exportParams.set('typ', 'rezerwacje');

        for (const [key, value] of params) {
            if (key === 'size' || key === 'page' || value === '') continue;
            if (neutralIdFilters.has(key) && value === '0') continue;
            exportParams.append(key, value);
        }

        return exportBaseUrl + '?' + exportParams.toString();
    }

    const list = window.panelList({
        section: page,
        api: reservationsApi,
        form: form,
        table: table,
        pageUrl: reservationsUrl,
        itemsKey: 'rezerwacje',
        paginationSlot: page.querySelector('[data-list-pagination]'),
        exportLink: exportLink,
        buildExportUrl: buildExportUrl,
        colspan: 7,
        renderRow: renderReservation,
        emptyState: function (hasFilters) {
            if (hasFilters) {
                return window.panelEmptyState({
                    title: 'Brak rezerwacji dla wybranych filtrów',
                    description: 'Zmień zakres dat, filtry lub frazę wyszukiwania, aby zobaczyć inne rezerwacje.',
                    action: { label: 'Wyczyść filtry', href: reservationsUrl, clear: true }
                });
            }

            return window.panelEmptyState({
                title: 'Brak rezerwacji',
                description: 'Rezerwacje pojawią się tutaj, gdy klienci zaczną składać zamówienia.'
            });
        },
        onError: showGeneralError
    });

    if (list === null) return;

    async function sendStatus(id, status) {
        const response = await fetch(reservationsApi, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ id: id, status: status })
        });
        let data = {};

        try {
            data = await response.json();
        } catch {
            data = {};
        }

        return { response: response, data: data };
    }

    // Delegacja na tabeli: wiersze są przebudowywane po każdym odświeżeniu.
    table.addEventListener('click', async function (event) {
        const button = event.target instanceof Element ? event.target.closest('[data-reservation-status]') : null;

        if (button === null) return;

        if (busy) return;

        const id = Number(button.dataset.id);
        const current = button.dataset.current || '';
        const target = button.dataset.target || '';
        const currentLabel = button.dataset.currentLabel || current;
        const targetLabel = button.dataset.targetLabel || target;

        if (id <= 0 || target === '') return;

        let title = 'Zmienić status rezerwacji?';
        let message = 'Status rezerwacji zmieni się z „' + currentLabel + '” na „' + targetLabel + '”.';
        let confirmLabel = 'Zmień status';
        let cancelLabel = 'Anuluj';
        let danger = false;

        if (target === 'anulowana') {
            title = 'Anulować rezerwację?';
            message = 'Rezerwacja pozostanie w bazie jako anulowana. Nie zostanie usunięta.';
            confirmLabel = 'Anuluj rezerwację';
            // „Wróć” zamiast „Anuluj”, żeby odrzucenie nie myliło się z akcją.
            cancelLabel = 'Wróć';
            danger = true;
        } else if (current === 'anulowana') {
            title = 'Cofnąć anulowanie?';
            message = 'Rezerwacja wróci do statusu „Oczekująca”.';
            confirmLabel = 'Cofnij anulowanie';
        }

        const confirmed = await window.panelConfirm({
            title: title,
            message: message,
            confirmLabel: confirmLabel,
            cancelLabel: cancelLabel,
            danger: danger
        });

        if (!confirmed) return;

        clearErrors();
        busy = true;
        button.disabled = true;

        try {
            const result = await sendStatus(id, target);

            if (!result.response.ok || result.data.success !== true) {
                showRowError(id, result.data);
                return;
            }

            let toastMessage = 'Status rezerwacji został zmieniony.';

            if (target === 'anulowana') {
                toastMessage = 'Rezerwacja została anulowana.';
            } else if (current === 'anulowana') {
                toastMessage = 'Anulowanie rezerwacji zostało cofnięte.';
            }

            window.showToast(toastMessage, 'success');
            await list.refresh();
        } catch {
            showGeneralError('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
        } finally {
            button.disabled = false;
            busy = false;
        }
    });
})();
