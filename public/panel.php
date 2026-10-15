<?php
declare(strict_types=1);

/*
    Panel klienta. Strona chroniona — dane użytkownika pochodzą z weryfikacji w bazie.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';

$user = require_login();

panel_layout_head($user, 'Panel — WeFixIT', 'home');
?>
    <section class="<?= PANEL_CARD_CLASS ?>">
        <h1 class="mb-4 text-2xl font-semibold">Panel użytkownika</h1>
        <p>Jesteś zalogowany. Ta strona jest dostępna tylko po zalogowaniu.</p>
    </section>
<?php panel_layout_foot(); ?>
