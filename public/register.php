<?php
declare(strict_types=1);

/*
    Formularz rejestracji. Renderuje token CSRF i wysyła go w nagłówku X-CSRF-Token.
    Zalogowanego użytkownika przekierowuje na panel właściwy dla jego roli.
*/
require_once dirname(__DIR__) . '/app/layout.php';

$user = current_user();

if ($user !== null) {
    header('Location: ' . auth_base_path() . '/' . auth_role_home_path($user['rola']));
    exit;
}

$csrfToken = csrf_token();

layout_head('Rejestracja — WeFixIT');
?>
    <main class="<?= LAYOUT_CARD_CLASS ?>">
        <div class="mb-6 flex flex-col items-center text-center">
            <span class="mb-4 flex h-11 w-11 items-center justify-center rounded-xl bg-zinc-900 text-white shadow-sm dark:bg-zinc-50 dark:text-zinc-900">
                <?= layout_logo('h-6 w-6') ?>
            </span>
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">WeFixIT</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight">Utwórz konto</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Dołącz do serwisu i zarządzaj rezerwacjami.</p>
        </div>

        <form id="register-form" action="api/auth/register" method="post" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="imie" class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Imię</label>
                    <input id="imie" name="imie" type="text" autocomplete="given-name" maxlength="100" required
                        class="<?= LAYOUT_INPUT_CLASS ?>">
                    <p id="register-error-imie" class="mt-1.5 hidden text-sm text-red-600 dark:text-red-400"></p>
                </div>

                <div>
                    <label for="nazwisko" class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nazwisko</label>
                    <input id="nazwisko" name="nazwisko" type="text" autocomplete="family-name" maxlength="100" required
                        class="<?= LAYOUT_INPUT_CLASS ?>">
                    <p id="register-error-nazwisko" class="mt-1.5 hidden text-sm text-red-600 dark:text-red-400"></p>
                </div>
            </div>

            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">E-mail</label>
                <input id="email" name="email" type="email" autocomplete="username" maxlength="254" required
                    class="<?= LAYOUT_INPUT_CLASS ?>">
                <p id="register-error-email" class="mt-1.5 hidden text-sm text-red-600 dark:text-red-400"></p>
            </div>

            <div>
                <label for="telefon" class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Telefon</label>
                <input id="telefon" name="telefon" type="tel" autocomplete="tel" maxlength="20" required
                    class="<?= LAYOUT_INPUT_CLASS ?>">
                <p id="register-error-telefon" class="mt-1.5 hidden text-sm text-red-600 dark:text-red-400"></p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="password" class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Hasło</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required
                        class="<?= LAYOUT_INPUT_CLASS ?>">
                    <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">Minimum 8 znaków.</p>
                    <p id="register-error-password" class="mt-1.5 hidden text-sm text-red-600 dark:text-red-400"></p>
                </div>

                <div>
                    <label for="password_powtorzenie" class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Powtórz hasło</label>
                    <input id="password_powtorzenie" name="password_powtorzenie" type="password"
                        autocomplete="new-password" minlength="8" required
                        class="<?= LAYOUT_INPUT_CLASS ?>">
                    <p id="register-error-password_powtorzenie" class="mt-1.5 hidden text-sm text-red-600 dark:text-red-400"></p>
                </div>
            </div>

            <p id="register-error" role="alert" class="hidden text-sm text-red-600 dark:text-red-400"></p>

            <button type="submit" class="<?= LAYOUT_BUTTON_CLASS ?>">
                Załóż konto
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
            Masz już konto?
            <a href="login" class="font-medium text-zinc-900 underline-offset-4 hover:underline dark:text-zinc-100">Zaloguj się</a>
        </p>

        <noscript>
            <p class="mt-4 text-sm text-red-600 dark:text-red-400">Włącz JavaScript, aby założyć konto.</p>
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
<?php layout_foot(); ?>
