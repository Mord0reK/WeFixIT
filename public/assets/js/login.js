/*
    Logowanie przez fetch().

    Formularz przekazuje w data-role-paths adresy paneli dla poszczególnych
    ról, żeby po odpowiedzi serwera przekierować użytkownika bez odwołań do
    katalogu głównego domeny.
*/
(function () {
    const form = document.getElementById('login-form');

    if (!form) return;

    const error = document.getElementById('login-error');
    const button = form.querySelector('button[type="submit"]');
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    let rolePaths = {};

    try {
        rolePaths = JSON.parse(form.dataset.rolePaths || '{}') || {};
    } catch {
        rolePaths = {};
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (button.disabled) return;

        error.textContent = '';
        error.classList.add('hidden');
        button.disabled = true;
        button.textContent = 'Logowanie…';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({
                    email: form.elements.email.value.trim(),
                    password: form.elements.password.value
                })
            });
            const data = await response.json();

            if (!response.ok || data.success !== true) {
                error.textContent = data.error || 'Nie udało się zalogować.';
                error.classList.remove('hidden');
                return;
            }

            const target = rolePaths[data.rola];

            if (typeof target !== 'string') {
                error.textContent = 'Nie udało się zalogować.';
                error.classList.remove('hidden');
                return;
            }

            window.location.href = target;
        } catch {
            error.textContent = 'Nie udało się połączyć z serwerem. Spróbuj ponownie.';
            error.classList.remove('hidden');
        } finally {
            button.disabled = false;
            button.textContent = 'Zaloguj się';
        }
    });
})();
