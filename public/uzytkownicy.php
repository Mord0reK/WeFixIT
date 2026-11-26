<?php
declare(strict_types=1);

/*
    Strona administracji kontami użytkowników.

    Wyświetla listę kont z filtrami, wyszukiwaniem i paginacją oraz zmianą
    roli z dropdownu z popupem potwierdzenia. Zmiana roli na klient przy
    aktywnym profilu pracownika najpierw dezaktywuje profil. Widok nie
    wykonuje SQL ani operacji zapisu — korzysta z app/uzytkownicy.php
    i endpointów JSON.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';
require_once dirname(__DIR__) . '/app/uzytkownicy.php';

$user = require_role(['admin']);
$basePath = auth_base_path();
$usersUrl = $basePath . '/uzytkownicy';
$usersApi = $basePath . '/api/admin/users';
$employeesApi = $basePath . '/api/admin/employees';
$roleFilter = uzytkownik_role_filter($_GET['rola'] ?? null);
$statusFilter = uzytkownik_status_filter($_GET['status'] ?? null);
$search = pagination_search_value($_GET['szukaj'] ?? null);
$page = pagination_page($_GET['page'] ?? null);
$size = pagination_size($_GET['size'] ?? null);
$userPage = uzytkownicy_list_paginated($roleFilter, $statusFilter, $search, $page, $size);
$users = $userPage['items'] ?? null;
$hasFilters = $roleFilter !== '' || $statusFilter !== 'all' || $search !== '';
$paginationParams = ['size' => $size];

if ($roleFilter !== '') {
    $paginationParams['rola'] = $roleFilter;
}

if ($statusFilter !== 'all') {
    $paginationParams['status'] = $statusFilter;
}

if ($search !== '') {
    $paginationParams['szukaj'] = $search;
}

$exportParams = ['typ' => 'uzytkownicy'];

if ($roleFilter !== '') {
    $exportParams['rola'] = $roleFilter;
}

if ($statusFilter !== 'all') {
    $exportParams['status'] = $statusFilter;
}

if ($search !== '') {
    $exportParams['szukaj'] = $search;
}

$exportUrl = $basePath . '/eksport?' . http_build_query($exportParams);
$roleLabels = [
    'klient' => 'Klient',
    'pracownik' => 'Pracownik',
    'admin' => 'Administrator',
];
$statusLabels = [
    'all' => 'Wszystkie',
    'active' => 'Aktywne',
    'inactive' => 'Nieaktywne',
];
$roleFilterOptions = [['value' => 'all', 'label' => 'Wszystkie']];
$roleOptionsForJs = [];

foreach (UZYTKOWNIK_ROLES as $roleOption) {
    $roleFilterOptions[] = [
        'value' => $roleOption,
        'label' => $roleLabels[$roleOption] ?? $roleOption,
    ];
    $roleOptionsForJs[] = [
        'value' => $roleOption,
        'label' => $roleLabels[$roleOption] ?? $roleOption,
    ];
}

$statusFilterOptions = [];

foreach (UZYTKOWNIK_STATUS_FILTERS as $statusOption) {
    $statusFilterOptions[] = [
        'value' => $statusOption,
        'label' => $statusLabels[$statusOption] ?? $statusOption,
    ];
}

panel_layout_head($user, 'Użytkownicy — WeFixIT', 'users');
$roleOptionsJson = htmlspecialchars(json_encode($roleOptionsForJs, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
?>
<section id="users-page" class="<?= PANEL_CARD_WIDE_CLASS ?>"
    data-api="<?= htmlspecialchars($usersApi, ENT_QUOTES, 'UTF-8') ?>"
    data-employees-api="<?= htmlspecialchars($employeesApi, ENT_QUOTES, 'UTF-8') ?>"
    data-user-id="<?= (int) $user['id'] ?>"
    data-role-options="<?= $roleOptionsJson ?>">
    <?php
    panel_section_header(
        'Użytkownicy',
        'Zarządzaj rolami i aktywnością kont. Panel nie tworzy ani nie usuwa kont.',
        [
            ['label' => 'Eksportuj CSV', 'href' => $exportUrl],
        ]
    );
    ?>

    <?php if ($users === null): ?>
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            Nie udało się pobrać listy użytkowników. Spróbuj ponownie później.
        </div>
    <?php else: ?>
        <form id="users-filter-form" method="get"
            action="<?= htmlspecialchars($usersUrl, ENT_QUOTES, 'UTF-8') ?>"
            class="mb-5 grid grid-cols-1 gap-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:grid-cols-2">
            <input type="hidden" name="size" value="<?= $size ?>">

            <div>
                <label for="users-filter-search" class="<?= LAYOUT_LABEL_CLASS ?>">Szukaj</label>
                <input id="users-filter-search" name="szukaj" type="search" maxlength="100"
                    value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Imię, nazwisko lub e-mail" class="<?= LAYOUT_INPUT_CLASS ?>">
            </div>

            <div class="flex flex-wrap items-center gap-2 sm:items-end">
                <button type="submit" class="<?= panel_button_classes('secondary') ?>">Szukaj</button>
                <a href="<?= htmlspecialchars($usersUrl, ENT_QUOTES, 'UTF-8') ?>"
                    data-clear-filters="1"<?= $hasFilters ? '' : ' hidden' ?>
                    class="<?= panel_button_classes('ghost') ?>">Wyczyść</a>
            </div>
        </form>

        <div class="<?= PANEL_TABLE_WRAP_CLASS ?>">
            <table id="users-table" class="<?= PANEL_TABLE_CLASS ?>">
                <thead>
                    <tr>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Użytkownik</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">E-mail</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">
                            <?php panel_filter_start('users-role', 'Rola', $roleFilter !== ''); ?>
                                <?php panel_filter_options(
                                    'users-filter-form',
                                    'rola',
                                    'Filtruj według roli',
                                    $roleFilterOptions,
                                    $roleFilter === '' ? 'all' : $roleFilter
                                ); ?>
                            <?php panel_filter_end(); ?>
                        </th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Profil pracownika</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">
                            <?php panel_filter_start('users-status', 'Status konta', $statusFilter !== 'all'); ?>
                                <?php panel_filter_options(
                                    'users-filter-form',
                                    'status',
                                    'Filtruj według statusu konta',
                                    $statusFilterOptions,
                                    $statusFilter
                                ); ?>
                            <?php panel_filter_end(); ?>
                        </th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?> text-right">Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($users === []): ?>
                        <tr>
                            <td colspan="6" class="p-4">
                                <?php
                                if ($hasFilters) {
                                    panel_empty_state(
                                        'Brak wyników',
                                        'Żadne konto nie spełnia wybranych filtrów ani frazy wyszukiwania. Zmień kryteria i spróbuj ponownie.',
                                        ['label' => 'Wyczyść filtry', 'href' => $usersUrl, 'clear' => true]
                                    );
                                } else {
                                    panel_empty_state(
                                        'Brak użytkowników',
                                        'W bazie nie ma jeszcze kont do wyświetlenia.'
                                    );
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($users as $managedUser): ?>
                        <?php
                        $managedUserId = (int) $managedUser['id'];
                        $isSelf = $managedUserId === (int) $user['id'];
                        $hasEmployeeProfile = $managedUser['ma_profil_pracownika'];
                        $employeeProfileActive = $managedUser['profil_pracownika_aktywny'];
                        $managedUserName = $managedUser['imie'] . ' ' . $managedUser['nazwisko'];
                        $managedRoleLabel = $roleLabels[$managedUser['rola']] ?? $managedUser['rola'];
                        ?>
                        <tr class="<?= PANEL_TR_CLASS ?>">
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <span class="block font-medium text-zinc-900 dark:text-zinc-50">
                                    <?= htmlspecialchars($managedUserName, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php if ($isSelf): ?>
                                    <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">Twoje konto</span>
                                <?php endif; ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <?= htmlspecialchars($managedUser['email'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <?= panel_badge(
                                    $managedRoleLabel,
                                    $isSelf ? 'success' : 'neutral'
                                ) ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <?php if ($hasEmployeeProfile): ?>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-medium">Tak</span>
                                        <?= panel_badge(
                                            $employeeProfileActive ? 'Aktywny' : 'Nieaktywny',
                                            $employeeProfileActive ? 'success' : 'neutral'
                                        ) ?>
                                    </div>
                                <?php else: ?>
                                    <?= panel_badge('Nie', 'neutral') ?>
                                <?php endif; ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <?= panel_badge(
                                    $managedUser['aktywny'] ? 'Aktywne' : 'Nieaktywne',
                                    $managedUser['aktywny'] ? 'success' : 'neutral'
                                ) ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <?php if ($isSelf): ?>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                        Nie możesz zmienić roli własnego konta.
                                    </span>
                                <?php else: ?>
                                    <div class="flex flex-col items-start gap-1">
                                        <?php
                                        // Dropdown roli wykorzystuje mechanizm menu filtra
                                        // z nagłówków tabel, więc pozycjonowaniem i animacją
                                        // zajmuje się panel.js.
                                        ?>
                                        <div class="relative" data-filter-dropdown data-user-role
                                            data-id="<?= $managedUserId ?>"
                                            data-name="<?= htmlspecialchars($managedUserName, ENT_QUOTES, 'UTF-8') ?>"
                                            data-current-role="<?= htmlspecialchars($managedUser['rola'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-employee-id="<?= $hasEmployeeProfile ? (int) $managedUser['profil_pracownika_id'] : 0 ?>"
                                            data-profile-active="<?= $employeeProfileActive ? '1' : '0' ?>">
                                            <button type="button" class="<?= panel_button_classes('secondary') ?>"
                                                data-filter-toggle aria-expanded="false" aria-haspopup="true"
                                                aria-controls="user-role-<?= $managedUserId ?>-menu">
                                                <span>Zmień rolę</span>
                                                <?= panel_icon('chevron-down', 'h-4 w-4 shrink-0') ?>
                                            </button>
                                            <div id="user-role-<?= $managedUserId ?>-menu"
                                                class="<?= PANEL_FILTER_MENU_CLASS ?>" data-filter-menu>
                                                <p class="<?= PANEL_FILTER_MENU_TITLE_CLASS ?>" aria-hidden="true">
                                                    Wybierz nową rolę
                                                </p>
                                                <fieldset>
                                                    <legend class="sr-only">Nowa rola konta</legend>
                                                    <?php foreach (UZYTKOWNIK_ROLES as $roleOption): ?>
                                                        <label class="<?= PANEL_FILTER_OPTION_CLASS ?>">
                                                            <input type="radio" name="rola-<?= $managedUserId ?>"
                                                                value="<?= htmlspecialchars($roleOption, ENT_QUOTES, 'UTF-8') ?>"
                                                                class="<?= PANEL_FILTER_RADIO_CLASS ?>"
                                                                data-user-role-option
                                                                <?= $managedUser['rola'] === $roleOption ? 'checked' : '' ?>>
                                                            <span class="min-w-0 break-words">
                                                                <?= htmlspecialchars($roleLabels[$roleOption] ?? $roleOption, ENT_QUOTES, 'UTF-8') ?>
                                                            </span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </fieldset>
                                            </div>
                                        </div>
                                        <?= panel_field_error('user-error-rola-' . $managedUserId) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div data-list-pagination>
            <?php panel_pagination($basePath, '/uzytkownicy', $paginationParams, $userPage); ?>
        </div>

        <p id="users-error" role="alert" class="mt-3 hidden text-sm text-red-600 dark:text-red-400"></p>
    <?php endif; ?>

    <noscript>
        <p class="mt-6 text-sm text-red-600 dark:text-red-400">Włącz JavaScript, aby zarządzać użytkownikami.</p>
    </noscript>
</section>

<?php panel_layout_foot(['panel-list.js', 'users.js']); ?>
