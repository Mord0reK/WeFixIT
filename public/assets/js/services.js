/*
    Zarządzanie usługami: formularz w wysuwanym panelu oraz akcje edycji,
    zmiany statusu i usuwania.

    Adres API pochodzi z data-api formularza, dzięki czemu działa również
    w instalacji w podkatalogu.
*/
(function () {
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
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const serviceFieldErrors = {
        nazwa: document.getElementById('service-error-nazwa'),
        opis: document.getElementById('service-error-opis'),
        kategoria_id: document.getElementById('service-error-kategoria_id'),
        cena: document.getElementById('service-error-cena'),
        czas_trwania_minuty: document.getElementById('service-error-czas_trwania_minuty')
    };
    let editingServiceId = null;
    let formBusy = false;

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

    function reloadServices() {
        window.setTimeout(function () {
            window.location.reload();
        }, 700);
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
            reloadServices();
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

    for (const button of document.querySelectorAll('[data-service-edit]')) {
        button.addEventListener('click', function () {
            editingServiceId = Number(button.dataset.id);
            serviceId.value = String(editingServiceId);
            serviceName.value = button.dataset.name || '';
            serviceDescription.value = button.dataset.description || '';
            setInactiveCategoryOptionsDisabled();
            const selectedCategoryId = button.dataset.category || '';

            for (const radio of serviceCategoryRadios) {
                if (radio.value === selectedCategoryId && radio.dataset.inactive === '1') {
                    radio.disabled = false;
                }

                radio.checked = radio.value === selectedCategoryId;
            }

            syncServiceCategoryValue();
            servicePrice.value = button.dataset.price || '';
            serviceDuration.value = button.dataset.duration || '';
            serviceFormTitle.textContent = 'Edytuj usługę';
            serviceSubmit.textContent = 'Zapisz zmiany';
            serviceCancel.classList.remove('hidden');
            clearServiceMessages();
        });
    }

    for (const button of document.querySelectorAll('[data-service-toggle]')) {
        button.addEventListener('click', async function () {
            const isActive = button.dataset.active === '1';
            const name = button.dataset.name || 'tę usługę';
            const confirmed = await window.panelConfirm({
                title: isActive ? 'Dezaktywować usługę?' : 'Aktywować usługę?',
                message: isActive
                    ? 'Usługa „' + name + '” przestanie być dostępna. Przypisania pracowników pozostaną w bazie.'
                    : 'Usługa „' + name + '” znów będzie dostępna.',
                confirmLabel: isActive ? 'Dezaktywuj' : 'Aktywuj',
                danger: false
            });

            if (!confirmed) return;

            button.disabled = true;
            let completed = false;

            try {
                const result = await sendServiceRequest('PATCH', {
                    id: Number(button.dataset.id),
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

                completed = true;
                window.showToast(
                    isActive ? 'Usługa została dezaktywowana.' : 'Usługa została aktywowana.',
                    'success'
                );
                reloadServices();
            } catch {
                window.showToast('Nie udało się połączyć z serwerem. Spróbuj ponownie.', 'error');
            } finally {
                if (!completed) {
                    button.disabled = false;
                }
            }
        });
    }

    for (const button of document.querySelectorAll('[data-service-delete]')) {
        button.addEventListener('click', async function () {
            const name = button.dataset.name || 'tę usługę';
            const confirmed = await window.panelConfirm({
                title: 'Usunąć usługę?',
                message: 'Usługa „' + name + '” zostanie trwale usunięta. Tej operacji nie można cofnąć.',
                confirmLabel: 'Usuń',
                danger: true
            });

            if (!confirmed) return;

            button.disabled = true;
            let completed = false;

            try {
                const result = await sendServiceRequest('DELETE', {
                    id: Number(button.dataset.id)
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

                completed = true;
                window.showToast('Usługa została usunięta.', 'success');
                reloadServices();
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
