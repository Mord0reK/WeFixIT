<?php
declare(strict_types=1);

/*
    Strona przypisań usług do pracownika w panelu administratora.

    Lista aktywnych usług pochodzi z app/pracownicy_uslugi.php, a formularz
    wysyła fetch() do /api/admin/employee-services. Zaznaczenie opisuje stan
    docelowy całego zbioru, a odłączanie nie usuwa historycznych wierszy.
    Widok nie wykonuje SQL ani operacji zapisu.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';
require_once dirname(__DIR__) . '/app/pracownicy.php';
require_once dirname(__DIR__) . '/app/pracownicy_uslugi.php';

$user = require_role(['admin']);
$basePath = auth_base_path();
$employeesUrl = $basePath . '/pracownicy';
$servicesUrl = $basePath . '/uslugi';
$servicesApi = $basePath . '/api/admin/employee-services';
$employeeId = null;

if (isset($_GET['id']) && is_string($_GET['id']) && $_GET['id'] !== '' && ctype_digit($_GET['id'])) {
    $employeeId = (int) $_GET['id'];

    if ($employeeId <= 0) {
        $employeeId = null;
    }
}

$employee = $employeeId !== null ? pracownik_find_by_id($employeeId) : null;

if ($employee === null) {
    http_response_code(404);
    layout_head('Nie znaleziono pracownika — WeFixIT');
    $employeesUrlSafe = htmlspecialchars($employeesUrl, ENT_QUOTES, 'UTF-8');
    ?>
    <main class="<?= LAYOUT_CARD_CLASS ?>">
        <h1 class="text-2xl font-semibold tracking-tight">Nie znaleziono pracownika</h1>
        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
            Wybrany profil pracownika nie istnieje lub identyfikator jest nieprawidłowy.
        </p>
        <a href="<?= $employeesUrlSafe ?>" class="<?= panel_button_classes('secondary') ?> mt-6">Wróć do pracowników</a>
    </main>
    <?php
    layout_foot();
    exit;
}

$services = pracownik_uslugi_state($employee['id']);
$activeCount = pracownik_uslugi_active_count($employee['id']);
$employeeName = $employee['imie'] . ' ' . $employee['nazwisko'];
$csrfToken = csrf_token();
$serviceGroups = [];

foreach ($services ?? [] as $service) {
    if (!isset($serviceGroups[$service['kategoria_id']])) {
        $serviceGroups[$service['kategoria_id']] = [
            'nazwa' => $service['kategoria_nazwa'],
            'uslugi' => [],
        ];
    }

    $serviceGroups[$service['kategoria_id']]['uslugi'][] = $service;
}

panel_layout_head($user, 'Usługi pracownika — WeFixIT', 'employees');
?>
<section class="<?= PANEL_CARD_WIDE_CLASS ?>">
    <?php
    panel_section_header(
        'Usługi pracownika: ' . $employeeName,
        'Zaznacz usługi, które mają być przypisane do pracownika.',
        [
            ['label' => 'Pracownicy', 'href' => $employeesUrl],
        ]
    );
    ?>

    <?php if (!$employee['aktywny']): ?>
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200">
            <div class="mb-2"><?= panel_badge('Nieaktywny', 'neutral') ?></div>
            <p>
                Profil pracownika jest nieaktywny. Przypisania nie będą brane pod uwagę
                przy rezerwacjach w Etapie 4, ale możesz je nadal edytować — historia pozostaje w bazie.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($services === null || $activeCount === null): ?>
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            Nie udało się pobrać przypisań usług. Spróbuj ponownie później.
        </div>
    <?php elseif ($services === []): ?>
        <?php
        panel_empty_state(
            'Brak aktywnych usług',
            'Najpierw dodaj usługi w module Usługi, aby przypisać je do pracownika.',
            ['label' => 'Przejdź do usług', 'href' => $servicesUrl]
        );
        ?>
    <?php else: ?>
        <p class="mb-5 text-sm text-zinc-600 dark:text-zinc-300">
            Aktualnie przypisane aktywne usługi:
            <span class="font-semibold text-zinc-900 dark:text-zinc-50"><?= $activeCount ?></span>
        </p>

        <form id="employee-services-form" method="post"
            action="<?= htmlspecialchars($servicesApi, ENT_QUOTES, 'UTF-8') ?>"
            class="space-y-6" novalidate>
            <?php foreach ($serviceGroups as $group): ?>
                <fieldset>
                    <legend class="mb-2 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
                        <?= htmlspecialchars($group['nazwa'], ENT_QUOTES, 'UTF-8') ?>
                    </legend>
                    <div class="space-y-2">
                        <?php foreach ($group['uslugi'] as $service): ?>
                            <label for="usluga-<?= $service['id'] ?>"
                                class="flex cursor-pointer items-start gap-3 rounded-lg border border-zinc-200 bg-white p-4 transition-colors hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:bg-zinc-800/60">
                                <input id="usluga-<?= $service['id'] ?>" type="checkbox"
                                    name="usluga_ids[]" value="<?= $service['id'] ?>"
                                    data-service-id="<?= $service['id'] ?>"
                                    class="mt-1 h-4 w-4 shrink-0 rounded border-zinc-300 accent-zinc-900 dark:border-zinc-700 dark:accent-zinc-100"
                                    <?= $service['przypisana'] ? 'checked' : '' ?>>
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium text-zinc-900 dark:text-zinc-50">
                                            <?= htmlspecialchars($service['nazwa'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <?php if ($service['przypisana']): ?>
                                            <?= panel_badge('Przypisana', 'success') ?>
                                        <?php elseif ($service['kiedykolwiek_przypisana']): ?>
                                            <?= panel_badge('wcześniej odłączona', 'neutral') ?>
                                        <?php else: ?>
                                            <?= panel_badge('Nieprzypisana', 'neutral') ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="mt-1 block text-sm text-zinc-500 dark:text-zinc-400">
                                        <?= number_format((float) $service['cena'], 2, ',', ' ') ?> zł
                                        · <?= $service['czas_trwania_minuty'] ?> min
                                    </span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php endforeach; ?>

            <?= panel_field_error('employee-services-error-usluga_ids') ?>
            <p id="employee-services-form-error" role="alert"
                class="hidden text-sm text-red-600 dark:text-red-400"></p>

            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" id="employee-services-submit" class="<?= panel_button_classes('primary') ?>">
                    Zapisz przypisania
                </button>
                <button type="button" id="employee-services-reset" class="<?= panel_button_classes('secondary') ?>">
                    Przywróć stan z bazy
                </button>
            </div>
        </form>

        <noscript>
            <p class="mt-4 text-sm text-red-600 dark:text-red-400">Włącz JavaScript, aby zapisywać przypisania usług.</p>
        </noscript>
    <?php endif; ?>
</section>

<script>
(function () {
    const form = document.getElementById('employee-services-form');
    if (!form) return;

    const formError = document.getElementById('employee-services-form-error');
    const fieldError = document.getElementById('employee-services-error-usluga_ids');
    const submit = document.getElementById('employee-services-submit');
    const reset = document.getElementById('employee-services-reset');
    const servicesApi = <?= json_encode($servicesApi, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const employeeId = <?= $employee['id'] ?>;
    let formBusy = false;

    function clearMessages() {
        formError.textContent = '';
        formError.classList.add('hidden');
        fieldError.textContent = '';
        fieldError.classList.add('hidden');
    }

    function showErrors(data) {
        const message = data.errors && typeof data.errors.usluga_ids === 'string'
            ? data.errors.usluga_ids
            : '';

        if (message !== '') {
            fieldError.textContent = message;
            fieldError.classList.remove('hidden');
            return;
        }

        formError.textContent = typeof data.error === 'string' && data.error !== ''
            ? data.error
            : 'Nie udało się zapisać przypisań usług.';
        formError.classList.remove('hidden');
    }

    reset.addEventListener('click', function () {
        form.reset();
        clearMessages();
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (formBusy) return;

        clearMessages();
        formBusy = true;
        submit.disabled = true;
        reset.disabled = true;

        const submitLabel = submit.textContent;
        const serviceIds = Array.from(form.querySelectorAll('input[name="usluga_ids[]"]:checked'))
            .map(function (input) {
                return Number(input.value);
            });
        let completed = false;
        submit.textContent = 'Zapisywanie…';

        try {
            const response = await fetch(servicesApi, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ pracownik_id: employeeId, usluga_ids: serviceIds })
            });
            let data = {};

            try {
                data = await response.json();
            } catch {
                data = {};
            }

            if (!response.ok || data.success !== true) {
                showErrors(data);
                return;
            }

            completed = true;
            window.showToast('Przypisania usług zostały zapisane.', 'success');
            window.setTimeout(function () {
                window.location.reload();
            }, 700);
        } catch {
            formError.textContent = 'Nie udało się połączyć z serwerem. Spróbuj ponownie.';
            formError.classList.remove('hidden');
        } finally {
            if (!completed) {
                formBusy = false;
                submit.disabled = false;
                reset.disabled = false;
                submit.textContent = submitLabel;
            }
        }
    });
})();
</script>
<?php panel_layout_foot(); ?>
