<?php
declare(strict_types=1);

/*
    Strona usług w panelu administratora.

    Lista, filtry, wyszukiwanie i paginacja pochodzą z app/uslugi.php.
    Formularz dodawania i edycji wysyła żądania fetch() do /api/admin/services.
    Widok nie wykonuje SQL ani operacji zapisu.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';
require_once dirname(__DIR__) . '/app/uslugi.php';
require_once dirname(__DIR__) . '/app/kategorie.php';

$user = require_role(['admin']);
$basePath = auth_base_path();
$servicesUrl = $basePath . '/uslugi';
$servicesApi = $basePath . '/api/admin/services';
$categoryFilter = usluga_filter_category_id($_GET['id_kategorii'] ?? null);
$statusFilter = usluga_filter_status($_GET['status'] ?? null);
$search = pagination_search_value($_GET['szukaj'] ?? null);
$page = pagination_page($_GET['page'] ?? null);
$size = pagination_size($_GET['size'] ?? null);
$servicePage = uslugi_list_paginated($categoryFilter, $statusFilter, $search, $page, $size);
$services = $servicePage['items'] ?? null;
$categories = kategorie_list();
$categoryFilterOptions = [['value' => '0', 'label' => 'Wszystkie']];
$serviceCategoryOptions = [];

foreach ($categories ?? [] as $category) {
    $categoryFilterOptions[] = [
        'value' => (string) $category['id'],
        'label' => $category['nazwa'] . ($category['aktywna'] ? '' : ' (nieaktywna)'),
    ];
    $serviceCategoryOptions[] = [
        'value' => (string) $category['id'],
        'label' => $category['nazwa'] . ($category['aktywna'] ? '' : ' (nieaktywna)'),
        'disabled' => !$category['aktywna'],
        'inactive' => !$category['aktywna'],
    ];
}

$filtersActive = $categoryFilter > 0 || $statusFilter !== 'all' || $search !== '';
$paginationParams = ['size' => $size];

if ($categoryFilter > 0) {
    $paginationParams['id_kategorii'] = $categoryFilter;
}

if ($statusFilter !== 'all') {
    $paginationParams['status'] = $statusFilter;
}

if ($search !== '') {
    $paginationParams['szukaj'] = $search;
}

panel_layout_head($user, 'Usługi — WeFixIT', 'services');
?>
<section class="<?= PANEL_CARD_WIDE_CLASS ?>">
    <?php
    panel_section_header(
        'Usługi',
        'Zarządzaj usługami dostępnymi w serwisie.',
        [
            ['label' => 'Dodaj usługę', 'drawer' => 'service-drawer'],
        ]
    );
    ?>

    <?php if ($services === null): ?>
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            Nie udało się pobrać listy usług. Spróbuj ponownie później.
        </div>
    <?php else: ?>
        <form id="services-filter-form" method="get"
            action="<?= htmlspecialchars($servicesUrl, ENT_QUOTES, 'UTF-8') ?>"
            class="mb-5 grid grid-cols-1 gap-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:grid-cols-2">
            <input type="hidden" name="size" value="<?= $size ?>">

            <div>
                <label for="services-filter-search" class="<?= LAYOUT_LABEL_CLASS ?>">Szukaj</label>
                <input id="services-filter-search" name="szukaj" type="search" maxlength="100"
                    value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Nazwa usługi" class="<?= LAYOUT_INPUT_CLASS ?>">
            </div>

            <div class="flex flex-wrap items-center gap-2 sm:items-end">
                <button type="submit" class="<?= panel_button_classes('secondary') ?>">Szukaj</button>
                <?php if ($filtersActive): ?>
                    <a href="<?= htmlspecialchars($servicesUrl, ENT_QUOTES, 'UTF-8') ?>"
                        class="<?= panel_button_classes('ghost') ?>">Wyczyść</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="<?= PANEL_TABLE_WRAP_CLASS ?>">
            <table id="services-table" class="<?= PANEL_TABLE_CLASS ?>">
                <thead>
                    <tr>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Nazwa</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">
                            <?php panel_filter_start('services-category', 'Kategoria', $categoryFilter > 0); ?>
                                <?php panel_filter_options(
                                    'services-filter-form',
                                    'id_kategorii',
                                    'Filtruj według kategorii',
                                    $categoryFilterOptions,
                                    (string) $categoryFilter
                                ); ?>
                            <?php panel_filter_end(); ?>
                        </th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Cena</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Czas trwania</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Pracownicy</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">
                            <?php panel_filter_start('services-status', 'Status', $statusFilter !== 'all'); ?>
                                <?php panel_filter_options(
                                    'services-filter-form',
                                    'status',
                                    'Filtruj według statusu',
                                    [
                                        ['value' => 'all', 'label' => 'Wszystkie'],
                                        ['value' => 'active', 'label' => 'Aktywne'],
                                        ['value' => 'inactive', 'label' => 'Nieaktywne'],
                                    ],
                                    $statusFilter
                                ); ?>
                            <?php panel_filter_end(); ?>
                        </th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?> text-right">Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($services === []): ?>
                        <tr>
                            <td colspan="7" class="p-4">
                                <?php
                                if ($filtersActive) {
                                    panel_empty_state(
                                        'Brak usług dla wybranych filtrów',
                                        'Zmień kategorię, status lub frazę wyszukiwania, aby zobaczyć inne usługi.',
                                        ['label' => 'Wyczyść filtry', 'href' => $servicesUrl]
                                    );
                                } else {
                                    panel_empty_state(
                                        'Brak usług',
                                        'Dodaj pierwszą usługę, aby przypisywać ją do pracowników.',
                                        ['label' => 'Dodaj usługę', 'drawer' => 'service-drawer']
                                    );
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($services as $service): ?>
                        <tr class="<?= PANEL_TR_CLASS ?>">
                            <td class="<?= PANEL_TD_CLASS ?> font-medium text-zinc-900 dark:text-zinc-50">
                                <?= htmlspecialchars($service['nazwa'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <?= htmlspecialchars($service['kategoria_nazwa'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!$service['kategoria_aktywna']): ?>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">(nieaktywna)</span>
                                <?php endif; ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?> whitespace-nowrap">
                                <?= number_format((float) $service['cena'], 2, ',', ' ') ?> zł
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?> whitespace-nowrap">
                                <?= $service['czas_trwania_minuty'] ?> min
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?> whitespace-nowrap">
                                <?= $service['pracownicy_active'] ?>
                                <?php if ($service['przypisania_total'] !== $service['pracownicy_active']): ?>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                        (łącznie: <?= $service['przypisania_total'] ?>)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <?= panel_badge(
                                    $service['aktywna'] ? 'Aktywna' : 'Nieaktywna',
                                    $service['aktywna'] ? 'success' : 'neutral'
                                ) ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <button type="button" class="<?= panel_button_classes('ghost') ?>"
                                        data-service-edit
                                        data-drawer-open="service-drawer"
                                        data-id="<?= $service['id'] ?>"
                                        data-name="<?= htmlspecialchars($service['nazwa'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-description="<?= htmlspecialchars($service['opis'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        data-category="<?= $service['kategoria_id'] ?>"
                                        data-price="<?= htmlspecialchars(number_format((float) $service['cena'], 2, ',', ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-duration="<?= $service['czas_trwania_minuty'] ?>">
                                        Edytuj
                                    </button>
                                    <button type="button" class="<?= panel_button_classes('secondary') ?>"
                                        data-service-toggle
                                        data-id="<?= $service['id'] ?>"
                                        data-name="<?= htmlspecialchars($service['nazwa'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-active="<?= $service['aktywna'] ? '1' : '0' ?>"
                                        title="<?= $service['aktywna'] ? 'Dezaktywuj' : 'Aktywuj' ?>"
                                        aria-label="<?= $service['aktywna'] ? 'Dezaktywuj' : 'Aktywuj' ?>">
                                        <?php if ($service['aktywna']): ?>
                                            <?= panel_icon('circle-slash', 'h-4 w-4 shrink-0 text-red-600 dark:text-red-400') ?>
                                        <?php else: ?>
                                            <?= panel_icon('circle-check', 'h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400') ?>
                                        <?php endif; ?>
                                    </button>
                                    <button type="button" class="<?= panel_button_classes('danger') ?>"
                                        data-service-delete
                                        data-id="<?= $service['id'] ?>"
                                        data-name="<?= htmlspecialchars($service['nazwa'], ENT_QUOTES, 'UTF-8') ?>"
                                        title="Usuń" aria-label="Usuń">
                                        <?= panel_icon('trash', 'h-4 w-4 shrink-0') ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php panel_pagination($basePath, '/uslugi', $paginationParams, $servicePage); ?>
    <?php endif; ?>
</section>

<?php
panel_drawer_start(
    'service-drawer',
    'service-form-title',
    'Dodaj usługę',
    'Nowa usługa będzie od razu aktywna.'
);
?>
    <form id="service-form" class="space-y-4" novalidate
        data-api="<?= htmlspecialchars($servicesApi, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" id="service-id" name="id" value="">

        <div>
            <label for="service-nazwa" class="<?= LAYOUT_LABEL_CLASS ?>">Nazwa</label>
            <input id="service-nazwa" name="nazwa" type="text" maxlength="150" required
                class="<?= LAYOUT_INPUT_CLASS ?>">
            <p class="<?= LAYOUT_HINT_CLASS ?>">Maksymalnie 150 znaków.</p>
            <?= panel_field_error('service-error-nazwa') ?>
        </div>

        <div>
            <label for="service-opis" class="<?= LAYOUT_LABEL_CLASS ?>">Opis</label>
            <textarea id="service-opis" name="opis" rows="4" maxlength="2000"
                class="<?= LAYOUT_TEXTAREA_CLASS ?>"></textarea>
            <p class="<?= LAYOUT_HINT_CLASS ?>">Opcjonalnie, maksymalnie 2000 znaków.</p>
            <?= panel_field_error('service-error-opis') ?>
        </div>

        <div class="space-y-4">
            <div>
                <span id="service-kategoria-label" class="<?= LAYOUT_LABEL_CLASS ?>">Kategoria</span>
                <div class="relative" data-filter-dropdown>
                    <button type="button"
                        class="<?= LAYOUT_INPUT_CLASS ?> items-center justify-between gap-2 text-left"
                        data-filter-toggle aria-expanded="false" aria-haspopup="true"
                        aria-labelledby="service-kategoria-label service-kategoria-value">
                        <span id="service-kategoria-value"
                            class="truncate text-zinc-400 dark:text-zinc-500"
                            data-service-category-value>Wybierz kategorię</span>
                        <?= panel_icon('chevron-down', 'h-4 w-4 shrink-0 text-zinc-500') ?>
                    </button>
                    <div class="<?= PANEL_FILTER_MENU_CLASS ?>" data-filter-menu>
                        <?php panel_filter_options(
                            'service-form',
                            'kategoria_id',
                            'Kategoria',
                            $serviceCategoryOptions,
                            '',
                            false
                        ); ?>
                    </div>
                </div>
                <?= panel_field_error('service-error-kategoria_id') ?>
            </div>

            <div>
                <label for="service-cena" class="<?= LAYOUT_LABEL_CLASS ?>">Cena</label>
                <input id="service-cena" name="cena" type="text" inputmode="decimal" maxlength="12"
                    placeholder="np. 199,99" required class="<?= LAYOUT_INPUT_CLASS ?>">
                <p class="<?= LAYOUT_HINT_CLASS ?>">Kropka lub przecinek, maksymalnie 2 miejsca.</p>
                <?= panel_field_error('service-error-cena') ?>
            </div>

            <div>
                <label for="service-czas" class="<?= LAYOUT_LABEL_CLASS ?>">Czas trwania (minuty)</label>
                <input id="service-czas" name="czas_trwania_minuty" type="number" min="1" max="1440" step="1"
                    required class="<?= LAYOUT_INPUT_CLASS ?>">
                <p class="<?= LAYOUT_HINT_CLASS ?>">Od 1 do 1440 minut.</p>
                <?= panel_field_error('service-error-czas_trwania_minuty') ?>
            </div>
        </div>

        <p id="service-form-error" role="alert" class="hidden text-sm text-red-600 dark:text-red-400"></p>

        <div class="flex flex-wrap items-center gap-2">
            <button type="submit" id="service-submit" class="<?= panel_button_classes('primary') ?>">
                Dodaj usługę
            </button>
            <button type="button" id="service-cancel" data-drawer-close
                class="<?= panel_button_classes('secondary') ?> hidden">
                Anuluj edycję
            </button>
        </div>
    </form>
<?php panel_drawer_end(); ?>

<noscript>
    <p class="mt-4 text-sm text-red-600 dark:text-red-400">Włącz JavaScript, aby zarządzać usługami.</p>
</noscript>

<?php panel_layout_foot('services.js'); ?>
