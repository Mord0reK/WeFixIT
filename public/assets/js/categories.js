/*
    Zarządzanie kategoriami usług: formularz w wysuwanym panelu oraz akcje
    edycji, zmiany statusu i usuwania.

    Adres API pochodzi z data-api formularza, dzięki czemu działa również
    w instalacji w podkatalogu.
*/
(function () {
    const categoryDrawer = document.getElementById('category-drawer');
    const categoryForm = document.getElementById('category-form');
    const categoryFormTitle = document.getElementById('category-form-title');
    const categoryFormError = document.getElementById('category-form-error');
    const categorySubmit = document.getElementById('category-submit');
    const categoryCancel = document.getElementById('category-cancel');
    const categoryId = document.getElementById('category-id');
    const categoryName = document.getElementById('category-nazwa');
    const categoryDescription = document.getElementById('category-opis');
    const categoryApi = categoryForm.dataset.api || '';
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
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

    if (categoryDrawer) {
        categoryDrawer.addEventListener('panel:drawer-close', resetCategoryForm);
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
