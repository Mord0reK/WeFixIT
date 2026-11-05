/*
    Edycja profilu przez fetch().

    Po zapisie aktualizujemy pola formularza danymi z odpowiedzi serwera,
    żeby wyświetlały wartości po trimowaniu i normalizacji.
*/
(function () {
    const form = document.getElementById('profile-form');

    if (!form) return;

    const error = document.getElementById('profile-error');
    const button = form.querySelector('button[type="submit"]');
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const fields = ['imie', 'nazwisko', 'email', 'telefon'];

    const fieldMessages = Object.fromEntries(
        fields.map((field) => [field, document.getElementById('profile-error-' + field)])
    );

    function clearMessages() {
        error.textContent = '';
        error.classList.add('hidden');

        for (const message of Object.values(fieldMessages)) {
            message.textContent = '';
            message.classList.add('hidden');
        }
    }

    function showErrors(data) {
        let shownFieldError = false;

        for (const [field, message] of Object.entries(data.errors || {})) {
            if (!fieldMessages[field]) continue;

            fieldMessages[field].textContent = message;
            fieldMessages[field].classList.remove('hidden');
            shownFieldError = true;
        }

        if (shownFieldError) return;

        error.textContent = data.error || 'Nie udało się zapisać profilu.';
        error.classList.remove('hidden');
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (button.disabled) return;

        clearMessages();
        button.disabled = true;
        button.textContent = 'Zapisywanie…';

        try {
            const response = await fetch(form.action, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({
                    imie: form.elements.imie.value.trim(),
                    nazwisko: form.elements.nazwisko.value.trim(),
                    email: form.elements.email.value.trim(),
                    telefon: form.elements.telefon.value.trim()
                })
            });
            const data = await response.json();

            if (!response.ok || data.success !== true) {
                showErrors(data);
                return;
            }

            const updated = data.user || {};

            for (const field of fields) {
                if (typeof updated[field] === 'string') {
                    form.elements[field].value = updated[field];
                }
            }

            window.showToast('Profil został zapisany.', 'success');
        } catch {
            error.textContent = 'Nie udało się połączyć z serwerem. Spróbuj ponownie.';
            error.classList.remove('hidden');
        } finally {
            button.disabled = false;
            button.textContent = 'Zapisz zmiany';
        }
    });
})();
