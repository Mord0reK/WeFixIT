<?php
declare(strict_types=1);

/*
    Strona pracowników w panelu administratora.

    Lista i formularze korzystają z app/pracownicy.php. Panel tworzy wyłącznie
    profile powiązane z istniejącymi kontami o roli pracownik — nie zakłada
    nowych kont użytkowników. Widok nie wykonuje SQL ani operacji zapisu.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';
require_once dirname(__DIR__) . '/app/pracownicy.php';

$user = require_role(['admin']);
$employees = pracownicy_list();
$availableAccounts = pracownicy_available_accounts();
$basePath = auth_base_path();
$employeesApi = $basePath . '/api/admin/employees';
$csrfToken = csrf_token();
$hasAvailableAccounts = $availableAccounts !== null && $availableAccounts !== [];
$hasEmployees = $employees !== null && $employees !== [];
$headerActions = $hasAvailableAccounts
    ? [['label' => 'Dodaj pracownika', 'href' => '#employee-form']]
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
    <?php elseif ($employees === []): ?>
        <?php
        if ($availableAccounts === []) {
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
    <?php else: ?>
        <div class="<?= PANEL_TABLE_WRAP_CLASS ?>">
            <table id="employees-table" class="<?= PANEL_TABLE_CLASS ?>">
                <thead>
                    <tr>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Pracownik</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">E-mail</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Usługi</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Status</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?> text-right">Akcje</th>
                    </tr>
                </thead>
                <tbody>
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
                                    <button type="button" class="<?= panel_button_classes('ghost') ?>"
                                        data-employee-edit
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
                                        data-active="<?= $employee['aktywny'] ? '1' : '0' ?>">
                                        <?= $employee['aktywny'] ? 'Dezaktywuj' : 'Aktywuj' ?>
                                    </button>
                                    <button type="button" class="<?= panel_button_classes('danger') ?>"
                                        data-employee-delete
                                        data-id="<?= $employee['id'] ?>"
                                        data-name="<?= htmlspecialchars($employee['imie'] . ' ' . $employee['nazwisko'], ENT_QUOTES, 'UTF-8') ?>">
                                        Usuń
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
    <section id="employee-form-card" class="<?= PANEL_CARD_WIDE_CLASS ?> mt-6 <?= $hasAvailableAccounts ? '' : 'hidden' ?>">
        <h2 id="employee-form-title" class="text-lg font-semibold tracking-tight">Dodaj pracownika</h2>
        <p id="employee-form-hint" class="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400">
            Profil zostanie powiązany z istniejącym kontem o roli pracownik.
        </p>

        <form id="employee-form" class="mt-5 max-w-2xl space-y-4" novalidate>
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
                <button type="button" id="employee-cancel" class="<?= panel_button_classes('secondary') ?> hidden">
                    Anuluj edycję
                </button>
            </div>
        </form>

        <noscript>
            <p class="mt-4 text-sm text-red-600 dark:text-red-400">Włącz JavaScript, aby zarządzać pracownikami.</p>
        </noscript>
    </section>
<?php endif; ?>

<script>
(function () {
    const employeeForm = document.getElementById('employee-form');
    const employeeFormCard = document.getElementById('employee-form-card');
    const employeeFormTitle = document.getElementById('employee-form-title');
    const employeeFormHint = document.getElementById('employee-form-hint');
    const employeeFormError = document.getElementById('employee-form-error');
    const employeeSubmit = document.getElementById('employee-submit');
    const employeeCancel = document.getElementById('employee-cancel');
    const employeeId = document.getElementById('employee-id');
    const employeeDescription = document.getElementById('employee-opis');
    const employeeActive = document.getElementById('employee-aktywny');
    const employeeAccount = document.getElementById('employee-uzytkownik');
    const employeeAccountSelectWrap = document.getElementById('employee-account-select-wrap');
    const employeeAccountReadonlyWrap = document.getElementById('employee-account-readonly-wrap');
    const employeeAccountReadonly = document.getElementById('employee-account-readonly');
    const employeeApi = <?= json_encode($employeesApi, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const canAdd = <?= $hasAvailableAccounts ? 'true' : 'false' ?>;
    const employeeFieldErrors = {
        uzytkownik_id: document.getElementById('employee-error-uzytkownik_id'),
        opis: document.getElementById('employee-error-opis'),
        aktywny: document.getElementById('employee-error-aktywny')
    };
    let editingEmployeeId = null;
    let formBusy = false;

    function clearEmployeeMessages() {
        employeeFormError.textContent = '';
        employeeFormError.classList.add('hidden');

        for (const element of Object.values(employeeFieldErrors)) {
            element.textContent = '';
            element.classList.add('hidden');
        }
    }

    function showEmployeeErrors(data) {
        let shownFieldError = false;

        for (const [field, message] of Object.entries(data.errors || {})) {
            if (!employeeFieldErrors[field] || typeof message !== 'string') continue;

            employeeFieldErrors[field].textContent = message;
            employeeFieldErrors[field].classList.remove('hidden');
            shownFieldError = true;
        }

        if (shownFieldError) return;

        employeeFormError.textContent = typeof data.error === 'string' && data.error !== ''
            ? data.error
            : 'Nie udało się zapisać pracownika.';
        employeeFormError.classList.remove('hidden');
    }

    function resetEmployeeForm() {
        editingEmployeeId = null;
        employeeForm.reset();
        employeeId.value = '';
        employeeFormTitle.textContent = 'Dodaj pracownika';
        employeeFormHint.textContent = 'Profil zostanie powiązany z istniejącym kontem o roli pracownik.';
        employeeSubmit.textContent = 'Dodaj pracownika';
        employeeCancel.classList.add('hidden');
        employeeAccount.disabled = false;
        employeeAccountSelectWrap.classList.remove('hidden');
        employeeAccountReadonlyWrap.classList.add('hidden');
        clearEmployeeMessages();

        if (!canAdd) {
            employeeFormCard.classList.add('hidden');
        }
    }

    function reloadEmployees() {
        window.setTimeout(function () {
            window.location.reload();
        }, 700);
    }

    async function sendEmployeeRequest(method, payload) {
        const response = await fetch(employeeApi, {
            method: method,
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

    if (employeeForm) {
        employeeForm.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (formBusy) return;

            clearEmployeeMessages();
            formBusy = true;
            employeeSubmit.disabled = true;
            employeeCancel.disabled = true;

            const submitLabel = employeeSubmit.textContent;
            let completed = false;
            employeeSubmit.textContent = 'Zapisywanie…';

            const payload = {
                opis: employeeDescription.value.trim(),
                aktywny: employeeActive.checked
            };

            if (editingEmployeeId === null) {
                payload.uzytkownik_id = Number(employeeAccount.value);
            } else {
                payload.id = editingEmployeeId;
            }

            try {
                const result = await sendEmployeeRequest(
                    editingEmployeeId === null ? 'POST' : 'PATCH',
                    payload
                );

                if (!result.response.ok || result.data.success !== true) {
                    showEmployeeErrors(result.data);
                    return;
                }

                completed = true;
                window.showToast(
                    editingEmployeeId === null ? 'Pracownik został dodany.' : 'Pracownik został zapisany.',
                    'success'
                );
                reloadEmployees();
            } catch {
                employeeFormError.textContent = 'Nie udało się połączyć z serwerem. Spróbuj ponownie.';
                employeeFormError.classList.remove('hidden');
            } finally {
                if (!completed) {
                    formBusy = false;
                    employeeSubmit.disabled = false;
                    employeeCancel.disabled = false;
                    employeeSubmit.textContent = submitLabel;
                }
            }
        });

        employeeCancel.addEventListener('click', function () {
            resetEmployeeForm();
        });
    }

    for (const button of document.querySelectorAll('[data-employee-edit]')) {
        button.addEventListener('click', function () {
            editingEmployeeId = Number(button.dataset.id);
            employeeId.value = String(editingEmployeeId);
            employeeDescription.value = button.dataset.description || '';
            employeeActive.checked = button.dataset.active === '1';
            employeeAccountReadonly.textContent = button.dataset.account || '';
            employeeFormCard.classList.remove('hidden');
            employeeFormTitle.textContent = 'Edytuj pracownika';
            employeeFormHint.textContent = 'Edytowalne są tylko opis i flaga aktywności.';
            employeeSubmit.textContent = 'Zapisz zmiany';
            employeeCancel.classList.remove('hidden');
            employeeAccount.disabled = true;
            employeeAccountSelectWrap.classList.add('hidden');
            employeeAccountReadonlyWrap.classList.remove('hidden');
            clearEmployeeMessages();
            employeeFormCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            employeeDescription.focus();
        });
    }

    for (const button of document.querySelectorAll('[data-employee-toggle]')) {
        button.addEventListener('click', async function () {
            const isActive = button.dataset.active === '1';
            const name = button.dataset.name || 'tego pracownika';
            const confirmed = await window.panelConfirm({
                title: isActive ? 'Dezaktywować pracownika?' : 'Aktywować pracownika?',
                message: isActive
                    ? 'Profil pracownika „' + name + '” zostanie oznaczony jako nieaktywny. Powiązania pozostaną w bazie.'
                    : 'Profil pracownika „' + name + '” znów będzie aktywny.',
                confirmLabel: isActive ? 'Dezaktywuj' : 'Aktywuj',
                danger: false
            });

            if (!confirmed) return;

            button.disabled = true;
            let completed = false;

            try {
                const result = await sendEmployeeRequest('PATCH', {
                    id: Number(button.dataset.id),
                    aktywny: !isActive
                });

                if (!result.response.ok || result.data.success !== true) {
                    window.showToast(
                        typeof result.data.error === 'string' && result.data.error !== ''
                            ? result.data.error
                            : 'Nie udało się zmienić statusu pracownika.',
                        'error'
                    );
                    return;
                }

                completed = true;
                window.showToast(
                    isActive ? 'Pracownik został dezaktywowany.' : 'Pracownik został aktywowany.',
                    'success'
                );
                reloadEmployees();
            } catch {
                window.showToast('Nie udało się połączyć z serwerem. Spróbuj ponownie.', 'error');
            } finally {
                if (!completed) {
                    button.disabled = false;
                }
            }
        });
    }

    for (const button of document.querySelectorAll('[data-employee-delete]')) {
        button.addEventListener('click', async function () {
            const name = button.dataset.name || 'tego pracownika';
            const confirmed = await window.panelConfirm({
                title: 'Usunąć pracownika?',
                message: 'Profil pracownika „' + name + '” zostanie trwale usunięty. Tej operacji nie można cofnąć.',
                confirmLabel: 'Usuń',
                danger: true
            });

            if (!confirmed) return;

            button.disabled = true;
            let completed = false;

            try {
                const result = await sendEmployeeRequest('DELETE', {
                    id: Number(button.dataset.id)
                });

                if (!result.response.ok || result.data.success !== true) {
                    window.showToast(
                        typeof result.data.error === 'string' && result.data.error !== ''
                            ? result.data.error
                            : 'Nie udało się usunąć pracownika.',
                        'error'
                    );
                    return;
                }

                completed = true;
                window.showToast('Pracownik został usunięty.', 'success');
                reloadEmployees();
            } catch {
                window.showToast('Nie udało się połączyć z serwerem. Spróbuj ponownie.', 'error');
            } finally {
                if (!completed) {
                    button.disabled = false;
                }
            }
        });
    }
})();
</script>
<?php panel_layout_foot(); ?>
