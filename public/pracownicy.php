<?php
declare(strict_types=1);

/*
    Strona pracowników w panelu administratora.

    Lista, wyszukiwanie i paginacja oraz formularze korzystają z
    app/pracownicy.php. Panel tworzy wyłącznie profile powiązane z istniejącymi
    kontami o roli pracownik — nie zakłada nowych kont użytkowników.
    Widok nie wykonuje SQL ani operacji zapisu.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';
require_once dirname(__DIR__) . '/app/pracownicy.php';

$user = require_role(['admin']);
$basePath = auth_base_path();
$employeesUrl = $basePath . '/pracownicy';
$employeesApi = $basePath . '/api/admin/employees';
$search = pagination_search_value($_GET['szukaj'] ?? null);
$statusFilter = pracownik_filter_status($_GET['status'] ?? null);
$page = pagination_page($_GET['page'] ?? null);
$size = pagination_size($_GET['size'] ?? null);
$employeePage = pracownicy_list_paginated($search, $page, $size, $statusFilter);
$employees = $employeePage['items'] ?? null;
$availableAccounts = pracownicy_available_accounts();
$hasAvailableAccounts = $availableAccounts !== null && $availableAccounts !== [];
$hasEmployees = $employeePage !== null && ($employeePage['total'] ?? 0) > 0;
$hasFilters = $search !== '' || $statusFilter !== 'all';
$paginationParams = ['size' => $size];

if ($search !== '') {
    $paginationParams['szukaj'] = $search;
}

if ($statusFilter !== 'all') {
    $paginationParams['status'] = $statusFilter;
}

$headerActions = $hasAvailableAccounts
    ? [['label' => 'Dodaj pracownika', 'drawer' => 'employee-drawer']]
    : [];

panel_layout_head($user, 'Pracownicy — WeFixIT', 'employees');
?>
<section class="<?= PANEL_CARD_WIDE_CLASS ?>">
    <?php
    panel_section_header(
        'Pracownicy',
        'Zarządzaj profilami pracowników powiązanymi z kontami użytkowników.',
        $headerActions
    );
    ?>

    <?php if ($employees === null): ?>
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            Nie udało się pobrać listy pracowników. Spróbuj ponownie później.
        </div>
    <?php else: ?>
        <form id="employees-filter-form" method="get"
            action="<?= htmlspecialchars($employeesUrl, ENT_QUOTES, 'UTF-8') ?>"
            class="hidden">
            <input type="hidden" name="size" value="<?= $size ?>">
            <?php if ($search !== ''): ?>
                <input type="hidden" name="szukaj" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
        </form>

        <div class="<?= PANEL_TABLE_WRAP_CLASS ?>">
            <table id="employees-table" class="<?= PANEL_TABLE_CLASS ?>">
                <thead>
                    <tr>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Pracownik</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">E-mail</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Usługi</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">
                            <?php panel_filter_start('employees-status', 'Status', $statusFilter !== 'all'); ?>
                                <?php panel_filter_options(
                                    'employees-filter-form',
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
                    <?php if ($employees === []): ?>
                        <tr>
                            <td colspan="5" class="p-4">
                                <?php
                                if ($hasFilters) {
                                    panel_empty_state(
                                        'Brak pracowników dla wybranej frazy',
                                        'Zmień frazę wyszukiwania, aby zobaczyć inne profile.',
                                        ['label' => 'Wyczyść wyszukiwanie', 'href' => $employeesUrl]
                                    );
                                } elseif ($availableAccounts === []) {
                                    panel_empty_state(
                                        'Brak pracowników i kont do powiązania',
                                        'Aby dodać pierwszego pracownika, najpierw utwórz konto z rolą pracownik. '
                                        . 'Panel pracowników nie tworzy kont użytkowników.'
                                    );
                                } else {
                                    panel_empty_state(
                                        'Brak pracowników',
                                        'Utwórz profil pracownika powiązany z istniejącym kontem o roli pracownik.'
                                    );
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($employees as $employee): ?>
                        <?php
                        $description = $employee['opis'] ?? '';
                        $shortDescription = mb_strlen($description) > 80
                            ? mb_substr($description, 0, 77) . '…'
                            : $description;
                        $accountLabel = $employee['imie'] . ' ' . $employee['nazwisko']
                            . ' (' . $employee['email'] . ')';
                        ?>
                        <tr class="<?= PANEL_TR_CLASS ?>">
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <span class="block font-medium text-zinc-900 dark:text-zinc-50">
                                    <?= htmlspecialchars($employee['imie'] . ' ' . $employee['nazwisko'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php if ($shortDescription !== ''): ?>
                                    <span class="mt-0.5 block max-w-xs truncate text-xs text-zinc-500 dark:text-zinc-400"
                                        title="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($shortDescription, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <?= htmlspecialchars($employee['email'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!$employee['konto_aktywne']): ?>
                                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">(konto nieaktywne)</span>
                                <?php endif; ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?> whitespace-nowrap">
                                <?= $employee['uslugi_active'] ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <?= panel_badge(
                                    $employee['aktywny'] ? 'Aktywny' : 'Nieaktywny',
                                    $employee['aktywny'] ? 'success' : 'neutral'
                                ) ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <a href="<?= htmlspecialchars($basePath . '/pracownicy-uslugi?id=' . $employee['id'], ENT_QUOTES, 'UTF-8') ?>"
                                        class="<?= panel_button_classes('ghost') ?>">
                                        Usługi
                                    </a>
                                    <a href="<?= htmlspecialchars($basePath . '/pracownicy-dostepnosc?id=' . $employee['id'], ENT_QUOTES, 'UTF-8') ?>"
                                        class="<?= panel_button_classes('ghost') ?>">
                                        Dostępność
                                    </a>
                                    <button type="button" class="<?= panel_button_classes('ghost') ?>"
                                        data-employee-edit
                                        data-drawer-open="employee-drawer"
                                        data-id="<?= $employee['id'] ?>"
                                        data-name="<?= htmlspecialchars($employee['imie'] . ' ' . $employee['nazwisko'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-description="<?= htmlspecialchars($employee['opis'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        data-account="<?= htmlspecialchars($accountLabel, ENT_QUOTES, 'UTF-8') ?>"
                                        data-active="<?= $employee['aktywny'] ? '1' : '0' ?>">
                                        Edytuj
                                    </button>
                                    <button type="button" class="<?= panel_button_classes('secondary') ?>"
                                        data-employee-toggle
                                        data-id="<?= $employee['id'] ?>"
                                        data-name="<?= htmlspecialchars($employee['imie'] . ' ' . $employee['nazwisko'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-active="<?= $employee['aktywny'] ? '1' : '0' ?>"
                                        title="<?= $employee['aktywny'] ? 'Dezaktywuj' : 'Aktywuj' ?>"
                                        aria-label="<?= $employee['aktywny'] ? 'Dezaktywuj' : 'Aktywuj' ?>">
                                        <?php if ($employee['aktywny']): ?>
                                            <?= panel_icon('circle-slash', 'h-4 w-4 shrink-0 text-red-600 dark:text-red-400') ?>
                                        <?php else: ?>
                                            <?= panel_icon('circle-check', 'h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400') ?>
                                        <?php endif; ?>
                                    </button>
                                    <button type="button" class="<?= panel_button_classes('danger') ?>"
                                        data-employee-delete
                                        data-id="<?= $employee['id'] ?>"
                                        data-name="<?= htmlspecialchars($employee['imie'] . ' ' . $employee['nazwisko'], ENT_QUOTES, 'UTF-8') ?>"
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
        <?php panel_pagination($basePath, '/pracownicy', $paginationParams, $employeePage); ?>
    <?php endif; ?>
</section>

<?php if ($availableAccounts === null): ?>
    <section class="<?= PANEL_CARD_WIDE_CLASS ?> mt-6">
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            Nie udało się pobrać kont dostępnych do powiązania z pracownikiem.
        </div>
    </section>
<?php elseif ($availableAccounts === [] && $hasEmployees): ?>
    <section class="<?= PANEL_CARD_WIDE_CLASS ?> mt-6">
        <?php
        panel_empty_state(
            'Brak kont do powiązania',
            'Aby dodać pracownika, najpierw utwórz konto z rolą pracownik. '
            . 'Panel pracowników nie tworzy kont użytkowników.'
        );
        ?>
    </section>
<?php endif; ?>

<?php if ($hasEmployees || $hasAvailableAccounts): ?>
    <?php
    panel_drawer_start(
        'employee-drawer',
        'employee-form-title',
        'Dodaj pracownika',
        'Profil zostanie powiązany z istniejącym kontem o roli pracownik.',
        'employee-form-hint'
    );
    ?>
        <form id="employee-form" class="space-y-4" novalidate
            data-api="<?= htmlspecialchars($employeesApi, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" id="employee-id" name="id" value="">

            <div id="employee-account-select-wrap">
                <label for="employee-uzytkownik" class="<?= LAYOUT_LABEL_CLASS ?>">Konto</label>
                <select id="employee-uzytkownik" name="uzytkownik_id" class="<?= LAYOUT_INPUT_CLASS ?>" required>
                    <option value="">Wybierz konto</option>
                    <?php foreach ($availableAccounts ?? [] as $account): ?>
                        <option value="<?= $account['id'] ?>">
                            <?= htmlspecialchars(
                                $account['imie'] . ' ' . $account['nazwisko'] . ' (' . $account['email'] . ')',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="<?= LAYOUT_HINT_CLASS ?>">Wyświetlane są tylko aktywne konta z rolą pracownik bez profilu.</p>
                <?= panel_field_error('employee-error-uzytkownik_id') ?>
            </div>

            <div id="employee-account-readonly-wrap" class="hidden">
                <span class="<?= LAYOUT_LABEL_CLASS ?>">Konto</span>
                <p id="employee-account-readonly"
                    class="rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-700 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-200"></p>
                <p class="<?= LAYOUT_HINT_CLASS ?>">Powiązanie konta jest niezmienne po utworzeniu profilu.</p>
            </div>

            <div>
                <label for="employee-opis" class="<?= LAYOUT_LABEL_CLASS ?>">Opis</label>
                <textarea id="employee-opis" name="opis" rows="4" maxlength="2000"
                    class="<?= LAYOUT_TEXTAREA_CLASS ?>"></textarea>
                <p class="<?= LAYOUT_HINT_CLASS ?>">Opcjonalnie, maksymalnie 2000 znaków.</p>
                <?= panel_field_error('employee-error-opis') ?>
            </div>

            <div>
                <label for="employee-aktywny" class="inline-flex items-center gap-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    <input id="employee-aktywny" name="aktywny" type="checkbox" checked
                        class="h-4 w-4 rounded border-zinc-300 accent-zinc-900 dark:border-zinc-700 dark:accent-zinc-100">
                    Profil aktywny
                </label>
                <?= panel_field_error('employee-error-aktywny') ?>
            </div>

            <p id="employee-form-error" role="alert" class="hidden text-sm text-red-600 dark:text-red-400"></p>

            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" id="employee-submit" class="<?= panel_button_classes('primary') ?>">
                    Dodaj pracownika
                </button>
                <button type="button" id="employee-cancel" data-drawer-close
                    class="<?= panel_button_classes('secondary') ?> hidden">
                    Anuluj edycję
                </button>
            </div>
        </form>
    <?php panel_drawer_end(); ?>

    <noscript>
        <p class="mt-4 text-sm text-red-600 dark:text-red-400">Włącz JavaScript, aby zarządzać pracownikami.</p>
    </noscript>
<?php endif; ?>

<?php panel_layout_foot('employees.js'); ?>
