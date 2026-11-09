<?php
declare(strict_types=1);

/*
    Formularz rezerwacji dla zalogowanego użytkownika.

    Usługi renderuje serwer, mapa pracowników trafia do JavaScriptu przez
    data-employees, a wolne terminy są doładowywane przez AJAX. Wysłanie
    rezerwacji obsługuje reservation-form.js; strona nie zawiera fallbacku
    bez JavaScriptu.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';
require_once dirname(__DIR__) . '/app/rezerwacje_klienta.php';

$user = require_login();
$basePath = auth_base_path();
$services = reservation_form_services();
$preselectedId = reservation_id_value($_GET['usluga'] ?? null);
$preselectId = 0;
$servicesByCategory = [];
$employeesByService = [];
$availableServiceIds = [];

foreach ($services ?? [] as $service) {
    $availableServiceIds[$service['id']] = true;
    $servicesByCategory[$service['kategoria_nazwa']][] = $service;
    $employeesByService[$service['id']] = [];

    foreach ($service['pracownicy'] as $employee) {
        $employeesByService[$service['id']][] = [
            'id' => $employee['id'],
            'label' => $employee['imie'] . ' ' . $employee['nazwisko'],
        ];
    }
}

$employeesJson = htmlspecialchars(
    json_encode(
        $employeesByService,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ),
    ENT_QUOTES,
    'UTF-8'
);

if ($preselectedId !== null && isset($availableServiceIds[$preselectedId])) {
    $preselectId = $preselectedId;
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$maxDate = (new DateTimeImmutable('today'))
    ->modify('+' . (AVAILABILITY_HORIZON_DAYS - 1) . ' days')
    ->format('Y-m-d');

panel_layout_head($user, 'Nowa rezerwacja — WeFixIT', 'rezerwacja');
?>
<section class="<?= PANEL_CARD_CLASS ?>">
    <?php
    panel_section_header(
        'Nowa rezerwacja',
        'Wybierz usługę, pracownika, datę i godzinę.',
        [['label' => 'Wróć do oferty', 'href' => $basePath . '/oferta']]
    );
    ?>

    <?php if ($services === null): ?>
        <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            Nie udało się pobrać usług. Spróbuj ponownie później.
        </div>
    <?php elseif ($services === []): ?>
        <?php
        panel_empty_state(
            'Brak usług do rezerwacji',
            'Żadna aktywna usługa nie ma obecnie dostępnego pracownika.',
            ['label' => 'Przejdź do oferty', 'href' => $basePath . '/oferta']
        );
        ?>
    <?php else: ?>
        <form id="reservation-form" class="max-w-xl space-y-6"
            data-api-reservations="<?= htmlspecialchars($basePath . '/api/reservations', ENT_QUOTES, 'UTF-8') ?>"
            data-api-availability="<?= htmlspecialchars($basePath . '/api/availability', ENT_QUOTES, 'UTF-8') ?>"
            data-usluga-preselect="<?= $preselectId > 0 ? $preselectId : '' ?>"
            data-employees="<?= $employeesJson ?>"
            data-login-url="<?= htmlspecialchars($basePath . '/login', ENT_QUOTES, 'UTF-8') ?>"
            data-panel-url="<?= htmlspecialchars($basePath . '/panel', ENT_QUOTES, 'UTF-8') ?>">

            <div>
                <label for="reservation-usluga" class="<?= LAYOUT_LABEL_CLASS ?>">Usługa</label>
                <select id="reservation-usluga" name="usluga_id" class="<?= LAYOUT_INPUT_CLASS ?>"
                    aria-describedby="reservation-error-usluga" required>
                    <option value="">Wybierz usługę</option>
                    <?php foreach ($servicesByCategory as $categoryName => $categoryServices): ?>
                        <optgroup label="<?= htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') ?>">
                            <?php foreach ($categoryServices as $service): ?>
                                <option value="<?= $service['id'] ?>" <?= $preselectId === $service['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($service['nazwa'], ENT_QUOTES, 'UTF-8') ?>
                                    — <?= number_format((float) $service['cena'], 2, ',', ' ') ?> zł,
                                    <?= $service['czas_trwania_minuty'] ?> min
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <p id="reservation-error-usluga" class="mt-1.5 hidden text-sm text-red-600 dark:text-red-400"></p>
            </div>

            <div>
                <label for="reservation-pracownik" class="<?= LAYOUT_LABEL_CLASS ?>">Pracownik</label>
                <select id="reservation-pracownik" name="pracownik_id" class="<?= LAYOUT_INPUT_CLASS ?>"
                    aria-describedby="reservation-error-pracownik" disabled required>
                    <option value="">Najpierw wybierz usługę</option>
                </select>
                <p id="reservation-error-pracownik" class="mt-1.5 hidden text-sm text-red-600 dark:text-red-400"></p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="reservation-data" class="<?= LAYOUT_LABEL_CLASS ?>">Data</label>
                    <input id="reservation-data" name="data" type="date" min="<?= $today ?>" max="<?= $maxDate ?>"
                        class="<?= LAYOUT_INPUT_CLASS ?>" aria-describedby="reservation-error-data" required>
                    <p id="reservation-error-data" class="mt-1.5 hidden text-sm text-red-600 dark:text-red-400"></p>
                </div>

                <div>
                    <span class="<?= LAYOUT_LABEL_CLASS ?>">Godzina</span>
                    <div id="reservation-slots" class="flex flex-wrap gap-2" role="group" aria-label="Dostępne godziny">
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            Wybierz usługę, pracownika i datę.
                        </p>
                    </div>
                    <p id="reservation-error-godzina" class="mt-1.5 hidden text-sm text-red-600 dark:text-red-400"></p>
                </div>
            </div>

            <div>
                <label for="reservation-komentarz" class="<?= LAYOUT_LABEL_CLASS ?>">Komentarz (opcjonalnie)</label>
                <textarea id="reservation-komentarz" name="komentarz" maxlength="1000"
                    class="<?= LAYOUT_TEXTAREA_CLASS ?>" aria-describedby="reservation-error-komentarz"></textarea>
                <p id="reservation-error-komentarz" class="mt-1.5 hidden text-sm text-red-600 dark:text-red-400"></p>
            </div>

            <p id="reservation-form-error" role="alert" class="hidden text-sm text-red-600 dark:text-red-400"></p>

            <div class="flex flex-col gap-2 sm:flex-row">
                <button id="reservation-submit" type="submit" class="<?= panel_button_classes('primary') ?>" disabled>
                    Zarezerwuj termin
                </button>
            </div>
        </form>

        <noscript>
            <p class="mt-4 text-sm text-red-600 dark:text-red-400">
                Rezerwacja wymaga włączonego JavaScriptu.
            </p>
        </noscript>
    <?php endif; ?>
</section>
<?php
panel_layout_foot('reservation-form.js');
