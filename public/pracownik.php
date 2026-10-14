<?php
declare(strict_types=1);

/*
    Zalążek panelu pracownika. Dostęp dla ról pracownik i admin.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';

$user = require_role(['pracownik', 'admin']);

panel_layout_head($user, 'Panel pracownika — WeFixIT', 'home');
?>
    <section class="<?= PANEL_CARD_CLASS ?>">
        <h1 class="mb-4 text-2xl font-semibold">Panel pracownika</h1>
        <p>Ta strona jest dostępna dla pracowników i administratorów.</p>
    </section>
<?php panel_layout_foot(); ?>
