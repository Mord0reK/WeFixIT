<?php
declare(strict_types=1);

/*
    Widok profilu zalogowanego użytkownika.

    Strona chroniona — bez sesji przekierowuje na /login. Formularz edycji
    czterech pól wysyła PATCH na /api/profile przez fetch() i aktualizuje
    wartości bez przeładowania strony. Wszystkie dane są escapowane.
*/
require_once dirname(__DIR__) . '/app/auth.php';

$user = require_login();
$csrfToken = csrf_token();
$basePath = auth_base_path();
$homePath = $basePath . '/' . auth_role_home_path($user['rola']);
$profileApi = $basePath . '/api/profile';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil — WeFixIT</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="flex min-h-screen items-center justify-center bg-gray-100 p-4 text-gray-900">
    <main class="w-full max-w-sm rounded-lg bg-white p-6 shadow-sm">
        <h1 class="mb-6 text-center text-2xl font-semibold">Mój profil</h1>

        <p class="mb-4 text-sm text-gray-600">
            Rola: <?= htmlspecialchars($user['rola'], ENT_QUOTES, 'UTF-8') ?>
        </p>

        <form id="profile-form" action="<?= htmlspecialchars($profileApi, ENT_QUOTES, 'UTF-8') ?>" method="post" class="space-y-4">
            <div>
                <label for="imie" class="mb-1 block text-sm font-medium">Imię</label>
                <input id="imie" name="imie" type="text" autocomplete="given-name" maxlength="100" required
                    value="<?= htmlspecialchars($user['imie'], ENT_QUOTES, 'UTF-8') ?>"
                    class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600">
                <p id="profile-error-imie" class="mt-1 hidden text-sm text-red-600"></p>
            </div>

            <div>
                <label for="nazwisko" class="mb-1 block text-sm font-medium">Nazwisko</label>
                <input id="nazwisko" name="nazwisko" type="text" autocomplete="family-name" maxlength="100" required
                    value="<?= htmlspecialchars($user['nazwisko'], ENT_QUOTES, 'UTF-8') ?>"
                    class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600">
                <p id="profile-error-nazwisko" class="mt-1 hidden text-sm text-red-600"></p>
            </div>

            <div>
                <label for="email" class="mb-1 block text-sm font-medium">E-mail</label>
                <input id="email" name="email" type="email" autocomplete="username" maxlength="254" required
                    value="<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>"
                    class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600">
                <p id="profile-error-email" class="mt-1 hidden text-sm text-red-600"></p>
            </div>

            <div>
                <label for="telefon" class="mb-1 block text-sm font-medium">Telefon</label>
                <input id="telefon" name="telefon" type="tel" autocomplete="tel" maxlength="20" required
                    value="<?= htmlspecialchars($user['telefon'], ENT_QUOTES, 'UTF-8') ?>"
                    class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600">
                <p id="profile-error-telefon" class="mt-1 hidden text-sm text-red-600"></p>
            </div>

            <p id="profile-error" role="alert" class="hidden text-sm text-red-600"></p>
            <p id="profile-success" role="status" class="hidden text-sm text-gray-600"></p>

            <button type="submit"
                class="w-full rounded bg-blue-600 px-4 py-2 font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60">
                Zapisz zmiany
            </button>
        </form>

        <p class="mt-4 text-center text-sm text-gray-600">
            <a href="<?= htmlspecialchars($homePath, ENT_QUOTES, 'UTF-8') ?>" class="text-blue-600 underline hover:text-blue-700">Wróć do panelu</a>
        </p>

        <noscript>
            <p class="mt-4 text-sm text-red-600">Włącz JavaScript, aby edytować profil.</p>
        </noscript>
    </main>

    <script>
        const form = document.getElementById('profile-form');
        const error = document.getElementById('profile-error');
        const success = document.getElementById('profile-success');
        const button = form.querySelector('button[type="submit"]');
        const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const fields = ['imie', 'nazwisko', 'email', 'telefon'];

        const fieldMessages = Object.fromEntries(
            fields.map((field) => [field, document.getElementById('profile-error-' + field)])
        );

        function clearMessages() {
            error.textContent = '';
            error.classList.add('hidden');
            success.textContent = '';
            success.classList.add('hidden');

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

                success.textContent = 'Profil został zapisany.';
                success.classList.remove('hidden');
            } catch {
                error.textContent = 'Nie udało się połączyć z serwerem. Spróbuj ponownie.';
                error.classList.remove('hidden');
            } finally {
                button.disabled = false;
                button.textContent = 'Zapisz zmiany';
            }
        });
    </script>
</body>
</html>
