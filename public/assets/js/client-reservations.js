/*
    Anulowanie rezerwacji klienta przez fetch().

    Adres API i login pochodzą z atrybutów sekcji, dzięki czemu skrypt działa
    również w instalacji w podkatalogu. Potwierdzenie zapewnia panelConfirm,
    a po sukcesie strona jest przeładowywana, żeby lista pokazała nowy status.
*/
(function () {
    const section = document.getElementById('client-reservations');

    if (!section) return;

    const apiUrl = section.dataset.api || '';
    const loginUrl = section.dataset.loginUrl || '/login';
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const generalError = document.getElementById('client-reservations-error');
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

    async function cancelReservation(id) {
        const response = await fetch(apiUrl, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ id: id })
        });
        let data = {};

        try {
            data = await response.json();
        } catch {
            data = {};
        }

        return { response: response, data: data };
    }

    document.querySelectorAll('[data-client-cancel]').forEach(function (button) {
        button.addEventListener('click', async function () {
            if (busy) return;

            const id = Number(button.dataset.id);

            if (!Number.isInteger(id) || id <= 0) return;

            const label = button.dataset.label || 'tę rezerwację';
            const confirmed = await window.panelConfirm({
                title: 'Anulować rezerwację?',
                message: 'Rezerwacja „' + label + '” zostanie anulowana. Pozostanie w historii jako anulowana.',
                confirmLabel: 'Anuluj rezerwację',
                danger: true
            });

            if (!confirmed) return;

            clearError();
            busy = true;
            button.disabled = true;
            const submitLabel = button.textContent;
            let completed = false;
            button.textContent = 'Anulowanie…';

            try {
                const result = await cancelReservation(id);

                if (result.response.status === 401) {
                    window.location.href = loginUrl;
                    return;
                }

                if (result.response.status === 403) {
                    showError(
                        typeof result.data.error === 'string' && result.data.error !== ''
                            ? result.data.error
                            : 'Brak uprawnień do anulowania tej rezerwacji.'
                    );
                    return;
                }

                if (result.response.status === 404) {
                    showError('Ta rezerwacja nie istnieje.');
                    return;
                }

                if (result.response.status === 409) {
                    window.showToast(
                        typeof result.data.error === 'string' && result.data.error !== ''
                            ? result.data.error
                            : 'Tej rezerwacji nie można już anulować.',
                        'error'
                    );
                    return;
                }

                if (!result.response.ok || result.data.success !== true) {
                    showError('Nie udało się anulować rezerwacji. Wystąpił problem z serwerem.');
                    return;
                }

                completed = true;
                window.showToast('Rezerwacja została anulowana.', 'success');
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
