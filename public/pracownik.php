<?php
declare(strict_types=1);

/*
    Zalążek panelu pracownika. Dostęp dla ról pracownik i admin.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';

$user = require_role(['pracownik', 'admin']);

panel_layout_head($user, 'Panel pracownika — WeFixIT', $user['rola'] === 'admin' ? 'employee_panel' : 'home');
?>
    <section class="<?= PANEL_CARD_CLASS ?>">
        <?php panel_section_header('Panel pracownika', 'Widok roboczy dla pracowników serwisu.'); ?>

        <div class="rounded-lg border border-dashed border-zinc-200 bg-zinc-50/60 p-6 text-center dark:border-zinc-800 dark:bg-zinc-950/50">
            <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300">
                <?= panel_icon('briefcase', 'h-5 w-5') ?>
            </span>
            <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-400">
                Ta strona jest dostępna dla pracowników i administratorów.
            </p>
        </div>
    </section>
<?php panel_layout_foot(); ?>
