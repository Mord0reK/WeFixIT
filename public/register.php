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

<?php
layout_script('register.js');
layout_foot();
?>
