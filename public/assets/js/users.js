/*
    Administracja kontami: lista z API oraz zmiana roli z dropdownu
    z popupem potwierdzenia.

    Zmiana roli na klient przy aktywnym profilu pracownika wymaga dwóch
    potwierdzeń: najpierw dezaktywacji profilu (endpoint pracowników),
    a dopiero potem samej zmiany roli. Adresy API pochodzą z atrybutów
    data-api i data-employees-api sekcji strony, dzięki czemu działają
    również w instalacji w podkatalogu. Filtry, wyszukiwanie i paginacja
    pobierają dane przez panelList bez przeładowania strony, a po udanej
    zmianie lista jest odświeżana z API zamiast przeładowywania karty.
*/
(function () {
    const page = document.getElementById('users-page');

    if (!page) return;

    const usersApi = page.dataset.api || '';
    const employeesApi = page.dataset.employeesApi || '';
    const currentUserId = Number(page.dataset.userId || '0');
    const roleOptions = JSON.parse(page.dataset.roleOptions || '[]');
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const form = document.getElementById('users-filter-form');
    const table = document.getElementById('users-table');
    const generalError = document.getElementById('users-error');
    const classes = window.panelTableClasses();
    let busy = false;

    // Literały klas dropdownu roli odpowiadają stałym z app/panel_ui.php,
    // dzięki czemu wiersze budowane w JS wyglądają jak render serwera.
    const FILTER_MENU_CLASS = 'panel-filter-scrollbar fixed z-50 hidden max-h-[18.5rem] w-max min-w-56 max-w-[min(24rem,calc(100vw-2rem))] overflow-y-auto overscroll-contain whitespace-normal rounded-lg border border-zinc-200 bg-white p-1.5 shadow-lg dark:border-zinc-800 dark:bg-zinc-900';
    const FILTER_MENU_TITLE_CLASS = 'px-2 pb-1 pt-1 text-[11px] font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500';
    const FILTER_OPTION_CLASS = 'flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm font-normal normal-case tracking-normal text-zinc-700 transition-colors hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800';
    const FILTER_RADIO_CLASS = 'h-4 w-4 shrink-0 accent-zinc-900 dark:accent-zinc-50';

    // Mały pomocnik budowania DOM; teksty zawsze przez textContent.
    function el(tag, className, text) {
        const element = document.createElement(tag);

        if (className) {
            element.className = className;
        }

        if (text !== undefined && text !== null) {
            element.textContent = text;
        }

        return element;
    }

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
        const roleError = document.getElementById('user-error-rola-' + id);
        const roleMessage = data.errors && typeof data.errors.rola === 'string' ? data.errors.rola : '';

        if (roleError && roleMessage !== '') {
            roleError.textContent = roleMessage;
            roleError.classList.remove('hidden');
            return;
        }

        showGeneralError(typeof data.error === 'string' && data.error !== ''
            ? data.error
            : 'Nie udało się zapisać konta użytkownika.');
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
            hint.textContent = 'Nie możesz zmienić roli własnego konta.';
            actionsCell.appendChild(hint);
        } else {
            const roleWrap = el('div', 'flex flex-col items-start gap-1');

            // Dropdown roli korzysta z mechanizmu menu filtra z panel.js,
            // więc otwieranie i pozycjonowanie obsługuje wspólny skrypt.
            const roleDropdown = el('div', 'relative');
            roleDropdown.setAttribute('data-filter-dropdown', '1');
            roleDropdown.setAttribute('data-user-role', '1');
            roleDropdown.dataset.id = String(id);
            roleDropdown.dataset.name = name;
            roleDropdown.dataset.currentRole = String(managedUser.rola || '');
            roleDropdown.dataset.employeeId = String(managedUser.profil_pracownika_id || '0');
            roleDropdown.dataset.profileActive = managedUser.profil_pracownika_aktywny === true ? '1' : '0';

            const roleToggle = el('button', window.panelButtonClasses('secondary'));
            roleToggle.type = 'button';
            roleToggle.setAttribute('data-filter-toggle', '1');
            roleToggle.setAttribute('aria-expanded', 'false');
            roleToggle.setAttribute('aria-haspopup', 'true');
            roleToggle.setAttribute('aria-controls', 'user-role-' + id + '-menu');
            roleToggle.appendChild(el('span', '', 'Zmień rolę'));
            roleToggle.appendChild(window.panelIcon('chevron-down', 'h-4 w-4 shrink-0'));
            roleDropdown.appendChild(roleToggle);

            const roleMenu = el('div', FILTER_MENU_CLASS);
            roleMenu.id = 'user-role-' + id + '-menu';
            roleMenu.setAttribute('data-filter-menu', '1');
            const menuTitle = el('p', FILTER_MENU_TITLE_CLASS, 'Wybierz nową rolę');
            menuTitle.setAttribute('aria-hidden', 'true');
            roleMenu.appendChild(menuTitle);

            const fieldset = document.createElement('fieldset');
            fieldset.appendChild(el('legend', 'sr-only', 'Nowa rola konta'));

            for (const option of roleOptions) {
                const optionLabel = el('label', FILTER_OPTION_CLASS);
                const radio = el('input', FILTER_RADIO_CLASS);
                radio.type = 'radio';
                radio.name = 'rola-' + id;
                radio.value = option.value;
                radio.setAttribute('data-user-role-option', '1');
                radio.checked = option.value === managedUser.rola;
                optionLabel.appendChild(radio);
                optionLabel.appendChild(el('span', 'min-w-0 break-words', option.label));
                fieldset.appendChild(optionLabel);
            }

            roleMenu.appendChild(fieldset);
            roleDropdown.appendChild(roleMenu);
            roleWrap.appendChild(roleDropdown);
            roleWrap.appendChild(window.panelFieldError('user-error-rola-' + id));
            actionsCell.appendChild(roleWrap);
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

    async function send(url, payload) {
        const response = await fetch(url, {
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
    table.addEventListener('change', async function (event) {
        const option = event.target instanceof Element ? event.target.closest('[data-user-role-option]') : null;

        if (option === null) return;

        const container = option.closest('[data-user-role]');

        if (container === null) return;

        const currentRole = container.dataset.currentRole || '';
        const newRole = option.value;

        // Od razu przywracamy zaznaczenie bieżącej roli, żeby anulowany
        // wybór nie zostawił w menu fałszywego stanu.
        for (const radio of container.querySelectorAll('[data-user-role-option]')) {
            radio.checked = radio.value === currentRole;
        }

        if (busy || newRole === '' || newRole === currentRole) return;

        const id = Number(container.dataset.id);
        const name = container.dataset.name || '';
        const labelOption = roleOptions.find(function (optionItem) {
            return optionItem.value === newRole;
        });
        const newRoleLabel = labelOption ? labelOption.label : newRole;

        /*
            Aktywny profil pracownika blokuje zmianę roli na klient, więc
            najpierw popup potwierdza dezaktywację profilu, a dopiero po
            niej drugi popup pyta o samą zmianę roli.
        */
        const employeeId = Number(container.dataset.employeeId || '0');
        const profileActive = container.dataset.profileActive === '1';
        let profileDeactivated = false;

        if (newRole === 'klient' && employeeId > 0 && profileActive) {
            const deactivateConfirmed = await window.panelConfirm({
                title: 'Dezaktywuj profil pracownika',
                message: 'Konto ' + name + ' ma aktywny profil pracownika. Zmiana roli na klient wymaga najpierw jego dezaktywacji. Czy dezaktywować profil pracownika?',
                confirmLabel: 'Dezaktywuj profil',
                danger: true
            });

            if (!deactivateConfirmed) return;

            clearMessages();
            busy = true;

            try {
                const result = await send(employeesApi, { id: employeeId, aktywny: false });

                if (!result.response.ok || result.data.success !== true) {
                    showGeneralError(typeof result.data.error === 'string' && result.data.error !== ''
                        ? result.data.error
                        : 'Nie udało się dezaktywować profilu pracownika.');
                    return;
                }

                profileDeactivated = true;
            } catch {
                showGeneralError('Nie udało się połączyć z serwerem. Spróbuj ponownie.');
                return;
            } finally {
                busy = false;
            }

            // Profil jest już dezaktywowany, więc ponowne wybranie klienta
            // w tym wierszu nie może powtarzać pierwszego popupu.
            container.dataset.profileActive = '0';
        }

        /*
            Gdy profil pracownika już jest nieaktywny, popup dezaktywacji
            nie jest potrzebny — nic nie ma do wyłączenia. Komunikat o
            zmianie roli dopowiada wtedy o tym stanie, żeby wybór klienta
            przy widocznym profilu nikogo nie zaskakiwał.
        */
        const inactiveProfileNote = newRole === 'klient' && employeeId > 0 && !profileActive
            ? ' Profil pracownika konta jest już nieaktywny.'
            : '';

        const confirmed = await window.panelConfirm({
            title: 'Zmiana roli',
            message: 'Czy zmienić rolę konta ' + name + ' na ' + newRoleLabel + '?' + inactiveProfileNote,
            confirmLabel: 'Zmień rolę'
        });

        // Profil mógł zostać już dezaktywowany, więc przy anulowanej zmianie
        // roli odświeżamy listę, żeby wiersz pokazywał prawdziwy stan.
        if (!confirmed) {
            if (profileDeactivated) {
                await list.refresh();
            }

            return;
        }

        clearMessages();
        busy = true;

        try {
            const result = await send(usersApi, { id: id, rola: newRole });

            if (!result.response.ok || result.data.success !== true) {
                showRowErrors(id, result.data);

                if (profileDeactivated) {
                    await list.refresh();
                }

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
})();
