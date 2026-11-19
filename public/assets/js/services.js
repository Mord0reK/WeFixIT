/*
    Zarządzanie usługami: lista pobierana z API, formularz w wysuwanym
    panelu oraz akcje edycji, zmiany statusu i usuwania.

    Adres API pochodzi z data-api formularza, dzięki czemu działa również
    w instalacji w podkatalogu. Wszystkie operacje kończą się odświeżeniem
    listy przez panelList zamiast przeładowaniem strony.
*/
(function () {
    const page = document.querySelector('[data-api][data-services-url]');
    const serviceDrawer = document.getElementById('service-drawer');
    const serviceForm = document.getElementById('service-form');
    const serviceFormTitle = document.getElementById('service-form-title');
    const serviceFormError = document.getElementById('service-form-error');
    const serviceSubmit = document.getElementById('service-submit');
    const serviceCancel = document.getElementById('service-cancel');
    const serviceId = document.getElementById('service-id');
    const serviceName = document.getElementById('service-nazwa');
    const serviceDescription = document.getElementById('service-opis');
    const serviceCategoryRadios = Array.from(serviceForm.querySelectorAll('input[name="kategoria_id"]'));
    const serviceCategoryValue = document.querySelector('[data-service-category-value]');
    const servicePrice = document.getElementById('service-cena');
    const serviceDuration = document.getElementById('service-czas');
    const serviceApi = serviceForm.dataset.api || '';
    const servicesUrl = page ? page.dataset.servicesUrl || '' : '';
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const classes = window.panelTableClasses();
    const serviceFieldErrors = {
        nazwa: document.getElementById('service-error-nazwa'),
        opis: document.getElementById('service-error-opis'),
        kategoria_id: document.getElementById('service-error-kategoria_id'),
        cena: document.getElementById('service-error-cena'),
        czas_trwania_minuty: document.getElementById('service-error-czas_trwania_minuty')
    };
    let editingServiceId = null;
    let formBusy = false;
    let operationBusy = false;

    function clearServiceMessages() {
        serviceFormError.textContent = '';
        serviceFormError.classList.add('hidden');

        for (const element of Object.values(serviceFieldErrors)) {
            element.textContent = '';
            element.classList.add('hidden');
        }
    }

    function showServiceErrors(data) {
        let shownFieldError = false;

        for (const [field, message] of Object.entries(data.errors || {})) {
            if (!serviceFieldErrors[field] || typeof message !== 'string') continue;

            serviceFieldErrors[field].textContent = message;
            serviceFieldErrors[field].classList.remove('hidden');
            shownFieldError = true;
        }

        if (shownFieldError) return;

        serviceFormError.textContent = typeof data.error === 'string' && data.error !== ''
            ? data.error
            : 'Nie udało się zapisać usługi.';
        serviceFormError.classList.remove('hidden');
    }

    function setInactiveCategoryOptionsDisabled() {
        for (const radio of serviceCategoryRadios) {
            if (radio.dataset.inactive === '1') {
                radio.disabled = true;
            }
        }
    }

    function selectedServiceCategory() {
        return serviceCategoryRadios.find(function (radio) {
            return radio.checked;
        }) || null;
    }

    function syncServiceCategoryValue() {
        const selected = selectedServiceCategory();

        if (selected === null) {
            serviceCategoryValue.textContent = 'Wybierz kategorię';
            serviceCategoryValue.classList.add('text-zinc-400', 'dark:text-zinc-500');
            return;
        }

        const optionLabel = selected.closest('label');
        serviceCategoryValue.textContent = optionLabel !== null
            ? optionLabel.querySelector('span').textContent
            : selected.value;
        serviceCategoryValue.classList.remove('text-zinc-400', 'dark:text-zinc-500');
    }

    for (const radio of serviceCategoryRadios) {
        radio.addEventListener('change', syncServiceCategoryValue);
    }

    function resetServiceForm() {
        editingServiceId = null;
        serviceForm.reset();
        setInactiveCategoryOptionsDisabled();
        syncServiceCategoryValue();
        serviceId.value = '';
        serviceFormTitle.textContent = 'Dodaj usługę';
        serviceSubmit.textContent = 'Dodaj usługę';
        serviceCancel.classList.add('hidden');
        clearServiceMessages();
    }

    if (serviceDrawer) {
        serviceDrawer.addEventListener('panel:drawer-close', resetServiceForm);
    }

    // Format ceny i zakres dat odtwarza formatowanie widoku PHP.
    function formatPrice(value) {
        const number = Number(value);

        if (!isFinite(number)) {
            return '0,00';
        }

        const parts = number.toFixed(2).split('.');
        const integer = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

        return integer + ',' + parts[1];
    }

    function renderService(service) {
        const row = document.createElement('tr');
        row.className = classes.tr;

        const nameCell = document.createElement('td');
        nameCell.className = classes.td + ' font-medium text-zinc-900 dark:text-zinc-50';
        nameCell.textContent = service.nazwa || '';
        row.appendChild(nameCell);

        const categoryCell = document.createElement('td');
        categoryCell.className = classes.td;
        categoryCell.appendChild(document.createTextNode(service.kategoria_nazwa || ''));

        if (service.kategoria_aktywna !== true) {
            const inactive = document.createElement('span');
            inactive.className = 'text-xs text-zinc-500 dark:text-zinc-400';
            inactive.textContent = ' (nieaktywna)';
            categoryCell.appendChild(inactive);
        }

        row.appendChild(categoryCell);

        const priceCell = document.createElement('td');
        priceCell.className = classes.td + ' whitespace-nowrap';
        priceCell.textContent = formatPrice(service.cena) + ' zł';
        row.appendChild(priceCell);

        const durationCell = document.createElement('td');
        durationCell.className = classes.td + ' whitespace-nowrap';
        durationCell.textContent = String(service.czas_trwania_minuty) + ' min';
        row.appendChild(durationCell);

        const employeesCell = document.createElement('td');
        employeesCell.className = classes.td + ' whitespace-nowrap';
        employeesCell.appendChild(document.createTextNode(String(service.pracownicy_active)));

        if (service.przypisania_total !== service.pracownicy_active) {
            const total = document.createElement('span');
            total.className = 'text-xs text-zinc-500 dark:text-zinc-400';
            total.textContent = ' (łącznie: ' + service.przypisania_total + ')';
            employeesCell.appendChild(total);
        }

        row.appendChild(employeesCell);

        const statusCell = document.createElement('td');
        statusCell.className = classes.td;
        statusCell.appendChild(window.panelBadge(
            service.aktywna === true ? 'Aktywna' : 'Nieaktywna',
            service.aktywna === true ? 'success' : 'neutral'
        ));
        row.appendChild(statusCell);

        const actionsCell = document.createElement('td');
        actionsCell.className = classes.td;
        const actionsWrap = document.createElement('div');
        actionsWrap.className = 'flex flex-wrap justify-end gap-2';

        const isActive = service.aktywna === true;
        const name = service.nazwa || '';

        const editButton = document.createElement('button');
        editButton.type = 'button';
        editButton.className = window.panelButtonClasses('ghost');
        editButton.setAttribute('data-service-edit', '1');
        editButton.setAttribute('data-drawer-open', 'service-drawer');
        editButton.dataset.id = String(service.id);
        editButton.dataset.name = name;
        editButton.dataset.description = service.opis || '';
        editButton.dataset.category = String(service.kategoria_id || '0');
        editButton.dataset.price = Number(service.cena).toFixed(2).replace('.', ',');
        editButton.dataset.duration = String(service.czas_trwania_minuty);
        editButton.textContent = 'Edytuj';
        actionsWrap.appendChild(editButton);

        const toggleButton = document.createElement('button');
        toggleButton.type = 'button';
        toggleButton.className = window.panelButtonClasses('secondary');
        toggleButton.setAttribute('data-service-toggle', '1');
        toggleButton.dataset.id = String(service.id);
        toggleButton.dataset.name = name;
        toggleButton.dataset.active = isActive ? '1' : '0';
        toggleButton.title = isActive ? 'Dezaktywuj' : 'Aktywuj';
        toggleButton.setAttribute('aria-label', isActive ? 'Dezaktywuj' : 'Aktywuj');
        toggleButton.appendChild(window.panelIcon(
            isActive ? 'circle-slash' : 'circle-check',
            isActive
                ? 'h-4 w-4 shrink-0 text-red-600 dark:text-red-400'
                : 'h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400'
        ));
        actionsWrap.appendChild(toggleButton);

        const deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = window.panelButtonClasses('danger');
        deleteButton.setAttribute('data-service-delete', '1');
        deleteButton.dataset.id = String(service.id);
        deleteButton.dataset.name = name;
        deleteButton.title = 'Usuń';
        deleteButton.setAttribute('aria-label', 'Usuń');
        deleteButton.appendChild(window.panelIcon('trash', 'h-4 w-4 shrink-0'));
        actionsWrap.appendChild(deleteButton);

        actionsCell.appendChild(actionsWrap);
        row.appendChild(actionsCell);

        return row;
    }

    const list = window.panelList({
        section: page,
        api: serviceApi,
        form: document.getElementById('services-filter-form'),
        table: document.getElementById('services-table'),
        pageUrl: servicesUrl,
        itemsKey: 'uslugi',
        paginationSlot: page ? page.querySelector('[data-list-pagination]') : null,
        colspan: 7,
        renderRow: renderService,
        emptyState: function (hasFilters) {
            if (hasFilters) {
                return window.panelEmptyState({
                    title: 'Brak usług dla wybranych filtrów',
                    description: 'Zmień kategorię, status lub frazę wyszukiwania, aby zobaczyć inne usługi.',
                    action: { label: 'Wyczyść filtry', href: servicesUrl, clear: true }
                });
            }

            return window.panelEmptyState({
                title: 'Brak usług',
                description: 'Dodaj pierwszą usługę, aby przypisywać ją do pracowników.',
                action: { label: 'Dodaj usługę', drawer: 'service-drawer' }
            });
        },
        onError: function (message) {
            window.showToast(message, 'error');
        }
    });

    // Gdy widok nie wyrenderował tabeli (np. błąd bazy), listy nie odświeżamy.
    async function refreshServiceList() {
        if (list !== null) {
            await list.refresh();
        }
    }

    async function sendServiceRequest(method, payload) {
        const response = await fetch(serviceApi, {
            method: method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify(payload)
        });
        let data = {};

        try {
            data = await response.json();
        } catch {
            data = {};
        }

        return { response: response, data: data };
    }

    serviceForm.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (formBusy) return;

        clearServiceMessages();
        formBusy = true;
        serviceSubmit.disabled = true;
        serviceCancel.disabled = true;

        const submitLabel = serviceSubmit.textContent;
        let completed = false;
        serviceSubmit.textContent = 'Zapisywanie…';

        const selectedCategory = selectedServiceCategory();
        const payload = {
            nazwa: serviceName.value.trim(),
            opis: serviceDescription.value.trim(),
            kategoria_id: selectedCategory === null ? 0 : Number(selectedCategory.value),
            cena: servicePrice.value.trim(),
            czas_trwania_minuty: Number(serviceDuration.value)
        };

        if (editingServiceId !== null) {
            payload.id = editingServiceId;
        }

        try {
            const result = await sendServiceRequest(editingServiceId === null ? 'POST' : 'PATCH', payload);

            if (!result.response.ok || result.data.success !== true) {
                showServiceErrors(result.data);
                return;
            }

            completed = true;
            window.showToast(
                editingServiceId === null ? 'Usługa została dodana.' : 'Usługa została zapisana.',
                'success'
            );
            window.panelCloseDrawer(serviceDrawer, false);
            resetServiceForm();
            await refreshServiceList();
        } catch {
            serviceFormError.textContent = 'Nie udało się połączyć z serwerem. Spróbuj ponownie.';
            serviceFormError.classList.remove('hidden');
        } finally {
            if (!completed) {
                formBusy = false;
                serviceSubmit.disabled = false;
                serviceCancel.disabled = false;
                serviceSubmit.textContent = submitLabel;
            }
        }
    });

    serviceCancel.addEventListener('click', function () {
        resetServiceForm();
    });

    // Delegacja na tabeli działa również po przebudowaniu wierszy.
    page.addEventListener('click', function (event) {
        const editButton = event.target instanceof Element ? event.target.closest('[data-service-edit]') : null;

        if (editButton === null) return;

        editingServiceId = Number(editButton.dataset.id);
        serviceId.value = String(editingServiceId);
        serviceName.value = editButton.dataset.name || '';
        serviceDescription.value = editButton.dataset.description || '';
        setInactiveCategoryOptionsDisabled();

        const selectedCategoryId = editButton.dataset.category || '';

        for (const radio of serviceCategoryRadios) {
            if (radio.value === selectedCategoryId && radio.dataset.inactive === '1') {
                radio.disabled = false;
            }

            radio.checked = radio.value === selectedCategoryId;
        }

        syncServiceCategoryValue();
        servicePrice.value = editButton.dataset.price || '';
        serviceDuration.value = editButton.dataset.duration || '';
        serviceFormTitle.textContent = 'Edytuj usługę';
        serviceSubmit.textContent = 'Zapisz zmiany';
        serviceCancel.classList.remove('hidden');
        clearServiceMessages();
    });

    page.addEventListener('click', async function (event) {
        const toggleButton = event.target instanceof Element ? event.target.closest('[data-service-toggle]') : null;

        if (toggleButton === null) return;

        if (operationBusy) return;

        const isActive = toggleButton.dataset.active === '1';
        const name = toggleButton.dataset.name || 'tę usługę';
        const confirmed = await window.panelConfirm({
            title: isActive ? 'Dezaktywować usługę?' : 'Aktywować usługę?',
            message: isActive
                ? 'Usługa „' + name + '” przestanie być dostępna. Przypisania pracowników pozostaną w bazie.'
                : 'Usługa „' + name + '” znów będzie dostępna.',
            confirmLabel: isActive ? 'Dezaktywuj' : 'Aktywuj',
            danger: false
        });

        if (!confirmed) return;

        operationBusy = true;
        toggleButton.disabled = true;

        try {
            const result = await sendServiceRequest('PATCH', {
                id: Number(toggleButton.dataset.id),
                aktywna: !isActive
            });

            if (!result.response.ok || result.data.success !== true) {
                window.showToast(
                    typeof result.data.error === 'string' && result.data.error !== ''
                        ? result.data.error
                        : 'Nie udało się zmienić statusu usługi.',
                    'error'
                );
                return;
            }

            window.showToast(
                isActive ? 'Usługa została dezaktywowana.' : 'Usługa została aktywowana.',
                'success'
            );
            await refreshServiceList();
        } catch {
            window.showToast('Nie udało się połączyć z serwerem. Spróbuj ponownie.', 'error');
        } finally {
            // Po udanym odświeżeniu przycisk jest odłączony, a po błędzie GET
            // zostaje w starym wierszu — w obu przypadkach go zwalniamy.
            toggleButton.disabled = false;
            operationBusy = false;
        }
    });

    page.addEventListener('click', async function (event) {
        const deleteButton = event.target instanceof Element ? event.target.closest('[data-service-delete]') : null;

        if (deleteButton === null) return;

        if (operationBusy) return;

        const name = deleteButton.dataset.name || 'tę usługę';
        const confirmed = await window.panelConfirm({
            title: 'Usunąć usługę?',
            message: 'Usługa „' + name + '” zostanie trwale usunięta. Tej operacji nie można cofnąć.',
            confirmLabel: 'Usuń',
            danger: true
        });

        if (!confirmed) return;

        operationBusy = true;
        deleteButton.disabled = true;

        try {
            const result = await sendServiceRequest('DELETE', {
                id: Number(deleteButton.dataset.id)
            });

            if (!result.response.ok || result.data.success !== true) {
                window.showToast(
                    typeof result.data.error === 'string' && result.data.error !== ''
                        ? result.data.error
                        : 'Nie udało się usunąć usługi.',
                    'error'
                );
                return;
            }

            window.showToast('Usługa została usunięta.', 'success');
            await refreshServiceList();
        } catch {
            window.showToast('Nie udało się połączyć z serwerem. Spróbuj ponownie.', 'error');
        } finally {
            // Po udanym odświeżeniu przycisk jest odłączony, a po błędzie GET
            // zostaje w starym wierszu — w obu przypadkach go zwalniamy.
            deleteButton.disabled = false;
            operationBusy = false;
        }
    });
})();
