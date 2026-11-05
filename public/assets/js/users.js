/*
    Administracja kontami: zmiana roli oraz aktywacja i dezaktywacja.

    Adres API pochodzi z data-api sekcji strony, dzięki czemu działa również
    w instalacji w podkatalogu. Operacje wymagające potwierdzenia korzystają
    z window.panelConfirm.
*/
(function () {
    const page = document.getElementById('users-page');

    if (!page) return;

    const usersApi = page.dataset.api || '';
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const generalError = document.getElementById('users-error');
    let busy = false;

    function clearMessages() {
        if (generalError) {
            generalError.textContent = '';
            generalError.classList.add('hidden');
        }

        document.querySelectorAll('[id^="user-error-"]').forEach(function (element) {
            element.textContent = '';
            element.classList.add('hidden');
        });
    }

    function showGeneralError(message) {
        if (!generalError) return;

        generalError.textContent = message;
        generalError.classList.remove('hidden');
    }

    function showRowErrors(id, data) {
        let shown = false;
        const roleError = document.getElementById('user-error-rola-' + id);
        const activeError = document.getElementById('user-error-aktywny-' + id);
        const roleMessage = data.errors && typeof data.errors.rola === 'string' ? data.errors.rola : '';
        const activeMessage = data.errors && typeof data.errors.aktywny === 'string' ? data.errors.aktywny : '';

        if (roleError && roleMessage !== '') {
            roleError.textContent = roleMessage;
            roleError.classList.remove('hidden');
            shown = true;
        }

        if (activeError && activeMessage !== '') {
            activeError.textContent = activeMessage;
            activeError.classList.remove('hidden');
            shown = true;
        }

        if (!shown) {
            showGeneralError(typeof data.error === 'string' && data.error !== ''
                ? data.error
                : 'Nie udało się zapisać konta użytkownika.');
        }
    }

    async function send(payload) {
        const response = await fetch(usersApi, {
            method: 'PATCH',
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

    document.querySelectorAll('[data-user-role-form]').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (busy) return;

            const id = Number(form.dataset.id);
            const button = form.querySelector('button[type="submit"]');
            clearMessages();
            busy = true;
            if (button) button.disabled = true;
            let completed = false;

            try {
                const result = await send({ id: id, rola: form.elements.rola.value });

                if (!result.response.ok || result.data.success !== true) {
                    showRowErrors(id, result.data);
                    return;
                }

                completed = true;
                window.showToast('Rola użytkownika została zmieniona.', 'success');
                window.setTimeout(function () {
                    window.location.reload();
                }, 600);
            } catch {
                showGeneralError('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
            } finally {
                if (!completed) {
                    busy = false;
                    if (button) button.disabled = false;
                }
            }
        });
    });

    document.querySelectorAll('[data-user-toggle]').forEach(function (button) {
        button.addEventListener('click', async function () {
            if (busy) return;

            const id = Number(button.dataset.id);
            const active = button.dataset.active === '1';
            const name = button.dataset.name || '';
            const confirmed = await window.panelConfirm({
                title: active ? 'Dezaktywuj konto' : 'Aktywuj konto',
                message: active
                    ? 'Czy dezaktywować konto ' + name + '? Użytkownik nie będzie mógł się zalogować.'
                    : 'Czy aktywować konto ' + name + '?',
                confirmLabel: active ? 'Dezaktywuj' : 'Aktywuj',
                danger: active
            });

            if (!confirmed) return;

            clearMessages();
            busy = true;
            button.disabled = true;
            let completed = false;

            try {
                const result = await send({ id: id, aktywny: !active });

                if (!result.response.ok || result.data.success !== true) {
                    showRowErrors(id, result.data);
                    return;
                }

                completed = true;
                window.showToast(
                    active ? 'Konto zostało dezaktywowane.' : 'Konto zostało aktywowane.',
                    'success'
                );
                window.setTimeout(function () {
                    window.location.reload();
                }, 600);
            } catch {
                showGeneralError('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
            } finally {
                if (!completed) {
                    busy = false;
                    button.disabled = false;
                }
            }
        });
    });
})();
