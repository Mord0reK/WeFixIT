/*
    Dostępność pracownika: wybór daty, grafik tygodniowy i nieobecności.

    Konfiguracja strony (adresy API, identyfikator pracownika i domyślny dzień)
    pochodzi z atrybutów data-* sekcji #employee-availability.
*/
(function () {
    const page = document.getElementById('employee-availability');

    if (!page) return;

    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const scheduleApi = page.dataset.scheduleApi || '';
    const absencesApi = page.dataset.absencesApi || '';
    const employeeId = Number(page.dataset.employeeId || 0);
    const defaultDay = Number(page.dataset.defaultDay || 1);

    (function () {
        const monthNames = [
            'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec',
            'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'
        ];

        function padNumber(value) {
            return String(value).padStart(2, '0');
        }

        function dateIso(date) {
            return date.getFullYear() + '-' + padNumber(date.getMonth() + 1) + '-' + padNumber(date.getDate());
        }

        function dateLabel(date) {
            return padNumber(date.getDate()) + '.' + padNumber(date.getMonth() + 1) + '.' + date.getFullYear();
        }

        function syncPickerDisplay(field) {
            const display = field.querySelector('[data-picker-value]');

            if (!display) return;

            const placeholder = display.dataset.placeholder || '';

            if (field.hasAttribute('data-date-picker')) {
                const input = field.querySelector('input[type="hidden"]');

                if (input && input.value !== '') {
                    const selected = new Date(input.value + 'T00:00:00');

                    if (!Number.isNaN(selected.getTime())) {
                        display.textContent = dateLabel(selected);
                        display.classList.remove('text-zinc-400', 'dark:text-zinc-500');
                        return;
                    }
                }

                display.textContent = placeholder;
                display.classList.add('text-zinc-400', 'dark:text-zinc-500');
                return;
            }

            const checked = field.querySelector('input[type="radio"]:checked');

            if (!checked) {
                display.textContent = placeholder;
                display.classList.add('text-zinc-400', 'dark:text-zinc-500');
                return;
            }

            const optionLabel = checked.closest('label');
            const optionText = optionLabel ? optionLabel.querySelector('span') : null;
            display.textContent = optionText ? optionText.textContent.trim() : checked.value;
            display.classList.remove('text-zinc-400', 'dark:text-zinc-500');
        }

        function resetPickers(form) {
            if (!form) return;

            for (const field of form.querySelectorAll('[data-picker-field]')) {
                syncPickerDisplay(field);

                if (typeof field.resetDatePicker === 'function') {
                    field.resetDatePicker();
                }
            }
        }

        window.panelResetPickers = resetPickers;

        for (const field of document.querySelectorAll('[data-picker-field]')) {
            for (const radio of field.querySelectorAll('input[type="radio"]')) {
                radio.addEventListener('change', function () {
                    syncPickerDisplay(field);
                });
            }

            syncPickerDisplay(field);
        }

        function attachDatePicker(field) {
            const input = field.querySelector('input[type="hidden"]');
            const calendar = field.querySelector('[data-date-calendar]');
            const display = field.querySelector('[data-picker-value]');
            const monthLabel = calendar ? calendar.querySelector('[data-date-month]') : null;
            const days = calendar ? calendar.querySelector('[data-date-days]') : null;
            const previous = calendar ? calendar.querySelector('[data-date-prev]') : null;
            const next = calendar ? calendar.querySelector('[data-date-next]') : null;

            if (!input || !calendar || !display || !monthLabel || !days || !previous || !next) return;

            const today = dateIso(new Date());
            let view = new Date();
            view = new Date(view.getFullYear(), view.getMonth(), 1);

            function selectedDate() {
                if (input.value === '') return null;

                const selected = new Date(input.value + 'T00:00:00');

                return Number.isNaN(selected.getTime()) ? null : selected;
            }

            function setDisplay(date) {
                if (date === null) {
                    display.textContent = display.dataset.placeholder || 'Wybierz datę';
                    display.classList.add('text-zinc-400', 'dark:text-zinc-500');
                    return;
                }

                display.textContent = dateLabel(date);
                display.classList.remove('text-zinc-400', 'dark:text-zinc-500');
            }

            function render() {
                monthLabel.textContent = monthNames[view.getMonth()] + ' ' + view.getFullYear();
                days.replaceChildren();

                const firstDay = new Date(view.getFullYear(), view.getMonth(), 1);
                const offset = (firstDay.getDay() + 6) % 7;

                for (let index = 0; index < offset; index += 1) {
                    days.appendChild(document.createElement('span'));
                }

                const daysInMonth = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
                const selected = selectedDate();
                const selectedIso = selected === null ? '' : dateIso(selected);

                for (let day = 1; day <= daysInMonth; day += 1) {
                    const date = new Date(view.getFullYear(), view.getMonth(), day);
                    const iso = dateIso(date);
                    const button = document.createElement('button');

                    button.type = 'button';
                    button.textContent = String(day);
                    button.className = 'inline-flex h-8 w-8 items-center justify-center rounded-md text-sm text-zinc-700 transition-colors hover:bg-zinc-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 dark:text-zinc-200 dark:hover:bg-zinc-800';

                    if (iso === selectedIso) {
                        button.classList.add(
                            'bg-zinc-900', 'text-white', 'hover:bg-zinc-800',
                            'dark:bg-zinc-50', 'dark:text-zinc-900', 'dark:hover:bg-zinc-200'
                        );
                    } else if (iso === today) {
                        button.classList.add('ring-1', 'ring-inset', 'ring-zinc-300', 'dark:ring-zinc-600');
                    }

                    button.addEventListener('click', function () {
                        input.value = iso;
                        setDisplay(date);
                        render();
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    });

                    days.appendChild(button);
                }
            }

            function reset() {
                input.value = '';
                const now = new Date();
                view = new Date(now.getFullYear(), now.getMonth(), 1);
                setDisplay(null);
                render();
            }

            field.resetDatePicker = reset;

            previous.addEventListener('click', function () {
                view = new Date(view.getFullYear(), view.getMonth() - 1, 1);
                render();
            });

            next.addEventListener('click', function () {
                view = new Date(view.getFullYear(), view.getMonth() + 1, 1);
                render();
            });

            const selected = selectedDate();

            if (selected !== null) {
                view = new Date(selected.getFullYear(), selected.getMonth(), 1);
            }

            setDisplay(selected);
            render();
        }

        for (const field of document.querySelectorAll('[data-date-picker]')) {
            attachDatePicker(field);
        }
    })();

    (function () {
        const drawer = document.getElementById('schedule-drawer');
        const form = document.getElementById('schedule-form');
        const formError = document.getElementById('schedule-form-error');
        const actionError = document.getElementById('schedule-action-error');
        const daySelect = document.getElementById('schedule-dzien');
        const submitButton = document.getElementById('schedule-submit');
        const fieldErrors = {
            dzien_tygodnia: document.getElementById('schedule-error-dzien_tygodnia'),
            czas_rozpoczecia: document.getElementById('schedule-error-czas_rozpoczecia'),
            czas_zakonczenia: document.getElementById('schedule-error-czas_zakonczenia')
        };
        let busy = false;

        function hideMessage(element) {
            if (!element) return;
            element.textContent = '';
            element.classList.add('hidden');
        }

        function showMessage(element, message) {
            if (!element) return;
            element.textContent = message;
            element.classList.remove('hidden');
        }

        function clearFormMessages() {
            hideMessage(formError);

            for (const element of Object.values(fieldErrors)) {
                hideMessage(element);
            }
        }

        function clearActionMessage() {
            hideMessage(actionError);
        }

        function showFieldErrors(errors) {
            let shown = false;

            for (const [field, message] of Object.entries(errors || {})) {
                if (!fieldErrors[field] || typeof message !== 'string') continue;

                showMessage(fieldErrors[field], message);
                shown = true;
            }

            return shown;
        }

        async function send(method, payload) {
            const response = await fetch(scheduleApi, {
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

        function showRequestError(data) {
            showMessage(actionError, typeof data.error === 'string' && data.error !== ''
                ? data.error
                : 'Nie udało się zapisać grafiku.');
        }

        function resetScheduleForm() {
            if (!form) return;

            form.reset();

            if (window.panelResetPickers) {
                window.panelResetPickers(form);
            }

            if (daySelect) {
                daySelect.value = String(defaultDay);
            }

            clearFormMessages();
        }

        if (drawer) {
            drawer.addEventListener('panel:drawer-close', resetScheduleForm);
        }

        if (form) {
            form.addEventListener('submit', async function (event) {
                event.preventDefault();

                if (busy) return;

                clearFormMessages();
                busy = true;
                if (submitButton) submitButton.disabled = true;
                let completed = false;
                const startInput = form.querySelector('input[name="czas_rozpoczecia"]:checked');
                const endInput = form.querySelector('input[name="czas_zakonczenia"]:checked');

                try {
                    const result = await send('POST', {
                        pracownik_id: employeeId,
                        dzien_tygodnia: Number(daySelect ? daySelect.value : 0),
                        czas_rozpoczecia: startInput === null ? '' : startInput.value,
                        czas_zakonczenia: endInput === null ? '' : endInput.value
                    });

                    if (!result.response.ok || result.data.success !== true) {
                        if (!showFieldErrors(result.data.errors)) {
                            showMessage(formError, typeof result.data.error === 'string' && result.data.error !== ''
                                ? result.data.error
                                : 'Nie udało się zapisać grafiku.');
                        }
                        return;
                    }

                    completed = true;
                    window.showToast('Przedział grafiku został dodany.', 'success');
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 600);
                } catch {
                    showMessage(formError, 'Nie udało się połączyć z serwerem. Spróbuj ponownie.');
                } finally {
                    if (!completed) {
                        busy = false;
                        if (submitButton) submitButton.disabled = false;
                    }
                }
            });
        }

        for (const button of document.querySelectorAll('[data-schedule-add]')) {
            button.addEventListener('click', function () {
                clearFormMessages();

                if (daySelect && button.dataset.day) {
                    daySelect.value = button.dataset.day;
                }
            });
        }

        document.querySelectorAll('[data-schedule-toggle]').forEach(function (button) {
            button.addEventListener('click', async function () {
                if (busy) return;

                clearActionMessage();
                busy = true;
                button.disabled = true;
                const active = button.dataset.active === '1';
                let completed = false;

                try {
                    const result = await send('PATCH', {
                        id: Number(button.dataset.id),
                        pracownik_id: employeeId,
                        aktywne: !active
                    });

                    if (!result.response.ok || result.data.success !== true) {
                        showRequestError(result.data);
                        return;
                    }

                    completed = true;
                    window.showToast(active ? 'Przedział został dezaktywowany.' : 'Przedział został aktywowany.', 'success');
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 600);
                } catch {
                    showMessage(actionError, 'Nie udało się połączyć z serwerem. Spróbuj ponownie.');
                } finally {
                    if (!completed) {
                        busy = false;
                        button.disabled = false;
                    }
                }
            });
        });

        document.querySelectorAll('[data-schedule-delete]').forEach(function (button) {
            button.addEventListener('click', async function () {
                if (busy) return;

                const confirmed = await window.panelConfirm({
                    title: 'Usuń przedział grafiku',
                    message: 'Czy usunąć przedział ' + (button.dataset.label || '') + '? Tej operacji nie można cofnąć.',
                    confirmLabel: 'Usuń',
                    danger: true
                });

                if (!confirmed) return;

                clearActionMessage();
                busy = true;
                button.disabled = true;
                let completed = false;

                try {
                    const result = await send('DELETE', {
                        id: Number(button.dataset.id),
                        pracownik_id: employeeId
                    });

                    if (!result.response.ok || result.data.success !== true) {
                        showRequestError(result.data);
                        return;
                    }

                    completed = true;
                    window.showToast('Przedział grafiku został usunięty.', 'success');
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 600);
                } catch {
                    showMessage(actionError, 'Nie udało się połączyć z serwerem. Spróbuj ponownie.');
                } finally {
                    if (!completed) {
                        busy = false;
                        button.disabled = false;
                    }
                }
            });
        });
    })();

    (function () {
        const drawer = document.getElementById('absence-drawer');
        const form = document.getElementById('absence-form');
        const formError = document.getElementById('absence-form-error');
        const actionError = document.getElementById('absence-action-error');
        const actionWarning = document.getElementById('absence-action-warning');
        const submitButton = document.getElementById('absence-submit');
        const reasonPicker = document.querySelector('[data-reason-picker]');
        const customReasonWrap = reasonPicker ? reasonPicker.querySelector('[data-reason-custom]') : null;
        const customReason = document.getElementById('absence-powod-inne');
        const fieldErrors = {
            data_od: document.getElementById('absence-error-data_od'),
            data_do: document.getElementById('absence-error-data_do'),
            rodzaj: document.getElementById('absence-error-rodzaj'),
            powod: document.getElementById('absence-error-powod')
        };
        let busy = false;

        function hideMessage(element) {
            if (!element) return;
            element.textContent = '';
            element.classList.add('hidden');
        }

        function showMessage(element, message) {
            if (!element) return;
            element.textContent = message;
            element.classList.remove('hidden');
        }

        function clearFormMessages() {
            hideMessage(formError);

            for (const element of Object.values(fieldErrors)) {
                hideMessage(element);
            }
        }

        function clearActionMessages() {
            hideMessage(actionError);
            hideMessage(actionWarning);
        }

        function clearMessages() {
            clearFormMessages();
            clearActionMessages();
        }

        function showFormError(message) {
            showMessage(formError, message);
        }

        function showActionError(message) {
            showMessage(actionError, message);
        }

        function showErrors(data) {
            let shown = false;

            for (const [field, message] of Object.entries(data.errors || {})) {
                if (!fieldErrors[field] || typeof message !== 'string') continue;

                showMessage(fieldErrors[field], message);
                shown = true;
            }

            if (!shown) {
                showFormError(typeof data.error === 'string' && data.error !== ''
                    ? data.error
                    : 'Nie udało się zapisać nieobecności.');
            }
        }

        function syncCustomReason() {
            if (!reasonPicker || !customReasonWrap) return;

            const selected = reasonPicker.querySelector('input[name="powod"]:checked');
            const isCustom = selected !== null && selected.value === '__custom__';

            customReasonWrap.classList.toggle('hidden', !isCustom);

            if (!isCustom && customReason) {
                customReason.value = '';
            }
        }

        if (reasonPicker) {
            for (const radio of reasonPicker.querySelectorAll('input[name="powod"]')) {
                radio.addEventListener('change', syncCustomReason);
            }

            syncCustomReason();
        }

        function resetAbsenceForm() {
            if (!form) return;

            form.reset();

            if (window.panelResetPickers) {
                window.panelResetPickers(form);
            }

            syncCustomReason();
            clearFormMessages();
        }

        if (drawer) {
            drawer.addEventListener('panel:drawer-close', resetAbsenceForm);
        }

        async function send(method, payload) {
            const response = await fetch(absencesApi, {
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

        if (form) {
            form.addEventListener('submit', async function (event) {
                event.preventDefault();

                if (busy) return;

                clearMessages();
                busy = true;
                if (submitButton) submitButton.disabled = true;
                let completed = false;
                const selectedReasonInput = form.querySelector('input[name="powod"]:checked');
                const selectedReason = selectedReasonInput === null ? '' : selectedReasonInput.value;
                const reason = selectedReason === '__custom__'
                    ? (customReason ? customReason.value.trim() : '')
                    : selectedReason;

                try {
                    const result = await send('POST', {
                        pracownik_id: employeeId,
                        data_od: form.elements.data_od.value,
                        data_do: form.elements.data_do.value,
                        rodzaj: form.elements.rodzaj.value,
                        powod: reason
                    });

                    if (!result.response.ok || result.data.success !== true) {
                        showErrors(result.data);
                        return;
                    }

                    completed = true;
                    window.showToast('Wniosek o nieobecność został utworzony.', 'success');
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 600);
                } catch {
                    showFormError('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
                } finally {
                    if (!completed) {
                        busy = false;
                        if (submitButton) submitButton.disabled = false;
                    }
                }
            });
        }

        document.querySelectorAll('[data-absence-status]').forEach(function (button) {
            button.addEventListener('click', async function () {
                if (busy) return;

                const target = button.dataset.absenceStatus || '';
                const dates = button.dataset.dates || '';
                const warning = button.dataset.warning || '';
                let message = 'Czy zmienić status nieobecności' + (dates !== '' ? ' ' + dates : '') + '?';

                if (target === 'zatwierdzona' && warning !== '') {
                    message += ' ' + warning;
                }

                const labels = {
                    zatwierdzona: 'Zatwierdź',
                    odrzucona: 'Odrzuć',
                    anulowana: 'Anuluj'
                };
                const confirmed = await window.panelConfirm({
                    title: 'Zmień status nieobecności',
                    message: message,
                    confirmLabel: labels[target] || 'Potwierdź',
                    danger: target === 'odrzucona' || target === 'anulowana'
                });

                if (!confirmed) return;

                clearMessages();
                busy = true;
                button.disabled = true;
                let completed = false;

                try {
                    const result = await send('PATCH', {
                        id: Number(button.dataset.id),
                        status: target
                    });

                    if (!result.response.ok || result.data.success !== true) {
                        const statusError = result.data.errors && typeof result.data.errors.status === 'string'
                            ? result.data.errors.status
                            : (typeof result.data.error === 'string' && result.data.error !== ''
                                ? result.data.error
                                : 'Nie udało się zmienić statusu nieobecności.');
                        showActionError(statusError);
                        return;
                    }

                    completed = true;

                    if (result.data.ostrzezenie && Number(result.data.ostrzezenie.liczba_rezerwacji) > 0) {
                        actionWarning.textContent = result.data.ostrzezenie.komunikat;
                        actionWarning.classList.remove('hidden');
                        window.setTimeout(function () {
                            window.location.reload();
                        }, 2500);
                        return;
                    }

                    window.showToast('Status nieobecności został zmieniony.', 'success');
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 600);
                } catch {
                    showActionError('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
                } finally {
                    if (!completed) {
                        busy = false;
                        button.disabled = false;
                    }
                }
            });
        });
    })();
})();
