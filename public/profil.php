<?php
declare(strict_types=1);

/*
    Widok profilu zalogowanego użytkownika.

    Strona chroniona — bez sesji przekierowuje na /login. Formularz edycji
    czterech pól wysyła PATCH na /api/profile przez fetch() i aktualizuje
    wartości bez przeładowania strony. Wszystkie dane są escapowane.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';

$user = require_login();
$csrfToken = csrf_token();
$basePath = auth_base_path();
$profileApi = $basePath . '/api/profile';

panel_layout_head($user, 'Profil — WeFixIT', 'profil');
?>
    <section class="<?= PANEL_CARD_CLASS ?>">
        <?php panel_section_header('Mój profil', 'Edytuj dane kontaktowe swojego konta.'); ?>

        <div class="mb-6 flex items-center gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-950/50">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-sm font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                <?= htmlspecialchars(panel_user_initials($user), ENT_QUOTES, 'UTF-8') ?>
            </span>
            <div class="min-w-0">
                <p class="truncate text-sm font-medium">
                    <?= htmlspecialchars(trim($user['imie'] . ' ' . $user['nazwisko']), ENT_QUOTES, 'UTF-8') ?>
                </p>
                <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                    Rola: <?= htmlspecialchars(auth_role_label($user['rola']), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>
        </div>

        <form id="profile-form" action="<?= htmlspecialchars($profileApi, ENT_QUOTES, 'UTF-8') ?>" method="post" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="imie" class="<?= LAYOUT_LABEL_CLASS ?>">Imię</label>
                    <input id="imie" name="imie" type="text" autocomplete="given-name" maxlength="100" required
                        value="<?= htmlspecialchars($user['imie'], ENT_QUOTES, 'UTF-8') ?>"
                        class="<?= LAYOUT_INPUT_CLASS ?>">
                    <?= panel_field_error('profile-error-imie') ?>
                </div>

                <div>
                    <label for="nazwisko" class="<?= LAYOUT_LABEL_CLASS ?>">Nazwisko</label>
                    <input id="nazwisko" name="nazwisko" type="text" autocomplete="family-name" maxlength="100" required
                        value="<?= htmlspecialchars($user['nazwisko'], ENT_QUOTES, 'UTF-8') ?>"
                        class="<?= LAYOUT_INPUT_CLASS ?>">
                    <?= panel_field_error('profile-error-nazwisko') ?>
                </div>
            </div>

            <div>
                <label for="email" class="<?= LAYOUT_LABEL_CLASS ?>">E-mail</label>
                <input id="email" name="email" type="email" autocomplete="username" maxlength="254" required
                    value="<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>"
                    class="<?= LAYOUT_INPUT_CLASS ?>">
                <?= panel_field_error('profile-error-email') ?>
            </div>

            <div>
                <label for="telefon" class="<?= LAYOUT_LABEL_CLASS ?>">Telefon</label>
                <input id="telefon" name="telefon" type="tel" autocomplete="tel" maxlength="20" required
                    value="<?= htmlspecialchars($user['telefon'], ENT_QUOTES, 'UTF-8') ?>"
                    class="<?= LAYOUT_INPUT_CLASS ?>">
                <?= panel_field_error('profile-error-telefon') ?>
            </div>

            <p id="profile-error" role="alert" class="hidden text-sm text-red-600 dark:text-red-400"></p>

            <button type="submit" class="<?= panel_button_classes('primary') ?>">
                Zapisz zmiany
            </button>
        </form>

        <noscript>
            <p class="mt-4 text-sm text-red-600 dark:text-red-400">Włącz JavaScript, aby edytować profil.</p>
        </noscript>
    </section>

    <script>
        const form = document.getElementById('profile-form');
        const error = document.getElementById('profile-error');
        const button = form.querySelector('button[type="submit"]');
        const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const fields = ['imie', 'nazwisko', 'email', 'telefon'];

        const fieldMessages = Object.fromEntries(
            fields.map((field) => [field, document.getElementById('profile-error-' + field)])
        );

        function clearMessages() {
            error.textContent = '';
            error.classList.add('hidden');

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

                showToast('Profil został zapisany.', 'success');
            } catch {
                error.textContent = 'Nie udało się połączyć z serwerem. Spróbuj ponownie.';
                error.classList.remove('hidden');
            } finally {
                button.disabled = false;
                button.textContent = 'Zapisz zmiany';
            }
        });
    </script>
<?php panel_layout_foot(); ?>
