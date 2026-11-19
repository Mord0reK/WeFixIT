/*
    Wylogowanie ze strony głównej.

    Endpoint zmienia stan, więc wysyłamy POST z tokenem CSRF zapisanym w mecie.
    Po udanym wylogowaniu wracamy na stronę główną wskazaną przez formularz.
*/
(function () {
    const form = document.getElementById('home-logout-form');

    if (!form) return;

    const meta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = meta ? meta.content : '';
    const button = form.querySelector('button[type="submit"]');
    const homeUrl = form.dataset.homeUrl || '';

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (button.disabled) return;

        button.disabled = true;
        button.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': csrfToken }
            });
            const data = await response.json();

            if (!response.ok || data.success !== true) {
                window.showToast(data.error || 'Nie udało się wylogować.', 'error');
                return;
            }

            window.location.href = homeUrl;
        } catch {
            window.showToast('Nie udało się połączyć z serwerem. Spróbuj ponownie.', 'error');
        } finally {
            button.disabled = false;
            button.removeAttribute('aria-busy');
        }
    });
})();
