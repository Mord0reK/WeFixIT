<?php
declare(strict_types=1);

/*
    Strona usług w panelu administratora.

    Lista i filtry pochodzą z app/uslugi.php, a formularz dodawania i edycji
    wysyła żądania fetch() do /api/admin/services. Widok nie wykonuje SQL ani
    operacji zapisu.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';
require_once dirname(__DIR__) . '/app/uslugi.php';
require_once dirname(__DIR__) . '/app/kategorie.php';

$user = require_role(['admin']);
$categoryFilter = usluga_filter_category_id($_GET['id_kategorii'] ?? null);
$statusFilter = usluga_filter_status($_GET['status'] ?? null);
$services = uslugi_list($categoryFilter, $statusFilter);
$categories = kategorie_list();
$basePath = auth_base_path();
$servicesUrl = $basePath . '/uslugi';
$servicesApi = $basePath . '/api/admin/services';
$csrfToken = csrf_token();
$filtersActive = $categoryFilter > 0 || $statusFilter !== 'all';

panel_layout_head($user, 'Usługi — WeFixIT', 'services');
?>
<section class="<?= PANEL_CARD_WIDE_CLASS ?>">
    <?php
    panel_section_header(
        'Usługi',
        'Zarządzaj usługami dostępnymi w serwisie.',
        [
            ['label' => 'Dodaj usługę', 'href' => '#service-form'],
        ]
    );
    ?>

    <form method="get" action="<?= htmlspecialchars($servicesUrl, ENT_QUOTES, 'UTF-8') ?>"
        class="mb-5 grid grid-cols-1 gap-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
        <div>
            <label for="filter-kategoria" class="<?= LAYOUT_LABEL_CLASS ?>">Kategoria</label>
            <select id="filter-kategoria" name="id_kategorii" class="<?= LAYOUT_INPUT_CLASS ?>">
                <option value="0">Wszystkie kategorie</option>
                <?php foreach ($categories ?? [] as $category): ?>
                    <option value="<?= $category['id'] ?>" <?= $categoryFilter === $category['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($category['nazwa'], ENT_QUOTES, 'UTF-8') ?>
                        <?= $category['aktywna'] ? '' : '(nieaktywna)' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="filter-status" class="<?= LAYOUT_LABEL_CLASS ?>">Status</label>
            <select id="filter-status" name="status" class="<?= LAYOUT_INPUT_CLASS ?>">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Wszystkie statusy</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Aktywne</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Nieaktywne</option>
            </select>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="submit" class="<?= panel_button_classes('secondary') ?>">Filtruj</button>
            <?php if ($filtersActive): ?>
                <a href="<?= htmlspecialchars($servicesUrl, ENT_QUOTES, 'UTF-8') ?>"
                    class="<?= panel_button_classes('ghost') ?>">Wyczyść</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($services === null): ?>
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            Nie udało się pobrać listy usług. Spróbuj ponownie później.
        </div>
    <?php elseif ($services === []): ?>
        <?php
        if ($filtersActive) {
            panel_empty_state(
                'Brak usług dla wybranych filtrów',
                'Zmień kategorię lub status, aby zobaczyć inne usługi.',
                ['label' => 'Wyczyść filtry', 'href' => $servicesUrl]
            );
        } else {
            panel_empty_state(
                'Brak usług',
                'Dodaj pierwszą usługę, aby przypisywać ją do pracowników.',
                ['label' => 'Dodaj usługę', 'href' => '#service-form']
            );
        }
        ?>
    <?php else: ?>
        <div class="<?= PANEL_TABLE_WRAP_CLASS ?>">
            <table id="services-table" class="<?= PANEL_TABLE_CLASS ?>">
                <thead>
                    <tr>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Nazwa</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Kategoria</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Cena</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Czas trwania</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Pracownicy</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?>">Status</th>
                        <th scope="col" class="<?= PANEL_TH_CLASS ?> text-right">Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services as $service): ?>
                        <tr class="<?= PANEL_TR_CLASS ?>">
                            <td class="<?= PANEL_TD_CLASS ?> font-medium text-zinc-900 dark:text-zinc-50">
                                <?= htmlspecialchars($service['nazwa'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <?= htmlspecialchars($service['kategoria_nazwa'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!$service['kategoria_aktywna']): ?>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">(nieaktywna)</span>
                                <?php endif; ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?> whitespace-nowrap">
                                <?= number_format((float) $service['cena'], 2, ',', ' ') ?> zł
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?> whitespace-nowrap">
                                <?= $service['czas_trwania_minuty'] ?> min
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?> whitespace-nowrap">
                                <?= $service['pracownicy_active'] ?>
                                <?php if ($service['przypisania_total'] !== $service['pracownicy_active']): ?>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                        (łącznie: <?= $service['przypisania_total'] ?>)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <?= panel_badge(
                                    $service['aktywna'] ? 'Aktywna' : 'Nieaktywna',
                                    $service['aktywna'] ? 'success' : 'neutral'
                                ) ?>
                            </td>
                            <td class="<?= PANEL_TD_CLASS ?>">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <button type="button" class="<?= panel_button_classes('ghost') ?>"
                                        data-service-edit
                                        data-id="<?= $service['id'] ?>"
                                        data-name="<?= htmlspecialchars($service['nazwa'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-description="<?= htmlspecialchars($service['opis'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        data-category="<?= $service['kategoria_id'] ?>"
                                        data-price="<?= htmlspecialchars(number_format((float) $service['cena'], 2, ',', ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-duration="<?= $service['czas_trwania_minuty'] ?>">
                                        Edytuj
                                    </button>
                                    <button type="button" class="<?= panel_button_classes('secondary') ?>"
                                        data-service-toggle
                                        data-id="<?= $service['id'] ?>"
                                        data-name="<?= htmlspecialchars($service['nazwa'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-active="<?= $service['aktywna'] ? '1' : '0' ?>">
                                        <?= $service['aktywna'] ? 'Dezaktywuj' : 'Aktywuj' ?>
                                    </button>
                                    <button type="button" class="<?= panel_button_classes('danger') ?>"
                                        data-service-delete
                                        data-id="<?= $service['id'] ?>"
                                        data-name="<?= htmlspecialchars($service['nazwa'], ENT_QUOTES, 'UTF-8') ?>">
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

<section id="service-form-card" class="<?= PANEL_CARD_WIDE_CLASS ?> mt-6">
    <h2 id="service-form-title" class="text-lg font-semibold tracking-tight">Dodaj usługę</h2>
    <p class="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400">Nowa usługa będzie od razu aktywna.</p>

    <form id="service-form" class="mt-5 max-w-2xl space-y-4" novalidate>
        <input type="hidden" id="service-id" name="id" value="">

        <div>
            <label for="service-nazwa" class="<?= LAYOUT_LABEL_CLASS ?>">Nazwa</label>
            <input id="service-nazwa" name="nazwa" type="text" maxlength="150" required
                class="<?= LAYOUT_INPUT_CLASS ?>">
            <p class="<?= LAYOUT_HINT_CLASS ?>">Maksymalnie 150 znaków.</p>
            <?= panel_field_error('service-error-nazwa') ?>
        </div>

        <div>
            <label for="service-opis" class="<?= LAYOUT_LABEL_CLASS ?>">Opis</label>
            <textarea id="service-opis" name="opis" rows="4" maxlength="2000"
                class="<?= LAYOUT_TEXTAREA_CLASS ?>"></textarea>
            <p class="<?= LAYOUT_HINT_CLASS ?>">Opcjonalnie, maksymalnie 2000 znaków.</p>
            <?= panel_field_error('service-error-opis') ?>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <label for="service-kategoria" class="<?= LAYOUT_LABEL_CLASS ?>">Kategoria</label>
                <select id="service-kategoria" name="kategoria_id" class="<?= LAYOUT_INPUT_CLASS ?>" required>
                    <option value="">Wybierz kategorię</option>
                    <?php foreach ($categories ?? [] as $category): ?>
                        <?php if (!$category['aktywna']) {
                            continue;
                        } ?>
                        <option value="<?= $category['id'] ?>">
                            <?= htmlspecialchars($category['nazwa'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= panel_field_error('service-error-kategoria_id') ?>
            </div>

            <div>
                <label for="service-cena" class="<?= LAYOUT_LABEL_CLASS ?>">Cena</label>
                <input id="service-cena" name="cena" type="text" inputmode="decimal" maxlength="12"
                    placeholder="np. 199,99" required class="<?= LAYOUT_INPUT_CLASS ?>">
                <p class="<?= LAYOUT_HINT_CLASS ?>">Kropka lub przecinek, maksymalnie 2 miejsca.</p>
                <?= panel_field_error('service-error-cena') ?>
            </div>

            <div>
                <label for="service-czas" class="<?= LAYOUT_LABEL_CLASS ?>">Czas trwania (minuty)</label>
                <input id="service-czas" name="czas_trwania_minuty" type="number" min="1" max="1440" step="1"
                    required class="<?= LAYOUT_INPUT_CLASS ?>">
                <p class="<?= LAYOUT_HINT_CLASS ?>">Od 1 do 1440 minut.</p>
                <?= panel_field_error('service-error-czas_trwania_minuty') ?>
            </div>
        </div>

        <p id="service-form-error" role="alert" class="hidden text-sm text-red-600 dark:text-red-400"></p>

        <div class="flex flex-wrap items-center gap-2">
            <button type="submit" id="service-submit" class="<?= panel_button_classes('primary') ?>">
                Dodaj usługę
            </button>
            <button type="button" id="service-cancel" class="<?= panel_button_classes('secondary') ?> hidden">
                Anuluj edycję
            </button>
        </div>
    </form>

    <noscript>
        <p class="mt-4 text-sm text-red-600 dark:text-red-400">Włącz JavaScript, aby zarządzać usługami.</p>
    </noscript>
</section>

<script>
(function () {
    const serviceForm = document.getElementById('service-form');
    const serviceFormTitle = document.getElementById('service-form-title');
    const serviceFormError = document.getElementById('service-form-error');
    const serviceSubmit = document.getElementById('service-submit');
    const serviceCancel = document.getElementById('service-cancel');
    const serviceId = document.getElementById('service-id');
    const serviceName = document.getElementById('service-nazwa');
    const serviceDescription = document.getElementById('service-opis');
    const serviceCategory = document.getElementById('service-kategoria');
    const servicePrice = document.getElementById('service-cena');
    const serviceDuration = document.getElementById('service-czas');
    const serviceApi = <?= json_encode($servicesApi, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const serviceFieldErrors = {
        nazwa: document.getElementById('service-error-nazwa'),
        opis: document.getElementById('service-error-opis'),
        kategoria_id: document.getElementById('service-error-kategoria_id'),
        cena: document.getElementById('service-error-cena'),
        czas_trwania_minuty: document.getElementById('service-error-czas_trwania_minuty')
    };
    let editingServiceId = null;
    let formBusy = false;

    function clearServiceMessages() {
        serviceFormError.textContent = '';
        serviceFormError.classList.add('hidden');

        for (const element of Object.values(serviceFieldErrors)) {
            element.textContent = '';
            element.classList.add('hidden');
        }
    }

    function showServiceErrors(data) {
        let shownFieldError = false;

        for (const [field, message] of Object.entries(data.errors || {})) {
            if (!serviceFieldErrors[field] || typeof message !== 'string') continue;

            serviceFieldErrors[field].textContent = message;
            serviceFieldErrors[field].classList.remove('hidden');
            shownFieldError = true;
        }

        if (shownFieldError) return;

        serviceFormError.textContent = typeof data.error === 'string' && data.error !== ''
            ? data.error
            : 'Nie udało się zapisać usługi.';
        serviceFormError.classList.remove('hidden');
    }

    function resetServiceForm() {
        editingServiceId = null;
        serviceForm.reset();
        serviceId.value = '';
        serviceFormTitle.textContent = 'Dodaj usługę';
        serviceSubmit.textContent = 'Dodaj usługę';
        serviceCancel.classList.add('hidden');
        clearServiceMessages();
    }

    function reloadServices() {
        window.setTimeout(function () {
            window.location.reload();
        }, 700);
    }

    async function sendServiceRequest(method, payload) {
        const response = await fetch(serviceApi, {
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

    serviceForm.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (formBusy) return;

        clearServiceMessages();
        formBusy = true;
        serviceSubmit.disabled = true;
        serviceCancel.disabled = true;

        const submitLabel = serviceSubmit.textContent;
        let completed = false;
        serviceSubmit.textContent = 'Zapisywanie…';

        const payload = {
            nazwa: serviceName.value.trim(),
            opis: serviceDescription.value.trim(),
            kategoria_id: Number(serviceCategory.value),
            cena: servicePrice.value.trim(),
            czas_trwania_minuty: Number(serviceDuration.value)
        };

        if (editingServiceId !== null) {
            payload.id = editingServiceId;
        }

        try {
            const result = await sendServiceRequest(editingServiceId === null ? 'POST' : 'PATCH', payload);

            if (!result.response.ok || result.data.success !== true) {
                showServiceErrors(result.data);
                return;
            }

            completed = true;
            window.showToast(
                editingServiceId === null ? 'Usługa została dodana.' : 'Usługa została zapisana.',
                'success'
            );
            reloadServices();
        } catch {
            serviceFormError.textContent = 'Nie udało się połączyć z serwerem. Spróbuj ponownie.';
            serviceFormError.classList.remove('hidden');
        } finally {
            if (!completed) {
                formBusy = false;
                serviceSubmit.disabled = false;
                serviceCancel.disabled = false;
                serviceSubmit.textContent = submitLabel;
            }
        }
    });

    serviceCancel.addEventListener('click', function () {
        resetServiceForm();
    });

    for (const button of document.querySelectorAll('[data-service-edit]')) {
        button.addEventListener('click', function () {
            editingServiceId = Number(button.dataset.id);
            serviceId.value = String(editingServiceId);
            serviceName.value = button.dataset.name || '';
            serviceDescription.value = button.dataset.description || '';
            serviceCategory.value = button.dataset.category || '';
            servicePrice.value = button.dataset.price || '';
            serviceDuration.value = button.dataset.duration || '';
            serviceFormTitle.textContent = 'Edytuj usługę';
            serviceSubmit.textContent = 'Zapisz zmiany';
            serviceCancel.classList.remove('hidden');
            clearServiceMessages();
            serviceForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
            serviceName.focus();
        });
    }

    for (const button of document.querySelectorAll('[data-service-toggle]')) {
        button.addEventListener('click', async function () {
            const isActive = button.dataset.active === '1';
            const name = button.dataset.name || 'tę usługę';
            const confirmed = await window.panelConfirm({
                title: isActive ? 'Dezaktywować usługę?' : 'Aktywować usługę?',
                message: isActive
                    ? 'Usługa „' + name + '” przestanie być dostępna. Przypisania pracowników pozostaną w bazie.'
                    : 'Usługa „' + name + '” znów będzie dostępna.',
                confirmLabel: isActive ? 'Dezaktywuj' : 'Aktywuj',
                danger: false
            });

            if (!confirmed) return;

            button.disabled = true;
            let completed = false;

            try {
                const result = await sendServiceRequest('PATCH', {
                    id: Number(button.dataset.id),
                    aktywna: !isActive
                });

                if (!result.response.ok || result.data.success !== true) {
                    window.showToast(
                        typeof result.data.error === 'string' && result.data.error !== ''
                            ? result.data.error
                            : 'Nie udało się zmienić statusu usługi.',
                        'error'
                    );
                    return;
                }

                completed = true;
                window.showToast(
                    isActive ? 'Usługa została dezaktywowana.' : 'Usługa została aktywowana.',
                    'success'
                );
                reloadServices();
            } catch {
                window.showToast('Nie udało się połączyć z serwerem. Spróbuj ponownie.', 'error');
            } finally {
                if (!completed) {
                    button.disabled = false;
                }
            }
        });
    }

    for (const button of document.querySelectorAll('[data-service-delete]')) {
        button.addEventListener('click', async function () {
            const name = button.dataset.name || 'tę usługę';
            const confirmed = await window.panelConfirm({
                title: 'Usunąć usługę?',
                message: 'Usługa „' + name + '” zostanie trwale usunięta. Tej operacji nie można cofnąć.',
                confirmLabel: 'Usuń',
                danger: true
            });

            if (!confirmed) return;

            button.disabled = true;
            let completed = false;

            try {
                const result = await sendServiceRequest('DELETE', {
                    id: Number(button.dataset.id)
                });

                if (!result.response.ok || result.data.success !== true) {
                    window.showToast(
                        typeof result.data.error === 'string' && result.data.error !== ''
                            ? result.data.error
                            : 'Nie udało się usunąć usługi.',
                        'error'
                    );
                    return;
                }

                completed = true;
                window.showToast('Usługa została usunięta.', 'success');
                reloadServices();
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
