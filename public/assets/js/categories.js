/*
    Zarządzanie kategoriami usług: lista pobierana z API, formularz
    w wysuwanym panelu oraz akcje edycji, zmiany statusu i usuwania.

    Adres API pochodzi z data-api formularza, dzięki czemu działa również
    w instalacji w podkatalogu. Wszystkie operacje kończą się odświeżeniem
    listy przez panelList zamiast przeładowaniem strony.
*/
(function () {
    const page = document.querySelector('[data-api][data-categories-url]');
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
    const categoriesUrl = page ? page.dataset.categoriesUrl || '' : '';
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const classes = window.panelTableClasses();
    const categoryFieldErrors = {
        nazwa: document.getElementById('category-error-nazwa'),
        opis: document.getElementById('category-error-opis')
    };
    let editingCategoryId = null;
    let formBusy = false;
    let operationBusy = false;

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

    function renderCategory(category) {
        const row = document.createElement('tr');
        row.className = classes.tr;

        const nameCell = document.createElement('td');
        nameCell.className = classes.td + ' font-medium text-zinc-900 dark:text-zinc-50';
        nameCell.textContent = category.nazwa || '';
        row.appendChild(nameCell);

        const descriptionCell = document.createElement('td');
        descriptionCell.className = classes.td + ' max-w-md';

        if (typeof category.opis === 'string' && category.opis !== '') {
            descriptionCell.textContent = category.opis;
        } else {
            const empty = document.createElement('span');
            empty.className = 'text-zinc-400 dark:text-zinc-500';
            empty.textContent = 'Brak opisu';
            descriptionCell.appendChild(empty);
        }

        row.appendChild(descriptionCell);

        const servicesCell = document.createElement('td');
        servicesCell.className = classes.td + ' whitespace-nowrap';
        servicesCell.appendChild(document.createTextNode(String(category.uslugi_total)));

        if (category.uslugi_active !== category.uslugi_total) {
            const active = document.createElement('span');
            active.className = 'text-xs text-zinc-500 dark:text-zinc-400';
            active.textContent = ' (aktywne: ' + category.uslugi_active + ')';
            servicesCell.appendChild(active);
        }

        row.appendChild(servicesCell);

        const statusCell = document.createElement('td');
        statusCell.className = classes.td;
        statusCell.appendChild(window.panelBadge(
            category.aktywna === true ? 'Aktywna' : 'Nieaktywna',
            category.aktywna === true ? 'success' : 'neutral'
        ));
        row.appendChild(statusCell);

        const actionsCell = document.createElement('td');
        actionsCell.className = classes.td;
        const actionsWrap = document.createElement('div');
        actionsWrap.className = 'flex flex-wrap justify-end gap-2';

        const isActive = category.aktywna === true;
        const name = category.nazwa || '';

        const editButton = document.createElement('button');
        editButton.type = 'button';
        editButton.className = window.panelButtonClasses('ghost');
        editButton.setAttribute('data-category-edit', '1');
        editButton.setAttribute('data-drawer-open', 'category-drawer');
        editButton.dataset.id = String(category.id);
        editButton.dataset.name = name;
        editButton.dataset.description = category.opis || '';
        editButton.textContent = 'Edytuj';
        actionsWrap.appendChild(editButton);

        const toggleButton = document.createElement('button');
        toggleButton.type = 'button';
        toggleButton.className = window.panelButtonClasses('secondary');
        toggleButton.setAttribute('data-category-toggle', '1');
        toggleButton.dataset.id = String(category.id);
        toggleButton.dataset.name = name;
        toggleButton.dataset.active = isActive ? '1' : '0';
        toggleButton.title = isActive ? 'Dezaktywuj' : 'Aktywuj';
        toggleButton.setAttribute('aria-label', isActive ? 'Dezaktywuj' : 'Aktywuj');
        toggleButton.appendChild(window.panelIcon(
            isActive ? 'circle-slash' : 'circle-check',
            isActive
                ? 'h-4 w-4 shrink-0 text-red-600 dark:text-red-400'
                : 'h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400'
        ));
        actionsWrap.appendChild(toggleButton);

        const deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = window.panelButtonClasses('danger');
        deleteButton.setAttribute('data-category-delete', '1');
        deleteButton.dataset.id = String(category.id);
        deleteButton.dataset.name = name;
        deleteButton.title = 'Usuń';
        deleteButton.setAttribute('aria-label', 'Usuń');
        deleteButton.appendChild(window.panelIcon('trash', 'h-4 w-4 shrink-0'));
        actionsWrap.appendChild(deleteButton);

        actionsCell.appendChild(actionsWrap);
        row.appendChild(actionsCell);

        return row;
    }

    const list = window.panelList({
        section: page,
        api: categoryApi,
        form: document.getElementById('categories-filter-form'),
        table: document.getElementById('categories-table'),
        pageUrl: categoriesUrl,
        itemsKey: 'kategorie',
        paginationSlot: page ? page.querySelector('[data-list-pagination]') : null,
        colspan: 5,
        renderRow: renderCategory,
        emptyState: function (hasFilters) {
            if (hasFilters) {
                return window.panelEmptyState({
                    title: 'Brak kategorii dla wybranej frazy',
                    description: 'Zmień frazę wyszukiwania, aby zobaczyć inne kategorie.',
                    action: { label: 'Wyczyść wyszukiwanie', href: categoriesUrl, clear: true }
                });
            }

            return window.panelEmptyState({
                title: 'Brak kategorii',
                description: 'Dodaj pierwszą kategorię, aby przypisywać do niej usługi.',
                action: { label: 'Dodaj kategorię', drawer: 'category-drawer' }
            });
        },
        onError: function (message) {
            window.showToast(message, 'error');
        }
    });

    // Gdy widok nie wyrenderował tabeli (np. błąd bazy), listy nie odświeżamy.
    async function refreshCategoryList() {
        if (list !== null) {
            await list.refresh();
        }
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
            window.showToast(
                editingCategoryId === null ? 'Kategoria została dodana.' : 'Kategoria została zapisana.',
                'success'
            );
            window.panelCloseDrawer(categoryDrawer, false);
            resetCategoryForm();
            await refreshCategoryList();
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

    // Delegacja na sekcji działa również po przebudowaniu wierszy.
    page.addEventListener('click', function (event) {
        const editButton = event.target instanceof Element ? event.target.closest('[data-category-edit]') : null;

        if (editButton === null) return;

        editingCategoryId = Number(editButton.dataset.id);
        categoryId.value = String(editingCategoryId);
        categoryName.value = editButton.dataset.name || '';
        categoryDescription.value = editButton.dataset.description || '';
        categoryFormTitle.textContent = 'Edytuj kategorię';
        categorySubmit.textContent = 'Zapisz zmiany';
        categoryCancel.classList.remove('hidden');
        clearCategoryMessages();
    });

    page.addEventListener('click', async function (event) {
        const toggleButton = event.target instanceof Element ? event.target.closest('[data-category-toggle]') : null;

        if (toggleButton === null) return;

        if (operationBusy) return;

        const isActive = toggleButton.dataset.active === '1';
        const name = toggleButton.dataset.name || 'tę kategorię';
        const confirmed = await window.panelConfirm({
            title: isActive ? 'Dezaktywować kategorię?' : 'Aktywować kategorię?',
            message: isActive
                ? 'Kategoria „' + name + '” przestanie być dostępna. Przypisane usługi pozostaną w bazie.'
                : 'Kategoria „' + name + '” znów będzie dostępna.',
            confirmLabel: isActive ? 'Dezaktywuj' : 'Aktywuj',
            danger: false
        });

        if (!confirmed) return;

        operationBusy = true;
        toggleButton.disabled = true;
        let completed = false;

        try {
            const result = await sendCategoryRequest('PATCH', {
                id: Number(toggleButton.dataset.id),
                aktywna: !isActive
            });

            if (!result.response.ok || result.data.success !== true) {
                window.showToast(
                    typeof result.data.error === 'string' && result.data.error !== ''
                        ? result.data.error
                        : 'Nie udało się zmienić statusu kategorii.',
                    'error'
                );
                return;
            }

            completed = true;
            window.showToast(
                isActive ? 'Kategoria została dezaktywowana.' : 'Kategoria została aktywowana.',
                'success'
            );
            await refreshCategoryList();
        } catch {
            window.showToast('Nie udało się połączyć z serwerem. Spróbuj ponownie.', 'error');
        } finally {
            if (!completed) {
                toggleButton.disabled = false;
            }

            operationBusy = false;
        }
    });

    page.addEventListener('click', async function (event) {
        const deleteButton = event.target instanceof Element ? event.target.closest('[data-category-delete]') : null;

        if (deleteButton === null) return;

        if (operationBusy) return;

        const name = deleteButton.dataset.name || 'tę kategorię';
        const confirmed = await window.panelConfirm({
            title: 'Usunąć kategorię?',
            message: 'Kategoria „' + name + '” zostanie trwale usunięta. Tej operacji nie można cofnąć.',
            confirmLabel: 'Usuń',
            danger: true
        });

        if (!confirmed) return;

        operationBusy = true;
        deleteButton.disabled = true;
        let completed = false;

        try {
            const result = await sendCategoryRequest('DELETE', {
                id: Number(deleteButton.dataset.id)
            });

            if (!result.response.ok || result.data.success !== true) {
                window.showToast(
                    typeof result.data.error === 'string' && result.data.error !== ''
                        ? result.data.error
                        : 'Nie udało się usunąć kategorii.',
                    'error'
                );
                return;
            }

            completed = true;
            window.showToast('Kategoria została usunięta.', 'success');
            await refreshCategoryList();
        } catch {
            window.showToast('Nie udało się połączyć z serwerem. Spróbuj ponownie.', 'error');
        } finally {
            if (!completed) {
                deleteButton.disabled = false;
            }

            operationBusy = false;
        }
    });
})();
