<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logowanie — WeFixIT</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="flex min-h-screen items-center justify-center bg-gray-100 p-4 text-gray-900">
    <main class="w-full max-w-sm rounded-lg bg-white p-6 shadow-sm">
        <h1 class="mb-6 text-center text-2xl font-semibold">Logowanie do WeFixIT</h1>

        <form id="login-form" action="api/auth/login" method="post" class="space-y-4">
            <div>
                <label for="email" class="mb-1 block text-sm font-medium">E-mail</label>
                <input id="email" name="email" type="email" autocomplete="username" maxlength="254" required
                    class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600">
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium">Hasło</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required
                    class="w-full rounded border border-gray-300 px-3 py-2 focus:border-blue-600 focus:outline-none focus:ring-1 focus:ring-blue-600">
            </div>

            <p id="login-error" role="alert" class="hidden text-sm text-red-600"></p>

            <button type="submit"
                class="w-full rounded bg-blue-600 px-4 py-2 font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60">
                Zaloguj się
            </button>
        </form>

        <noscript>
            <p class="mt-4 text-sm text-red-600">Włącz JavaScript, aby się zalogować.</p>
        </noscript>
    </main>

    <script>
        const form = document.getElementById('login-form');
        const error = document.getElementById('login-error');
        const button = form.querySelector('button[type="submit"]');

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
                    headers: { 'Content-Type': 'application/json' },
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

                window.location.href = 'panel';
            } catch {
                error.textContent = 'Nie udało się połączyć z serwerem. Spróbuj ponownie.';
                error.classList.remove('hidden');
            } finally {
                button.disabled = false;
                button.textContent = 'Zaloguj się';
            }
        });
    </script>
</body>
</html>
