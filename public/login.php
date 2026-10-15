<?php
declare(strict_types=1);

/*
    Formularz logowania. Renderuje token CSRF i wysyła go w nagłówku X-CSRF-Token.
    Zalogowanego użytkownika przekierowuje na panel właściwy dla jego roli.
*/
require_once dirname(__DIR__) . '/app/layout.php';

$user = current_user();

if ($user !== null) {
    header('Location: ' . auth_base_path() . '/' . auth_role_home_path($user['rola']));
    exit;
}

$csrfToken = csrf_token();

layout_head('Logowanie — WeFixIT');
?>
    <main class="<?= LAYOUT_CARD_CLASS ?>">
        <h1 class="mb-6 text-center text-2xl font-semibold">Logowanie do WeFixIT</h1>

        <form id="login-form" action="api/auth/login" method="post" class="space-y-4">
            <div>
                <label for="email" class="mb-1 block text-sm font-medium">E-mail</label>
                <input id="email" name="email" type="email" autocomplete="username" maxlength="254" required
                    class="<?= LAYOUT_INPUT_CLASS ?>">
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium">Hasło</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required
                    class="<?= LAYOUT_INPUT_CLASS ?>">
            </div>

            <p id="login-error" role="alert" class="hidden text-sm text-red-600 dark:text-red-400"></p>

            <button type="submit"
                class="w-full rounded bg-blue-600 px-4 py-2 font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60 dark:focus:ring-offset-gray-800">
                Zaloguj się
            </button>
        </form>

        <p class="mt-4 text-center text-sm text-gray-600 dark:text-gray-400">
            Nie masz konta?
            <a href="register" class="text-blue-600 underline hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">Załóż konto</a>
        </p>

        <noscript>
            <p class="mt-4 text-sm text-red-600 dark:text-red-400">Włącz JavaScript, aby się zalogować.</p>
        </noscript>
    </main>

    <script>
        const form = document.getElementById('login-form');
        const error = document.getElementById('login-error');
        const button = form.querySelector('button[type="submit"]');
        const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const rolePaths = <?= json_encode([
            'klient' => auth_role_home_path('klient'),
            'pracownik' => auth_role_home_path('pracownik'),
            'admin' => auth_role_home_path('admin'),
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

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
    </script>
<?php layout_foot(); ?>
