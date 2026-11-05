/*
    Rejestracja konta klienta przez fetch().

    Błędy walidacji z serwera trafiają do komunikatów konkretnych pól,
    a pozostałe błędy do wspólnego komunikatu formularza.
*/
(function () {
    const form = document.getElementById('register-form');

    if (!form) return;

    const error = document.getElementById('register-error');
    const button = form.querySelector('button[type="submit"]');
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const fields = ['imie', 'nazwisko', 'email', 'telefon', 'password', 'password_powtorzenie'];

    const fieldMessages = Object.fromEntries(
        fields.map((field) => [field, document.getElementById('register-error-' + field)])
    );

    function clearErrors() {
        error.textContent = '';
        error.classList.add('hidden');

        for (const message of Object.values(fieldMessages)) {
            message.textContent = '';
            message.classList.add('hidden');
        }
    }

    function showErrors(data) {
        let shownFieldError = false;

        for (const [field, message] of data.errors || {}) {
            if (!fieldMessages[field]) continue;

            fieldMessages[field].textContent = message;
            fieldMessages[field].classList.remove('hidden');
            shownFieldError = true;
        }

        if (shownFieldError) return;

        error.textContent = data.error || 'Nie udało się założyć konta.';
        error.classList.remove('hidden');
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (button.disabled) return;

        clearErrors();
        button.disabled = true;
        button.textContent = 'Rejestrowanie…';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({
                    imie: form.elements.imie.value.trim(),
                    nazwisko: form.elements.nazwisko.value.trim(),
                    email: form.elements.email.value.trim(),
                    telefon: form.elements.telefon.value.trim(),
                    password: form.elements.password.value,
                    password_powtorzenie: form.elements.password_powtorzenie.value
                })
            });
            const data = await response.json();

            if (!response.ok || data.success !== true) {
                showErrors(data);
                return;
            }

            window.location.href = 'panel';
        } catch {
            error.textContent = 'Nie udało się połączyć z serwerem. Spróbuj ponownie.';
            error.classList.remove('hidden');
        } finally {
            button.disabled = false;
            button.textContent = 'Załóż konto';
        }
    });
})();
