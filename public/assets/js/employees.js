/*
    Zarządzanie profilami pracowników: lista pobierana z API, formularz
    w wysuwanym panelu oraz akcje edycji, zmiany statusu i usuwania.

    Adres API pochodzi z data-api formularza, dzięki czemu działa również
    w instalacji w podkatalogu. Po każdej operacji lista i lista kont
    dostępnych do powiązania są odświeżane przez panelList bez przeładowania
    strony.
*/
(function () {
    const page = document.getElementById('employees-page');

    if (!page) return;

    const employeeDrawer = document.getElementById('employee-drawer');
    const employeeForm = document.getElementById('employee-form');
    const employeeFormTitle = document.getElementById('employee-form-title');
    const employeeFormHint = document.getElementById('employee-form-hint');
    const employeeFormError = document.getElementById('employee-form-error');
    const employeeSubmit = document.getElementById('employee-submit');
    const employeeCancel = document.getElementById('employee-cancel');
    const employeeId = document.getElementById('employee-id');
    const employeeDescription = document.getElementById('employee-opis');
    const employeeActive = document.getElementById('employee-aktywny');
    const employeeAccount = document.getElementById('employee-uzytkownik');
    const employeeAccountSelectWrap = document.getElementById('employee-account-select-wrap');
    const employeeAccountReadonlyWrap = document.getElementById('employee-account-readonly-wrap');
    const employeeAccountReadonly = document.getElementById('employee-account-readonly');
    const accountsEmptySection = page.querySelector('[data-employee-accounts-empty]');
    const employeesApi = employeeForm ? employeeForm.dataset.api || '' : '';
    const employeesUrl = page.dataset.employeesUrl || '';
    const basePath = page.dataset.basePath || '';
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const classes = window.panelTableClasses();
    const employeeFieldErrors = {
        uzytkownik_id: document.getElementById('employee-error-uzytkownik_id'),
        opis: document.getElementById('employee-error-opis'),
        aktywny: document.getElementById('employee-error-aktywny')
    };
    let editingEmployeeId = null;
    let formBusy = false;
    let operationBusy = false;

    function clearEmployeeMessages() {
        employeeFormError.textContent = '';
        employeeFormError.classList.add('hidden');

        for (const element of Object.values(employeeFieldErrors)) {
            element.textContent = '';
            element.classList.add('hidden');
        }
    }

    function showEmployeeErrors(data) {
        let shownFieldError = false;

        for (const [field, message] of Object.entries(data.errors || {})) {
            if (!employeeFieldErrors[field] || typeof message !== 'string') continue;

            employeeFieldErrors[field].textContent = message;
            employeeFieldErrors[field].classList.remove('hidden');
            shownFieldError = true;
        }

        if (shownFieldError) return;

        employeeFormError.textContent = typeof data.error === 'string' && data.error !== ''
            ? data.error
            : 'Nie udało się zapisać pracownika.';
        employeeFormError.classList.remove('hidden');
    }

    function resetEmployeeForm() {
        editingEmployeeId = null;
        employeeForm.reset();
        employeeId.value = '';
        employeeFormTitle.textContent = 'Dodaj pracownika';
        employeeFormHint.textContent = 'Profil zostanie powiązany z istniejącym kontem o roli pracownik.';
        employeeSubmit.textContent = 'Dodaj pracownika';
        employeeCancel.classList.add('hidden');
        employeeAccount.disabled = false;
        employeeAccountSelectWrap.classList.remove('hidden');
        employeeAccountReadonlyWrap.classList.add('hidden');
        clearEmployeeMessages();
    }

    if (employeeDrawer) {
        employeeDrawer.addEventListener('panel:drawer-close', resetEmployeeForm);
    }

    // Skraca opis tak, jak robi to widok PHP (80 znaków ze wielokropkiem).
    function shortDescription(description) {
        const text = typeof description === 'string' ? description : '';
        const characters = Array.from(text);

        return characters.length > 80 ? characters.slice(0, 77).join('') + '…' : text;
    }

    function renderEmployee(employee) {
        const row = document.createElement('tr');
        row.className = classes.tr;
        const name = (employee.imie || '') + ' ' + (employee.nazwisko || '');
        const description = typeof employee.opis === 'string' ? employee.opis : '';

        const nameCell = document.createElement('td');
        nameCell.className = classes.td;
        const nameValue = document.createElement('span');
        nameValue.className = 'block font-medium text-zinc-900 dark:text-zinc-50';
        nameValue.textContent = name;
        nameCell.appendChild(nameValue);

        const short = shortDescription(description);

        if (short !== '') {
            const shortValue = document.createElement('span');
            shortValue.className = 'mt-0.5 block max-w-xs truncate text-xs text-zinc-500 dark:text-zinc-400';
            shortValue.title = description;
            shortValue.textContent = short;
            nameCell.appendChild(shortValue);
        }

        row.appendChild(nameCell);

        const emailCell = document.createElement('td');
        emailCell.className = classes.td;
        emailCell.appendChild(document.createTextNode(employee.email || ''));

        if (employee.konto_aktywne !== true) {
            const inactive = document.createElement('span');
            inactive.className = 'block text-xs text-zinc-500 dark:text-zinc-400';
            inactive.textContent = '(konto nieaktywne)';
            emailCell.appendChild(inactive);
        }

        row.appendChild(emailCell);

        const servicesCell = document.createElement('td');
        servicesCell.className = classes.td + ' whitespace-nowrap';
        servicesCell.textContent = String(employee.uslugi_active);
        row.appendChild(servicesCell);

        const statusCell = document.createElement('td');
        statusCell.className = classes.td;
        statusCell.appendChild(window.panelBadge(
            employee.aktywny === true ? 'Aktywny' : 'Nieaktywny',
            employee.aktywny === true ? 'success' : 'neutral'
        ));
        row.appendChild(statusCell);

        const actionsCell = document.createElement('td');
        actionsCell.className = classes.td;
        const actionsWrap = document.createElement('div');
        actionsWrap.className = 'flex flex-wrap justify-end gap-2';

        const servicesLink = document.createElement('a');
        servicesLink.href = basePath + '/pracownicy-uslugi?id=' + employee.id;
        servicesLink.className = window.panelButtonClasses('ghost');
        servicesLink.textContent = 'Usługi';
        actionsWrap.appendChild(servicesLink);

        const availabilityLink = document.createElement('a');
        availabilityLink.href = basePath + '/pracownicy-dostepnosc?id=' + employee.id;
        availabilityLink.className = window.panelButtonClasses('ghost');
        availabilityLink.textContent = 'Dostępność';
        actionsWrap.appendChild(availabilityLink);

        const accountLabel = name + ' (' + (employee.email || '') + ')';
        const isActive = employee.aktywny === true;

        const editButton = document.createElement('button');
        editButton.type = 'button';
        editButton.className = window.panelButtonClasses('ghost');
        editButton.setAttribute('data-employee-edit', '1');
        editButton.setAttribute('data-drawer-open', 'employee-drawer');
        editButton.dataset.id = String(employee.id);
        editButton.dataset.name = name;
        editButton.dataset.description = description;
        editButton.dataset.account = accountLabel;
        editButton.dataset.active = isActive ? '1' : '0';
        editButton.textContent = 'Edytuj';
        actionsWrap.appendChild(editButton);

        const toggleButton = document.createElement('button');
        toggleButton.type = 'button';
        toggleButton.className = window.panelButtonClasses('secondary');
        toggleButton.setAttribute('data-employee-toggle', '1');
        toggleButton.dataset.id = String(employee.id);
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
        deleteButton.setAttribute('data-employee-delete', '1');
        deleteButton.dataset.id = String(employee.id);
        deleteButton.dataset.name = name;
        deleteButton.title = 'Usuń';
        deleteButton.setAttribute('aria-label', 'Usuń');
        deleteButton.appendChild(window.panelIcon('trash', 'h-4 w-4 shrink-0'));
        actionsWrap.appendChild(deleteButton);

        actionsCell.appendChild(actionsWrap);
        row.appendChild(actionsCell);

        return row;
    }

    // Odświeża select kont w formularzu oraz widoczność akcji dodawania.
    function syncAvailableAccounts(data) {
        if (!Array.isArray(data.konta_dostepne)) {
            return;
        }

        const accounts = data.konta_dostepne;

        if (employeeAccount !== null) {
            const selectedValue = employeeAccount.value;
            employeeAccount.replaceChildren();

            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Wybierz konto';
            employeeAccount.appendChild(placeholder);

            for (const account of accounts) {
                const option = document.createElement('option');
                option.value = String(account.id);
                option.textContent = account.imie + ' ' + account.nazwisko + ' (' + account.email + ')';
                employeeAccount.appendChild(option);
            }

            // Zachowujemy wybór, jeśli konto nadal jest dostępne.
            for (const option of employeeAccount.options) {
                option.selected = option.value === selectedValue;
            }
        }

        const addLink = page.querySelector('a[href="#employee-drawer"]');

        if (addLink !== null) {
            addLink.hidden = accounts.length === 0;
        }

        if (accountsEmptySection !== null) {
            const total = data.paginacja ? Number(data.paginacja.razem || '0') : 0;
            accountsEmptySection.hidden = !(accounts.length === 0 && total > 0);
        }
    }

    const list = window.panelList({
        section: page,
        api: employeesApi,
        form: document.getElementById('employees-filter-form'),
        table: document.getElementById('employees-table'),
        pageUrl: employeesUrl,
        itemsKey: 'pracownicy',
        paginationSlot: page.querySelector('[data-list-pagination]'),
        colspan: 5,
        renderRow: renderEmployee,
        emptyState: function (hasFilters, data) {
            if (hasFilters) {
                return window.panelEmptyState({
                    title: 'Brak pracowników dla wybranej frazy',
                    description: 'Zmień frazę wyszukiwania, aby zobaczyć inne profile.',
                    action: { label: 'Wyczyść wyszukiwanie', href: employeesUrl, clear: true }
                });
            }

            const accounts = data && Array.isArray(data.konta_dostepne) ? data.konta_dostepne : null;

            if (accounts !== null && accounts.length === 0) {
                return window.panelEmptyState({
                    title: 'Brak pracowników i kont do powiązania',
                    description: 'Aby dodać pierwszego pracownika, najpierw utwórz konto z rolą pracownik. '
                        + 'Panel pracowników nie tworzy kont użytkowników.'
                });
            }

            return window.panelEmptyState({
                title: 'Brak pracowników',
                description: 'Utwórz profil pracownika powiązany z istniejącym kontem o roli pracownik.'
            });
        },
        onLoaded: syncAvailableAccounts,
        onError: function (message) {
            window.showToast(message, 'error');
        }
    });

    // Gdy widok nie wyrenderował tabeli (np. błąd bazy), listy nie odświeżamy.
    async function refreshEmployeeList() {
        if (list !== null) {
            await list.refresh();
        }
    }

    async function sendEmployeeRequest(method, payload) {
        const response = await fetch(employeesApi, {
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

    if (employeeForm) {
        employeeForm.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (formBusy) return;

            clearEmployeeMessages();
            formBusy = true;
            employeeSubmit.disabled = true;
            employeeCancel.disabled = true;

            const submitLabel = employeeSubmit.textContent;
            let completed = false;
            employeeSubmit.textContent = 'Zapisywanie…';

            const payload = {
                opis: employeeDescription.value.trim(),
                aktywny: employeeActive.checked
            };

            if (editingEmployeeId === null) {
                payload.uzytkownik_id = Number(employeeAccount.value);
            } else {
                payload.id = editingEmployeeId;
            }

            try {
                const result = await sendEmployeeRequest(
                    editingEmployeeId === null ? 'POST' : 'PATCH',
                    payload
                );

                if (!result.response.ok || result.data.success !== true) {
                    showEmployeeErrors(result.data);
                    return;
                }

                completed = true;
                window.showToast(
                    editingEmployeeId === null ? 'Pracownik został dodany.' : 'Pracownik został zapisany.',
                    'success'
                );
                window.panelCloseDrawer(employeeDrawer, false);
                resetEmployeeForm();
                await refreshEmployeeList();
            } catch {
                employeeFormError.textContent = 'Nie udało się połączyć z serwerem. Spróbuj ponownie.';
                employeeFormError.classList.remove('hidden');
            } finally {
                if (!completed) {
                    formBusy = false;
                    employeeSubmit.disabled = false;
                    employeeCancel.disabled = false;
                    employeeSubmit.textContent = submitLabel;
                }
            }
        });

        employeeCancel.addEventListener('click', function () {
            resetEmployeeForm();
        });
    }

    // Delegacja na sekcji działa również po przebudowaniu wierszy.
    page.addEventListener('click', function (event) {
        const editButton = event.target instanceof Element ? event.target.closest('[data-employee-edit]') : null;

        if (editButton === null) return;

        editingEmployeeId = Number(editButton.dataset.id);
        employeeId.value = String(editingEmployeeId);
        employeeDescription.value = editButton.dataset.description || '';
        employeeActive.checked = editButton.dataset.active === '1';
        employeeAccountReadonly.textContent = editButton.dataset.account || '';
        employeeFormTitle.textContent = 'Edytuj pracownika';
        employeeFormHint.textContent = 'Edytowalne są tylko opis i flaga aktywności.';
        employeeSubmit.textContent = 'Zapisz zmiany';
        employeeCancel.classList.remove('hidden');
        employeeAccount.disabled = true;
        employeeAccountSelectWrap.classList.add('hidden');
        employeeAccountReadonlyWrap.classList.remove('hidden');
        clearEmployeeMessages();
    });

    page.addEventListener('click', async function (event) {
        const toggleButton = event.target instanceof Element ? event.target.closest('[data-employee-toggle]') : null;

        if (toggleButton === null) return;

        if (operationBusy) return;

        const isActive = toggleButton.dataset.active === '1';
        const name = toggleButton.dataset.name || 'tego pracownika';
        const confirmed = await window.panelConfirm({
            title: isActive ? 'Dezaktywować pracownika?' : 'Aktywować pracownika?',
            message: isActive
                ? 'Profil pracownika „' + name + '” zostanie oznaczony jako nieaktywny. Powiązania pozostaną w bazie.'
                : 'Profil pracownika „' + name + '” znów będzie aktywny.',
            confirmLabel: isActive ? 'Dezaktywuj' : 'Aktywuj',
            danger: false
        });

        if (!confirmed) return;

        operationBusy = true;
        toggleButton.disabled = true;

        try {
            const result = await sendEmployeeRequest('PATCH', {
                id: Number(toggleButton.dataset.id),
                aktywny: !isActive
            });

            if (!result.response.ok || result.data.success !== true) {
                window.showToast(
                    typeof result.data.error === 'string' && result.data.error !== ''
                        ? result.data.error
                        : 'Nie udało się zmienić statusu pracownika.',
                    'error'
                );
                return;
            }

            window.showToast(
                isActive ? 'Pracownik został dezaktywowany.' : 'Pracownik został aktywowany.',
                'success'
            );
            await refreshEmployeeList();
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
        const deleteButton = event.target instanceof Element ? event.target.closest('[data-employee-delete]') : null;

        if (deleteButton === null) return;

        if (operationBusy) return;

        const name = deleteButton.dataset.name || 'tego pracownika';
        const confirmed = await window.panelConfirm({
            title: 'Usunąć pracownika?',
            message: 'Profil pracownika „' + name + '” zostanie trwale usunięty. Tej operacji nie można cofnąć.',
            confirmLabel: 'Usuń',
            danger: true
        });

        if (!confirmed) return;

        operationBusy = true;
        deleteButton.disabled = true;
        let completed = false;

        try {
            const result = await sendEmployeeRequest('DELETE', {
                id: Number(deleteButton.dataset.id)
            });

            if (!result.response.ok || result.data.success !== true) {
                window.showToast(
                    typeof result.data.error === 'string' && result.data.error !== ''
                        ? result.data.error
                        : 'Nie udało się usunąć pracownika.',
                    'error'
                );
                return;
            }

            completed = true;
            window.showToast('Pracownik został usunięty.', 'success');
            await refreshEmployeeList();
        } catch {
            window.showToast('Nie udało się połączyć z serwerem. Spróbuj ponownie.', 'error');
        } finally {
            if (!completed) {
                deleteButton.disabled = false;
            }

            operationBusy = false;
        }
    });
})();
