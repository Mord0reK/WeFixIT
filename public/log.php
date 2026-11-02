<?php
declare(strict_types=1);

/*
    Strona dziennika zdarzeń administratora.

    Wyświetla historię operacji z filtrami i paginacją. Szczegóły są ukryte
    w rozwijanym wierszu, a widok nie wykonuje SQL, nie zapisuje danych i nie
    używa JavaScriptu.
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

panel_layout_head($user, 'Dziennik zdarzeń — WeFixIT', 'log');
?>
<section class="<?= PANEL_CARD_WIDE_CLASS ?>">
    <?php
    panel_section_header(
        'Dziennik zdarzeń',
        'Historia operacji administracyjnych i zmian w serwisie.'
    );
    ?>

    <form id="log-filter-form" method="get"
        action="<?= htmlspecialchars($logUrl, ENT_QUOTES, 'UTF-8') ?>"
        class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <input type="hidden" name="size" value="<?= $size ?>">

        <div>
            <label for="log-filter-from" class="<?= LAYOUT_LABEL_CLASS ?>">Data od</label>
            <input id="log-filter-from" name="data_od" type="date"
                value="<?= $dateFrom !== null ? htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') : '' ?>"
                class="<?= LAYOUT_INPUT_CLASS ?>">
        </div>

        <div>
            <label for="log-filter-to" class="<?= LAYOUT_LABEL_CLASS ?>">Data do</label>
            <input id="log-filter-to" name="data_do" type="date"
                value="<?= $dateTo !== null ? htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') : '' ?>"
                class="<?= LAYOUT_INPUT_CLASS ?>">
        </div>

        <div>
            <label for="log-filter-action" class="<?= LAYOUT_LABEL_CLASS ?>">Akcja</label>
            <select id="log-filter-action" name="akcja" class="<?= LAYOUT_INPUT_CLASS ?>">
                <option value="">Wszystkie akcje</option>
                <?php foreach (LOG_VIEW_ACTIONS as $actionOption): ?>
                    <option value="<?= htmlspecialchars($actionOption, ENT_QUOTES, 'UTF-8') ?>"
                        <?= $actionFilter === $actionOption ? 'selected' : '' ?>>
                        <?= htmlspecialchars($actionOption, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="log-filter-level" class="<?= LAYOUT_LABEL_CLASS ?>">Poziom</label>
            <select id="log-filter-level" name="poziom" class="<?= LAYOUT_INPUT_CLASS ?>">
                <option value="">Wszystkie poziomy</option>
                <?php foreach (LOG_VIEW_LEVELS as $levelOption): ?>
                    <option value="<?= htmlspecialchars($levelOption, ENT_QUOTES, 'UTF-8') ?>"
                        <?= $levelFilter === $levelOption ? 'selected' : '' ?>>
                        <?= htmlspecialchars($levelLabels[$levelOption] ?? $levelOption, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="log-filter-object" class="<?= LAYOUT_LABEL_CLASS ?>">Obiekt</label>
            <select id="log-filter-object" name="obiekt_typ" class="<?= LAYOUT_INPUT_CLASS ?>">
                <option value="">Wszystkie obiekty</option>
                <?php foreach (LOG_VIEW_OBJECT_TYPES as $objectOption): ?>
                    <option value="<?= htmlspecialchars($objectOption, ENT_QUOTES, 'UTF-8') ?>"
                        <?= $objectTypeFilter === $objectOption ? 'selected' : '' ?>>
                        <?= htmlspecialchars($objectOption, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex flex-wrap items-center gap-2 sm:items-end">
            <button type="submit" class="<?= panel_button_classes('secondary') ?>">Filtruj</button>
            <?php if ($filtersActive): ?>
                <a href="<?= htmlspecialchars($logUrl, ENT_QUOTES, 'UTF-8') ?>"
                    class="<?= panel_button_classes('ghost') ?>">Wyczyść filtry</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($events === null): ?>
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            Nie udało się pobrać dziennika zdarzeń. Spróbuj ponownie później.
        </div>
    <?php elseif ($events === []): ?>
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
    <?php else: ?>
        <div class="<?= PANEL_TABLE_WRAP_CLASS ?>">
            <table id="log-table" class="<?= PANEL_TABLE_CLASS ?>">
                <thead>
                    <tr>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Data i godzina</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Aktor</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Akcja</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Obiekt</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Poziom</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Opis</th>
                    </tr>
                </thead>
                <tbody>
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
                                <td colspan="6" class="border-b border-zinc-100 px-4 pb-3 align-top text-zinc-700 last:border-b-0 dark:border-zinc-800 dark:text-zinc-300">
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
