<?php
declare(strict_types=1);

/*
    Strona kategorii usług w panelu administratora.

    Lista pochodzi z app/kategorie.php i obsługuje wyszukiwanie oraz paginację.
    Formularz dodawania i edycji wysyła żądania fetch() do /api/admin/categories.
    Widok nie wykonuje SQL ani operacji zapisu.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';
require_once dirname(__DIR__) . '/app/kategorie.php';

$user = require_role(['admin']);
$basePath = auth_base_path();
$categoriesUrl = $basePath . '/kategorie';
$categoriesApi = $basePath . '/api/admin/categories';
$search = pagination_search_value($_GET['szukaj'] ?? null);
$statusFilter = kategoria_filter_status($_GET['status'] ?? null);
$page = pagination_page($_GET['page'] ?? null);
$size = pagination_size($_GET['size'] ?? null);
$categoryPage = kategorie_list_paginated($page, $size, $search, $statusFilter);
$categories = $categoryPage['items'] ?? null;
$hasFilters = $search !== '' || $statusFilter !== 'all';
$paginationParams = ['size' => $size];

if ($search !== '') {
    $paginationParams['szukaj'] = $search;
}

if ($statusFilter !== 'all') {
    $paginationParams['status'] = $statusFilter;
}

panel_layout_head($user, 'Kategorie — WeFixIT', 'categories');
?>
<section class="<?= PANEL_CARD_WIDE_CLASS ?>">
    <?php
    panel_section_header(
        'Kategorie',
        'Zarządzaj kategoriami usług dostępnych w serwisie.',
        [
            ['label' => 'Dodaj kategorię', 'drawer' => 'category-drawer'],
        ]
    );
    ?>

    <?php if ($categories === null): ?>
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            Nie udało się pobrać listy kategorii. Spróbuj ponownie później.
        </div>
    <?php else: ?>
        <form id="categories-filter-form" method="get"
            action="<?= htmlspecialchars($categoriesUrl, ENT_QUOTES, 'UTF-8') ?>"
            class="hidden">
            <input type="hidden" name="size" value="<?= $size ?>">
            <?php if ($search !== ''): ?>
                <input type="hidden" name="szukaj" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
        </form>

        <div class="<?= PANEL_TABLE_WRAP_CLASS ?>">
            <table id="categories-table" class="<?= PANEL_TABLE_CLASS ?>">
                <thead>
                    <tr>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Nazwa</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Opis</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Usługi</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">
                            <?php panel_filter_start('categories-status', 'Status', $statusFilter !== 'all'); ?>
                                <?php panel_filter_options(
                                    'categories-filter-form',
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
                    <?php if ($categories === []): ?>
                        <tr>
                            <td colspan="5" class="p-4">
                                <?php
                                if ($hasFilters) {
                                    panel_empty_state(
                                        'Brak kategorii dla wybranej frazy',
                                        'Zmień frazę wyszukiwania, aby zobaczyć inne kategorie.',
                                        ['label' => 'Wyczyść wyszukiwanie', 'href' => $categoriesUrl]
                                    );
                                } else {
                                    panel_empty_state(
                                        'Brak kategorii',
                                        'Dodaj pierwszą kategorię, aby przypisywać do niej usługi.',
                                        ['label' => 'Dodaj kategorię', 'drawer' => 'category-drawer']
                                    );
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($categories as $category): ?>
                        <tr class="<?= PANEL_TR_CLASS ?>">
                            <td class="<?= PANEL_TD_CLASS ?> font-medium text-zinc-900 dark:text-zinc-50">
                                <?= htmlspecialchars($category['nazwa'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?> max-w-md">
                                <?php if ($category['opis'] !== null && $category['opis'] !== ''): ?>
                                    <?= htmlspecialchars($category['opis'], ENT_QUOTES, 'UTF-8') ?>
                                <?php else: ?>
                                    <span class="text-zinc-400 dark:text-zinc-500">Brak opisu</span>
                                <?php endif; ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?> whitespace-nowrap">
                                <?= $category['uslugi_total'] ?>
                                <?php if ($category['uslugi_active'] !== $category['uslugi_total']): ?>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                        (aktywne: <?= $category['uslugi_active'] ?>)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <?= panel_badge(
                                    $category['aktywna'] ? 'Aktywna' : 'Nieaktywna',
                                    $category['aktywna'] ? 'success' : 'neutral'
                                ) ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <button type="button" class="<?= panel_button_classes('ghost') ?>"
                                        data-category-edit
                                        data-drawer-open="category-drawer"
                                        data-id="<?= $category['id'] ?>"
                                        data-name="<?= htmlspecialchars($category['nazwa'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-description="<?= htmlspecialchars($category['opis'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        Edytuj
                                    </button>
                                    <button type="button" class="<?= panel_button_classes('secondary') ?>"
                                        data-category-toggle
                                        data-id="<?= $category['id'] ?>"
                                        data-name="<?= htmlspecialchars($category['nazwa'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-active="<?= $category['aktywna'] ? '1' : '0' ?>"
                                        title="<?= $category['aktywna'] ? 'Dezaktywuj' : 'Aktywuj' ?>"
                                        aria-label="<?= $category['aktywna'] ? 'Dezaktywuj' : 'Aktywuj' ?>">
                                        <?php if ($category['aktywna']): ?>
                                            <?= panel_icon('circle-slash', 'h-4 w-4 shrink-0 text-red-600 dark:text-red-400') ?>
                                        <?php else: ?>
                                            <?= panel_icon('circle-check', 'h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400') ?>
                                        <?php endif; ?>
                                    </button>
                                    <button type="button" class="<?= panel_button_classes('danger') ?>"
                                        data-category-delete
                                        data-id="<?= $category['id'] ?>"
                                        data-name="<?= htmlspecialchars($category['nazwa'], ENT_QUOTES, 'UTF-8') ?>"
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
        <?php panel_pagination($basePath, '/kategorie', $paginationParams, $categoryPage); ?>
    <?php endif; ?>
</section>

<?php
panel_drawer_start(
    'category-drawer',
    'category-form-title',
    'Dodaj kategorię',
    'Nowa kategoria będzie od razu aktywna.'
);
?>
    <form id="category-form" class="space-y-4" novalidate
        data-api="<?= htmlspecialchars($categoriesApi, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" id="category-id" name="id" value="">

        <div>
            <label for="category-nazwa" class="<?= LAYOUT_LABEL_CLASS ?>">Nazwa</label>
            <input id="category-nazwa" name="nazwa" type="text" maxlength="100" required
                class="<?= LAYOUT_INPUT_CLASS ?>">
            <p class="<?= LAYOUT_HINT_CLASS ?>">Maksymalnie 100 znaków.</p>
            <?= panel_field_error('category-error-nazwa') ?>
        </div>

        <div>
            <label for="category-opis" class="<?= LAYOUT_LABEL_CLASS ?>">Opis</label>
            <textarea id="category-opis" name="opis" rows="4" maxlength="2000"
                class="<?= LAYOUT_TEXTAREA_CLASS ?>"></textarea>
            <p class="<?= LAYOUT_HINT_CLASS ?>">Opcjonalnie, maksymalnie 2000 znaków.</p>
            <?= panel_field_error('category-error-opis') ?>
        </div>

        <p id="category-form-error" role="alert" class="hidden text-sm text-red-600 dark:text-red-400"></p>

        <div class="flex flex-wrap items-center gap-2">
            <button type="submit" id="category-submit" class="<?= panel_button_classes('primary') ?>">
                Dodaj kategorię
            </button>
            <button type="button" id="category-cancel" data-drawer-close
                class="<?= panel_button_classes('secondary') ?> hidden">
                Anuluj edycję
            </button>
        </div>
    </form>
<?php panel_drawer_end(); ?>

<noscript>
    <p class="mt-4 text-sm text-red-600 dark:text-red-400">Włącz JavaScript, aby zarządzać kategoriami.</p>
</noscript>

<?php panel_layout_foot('categories.js'); ?>
