/*
    Dziennik zdarzeń: lista pobierana z API z filtrami i paginacją.

    Adres API pochodzi z data-api sekcji strony, dzięki czemu działa również
    w instalacji w podkatalogu. Szczegóły zdarzeń rozwijane są w dodatkowym
    wierszu, tak jak w widoku renderowanym przez serwer.
*/
(function () {
    const page = document.getElementById('log-page');

    if (!page) return;

    const logApi = page.dataset.api || '';
    const logUrl = page.dataset.logUrl || '';
    const levels = JSON.parse(page.dataset.levels || '{}');
    const classes = window.panelTableClasses();

    function formatDateTime(value) {
        const parts = String(value).split(/[- :]/);

        if (parts.length < 5) {
            return String(value);
        }

        return parts[2] + '.' + parts[1] + '.' + parts[0] + ' ' + parts[3] + ':' + parts[4];
    }

    function renderLogEvent(event) {
        const row = document.createElement('tr');
        row.className = classes.tr;
        row.setAttribute('data-log-row', '1');

        const dateCell = document.createElement('td');
        dateCell.className = classes.td + ' whitespace-nowrap';
        dateCell.textContent = formatDateTime(event.utworzono);
        row.appendChild(dateCell);

        const actorName = ((event.aktor_imie || '') + ' ' + (event.aktor_nazwisko || '')).trim();
        const actorCell = document.createElement('td');
        actorCell.className = classes.td;
        actorCell.textContent = actorName !== '' ? actorName : '—';
        row.appendChild(actorCell);

        const actionCell = document.createElement('td');
        actionCell.className = classes.td;
        const actionValue = document.createElement('span');
        actionValue.className = 'font-mono text-xs';
        actionValue.textContent = event.akcja || '';
        actionCell.appendChild(actionValue);
        row.appendChild(actionCell);

        const objectCell = document.createElement('td');
        objectCell.className = classes.td + ' whitespace-nowrap';

        if (event.obiekt_typ !== null && event.obiekt_typ !== undefined) {
            objectCell.textContent = event.obiekt_typ
                + (event.obiekt_id !== null && event.obiekt_id !== undefined ? ' #' + event.obiekt_id : '');
        } else {
            objectCell.textContent = '—';
        }

        row.appendChild(objectCell);

        const levelInfo = levels[event.poziom] || { label: event.poziom, variant: 'neutral' };
        const levelCell = document.createElement('td');
        levelCell.className = classes.td;
        levelCell.appendChild(window.panelBadge(levelInfo.label, levelInfo.variant));
        row.appendChild(levelCell);

        const descriptionCell = document.createElement('td');
        descriptionCell.className = classes.td;
        descriptionCell.textContent = event.opis || '';
        row.appendChild(descriptionCell);

        const rows = [row];

        // Szczegóły rozwijamy tylko dla niepustych danych, jak w widoku PHP.
        if (event.szczegoly !== null && event.szczegoly !== undefined) {
            const detailsJson = JSON.stringify(event.szczegoly, null, 2);

            if (detailsJson !== '[]' && detailsJson !== '{}') {
                const detailsRow = document.createElement('tr');
                detailsRow.setAttribute('data-log-details', '1');
                const detailsCell = document.createElement('td');
                detailsCell.colSpan = 6;
                detailsCell.className = 'border-b border-zinc-100 px-4 pb-3 align-top text-zinc-700 dark:border-zinc-800 dark:text-zinc-300';

                const details = document.createElement('details');
                const summary = document.createElement('summary');
                summary.className = 'cursor-pointer text-sm font-medium text-zinc-700 dark:text-zinc-200';
                summary.textContent = 'Szczegóły';
                details.appendChild(summary);

                const pre = document.createElement('pre');
                pre.className = 'mt-2 text-xs text-zinc-600 dark:text-zinc-300';
                pre.textContent = detailsJson;
                details.appendChild(pre);
                detailsCell.appendChild(details);
                detailsRow.appendChild(detailsCell);
                rows.push(detailsRow);
            }
        }

        return rows;
    }

    window.panelList({
        section: page,
        api: logApi,
        form: document.getElementById('log-filter-form'),
        table: document.getElementById('log-table'),
        pageUrl: logUrl,
        itemsKey: 'zdarzenia',
        paginationSlot: page.querySelector('[data-list-pagination]'),
        colspan: 6,
        renderRow: renderLogEvent,
        emptyState: function (hasFilters) {
            if (hasFilters) {
                return window.panelEmptyState({
                    title: 'Brak zdarzeń dla wybranych filtrów',
                    description: 'Zmień zakres dat lub pozostałe filtry, aby zobaczyć inne wpisy.',
                    action: { label: 'Wyczyść filtry', href: logUrl, clear: true }
                });
            }

            return window.panelEmptyState({
                title: 'Brak zdarzeń',
                description: 'Dziennik jest pusty. Operacje administracyjne pojawią się tutaj automatycznie.'
            });
        },
        onError: function (message) {
            window.showToast(message, 'error');
        }
    });
})();
