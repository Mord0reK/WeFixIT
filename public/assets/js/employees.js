/*
    Zarządzanie profilami pracowników: formularz w wysuwanym panelu oraz
    akcje edycji, zmiany statusu i usuwania.

    Adres API pochodzi z data-api formularza, dzięki czemu działa również
    w instalacji w podkatalogu.
*/
(function () {
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
    const employeeApi = employeeForm ? employeeForm.dataset.api || '' : '';
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const employeeFieldErrors = {
        uzytkownik_id: document.getElementById('employee-error-uzytkownik_id'),
        opis: document.getElementById('employee-error-opis'),
        aktywny: document.getElementById('employee-error-aktywny')
    };
    let editingEmployeeId = null;
    let formBusy = false;

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

    function reloadEmployees() {
        window.setTimeout(function () {
            window.location.reload();
        }, 700);
    }

    async function sendEmployeeRequest(method, payload) {
        const response = await fetch(employeeApi, {
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
                reloadEmployees();
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

    for (const button of document.querySelectorAll('[data-employee-edit]')) {
        button.addEventListener('click', function () {
            editingEmployeeId = Number(button.dataset.id);
            employeeId.value = String(editingEmployeeId);
            employeeDescription.value = button.dataset.description || '';
            employeeActive.checked = button.dataset.active === '1';
            employeeAccountReadonly.textContent = button.dataset.account || '';
            employeeFormTitle.textContent = 'Edytuj pracownika';
            employeeFormHint.textContent = 'Edytowalne są tylko opis i flaga aktywności.';
            employeeSubmit.textContent = 'Zapisz zmiany';
            employeeCancel.classList.remove('hidden');
            employeeAccount.disabled = true;
            employeeAccountSelectWrap.classList.add('hidden');
            employeeAccountReadonlyWrap.classList.remove('hidden');
            clearEmployeeMessages();
        });
    }

    for (const button of document.querySelectorAll('[data-employee-toggle]')) {
        button.addEventListener('click', async function () {
            const isActive = button.dataset.active === '1';
            const name = button.dataset.name || 'tego pracownika';
            const confirmed = await window.panelConfirm({
                title: isActive ? 'Dezaktywować pracownika?' : 'Aktywować pracownika?',
                message: isActive
                    ? 'Profil pracownika „' + name + '” zostanie oznaczony jako nieaktywny. Powiązania pozostaną w bazie.'
                    : 'Profil pracownika „' + name + '” znów będzie aktywny.',
                confirmLabel: isActive ? 'Dezaktywuj' : 'Aktywuj',
                danger: false
            });

            if (!confirmed) return;

            button.disabled = true;
            let completed = false;

            try {
                const result = await sendEmployeeRequest('PATCH', {
                    id: Number(button.dataset.id),
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

                completed = true;
                window.showToast(
                    isActive ? 'Pracownik został dezaktywowany.' : 'Pracownik został aktywowany.',
                    'success'
                );
                reloadEmployees();
            } catch {
                window.showToast('Nie udało się połączyć z serwerem. Spróbuj ponownie.', 'error');
            } finally {
                if (!completed) {
                    button.disabled = false;
                }
            }
        });
    }

    for (const button of document.querySelectorAll('[data-employee-delete]')) {
        button.addEventListener('click', async function () {
            const name = button.dataset.name || 'tego pracownika';
            const confirmed = await window.panelConfirm({
                title: 'Usunąć pracownika?',
                message: 'Profil pracownika „' + name + '” zostanie trwale usunięty. Tej operacji nie można cofnąć.',
                confirmLabel: 'Usuń',
                danger: true
            });

            if (!confirmed) return;

            button.disabled = true;
            let completed = false;

            try {
                const result = await sendEmployeeRequest('DELETE', {
                    id: Number(button.dataset.id)
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
                reloadEmployees();
            } catch {
                window.showToast('Nie udało się połączyć z serwerem. Spróbuj ponownie.', 'error');
            } finally {
                if (!completed) {
                    button.disabled = false;
                }
            }
        });
    }
})();
