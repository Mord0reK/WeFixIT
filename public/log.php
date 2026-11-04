<?php
declare(strict_types=1);

/*
    Strona dziennika zdarzeń administratora.

    Wyświetla historię operacji z filtrami i paginacją. Filtry działają jako
    rozwijane menu w nagłówkach kolumn, a szczegóły są ukryte w rozwijanym
    wierszu. Widok nie wykonuje SQL ani operacji zapisu.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';
require_once dirname(__DIR__) . '/app/log_view.php';

$user = require_role(['admin']);
$basePath = auth_base_path();
$logUrl = $basePath . '/log';
$actionFilter = log_view_filter_action($_GET['akcja'] ?? null);
$levelFilter = log_view_filter_level($_GET['poziom'] ?? null);
$objectTypeFilter = log_view_filter_object_type($_GET['obiekt_typ'] ?? null);
$dateFrom = log_view_filter_date($_GET['data_od'] ?? null);
$dateTo = log_view_filter_date($_GET['data_do'] ?? null);
$page = pagination_page($_GET['page'] ?? null);
$size = pagination_size($_GET['size'] ?? null);
$logPage = log_view_list([
    'akcja' => $actionFilter,
    'poziom' => $levelFilter,
    'obiekt_typ' => $objectTypeFilter,
    'data_od' => $dateFrom,
    'data_do' => $dateTo,
], $page, $size);
$events = $logPage['items'] ?? null;
$filtersActive = $actionFilter !== ''
    || $levelFilter !== ''
    || $objectTypeFilter !== ''
    || $dateFrom !== null
    || $dateTo !== null;
$paginationParams = ['size' => $size];

if ($actionFilter !== '') {
    $paginationParams['akcja'] = $actionFilter;
}

if ($levelFilter !== '') {
    $paginationParams['poziom'] = $levelFilter;
}

if ($objectTypeFilter !== '') {
    $paginationParams['obiekt_typ'] = $objectTypeFilter;
}

if ($dateFrom !== null) {
    $paginationParams['data_od'] = $dateFrom;
}

if ($dateTo !== null) {
    $paginationParams['data_do'] = $dateTo;
}

$levelLabels = [
    'info' => 'Info',
    'warning' => 'Ostrzeżenie',
    'critical' => 'Krytyczny',
];
$levelVariants = [
    'info' => 'neutral',
    'warning' => 'warning',
    'critical' => 'danger',
];
$actionFilterOptions = [['value' => '', 'label' => 'Wszystkie akcje']];

foreach (LOG_VIEW_ACTIONS as $actionOption) {
    $actionFilterOptions[] = [
        'value' => $actionOption,
        'label' => $actionOption,
    ];
}

$levelFilterOptions = [['value' => '', 'label' => 'Wszystkie poziomy']];

foreach (LOG_VIEW_LEVELS as $levelOption) {
    $levelFilterOptions[] = [
        'value' => $levelOption,
        'label' => $levelLabels[$levelOption] ?? $levelOption,
    ];
}

$objectFilterOptions = [['value' => '', 'label' => 'Wszystkie obiekty']];

foreach (LOG_VIEW_OBJECT_TYPES as $objectOption) {
    $objectFilterOptions[] = [
        'value' => $objectOption,
        'label' => $objectOption,
    ];
}

panel_layout_head($user, 'Dziennik zdarzeń — WeFixIT', 'log');
?>
<section class="<?= PANEL_CARD_WIDE_CLASS ?>">
    <?php
    panel_section_header(
        'Dziennik zdarzeń',
        'Historia operacji administracyjnych i zmian w serwisie.',
        $filtersActive
            ? [['label' => 'Wyczyść filtry', 'href' => $logUrl]]
            : []
    );
    ?>

    <?php if ($events === null): ?>
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            Nie udało się pobrać dziennika zdarzeń. Spróbuj ponownie później.
        </div>
    <?php else: ?>
        <form id="log-filter-form" method="get"
            action="<?= htmlspecialchars($logUrl, ENT_QUOTES, 'UTF-8') ?>"
            class="hidden">
            <input type="hidden" name="size" value="<?= $size ?>">
        </form>

        <div class="<?= PANEL_TABLE_WRAP_CLASS ?>">
            <table id="log-table" class="<?= PANEL_TABLE_CLASS ?>">
                <thead>
                    <tr>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">
                            <?php panel_filter_start('log-date', 'Data i godzina', $dateFrom !== null || $dateTo !== null); ?>
                                <?php panel_filter_date('log-filter-form', 'data_od', 'Data od', $dateFrom, 'Filtruj zdarzenia od daty'); ?>
                                <?php panel_filter_date('log-filter-form', 'data_do', 'Data do', $dateTo, 'Filtruj zdarzenia do daty'); ?>
                            <?php panel_filter_end(); ?>
                        </th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Aktor</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">
                            <?php panel_filter_start('log-action', 'Akcja', $actionFilter !== ''); ?>
                                <?php panel_filter_options('log-filter-form', 'akcja', 'Filtruj według akcji', $actionFilterOptions, $actionFilter); ?>
                            <?php panel_filter_end(); ?>
                        </th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">
                            <?php panel_filter_start('log-object', 'Obiekt', $objectTypeFilter !== ''); ?>
                                <?php panel_filter_options('log-filter-form', 'obiekt_typ', 'Filtruj według obiektu', $objectFilterOptions, $objectTypeFilter); ?>
                            <?php panel_filter_end(); ?>
                        </th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">
                            <?php panel_filter_start('log-level', 'Poziom', $levelFilter !== ''); ?>
                                <?php panel_filter_options('log-filter-form', 'poziom', 'Filtruj według poziomu', $levelFilterOptions, $levelFilter); ?>
                            <?php panel_filter_end(); ?>
                        </th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Opis</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($events === []): ?>
                        <tr>
                            <td colspan="6" class="p-4">
                                <?php
                                if ($filtersActive) {
                                    panel_empty_state(
                                        'Brak zdarzeń dla wybranych filtrów',
                                        'Zmień zakres dat lub pozostałe filtry, aby zobaczyć inne wpisy.',
                                        ['label' => 'Wyczyść filtry', 'href' => $logUrl]
                                    );
                                } else {
                                    panel_empty_state(
                                        'Brak zdarzeń',
                                        'Dziennik jest pusty. Operacje administracyjne pojawią się tutaj automatycznie.'
                                    );
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($events as $event): ?>
                        <?php
                        $actorName = trim(($event['aktor_imie'] ?? '') . ' ' . ($event['aktor_nazwisko'] ?? ''));
                        $actorLabel = $actorName !== '' ? $actorName : '—';
                        $objectLabel = '—';

                        if ($event['obiekt_typ'] !== null) {
                            $objectLabel = $event['obiekt_typ']
                                . ($event['obiekt_id'] !== null ? ' #' . $event['obiekt_id'] : '');
                        }

                        $detailsJson = null;

                        if ($event['szczegoly'] !== null) {
                            $encoded = json_encode(
                                $event['szczegoly'],
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
                            );

                            if ($encoded !== false) {
                                $detailsJson = $encoded;
                            }
                        }

                        $detailsVisible = $detailsJson !== null
                            && $detailsJson !== '[]'
                            && $detailsJson !== '{}';
                        ?>
                        <tr class="<?= PANEL_TR_CLASS ?>" data-log-row>
                            <td class="<?= PANEL_TD_CLASS ?> whitespace-nowrap">
                                <?= htmlspecialchars(date('d.m.Y H:i', strtotime($event['utworzono'])), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <?= htmlspecialchars($actorLabel, ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <span class="font-mono text-xs">
                                    <?= htmlspecialchars($event['akcja'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?> whitespace-nowrap">
                                <?= htmlspecialchars($objectLabel, ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <?= panel_badge(
                                    $levelLabels[$event['poziom']] ?? $event['poziom'],
                                    $levelVariants[$event['poziom']] ?? 'neutral'
                                ) ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <?= htmlspecialchars($event['opis'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                        </tr>
                        <?php if ($detailsVisible): ?>
                            <tr data-log-details>
                                <td colspan="6" class="border-b border-zinc-100 px-4 pb-3 align-top text-zinc-700 dark:border-zinc-800 dark:text-zinc-300">
                                    <details>
                                        <summary class="cursor-pointer text-sm font-medium text-zinc-700 dark:text-zinc-200">
                                            Szczegóły
                                        </summary>
                                        <pre class="mt-2 text-xs text-zinc-600 dark:text-zinc-300"><?= htmlspecialchars($detailsJson, ENT_QUOTES, 'UTF-8') ?></pre>
                                    </details>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php panel_pagination($basePath, '/log', $paginationParams, $logPage); ?>
    <?php endif; ?>
</section>
<?php panel_layout_foot(); ?>
