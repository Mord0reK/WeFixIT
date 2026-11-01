<?php
declare(strict_types=1);

/*
    Strona administracji kontami użytkowników.

    Wyświetla listę kont z filtrami, wyszukiwaniem i paginacją, zmianą roli
    oraz aktywacją lub dezaktywacją przez window.panelConfirm. Widok nie
    wykonuje SQL ani operacji zapisu — korzysta z app/uzytkownicy.php
    i endpointu JSON.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';
require_once dirname(__DIR__) . '/app/uzytkownicy.php';

$user = require_role(['admin']);
$basePath = auth_base_path();
$usersUrl = $basePath . '/uzytkownicy';
$usersApi = $basePath . '/api/admin/users';
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

$exportUrl = $basePath . '/eksport?' . http_build_query($exportParams);
$csrfToken = csrf_token();
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

panel_layout_head($user, 'Użytkownicy — WeFixIT', 'users');
?>
<section class="<?= PANEL_CARD_WIDE_CLASS ?>">
    <?php
    panel_section_header(
        'Użytkownicy',
        'Zarządzaj rolami i aktywnością kont. Panel nie tworzy ani nie usuwa kont.',
        [
            ['label' => 'Eksportuj CSV', 'href' => $exportUrl],
        ]
    );
    ?>

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

        <div class="w-full sm:w-48">
            <label for="users-filter-role" class="<?= LAYOUT_LABEL_CLASS ?>">Rola</label>
            <select id="users-filter-role" name="rola" class="<?= LAYOUT_INPUT_CLASS ?>">
                <option value="all" <?= $roleFilter === '' ? 'selected' : '' ?>>Wszystkie role</option>
                <?php foreach (UZYTKOWNIK_ROLES as $roleOption): ?>
                    <option value="<?= htmlspecialchars($roleOption, ENT_QUOTES, 'UTF-8') ?>"
                        <?= $roleFilter === $roleOption ? 'selected' : '' ?>>
                        <?= htmlspecialchars($roleLabels[$roleOption] ?? $roleOption, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="w-full sm:w-48">
            <label for="users-filter-status" class="<?= LAYOUT_LABEL_CLASS ?>">Status</label>
            <select id="users-filter-status" name="status" class="<?= LAYOUT_INPUT_CLASS ?>">
                <?php foreach (UZYTKOWNIK_STATUS_FILTERS as $statusOption): ?>
                    <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8') ?>"
                        <?= $statusFilter === $statusOption ? 'selected' : '' ?>>
                        <?= htmlspecialchars($statusLabels[$statusOption] ?? $statusOption, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="submit" class="<?= panel_button_classes('secondary') ?>">Filtruj</button>
            <?php if ($hasFilters): ?>
                <a href="<?= htmlspecialchars($usersUrl, ENT_QUOTES, 'UTF-8') ?>"
                    class="<?= panel_button_classes('ghost') ?>">Wyczyść filtry</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($users === null): ?>
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            Nie udało się pobrać listy użytkowników. Spróbuj ponownie później.
        </div>
    <?php elseif ($users === []): ?>
        <?php
        if ($hasFilters) {
            panel_empty_state(
                'Brak wyników',
                'Żadne konto nie spełnia wybranych filtrów ani frazy wyszukiwania. Zmień kryteria i spróbuj ponownie.',
                ['label' => 'Wyczyść filtry', 'href' => $usersUrl]
            );
        } else {
            panel_empty_state(
                'Brak użytkowników',
                'W bazie nie ma jeszcze kont do wyświetlenia.'
            );
        }
        ?>
    <?php else: ?>
        <div class="<?= PANEL_TABLE_WRAP_CLASS ?>">
            <table id="users-table" class="<?= PANEL_TABLE_CLASS ?>">
                <thead>
                    <tr>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Użytkownik</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">E-mail</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Rola</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Profil pracownika</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Status konta</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?> text-right">Akcje</th>
                    </tr>
                </thead>
                <tbody>
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
                                        Nie możesz zmienić roli ani dezaktywować własnego konta.
                                    </span>
                                <?php else: ?>
                                    <div class="flex flex-col items-stretch gap-3">
                                        <form class="flex flex-col gap-1"
                                            data-user-role-form
                                            data-id="<?= $managedUserId ?>"
                                            method="post"
                                            action="<?= htmlspecialchars($usersApi, ENT_QUOTES, 'UTF-8') ?>"
                                            novalidate>
                                            <div class="flex items-center gap-2">
                                                <label for="user-role-<?= $managedUserId ?>" class="sr-only">Rola</label>
                                                <select id="user-role-<?= $managedUserId ?>" name="rola"
                                                    class="<?= LAYOUT_INPUT_CLASS ?> h-9">
                                                    <?php foreach (UZYTKOWNIK_ROLES as $roleOption): ?>
                                                        <option value="<?= htmlspecialchars($roleOption, ENT_QUOTES, 'UTF-8') ?>"
                                                            <?= $managedUser['rola'] === $roleOption ? 'selected' : '' ?>
                                                            <?= $hasEmployeeProfile && $roleOption === 'klient' ? 'disabled' : '' ?>>
                                                            <?= htmlspecialchars($roleLabels[$roleOption] ?? $roleOption, ENT_QUOTES, 'UTF-8') ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="<?= panel_button_classes('secondary') ?>">
                                                    Zmień rolę
                                                </button>
                                            </div>
                                            <?php if ($hasEmployeeProfile): ?>
                                                <p class="<?= LAYOUT_HINT_CLASS ?>">
                                                    Zmiana na klienta wymaga wcześniejszej dezaktywacji profilu pracownika.
                                                </p>
                                            <?php endif; ?>
                                            <?= panel_field_error('user-error-rola-' . $managedUserId) ?>
                                        </form>

                                        <div class="flex flex-col items-start gap-1">
                                            <button type="button" class="<?= panel_button_classes('secondary') ?>"
                                                data-user-toggle
                                                data-id="<?= $managedUserId ?>"
                                                data-active="<?= $managedUser['aktywny'] ? '1' : '0' ?>"
                                                data-name="<?= htmlspecialchars($managedUserName, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= $managedUser['aktywny'] ? 'Dezaktywuj' : 'Aktywuj' ?>
                                            </button>
                                            <?= panel_field_error('user-error-aktywny-' . $managedUserId) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php panel_pagination($basePath, '/uzytkownicy', $paginationParams, $userPage); ?>

        <p id="users-error" role="alert" class="mt-3 hidden text-sm text-red-600 dark:text-red-400"></p>
    <?php endif; ?>

    <noscript>
        <p class="mt-6 text-sm text-red-600 dark:text-red-400">Włącz JavaScript, aby zarządzać użytkownikami.</p>
    </noscript>
</section>

<script>
(function () {
    const usersApi = <?= json_encode($usersApi, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const generalError = document.getElementById('users-error');
    let busy = false;

    function clearMessages() {
        if (generalError) {
            generalError.textContent = '';
            generalError.classList.add('hidden');
        }

        document.querySelectorAll('[id^="user-error-"]').forEach(function (element) {
            element.textContent = '';
            element.classList.add('hidden');
        });
    }

    function showGeneralError(message) {
        if (!generalError) return;

        generalError.textContent = message;
        generalError.classList.remove('hidden');
    }

    function showRowErrors(id, data) {
        let shown = false;
        const roleError = document.getElementById('user-error-rola-' + id);
        const activeError = document.getElementById('user-error-aktywny-' + id);
        const roleMessage = data.errors && typeof data.errors.rola === 'string' ? data.errors.rola : '';
        const activeMessage = data.errors && typeof data.errors.aktywny === 'string' ? data.errors.aktywny : '';

        if (roleError && roleMessage !== '') {
            roleError.textContent = roleMessage;
            roleError.classList.remove('hidden');
            shown = true;
        }

        if (activeError && activeMessage !== '') {
            activeError.textContent = activeMessage;
            activeError.classList.remove('hidden');
            shown = true;
        }

        if (!shown) {
            showGeneralError(typeof data.error === 'string' && data.error !== ''
                ? data.error
                : 'Nie udało się zapisać konta użytkownika.');
        }
    }

    async function send(payload) {
        const response = await fetch(usersApi, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify(payload)
        });
        let data = {};

        try {
            data = await response.json();
        } catch {
            data = {};
        }

        return { response: response, data: data };
    }

    document.querySelectorAll('[data-user-role-form]').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (busy) return;

            const id = Number(form.dataset.id);
            const button = form.querySelector('button[type="submit"]');
            clearMessages();
            busy = true;
            if (button) button.disabled = true;
            let completed = false;

            try {
                const result = await send({ id: id, rola: form.elements.rola.value });

                if (!result.response.ok || result.data.success !== true) {
                    showRowErrors(id, result.data);
                    return;
                }

                completed = true;
                window.showToast('Rola użytkownika została zmieniona.', 'success');
                window.setTimeout(function () {
                    window.location.reload();
                }, 600);
            } catch {
                showGeneralError('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
            } finally {
                if (!completed) {
                    busy = false;
                    if (button) button.disabled = false;
                }
            }
        });
    });

    document.querySelectorAll('[data-user-toggle]').forEach(function (button) {
        button.addEventListener('click', async function () {
            if (busy) return;

            const id = Number(button.dataset.id);
            const active = button.dataset.active === '1';
            const name = button.dataset.name || '';
            const confirmed = await window.panelConfirm({
                title: active ? 'Dezaktywuj konto' : 'Aktywuj konto',
                message: active
                    ? 'Czy dezaktywować konto ' + name + '? Użytkownik nie będzie mógł się zalogować.'
                    : 'Czy aktywować konto ' + name + '?',
                confirmLabel: active ? 'Dezaktywuj' : 'Aktywuj',
                danger: active
            });

            if (!confirmed) return;

            clearMessages();
            busy = true;
            button.disabled = true;
            let completed = false;

            try {
                const result = await send({ id: id, aktywny: !active });

                if (!result.response.ok || result.data.success !== true) {
                    showRowErrors(id, result.data);
                    return;
                }

                completed = true;
                window.showToast(
                    active ? 'Konto zostało dezaktywowane.' : 'Konto zostało aktywowane.',
                    'success'
                );
                window.setTimeout(function () {
                    window.location.reload();
                }, 600);
            } catch {
                showGeneralError('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
            } finally {
                if (!completed) {
                    busy = false;
                    button.disabled = false;
                }
            }
        });
    });
})();
</script>
<?php panel_layout_foot(); ?>
