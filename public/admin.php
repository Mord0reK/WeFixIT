<?php
declare(strict_types=1);

/*
    Zalążek panelu administratora. Dostęp wyłącznie dla roli admin.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';

$user = require_role(['admin']);

panel_layout_head($user, 'Panel administratora — WeFixIT', 'home');
?>
    <section class="<?= PANEL_CARD_CLASS ?>">
        <h1 class="mb-4 text-2xl font-semibold">Panel administratora</h1>
        <p>Ta strona jest dostępna tylko dla administratorów.</p>
    </section>
<?php panel_layout_foot(); ?>
