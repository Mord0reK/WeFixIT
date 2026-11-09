/*
    Zmiana statusu rezerwacji w panelu pracownika przez fetch().

    Adres API i strona logowania pochodzą z atrybutów sekcji. Potwierdzenie
    zapewnia panelConfirm, a po sukcesie strona jest przeładowywana.
*/
(function () {
    const section = document.getElementById('employee-reservations');

    if (!section) return;

    const apiUrl = section.dataset.api || '';
    const loginUrl = section.dataset.loginUrl || '/login';
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const generalError = document.getElementById('employee-reservations-error');
    let busy = false;

    function clearError() {
        if (!generalError) return;

        generalError.textContent = '';
        generalError.classList.add('hidden');
    }

    function showError(message) {
        if (!generalError) return;

        generalError.textContent = message;
        generalError.classList.remove('hidden');
    }

    async function sendStatus(id, status) {
        const response = await fetch(apiUrl, {
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

    document.querySelectorAll('[data-employee-status]').forEach(function (button) {
        button.addEventListener('click', async function () {
            if (busy) return;

            const id = Number(button.dataset.id);
            const target = button.dataset.target || '';
            const targetLabel = button.dataset.targetLabel || target;

            if (!Number.isInteger(id) || id <= 0 || target === '') return;

            let title = 'Zmienić status rezerwacji?';
            let message = 'Wybrana operacja: „' + targetLabel + '”.';
            let confirmLabel = targetLabel;
            let danger = false;

            if (target === 'anulowana') {
                title = 'Anulować rezerwację?';
                message = 'Rezerwacja pozostanie w historii jako anulowana i nie zostanie usunięta.';
                confirmLabel = 'Anuluj rezerwację';
                danger = true;
            } else if (target === 'oczekujaca') {
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

            clearError();
            busy = true;
            button.disabled = true;
            const submitLabel = button.textContent;
            let completed = false;
            button.textContent = 'Zapisywanie…';

            try {
                const result = await sendStatus(id, target);

                if (result.response.status === 401) {
                    window.location.href = loginUrl;
                    return;
                }

                if (result.response.status === 403) {
                    showError(
                        typeof result.data.error === 'string' && result.data.error !== ''
                            ? result.data.error
                            : 'Brak uprawnień do zmiany statusu rezerwacji.'
                    );
                    return;
                }

                if (result.response.status === 404) {
                    showError('Ta rezerwacja nie istnieje.');
                    return;
                }

                if (result.response.status === 422) {
                    const statusError = result.data.errors && typeof result.data.errors.status === 'string'
                        ? result.data.errors.status
                        : '';
                    showError(
                        statusError !== ''
                            ? statusError
                            : (typeof result.data.error === 'string' && result.data.error !== ''
                                ? result.data.error
                                : 'Nie można zmienić statusu rezerwacji.')
                    );
                    return;
                }

                if (!result.response.ok || result.data.success !== true) {
                    showError('Nie udało się zmienić statusu rezerwacji. Wystąpił problem z serwerem.');
                    return;
                }

                completed = true;
                let toastMessage = 'Status rezerwacji został zmieniony.';

                if (target === 'anulowana') {
                    toastMessage = 'Rezerwacja została anulowana.';
                } else if (target === 'oczekujaca') {
                    toastMessage = 'Anulowanie rezerwacji zostało cofnięte.';
                }

                window.showToast(toastMessage, 'success');
                window.setTimeout(function () {
                    window.location.reload();
                }, 600);
            } catch {
                showError('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
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
