<?php
declare(strict_types=1);

/*
    Dashboard administratora.

    Strona chroniona wymaga roli admin. Wszystkie liczby pochodzą z zapytań SQL
    w app/dashboard.php, a widok odpowiada wyłącznie za ich prezentację.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';
require_once dirname(__DIR__) . '/app/dashboard.php';

$user = require_role(['admin']);
$stats = admin_dashboard_stats();
$basePath = auth_base_path();
$profileUrl = $basePath . '/profil';

panel_layout_head($user, 'Dashboard — WeFixIT', 'dashboard');
?>
<section class="<?= PANEL_CARD_WIDE_CLASS ?>">
    <?php
    panel_section_header(
        'Dashboard',
        'Przegląd najważniejszych danych serwisu.',
        [
            ['label' => 'Mój profil', 'href' => $profileUrl],
        ]
    );
    ?>

    <?php if (!$stats['available']): ?>
        <div role="alert" class="rounded border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            Nie udało się pobrać statystyk. Spróbuj ponownie później.
        </div>
    <?php else: ?>
        <?php
        $hasBusinessData = $stats['services_total'] > 0
            || $stats['employees_active'] > 0
            || $stats['reservations_total'] > 0;
        ?>

        <?php if (!$hasBusinessData): ?>
            <div role="status" class="mb-6 rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                Baza nie zawiera jeszcze danych o usługach, pracownikach ani rezerwacjach.
                Świeży import <code class="font-mono">database-czysta.sql</code> jest pusty;
                aby zobaczyć przykładowe dane, zaimportuj <code class="font-mono">database.sql</code>.
            </div>
        <?php endif; ?>

        <?php
        $tiles = [
            [
                'key' => 'clients_total',
                'label' => 'Klienci',
                'value' => $stats['clients_total'],
                'description' => 'Konta z rolą klient.',
            ],
            [
                'key' => 'employees_active',
                'label' => 'Aktywni pracownicy',
                'value' => $stats['employees_active'],
                'description' => 'Pracownicy z aktywnym profilem.',
            ],
            [
                'key' => 'services_total',
                'label' => 'Usługi',
                'value' => $stats['services_total'],
                'description' => 'Aktywne: ' . $stats['services_active'] . ' z ' . $stats['services_total'],
            ],
            [
                'key' => 'reservations_total',
                'label' => 'Rezerwacje łącznie',
                'value' => $stats['reservations_total'],
                'description' => 'Wszystkie rezerwacje w bazie.',
            ],
            [
                'key' => 'reservations_today',
                'label' => 'Rezerwacje na dziś',
                'value' => $stats['reservations_today'],
                'description' => 'Zaplanowane na bieżący dzień.',
            ],
        ];
        ?>

        <div id="dashboard-stats" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($tiles as $tile): ?>
                <article class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900"
                    data-stat="<?= htmlspecialchars($tile['key'], ENT_QUOTES, 'UTF-8') ?>">
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400"><?= htmlspecialchars($tile['label'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-2 text-3xl font-semibold"><?= htmlspecialchars((string) $tile['value'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($tile['description'], ENT_QUOTES, 'UTF-8') ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php panel_layout_foot(); ?>
