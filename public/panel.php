<?php
declare(strict_types=1);

/*
    Panel klienta. Strona chroniona — dane użytkownika pochodzą z weryfikacji w bazie.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';

$user = require_login();

panel_layout_head($user, 'Panel — WeFixIT', $user['rola'] === 'admin' ? 'client_panel' : 'home');
?>
    <section class="<?= PANEL_CARD_CLASS ?>">
        <?php panel_section_header('Panel klienta', 'Twoje konto w serwisie WeFixIT.'); ?>

        <div class="rounded-lg border border-dashed border-zinc-200 bg-zinc-50/60 p-6 text-center dark:border-zinc-800 dark:bg-zinc-950/50">
            <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300">
                <?= panel_icon('user', 'h-5 w-5') ?>
            </span>
            <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-400">
                Jesteś zalogowany. Ta strona jest dostępna tylko po zalogowaniu.
            </p>
        </div>
    </section>
<?php panel_layout_foot(); ?>
