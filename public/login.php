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
        <div class="mb-6 flex flex-col items-center text-center">
            <span class="mb-4 flex h-11 w-11 items-center justify-center rounded-xl bg-zinc-900 text-white shadow-sm dark:bg-zinc-50 dark:text-zinc-900">
                <?= layout_logo('h-6 w-6') ?>
            </span>
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">WeFixIT</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight">Witaj ponownie</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Zaloguj się, aby przejść do panelu.</p>
        </div>

        <form id="login-form" action="api/auth/login" method="post" class="space-y-4">
            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">E-mail</label>
                <input id="email" name="email" type="email" autocomplete="username" maxlength="254" required
                    class="<?= LAYOUT_INPUT_CLASS ?>">
            </div>

            <div>
                <label for="password" class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Hasło</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required
                    class="<?= LAYOUT_INPUT_CLASS ?>">
            </div>

            <p id="login-error" role="alert" class="hidden text-sm text-red-600 dark:text-red-400"></p>

            <button type="submit" class="<?= LAYOUT_BUTTON_CLASS ?>">
                Zaloguj się
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
            Nie masz konta?
            <a href="register" class="font-medium text-zinc-900 underline-offset-4 hover:underline dark:text-zinc-100">Załóż konto</a>
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
