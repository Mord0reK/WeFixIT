/*
    Zmiana statusu rezerwacji przez fetch().

    Adres API pochodzi z data-api sekcji strony, dzięki czemu działa również
    w instalacji w podkatalogu. Potwierdzenie operacji zapewnia panelConfirm.
*/
(function () {
    const page = document.getElementById('reservations-page');

    if (!page) return;

    const reservationsApi = page.dataset.api || '';
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const generalError = document.getElementById('reservations-error');
    let busy = false;

    function clearErrors() {
        if (generalError) {
            generalError.textContent = '';
            generalError.classList.add('hidden');
        }

        document.querySelectorAll('[id^="reservation-error-"]').forEach(function (element) {
            element.textContent = '';
            element.classList.add('hidden');
        });
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

    document.querySelectorAll('[data-reservation-status]').forEach(function (button) {
        button.addEventListener('click', async function () {
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
            let danger = false;

            if (target === 'anulowana') {
                title = 'Anulować rezerwację?';
                message = 'Rezerwacja pozostanie w bazie jako anulowana. Nie zostanie usunięta.';
                confirmLabel = 'Anuluj rezerwację';
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
                danger: danger
            });

            if (!confirmed) return;

            clearErrors();
            busy = true;
            button.disabled = true;
            const submitLabel = button.textContent;
            let completed = false;
            button.textContent = 'Zapisywanie…';

            try {
                const result = await sendStatus(id, target);

                if (!result.response.ok || result.data.success !== true) {
                    showRowError(id, result.data);
                    return;
                }

                completed = true;
                let toastMessage = 'Status rezerwacji został zmieniony.';

                if (target === 'anulowana') {
                    toastMessage = 'Rezerwacja została anulowana.';
                } else if (current === 'anulowana') {
                    toastMessage = 'Anulowanie rezerwacji zostało cofnięte.';
                }

                window.showToast(toastMessage, 'success');
                window.setTimeout(function () {
                    window.location.reload();
                }, 600);
            } catch {
                showGeneralError('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
            } finally {
                if (!completed) {
                    busy = false;
                    button.disabled = false;
                    button.textContent = submitLabel;
                }
            }
        });
    });
})();
