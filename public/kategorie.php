<?php
declare(strict_types=1);

/*
    Strona kategorii usług w panelu administratora.

    Lista pochodzi z app/kategorie.php, a formularz dodawania i edycji wysyła
    żądania fetch() do /api/admin/categories. Widok nie wykonuje SQL ani
    operacji zapisu.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';
require_once dirname(__DIR__) . '/app/kategorie.php';

$user = require_role(['admin']);
$categories = kategorie_list();
$basePath = auth_base_path();
$categoriesApi = $basePath . '/api/admin/categories';
$csrfToken = csrf_token();

panel_layout_head($user, 'Kategorie — WeFixIT', 'categories');
?>
<section class="<?= PANEL_CARD_WIDE_CLASS ?>">
    <?php
    panel_section_header(
        'Kategorie',
        'Zarządzaj kategoriami usług dostępnych w serwisie.',
        [
            ['label' => 'Dodaj kategorię', 'href' => '#category-form'],
        ]
    );
    ?>

    <?php if ($categories === null): ?>
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            Nie udało się pobrać listy kategorii. Spróbuj ponownie później.
        </div>
    <?php elseif ($categories === []): ?>
        <?php
        panel_empty_state(
            'Brak kategorii',
            'Dodaj pierwszą kategorię, aby przypisywać do niej usługi.',
            ['label' => 'Dodaj kategorię', 'href' => '#category-form']
        );
        ?>
    <?php else: ?>
        <div class="<?= PANEL_TABLE_WRAP_CLASS ?>">
            <table id="categories-table" class="<?= PANEL_TABLE_CLASS ?>">
                <thead>
                    <tr>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Nazwa</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Opis</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Usługi</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Status</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?> text-right">Akcje</th>
                    </tr>
                </thead>
                <tbody>
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
                                        data-id="<?= $category['id'] ?>"
                                        data-name="<?= htmlspecialchars($category['nazwa'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-description="<?= htmlspecialchars($category['opis'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        Edytuj
                                    </button>
                                    <button type="button" class="<?= panel_button_classes('secondary') ?>"
                                        data-category-toggle
                                        data-id="<?= $category['id'] ?>"
                                        data-name="<?= htmlspecialchars($category['nazwa'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-active="<?= $category['aktywna'] ? '1' : '0' ?>">
                                        <?= $category['aktywna'] ? 'Dezaktywuj' : 'Aktywuj' ?>
                                    </button>
                                    <button type="button" class="<?= panel_button_classes('danger') ?>"
                                        data-category-delete
                                        data-id="<?= $category['id'] ?>"
                                        data-name="<?= htmlspecialchars($category['nazwa'], ENT_QUOTES, 'UTF-8') ?>">
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

<section id="category-form-card" class="<?= PANEL_CARD_WIDE_CLASS ?> mt-6">
    <h2 id="category-form-title" class="text-lg font-semibold tracking-tight">Dodaj kategorię</h2>
    <p class="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400">Nowa kategoria będzie od razu aktywna.</p>

    <form id="category-form" class="mt-5 max-w-2xl space-y-4" novalidate>
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
            <button type="button" id="category-cancel" class="<?= panel_button_classes('secondary') ?> hidden">
                Anuluj edycję
            </button>
        </div>
    </form>

    <noscript>
        <p class="mt-4 text-sm text-red-600 dark:text-red-400">Włącz JavaScript, aby zarządzać kategoriami.</p>
    </noscript>
</section>

<script>
(function () {
    const categoryForm = document.getElementById('category-form');
    const categoryFormTitle = document.getElementById('category-form-title');
    const categoryFormError = document.getElementById('category-form-error');
    const categorySubmit = document.getElementById('category-submit');
    const categoryCancel = document.getElementById('category-cancel');
    const categoryId = document.getElementById('category-id');
    const categoryName = document.getElementById('category-nazwa');
    const categoryDescription = document.getElementById('category-opis');
    const categoryApi = <?= json_encode($categoriesApi, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const categoryFieldErrors = {
        nazwa: document.getElementById('category-error-nazwa'),
        opis: document.getElementById('category-error-opis')
    };
    let editingCategoryId = null;
    let formBusy = false;

    function clearCategoryMessages() {
        categoryFormError.textContent = '';
        categoryFormError.classList.add('hidden');

        for (const element of Object.values(categoryFieldErrors)) {
            element.textContent = '';
            element.classList.add('hidden');
        }
    }

    function showCategoryErrors(data) {
        let shownFieldError = false;

        for (const [field, message] of Object.entries(data.errors || {})) {
            if (!categoryFieldErrors[field] || typeof message !== 'string') continue;

            categoryFieldErrors[field].textContent = message;
            categoryFieldErrors[field].classList.remove('hidden');
            shownFieldError = true;
        }

        if (shownFieldError) return;

        categoryFormError.textContent = typeof data.error === 'string' && data.error !== ''
            ? data.error
            : 'Nie udało się zapisać kategorii.';
        categoryFormError.classList.remove('hidden');
    }

    function resetCategoryForm() {
        editingCategoryId = null;
        categoryForm.reset();
        categoryId.value = '';
        categoryFormTitle.textContent = 'Dodaj kategorię';
        categorySubmit.textContent = 'Dodaj kategorię';
        categoryCancel.classList.add('hidden');
        clearCategoryMessages();
    }

    function reloadCategories() {
        window.setTimeout(function () {
            window.location.reload();
        }, 700);
    }

    async function sendCategoryRequest(method, payload) {
        const response = await fetch(categoryApi, {
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

    categoryForm.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (formBusy) return;

        clearCategoryMessages();
        formBusy = true;
        categorySubmit.disabled = true;
        categoryCancel.disabled = true;

        const submitLabel = categorySubmit.textContent;
        let completed = false;
        categorySubmit.textContent = 'Zapisywanie…';

        const payload = {
            nazwa: categoryName.value.trim(),
            opis: categoryDescription.value.trim()
        };

        if (editingCategoryId !== null) {
            payload.id = editingCategoryId;
        }

        try {
            const result = await sendCategoryRequest(editingCategoryId === null ? 'POST' : 'PATCH', payload);

            if (!result.response.ok || result.data.success !== true) {
                showCategoryErrors(result.data);
                return;
            }

            completed = true;
            showToast(
                editingCategoryId === null ? 'Kategoria została dodana.' : 'Kategoria została zapisana.',
                'success'
            );
            reloadCategories();
        } catch {
            categoryFormError.textContent = 'Nie udało się połączyć z serwerem. Spróbuj ponownie.';
            categoryFormError.classList.remove('hidden');
        } finally {
            if (!completed) {
                formBusy = false;
                categorySubmit.disabled = false;
                categoryCancel.disabled = false;
                categorySubmit.textContent = submitLabel;
            }
        }
    });

    categoryCancel.addEventListener('click', function () {
        resetCategoryForm();
    });

    for (const button of document.querySelectorAll('[data-category-edit]')) {
        button.addEventListener('click', function () {
            editingCategoryId = Number(button.dataset.id);
            categoryId.value = String(editingCategoryId);
            categoryName.value = button.dataset.name || '';
            categoryDescription.value = button.dataset.description || '';
            categoryFormTitle.textContent = 'Edytuj kategorię';
            categorySubmit.textContent = 'Zapisz zmiany';
            categoryCancel.classList.remove('hidden');
            clearCategoryMessages();
            categoryForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
            categoryName.focus();
        });
    }

    for (const button of document.querySelectorAll('[data-category-toggle]')) {
        button.addEventListener('click', async function () {
            const isActive = button.dataset.active === '1';
            const name = button.dataset.name || 'tę kategorię';
            const confirmed = await window.panelConfirm({
                title: isActive ? 'Dezaktywować kategorię?' : 'Aktywować kategorię?',
                message: isActive
                    ? 'Kategoria „' + name + '” przestanie być dostępna. Przypisane usługi pozostaną w bazie.'
                    : 'Kategoria „' + name + '” znów będzie dostępna.',
                confirmLabel: isActive ? 'Dezaktywuj' : 'Aktywuj',
                danger: false
            });

            if (!confirmed) return;

            button.disabled = true;
            let completed = false;

            try {
                const result = await sendCategoryRequest('PATCH', {
                    id: Number(button.dataset.id),
                    aktywna: !isActive
                });

                if (!result.response.ok || result.data.success !== true) {
                    showToast(
                        typeof result.data.error === 'string' && result.data.error !== ''
                            ? result.data.error
                            : 'Nie udało się zmienić statusu kategorii.',
                        'error'
                    );
                    return;
                }

                completed = true;
                showToast(
                    isActive ? 'Kategoria została dezaktywowana.' : 'Kategoria została aktywowana.',
                    'success'
                );
                reloadCategories();
            } catch {
                showToast('Nie udało się połączyć z serwerem. Spróbuj ponownie.', 'error');
            } finally {
                if (!completed) {
                    button.disabled = false;
                }
            }
        });
    }

    for (const button of document.querySelectorAll('[data-category-delete]')) {
        button.addEventListener('click', async function () {
            const name = button.dataset.name || 'tę kategorię';
            const confirmed = await window.panelConfirm({
                title: 'Usunąć kategorię?',
                message: 'Kategoria „' + name + '” zostanie trwale usunięta. Tej operacji nie można cofnąć.',
                confirmLabel: 'Usuń',
                danger: true
            });

            if (!confirmed) return;

            button.disabled = true;
            let completed = false;

            try {
                const result = await sendCategoryRequest('DELETE', {
                    id: Number(button.dataset.id)
                });

                if (!result.response.ok || result.data.success !== true) {
                    showToast(
                        typeof result.data.error === 'string' && result.data.error !== ''
                            ? result.data.error
                            : 'Nie udało się usunąć kategorii.',
                        'error'
                    );
                    return;
                }

                completed = true;
                showToast('Kategoria została usunięta.', 'success');
                reloadCategories();
            } catch {
                showToast('Nie udało się połączyć z serwerem. Spróbuj ponownie.', 'error');
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
