/*
    Formularz rezerwacji klienta: filtrowanie pracowników, ładowanie terminów
    przez AJAX i wysłanie rezerwacji przez fetch().
*/
(function () {
    const form = document.getElementById('reservation-form');

    if (!form) return;

    const apiReservations = form.dataset.apiReservations || '';
    const availabilityApi = form.dataset.apiAvailability || '';
    const loginUrl = form.dataset.loginUrl || '';
    const panelUrl = form.dataset.panelUrl || '';
    const preselectedService = form.dataset.uslugaPreselect || '';
    let employeesByService = {};

    try {
        const parsedEmployees = JSON.parse(form.dataset.employees || '{}');

        if (parsedEmployees && typeof parsedEmployees === 'object') {
            employeesByService = parsedEmployees;
        }
    } catch {
        employeesByService = {};
    }

    const serviceSelect = document.getElementById('reservation-usluga');
    const employeeSelect = document.getElementById('reservation-pracownik');
    const dateInput = document.getElementById('reservation-data');
    const slotsContainer = document.getElementById('reservation-slots');
    const commentInput = document.getElementById('reservation-komentarz');
    const submitButton = document.getElementById('reservation-submit');
    const formError = document.getElementById('reservation-form-error');
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const fieldErrors = {
        usluga: document.getElementById('reservation-error-usluga'),
        pracownik: document.getElementById('reservation-error-pracownik'),
        data: document.getElementById('reservation-error-data'),
        godzina: document.getElementById('reservation-error-godzina'),
        komentarz: document.getElementById('reservation-error-komentarz')
    };
    const errorFieldMap = {
        usluga_id: 'usluga',
        pracownik_id: 'pracownik',
        data: 'data',
        godzina: 'godzina',
        komentarz: 'komentarz'
    };
    const slotIdleClass = 'inline-flex h-9 items-center justify-center gap-2 rounded-md border border-zinc-200 bg-white px-4 text-sm font-medium text-zinc-700 shadow-sm transition-colors hover:bg-zinc-100 hover:text-zinc-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800 dark:hover:text-zinc-50 dark:focus-visible:ring-offset-zinc-900';
    const slotActiveClass = 'inline-flex h-9 items-center justify-center gap-2 rounded-md bg-zinc-900 px-4 text-sm font-medium text-white shadow-sm transition-colors hover:bg-zinc-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60 dark:bg-zinc-50 dark:text-zinc-900 dark:hover:bg-zinc-200 dark:focus-visible:ring-offset-zinc-900';
    let selectedStart = '';
    let requestId = 0;
    let availabilityBusy = false;
    let reloadRequested = false;
    let submitting = false;

    function setSubmitState() {
        const ready = !submitting
            && Number(serviceSelect.value) > 0
            && Number(employeeSelect.value) > 0
            && dateInput.value !== ''
            && selectedStart !== '';

        submitButton.disabled = !ready;
    }

    function clearErrors() {
        for (const element of Object.values(fieldErrors)) {
            if (!element) continue;

            element.textContent = '';
            element.classList.add('hidden');
        }

        formError.textContent = '';
        formError.classList.add('hidden');
    }

    function showGeneralError(message) {
        formError.textContent = message;
        formError.classList.remove('hidden');
    }

    function showFieldError(field, message) {
        const element = fieldErrors[field];

        if (!element || message === '') return;

        element.textContent = message;
        element.classList.remove('hidden');
    }

    function showSlotMessage(message) {
        slotsContainer.replaceChildren();

        const paragraph = document.createElement('p');
        paragraph.className = 'text-sm text-zinc-500 dark:text-zinc-400';
        paragraph.textContent = message;
        slotsContainer.appendChild(paragraph);

        selectedStart = '';
        setSubmitState();
    }

    function invalidateSlots(message) {
        requestId += 1;
        showSlotMessage(message);
    }

    function syncEmployeeOptions() {
        const serviceId = serviceSelect.value;
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = serviceId === ''
            ? 'Najpierw wybierz usługę'
            : 'Wybierz pracownika';

        employeeSelect.replaceChildren(placeholder);
        employeeSelect.disabled = serviceId === '';
        employeeSelect.value = '';

        if (serviceId === '') return;

        const employees = Array.isArray(employeesByService[serviceId])
            ? employeesByService[serviceId]
            : [];

        for (const employee of employees) {
            if (!employee || employee.id === undefined) continue;

            const option = document.createElement('option');
            option.value = String(employee.id);
            option.textContent = String(employee.label);
            employeeSelect.appendChild(option);
        }
    }

    function renderSlots(slots) {
        slotsContainer.replaceChildren();
        selectedStart = '';

        if (!Array.isArray(slots) || slots.length === 0) {
            showSlotMessage('Brak wolnych terminów w tym dniu. Wybierz inną datę.');
            return;
        }

        const list = document.createElement('div');
        list.className = 'flex flex-wrap gap-2';

        for (const slot of slots) {
            const start = slot && typeof slot.start === 'string' ? slot.start : '';

            if (start === '') continue;

            const button = document.createElement('button');
            button.type = 'button';
            button.className = slotIdleClass;
            button.textContent = start.slice(11, 16);
            button.setAttribute('aria-pressed', 'false');
            button.addEventListener('click', function () {
                selectedStart = start;

                for (const item of list.querySelectorAll('button')) {
                    const active = item === button;

                    item.className = active ? slotActiveClass : slotIdleClass;
                    item.setAttribute('aria-pressed', active ? 'true' : 'false');
                }

                setSubmitState();
            });

            list.appendChild(button);
        }

        if (list.children.length === 0) {
            showSlotMessage('Brak wolnych terminów w tym dniu. Wybierz inną datę.');
            return;
        }

        slotsContainer.appendChild(list);
        setSubmitState();
    }

    async function loadSlots() {
        const serviceId = Number(serviceSelect.value);
        const employeeId = Number(employeeSelect.value);
        const date = dateInput.value;

        if (serviceId <= 0 || employeeId <= 0 || date === '') {
            invalidateSlots('Wybierz usługę, pracownika i datę.');
            return;
        }

        if (availabilityBusy) {
            requestId += 1;
            reloadRequested = true;
            return;
        }

        availabilityBusy = true;
        reloadRequested = false;
        const currentRequest = ++requestId;
        showSlotMessage('Ładowanie terminów…');

        try {
            const params = new URLSearchParams({
                pracownik_id: String(employeeId),
                usluga_id: String(serviceId),
                data: date
            });
            const response = await fetch(availabilityApi + '?' + params.toString(), {
                credentials: 'same-origin'
            });

            if (currentRequest !== requestId) return;

            if (response.status === 401) {
                window.location.href = loginUrl;
                return;
            }

            let data = {};

            try {
                data = await response.json();
            } catch {
                data = {};
            }

            if (currentRequest !== requestId) return;

            if (response.status === 409) {
                showSlotMessage(typeof data.error === 'string' && data.error !== ''
                    ? data.error
                    : 'Termin nie jest już dostępny. Wybierz inną datę.');
                return;
            }

            if (!response.ok) {
                showSlotMessage('Nie udało się pobrać terminów. Wystąpił problem z serwerem.');
                return;
            }

            renderSlots(data.sloty);
        } catch {
            if (currentRequest === requestId) {
                showSlotMessage('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
            }
        } finally {
            availabilityBusy = false;

            if (reloadRequested) {
                reloadRequested = false;
                loadSlots();
            }
        }
    }

    serviceSelect.addEventListener('change', function () {
        clearErrors();
        syncEmployeeOptions();
        employeeSelect.value = '';
        dateInput.value = '';
        invalidateSlots('Wybierz usługę, pracownika i datę.');
        setSubmitState();
    });

    employeeSelect.addEventListener('change', function () {
        clearErrors();
        invalidateSlots('Wybierz datę, aby zobaczyć wolne terminy.');
        loadSlots();
    });

    dateInput.addEventListener('change', function () {
        clearErrors();
        invalidateSlots('Wybierz usługę, pracownika i datę.');
        loadSlots();
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (submitting) return;

        clearErrors();

        const serviceId = Number(serviceSelect.value);
        const employeeId = Number(employeeSelect.value);
        const date = dateInput.value;

        if (serviceId <= 0 || employeeId <= 0 || date === '') {
            showGeneralError('Wybierz usługę, pracownika i datę.');
            return;
        }

        if (selectedStart === '') {
            showFieldError('godzina', 'Wybierz godzinę.');
            return;
        }

        submitting = true;
        submitButton.disabled = true;
        const submitLabel = submitButton.textContent;
        let succeeded = false;
        submitButton.textContent = 'Rezerwowanie…';

        try {
            const payload = {
                pracownik_id: employeeId,
                usluga_id: serviceId,
                data: date,
                godzina: selectedStart.slice(11, 19)
            };
            const comment = commentInput.value.trim();

            if (comment !== '') {
                payload.komentarz = comment;
            }

            const response = await fetch(apiReservations, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(payload)
            });
            let data = {};

            try {
                data = await response.json();
            } catch {
                data = {};
            }

            if (response.status === 201 && data.success === true) {
                succeeded = true;

                if (typeof window.showToast === 'function') {
                    window.showToast('Rezerwacja została utworzona.', 'success');
                }

                // Lista rezerwacji klienta powstaje w kolejnym batchu,
                // dlatego po sukcesie wracamy na panel.
                window.setTimeout(function () {
                    window.location.href = panelUrl;
                }, 800);
                return;
            }

            if (response.status === 401) {
                window.location.href = loginUrl;
                return;
            }

            if (response.status === 422) {
                if (data.errors && typeof data.errors === 'object') {
                    for (const [field, message] of Object.entries(data.errors)) {
                        const target = errorFieldMap[field];

                        if (target && typeof message === 'string' && message !== '') {
                            showFieldError(target, message);
                        }
                    }
                }

                if (typeof data.error === 'string' && data.error !== '') {
                    showGeneralError(data.error);
                }

                return;
            }

            if (response.status === 409) {
                const message = typeof data.error === 'string' && data.error !== ''
                    ? data.error
                    : 'Wybrany termin jest już zajęty.';

                if (typeof window.showToast === 'function') {
                    window.showToast(message, 'error');
                } else {
                    showGeneralError(message);
                }

                return;
            }

            showGeneralError('Nie udało się utworzyć rezerwacji. Wystąpił problem z serwerem.');
        } catch {
            showGeneralError('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
        } finally {
            submitting = false;

            if (!succeeded) {
                submitButton.textContent = submitLabel;
                setSubmitState();
            }
        }
    });

    if (preselectedService !== '') {
        for (const option of serviceSelect.options) {
            if (option.value === preselectedService) {
                serviceSelect.value = preselectedService;
                break;
            }
        }
    }

    syncEmployeeOptions();
    setSubmitState();
})();
