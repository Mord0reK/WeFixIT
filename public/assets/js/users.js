/*
    Administracja kontami: lista z API, zmiana roli oraz aktywacja
    i dezaktywacja.

    Adres API pochodzi z data-api sekcji strony, dzięki czemu działa również
    w instalacji w podkatalogu. Filtry, wyszukiwanie i paginacja pobierają
    dane przez panelList bez przeładowania strony. Operacje wymagające
    potwierdzenia korzystają z window.panelConfirm, a po udanej zmianie
    lista jest odświeżana z API zamiast przeładowywania karty.
*/
(function () {
    const page = document.getElementById('users-page');

    if (!page) return;

    const usersApi = page.dataset.api || '';
    const currentUserId = Number(page.dataset.userId || '0');
    const roleOptions = JSON.parse(page.dataset.roleOptions || '[]');
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const form = document.getElementById('users-filter-form');
    const table = document.getElementById('users-table');
    const generalError = document.getElementById('users-error');
    const classes = window.panelTableClasses();
    let busy = false;

    function clearMessages() {
        if (generalError) {
            generalError.textContent = '';
            generalError.classList.add('hidden');
        }

        for (const element of page.querySelectorAll('[id^="user-error-"]')) {
            element.textContent = '';
            element.classList.add('hidden');
        }
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

    // Buduje wiersz konta tak, jak robi to widok PHP przy pierwszym renderze.
    function renderUser(managedUser) {
        const id = Number(managedUser.id);
        const isSelf = id === currentUserId;
        const hasEmployeeProfile = managedUser.ma_profil_pracownika === true;
        const name = (managedUser.imie || '') + ' ' + (managedUser.nazwisko || '');
        const row = document.createElement('tr');
        row.className = classes.tr;

        const nameCell = document.createElement('td');
        nameCell.className = classes.td;
        const nameValue = document.createElement('span');
        nameValue.className = 'block font-medium text-zinc-900 dark:text-zinc-50';
        nameValue.textContent = name;
        nameCell.appendChild(nameValue);

        if (isSelf) {
            const own = document.createElement('span');
            own.className = 'mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400';
            own.textContent = 'Twoje konto';
            nameCell.appendChild(own);
        }

        row.appendChild(nameCell);

        const emailCell = document.createElement('td');
        emailCell.className = classes.td;
        emailCell.textContent = managedUser.email || '';
        row.appendChild(emailCell);

        const roleLabel = roleOptions.find(function (option) {
            return option.value === managedUser.rola;
        });
        const roleCell = document.createElement('td');
        roleCell.className = classes.td;
        roleCell.appendChild(window.panelBadge(
            roleLabel ? roleLabel.label : String(managedUser.rola),
            isSelf ? 'success' : 'neutral'
        ));
        row.appendChild(roleCell);

        const profileCell = document.createElement('td');
        profileCell.className = classes.td;

        if (hasEmployeeProfile) {
            const profileWrap = document.createElement('div');
            profileWrap.className = 'flex flex-wrap items-center gap-2';
            profileWrap.appendChild(Object.assign(
                document.createElement('span'),
                { className: 'text-sm font-medium', textContent: 'Tak' }
            ));
            profileWrap.appendChild(window.panelBadge(
                managedUser.profil_pracownika_aktywny === true ? 'Aktywny' : 'Nieaktywny',
                managedUser.profil_pracownika_aktywny === true ? 'success' : 'neutral'
            ));
            profileCell.appendChild(profileWrap);
        } else {
            profileCell.appendChild(window.panelBadge('Nie', 'neutral'));
        }

        row.appendChild(profileCell);

        const statusCell = document.createElement('td');
        statusCell.className = classes.td;
        statusCell.appendChild(window.panelBadge(
            managedUser.aktywny === true ? 'Aktywne' : 'Nieaktywne',
            managedUser.aktywny === true ? 'success' : 'neutral'
        ));
        row.appendChild(statusCell);

        const actionsCell = document.createElement('td');
        actionsCell.className = classes.td;

        if (isSelf) {
            const hint = document.createElement('span');
            hint.className = 'text-xs text-zinc-500 dark:text-zinc-400';
            hint.textContent = 'Nie możesz zmienić roli ani dezaktywować własnego konta.';
            actionsCell.appendChild(hint);
        } else {
            const roleForm = document.createElement('form');
            roleForm.className = 'flex flex-col gap-1';
            roleForm.setAttribute('data-user-role-form', '1');
            roleForm.dataset.id = String(id);
            roleForm.method = 'post';
            roleForm.action = usersApi;
            roleForm.noValidate = true;

            const selectWrap = document.createElement('div');
            selectWrap.className = 'flex items-center gap-2';

            const selectLabel = document.createElement('label');
            selectLabel.className = 'sr-only';
            selectLabel.htmlFor = 'user-role-' + id;
            selectLabel.textContent = 'Rola';
            selectWrap.appendChild(selectLabel);

            const select = document.createElement('select');
            select.id = 'user-role-' + id;
            select.name = 'rola';
            select.className = classes.input + ' h-9';

            for (const option of roleOptions) {
                const optionElement = document.createElement('option');
                optionElement.value = option.value;
                optionElement.textContent = option.label;
                optionElement.selected = option.value === managedUser.rola;

                // Zmiana na klienta wymaga wcześniejszej dezaktywacji profilu.
                if (hasEmployeeProfile && option.value === 'klient') {
                    optionElement.disabled = true;
                }

                select.appendChild(optionElement);
            }

            selectWrap.appendChild(select);

            const submitButton = document.createElement('button');
            submitButton.type = 'submit';
            submitButton.className = window.panelButtonClasses('secondary');
            submitButton.textContent = 'Zmień rolę';
            selectWrap.appendChild(submitButton);
            roleForm.appendChild(selectWrap);

            if (hasEmployeeProfile) {
                const hint = document.createElement('p');
                hint.className = classes.hint;
                hint.textContent = 'Zmiana na klienta wymaga wcześniejszej dezaktywacji profilu pracownika.';
                roleForm.appendChild(hint);
            }

            roleForm.appendChild(window.panelFieldError('user-error-rola-' + id));
            actionsCell.appendChild(roleForm);

            const toggleWrap = document.createElement('div');
            toggleWrap.className = 'flex flex-col items-start gap-1';
            const isActive = managedUser.aktywny === true;
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = window.panelButtonClasses('secondary');
            toggle.setAttribute('data-user-toggle', '1');
            toggle.dataset.id = String(id);
            toggle.dataset.active = isActive ? '1' : '0';
            toggle.dataset.name = name;
            toggle.title = isActive ? 'Dezaktywuj' : 'Aktywuj';
            toggle.setAttribute('aria-label', isActive ? 'Dezaktywuj' : 'Aktywuj');

            if (isActive) {
                toggle.textContent = 'Dezaktywuj';
            } else {
                toggle.appendChild(window.panelIcon('circle-check', 'h-4 w-4 shrink-0'));
            }

            toggleWrap.appendChild(toggle);
            toggleWrap.appendChild(window.panelFieldError('user-error-aktywny-' + id));
            actionsCell.appendChild(toggleWrap);
        }

        row.appendChild(actionsCell);

        return row;
    }

    const list = window.panelList({
        section: page,
        api: usersApi,
        form: form,
        table: table,
        pageUrl: form ? form.getAttribute('action') || '' : '',
        itemsKey: 'uzytkownicy',
        paginationSlot: page.querySelector('[data-list-pagination]'),
        colspan: 6,
        renderRow: renderUser,
        emptyState: function (hasFilters) {
            if (hasFilters) {
                return window.panelEmptyState({
                    title: 'Brak wyników',
                    description: 'Żadne konto nie spełnia wybranych filtrów ani frazy wyszukiwania. Zmień kryteria i spróbuj ponownie.',
                    action: { label: 'Wyczyść filtry', href: form ? form.getAttribute('action') || '' : '', clear: true }
                });
            }

            return window.panelEmptyState({
                title: 'Brak użytkowników',
                description: 'W bazie nie ma jeszcze kont do wyświetlenia.'
            });
        },
        onError: showGeneralError
    });

    if (list === null) return;

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

    // Delegacja na tabeli: wiersze są przebudowywane po każdym odświeżeniu.
    table.addEventListener('submit', async function (event) {
        const roleForm = event.target instanceof Element ? event.target.closest('[data-user-role-form]') : null;

        if (roleForm === null) return;

        event.preventDefault();

        if (busy) return;

        const id = Number(roleForm.dataset.id);
        const select = roleForm.querySelector('select[name="rola"]');
        const button = roleForm.querySelector('button[type="submit"]');
        clearMessages();
        busy = true;

        if (button) button.disabled = true;

        try {
            const result = await send({ id: id, rola: select ? select.value : '' });

            if (!result.response.ok || result.data.success !== true) {
                showRowErrors(id, result.data);
                return;
            }

            window.showToast('Rola użytkownika została zmieniona.', 'success');
            await list.refresh();
        } catch {
            showGeneralError('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
        } finally {
            busy = false;
        }
    });

    table.addEventListener('click', async function (event) {
        const button = event.target instanceof Element ? event.target.closest('[data-user-toggle]') : null;

        if (button === null) return;

        if (busy) return;

        const id = Number(button.dataset.id);
        const isActive = button.dataset.active === '1';
        const name = button.dataset.name || '';

        const confirmed = await window.panelConfirm({
            title: isActive ? 'Dezaktywuj konto' : 'Aktywuj konto',
            message: isActive
                ? 'Czy dezaktywować konto ' + name + '? Użytkownik nie będzie mógł się zalogować.'
                : 'Czy aktywować konto ' + name + '?',
            confirmLabel: isActive ? 'Dezaktywuj' : 'Aktywuj',
            danger: isActive
        });

        if (!confirmed) return;

        clearMessages();
        busy = true;
        button.disabled = true;

        try {
            const result = await send({ id: id, aktywny: !isActive });

            if (!result.response.ok || result.data.success !== true) {
                showRowErrors(id, result.data);
                return;
            }

            window.showToast(
                isActive ? 'Konto zostało dezaktywowane.' : 'Konto zostało aktywowane.',
                'success'
            );
            await list.refresh();
        } catch {
            showGeneralError('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
        } finally {
            busy = false;
        }
    });
})();
