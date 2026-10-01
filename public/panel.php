<?php
require_once dirname(__DIR__) . '/app/session.php';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel — WeFixIT</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="flex min-h-screen items-center justify-center bg-gray-100 p-4 text-gray-900">
    <main class="w-full max-w-sm rounded-lg bg-white p-6 shadow-sm">
        <h1 class="mb-4 text-2xl font-semibold">Panel użytkownika</h1>
        <p>Jesteś zalogowany. Ta strona jest dostępna tylko po zalogowaniu.</p>
        <p class="mt-4 text-sm">Identyfikator użytkownika: <?= $userId ?></p>

        <form id="logout-form" action="api/auth/logout" method="post" class="mt-4">
            <p id="logout-error" role="alert" class="mb-4 hidden text-sm text-red-600"></p>
            <button type="submit"
                class="w-full rounded bg-blue-600 px-4 py-2 font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60">
                Wyloguj się
            </button>
        </form>
    </main>

    <script>
        const form = document.getElementById('logout-form');
        const error = document.getElementById('logout-error');
        const button = form.querySelector('button[type="submit"]');

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (button.disabled) return;

            error.textContent = '';
            error.classList.add('hidden');
            button.disabled = true;
            button.textContent = 'Wylogowywanie…';

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin'
                });
                const data = await response.json();

                if (!response.ok || data.success !== true) {
                    error.textContent = data.error || 'Nie udało się wylogować.';
                    error.classList.remove('hidden');
                    return;
                }

                window.location.href = 'login';
            } catch {
                error.textContent = 'Nie udało się połączyć z serwerem. Spróbuj ponownie.';
                error.classList.remove('hidden');
            } finally {
                button.disabled = false;
                button.textContent = 'Wyloguj się';
            }
        });
    </script>
</body>
</html>
