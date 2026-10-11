<?php
declare(strict_types=1);

/*
    Formularz rejestracji. Renderuje token CSRF i wysyła go w nagłówku X-CSRF-Token.
*/
require_once dirname(__DIR__) . '/app/csrf.php';

header('Cache-Control: no-store');

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rejestracja — WeFixIT</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="flex min-h-screen items-center justify-center bg-gray-100 p-4 text-gray-900">
    <main class="w-full max-w-sm rounded-lg bg-white p-6 shadow-sm">
        <h1 class="mb-6 text-center text-2xl font-semibold">Rejestracja w WeFixIT</h1>

        <form id="register-form" action="api/auth/register" method="post" class="space-y-4">
            <div>
                <label for="imie" class="mb-1 block text-sm font-medium">Imię</label>
                <input id="imie" name="imie" type="text" autocomplete="given-name" maxlength="100" required
                    class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600">
                <p id="register-error-imie" class="mt-1 hidden text-sm text-red-600"></p>
            </div>

            <div>
                <label for="nazwisko" class="mb-1 block text-sm font-medium">Nazwisko</label>
                <input id="nazwisko" name="nazwisko" type="text" autocomplete="family-name" maxlength="100" required
                    class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600">
                <p id="register-error-nazwisko" class="mt-1 hidden text-sm text-red-600"></p>
            </div>

            <div>
                <label for="email" class="mb-1 block text-sm font-medium">E-mail</label>
                <input id="email" name="email" type="email" autocomplete="username" maxlength="254" required
                    class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600">
                <p id="register-error-email" class="mt-1 hidden text-sm text-red-600"></p>
            </div>

            <div>
                <label for="telefon" class="mb-1 block text-sm font-medium">Telefon</label>
                <input id="telefon" name="telefon" type="tel" autocomplete="tel" maxlength="20" required
                    class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600">
                <p id="register-error-telefon" class="mt-1 hidden text-sm text-red-600"></p>
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium">Hasło</label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required
                    class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600">
                <p class="mt-1 text-sm text-gray-600">Minimum 8 znaków.</p>
                <p id="register-error-password" class="mt-1 hidden text-sm text-red-600"></p>
            </div>

            <div>
                <label for="password_powtorzenie" class="mb-1 block text-sm font-medium">Powtórz hasło</label>
                <input id="password_powtorzenie" name="password_powtorzenie" type="password"
                    autocomplete="new-password" minlength="8" required
                    class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600">
                <p id="register-error-password_powtorzenie" class="mt-1 hidden text-sm text-red-600"></p>
            </div>

            <p id="register-error" role="alert" class="hidden text-sm text-red-600"></p>

            <button type="submit"
                class="w-full rounded bg-blue-600 px-4 py-2 font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60">
                Załóż konto
            </button>
        </form>

        <p class="mt-4 text-center text-sm text-gray-600">
            Masz już konto?
            <a href="login" class="text-blue-600 underline hover:text-blue-700">Zaloguj się</a>
        </p>

        <noscript>
            <p class="mt-4 text-sm text-red-600">Włącz JavaScript, aby założyć konto.</p>
        </noscript>
    </main>

    <script>
        const form = document.getElementById('register-form');
        const error = document.getElementById('register-error');
        const button = form.querySelector('button[type="submit"]');
        const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
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
    </script>
</body>
</html>