<?php
declare(strict_types=1);

/*
    Lista kategorii i usług oferty.

    Zalogowany użytkownik widzi ofertę wewnątrz panelu (sidebar, karta
    profilu), a gość otrzymuje samodzielną stronę publiczną — strona nie
    wymaga logowania. W panelu filtry działają jak w tabelach: wyszukiwanie
    i dropdown kategorii odświeżają listę przez fetch() do /api/oferta,
    bez przeładowania strony, a przy każdej usłudze stoi jawny przycisk
    rezerwacji prowadzący do formularza z wybraną usługą. Gość zachowuje
    zwykłe linki SSR, żeby strona publiczna działała także bez JavaScriptu.
*/
require_once dirname(__DIR__) . '/app/panel_layout.php';
require_once dirname(__DIR__) . '/app/oferta.php';
require_once dirname(__DIR__) . '/app/pagination.php';

$basePath = auth_base_path();
$user = current_user();
$categoryId = oferta_category_filter($_GET['kategoria'] ?? null);
$search = pagination_search_value($_GET['szukaj'] ?? null);
$categories = oferta_kategorie();
$services = oferta_uslugi($categoryId, $search);
$servicesByCategory = [];
$filtersActive = $categoryId > 0 || $search !== '';

foreach ($services ?? [] as $service) {
    $servicesByCategory[$service['kategoria_id']][] = $service;
}

/*
    Lista oferty jest wspólna dla panelu i strony publicznej, dlatego renderuje
    ją jedna funkcja widoku przyjmująca wyłącznie gotowe dane. W panelu
    JavaScript podmienia zawartość kontenera po każdym pobraniu z API, a
    $reservationHref włącza jawny przycisk rezerwacji przy każdej usłudze —
    przekazujemy go tylko rolom, które mogą tworzyć rezerwacje.
*/
function oferta_view_list(
    string $basePath,
    bool $filtersActive,
    ?array $categories,
    ?array $services,
    array $servicesByCategory,
    ?string $reservationHref = null
): void {
    ?>
    <div id="offer-list"
        data-service-url="<?= htmlspecialchars($basePath . '/usluga', ENT_QUOTES, 'UTF-8') ?>"<?php
        if ($reservationHref !== null) {
            echo ' data-reservation-url="' . htmlspecialchars($reservationHref, ENT_QUOTES, 'UTF-8') . '"';
        }
        ?>>
        <?php if ($services === null): ?>
            <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
                Nie udało się pobrać oferty. Spróbuj ponownie później.
            </div>
        <?php elseif ($services === []): ?>
            <div class="rounded-xl border border-dashed border-zinc-200 bg-zinc-50/60 p-8 text-center dark:border-zinc-800 dark:bg-zinc-950/50">
                <?php if ($filtersActive): ?>
                    <p class="text-sm font-medium text-zinc-900 dark:text-zinc-50">Brak usług dla wybranych filtrów</p>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        Zmień kategorię lub frazę wyszukiwania, aby zobaczyć inne usługi.
                    </p>
                <?php else: ?>
                    <p class="text-sm font-medium text-zinc-900 dark:text-zinc-50">Brak usług w ofercie</p>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        Wybierz inną kategorię albo wróć do pełnej oferty.
                    </p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="space-y-6">
                <?php foreach ($categories ?? [] as $category): ?>
                    <?php
                    $categoryServices = $servicesByCategory[$category['id']] ?? [];

                    if ($categoryServices === []) {
                        continue;
                    }
                    ?>
                    <section class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <h2 class="text-lg font-semibold tracking-tight">
                            <?= htmlspecialchars($category['nazwa'], ENT_QUOTES, 'UTF-8') ?>
                        </h2>

                        <?php if ($category['opis'] !== null && $category['opis'] !== ''): ?>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                <?= htmlspecialchars($category['opis'], ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        <?php endif; ?>

                        <ul class="mt-4 space-y-3">
                            <?php foreach ($categoryServices as $service): ?>
                                <li class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950/50">
                                    <a href="<?= htmlspecialchars($basePath . '/usluga?id=' . $service['id'], ENT_QUOTES, 'UTF-8') ?>"
                                        class="font-medium text-zinc-900 underline-offset-4 hover:underline dark:text-zinc-100">
                                        <?= htmlspecialchars($service['nazwa'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                        <?= number_format((float) $service['cena'], 2, ',', ' ') ?> zł
                                        · <?= $service['czas_trwania_minuty'] ?> min
                                    </p>
                                    <?php if ($reservationHref !== null): ?>
                                        <a href="<?= htmlspecialchars($reservationHref . '?usluga=' . $service['id'], ENT_QUOTES, 'UTF-8') ?>"
                                            class="mt-3 <?= panel_button_classes('primary') ?>">
                                            Zarezerwuj
                                        </a>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

if ($user !== null) {
    $offerApi = $basePath . '/api/oferta';
    $offerUrl = $basePath . '/oferta';
    $categoryOptions = [['value' => '0', 'label' => 'Wszystkie']];
    $currentCategoryLabel = 'Wszystkie';

    foreach ($categories ?? [] as $category) {
        $categoryOptions[] = [
            'value' => (string) $category['id'],
            'label' => $category['nazwa'],
        ];

        if ($category['id'] === $categoryId) {
            $currentCategoryLabel = $category['nazwa'];
        }
    }

    // Rezerwację może zainicjować klient i administrator; pracownikowi
    // nie pokazujemy akcji prowadzącej do formularza, do którego brak mu roli.
    $canReserve = in_array((string) $user['rola'], ['klient', 'admin'], true);
    $actions = $canReserve
        ? [['label' => 'Nowa rezerwacja', 'href' => $basePath . '/rezerwacja']]
        : [];

    panel_layout_head($user, 'Oferta usług — WeFixIT', 'oferta');
    ?>
    <section class="<?= PANEL_CARD_CLASS ?>">
        <?php
        panel_section_header(
            'Oferta usług',
            'Wybierz kategorię i sprawdź szczegóły dostępnych usług.',
            $actions
        );
        ?>
        <form id="offer-filter-form"
            class="mb-5 grid grid-cols-1 gap-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:grid-cols-2"
            data-api="<?= htmlspecialchars($offerApi, ENT_QUOTES, 'UTF-8') ?>">
            <div>
                <label for="offer-search" class="<?= LAYOUT_LABEL_CLASS ?>">Szukaj</label>
                <input id="offer-search" name="szukaj" type="search" maxlength="100"
                    value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Nazwa usługi" class="<?= LAYOUT_INPUT_CLASS ?>">
            </div>

            <div>
                <span id="offer-category-label" class="<?= LAYOUT_LABEL_CLASS ?>">Kategoria</span>
                <div class="relative" data-filter-dropdown>
                    <button type="button"
                        class="<?= LAYOUT_INPUT_CLASS ?> items-center justify-between gap-2 text-left"
                        data-filter-toggle aria-expanded="false" aria-haspopup="true"
                        aria-controls="offer-category-menu"
                        aria-labelledby="offer-category-label offer-category-value">
                        <span id="offer-category-value"
                            class="truncate<?= $categoryId === 0 ? ' text-zinc-400 dark:text-zinc-500' : '' ?>"
                            data-offer-category-value><?= htmlspecialchars($currentCategoryLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <?= panel_icon('chevron-down', 'h-4 w-4 shrink-0 text-zinc-500') ?>
                    </button>
                    <div id="offer-category-menu" class="<?= PANEL_FILTER_MENU_CLASS ?>" data-filter-menu>
                        <?php panel_filter_options(
                            'offer-filter-form',
                            'kategoria',
                            'Filtruj ofertę według kategorii',
                            $categoryOptions,
                            (string) $categoryId
                        ); ?>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 sm:items-end">
                <button type="submit" class="<?= panel_button_classes('secondary') ?>">Szukaj</button>
                <a href="<?= htmlspecialchars($offerUrl, ENT_QUOTES, 'UTF-8') ?>"
                    data-clear-filters="1"<?= $filtersActive ? '' : ' hidden' ?>
                    class="<?= panel_button_classes('ghost') ?>">Wyczyść</a>
            </div>
        </form>

        <p id="offer-error" role="alert" class="hidden rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200"></p>

        <?php oferta_view_list(
            $basePath,
            $filtersActive,
            $categories,
            $services,
            $servicesByCategory,
            $canReserve ? $basePath . '/rezerwacja' : null
        ); ?>
    </section>

    <noscript>
        <p class="mt-4 text-sm text-red-600 dark:text-red-400">Włącz JavaScript, aby filtrować i wyszukiwać w ofercie.</p>
    </noscript>
    <?php
    panel_layout_foot(['offer.js']);
} else {
    layout_head('Oferta usług — WeFixIT', LAYOUT_PUBLIC_BODY_CLASS);
    ?>
    <main class="mx-auto w-full max-w-xl p-4 sm:p-8">
        <header class="mb-6">
            <div class="mb-4 flex items-center gap-2.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-zinc-900 text-white shadow-sm dark:bg-zinc-50 dark:text-zinc-900">
                    <?= layout_logo('h-5 w-5') ?>
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-semibold tracking-tight">WeFixIT</span>
                    <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">Serwis komputerowy</span>
                </span>
            </div>

            <h1 class="text-2xl font-semibold tracking-tight">Oferta usług</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Wybierz kategorię i sprawdź szczegóły dostępnych usług.
            </p>
        </header>

        <nav aria-label="Filtruj ofertę według kategorii" class="mb-6 flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars($basePath . '/oferta', ENT_QUOTES, 'UTF-8') ?>"
                class="<?= $categoryId === 0 ? panel_button_classes('primary') : panel_button_classes('secondary') ?>">
                Wszystkie
            </a>
            <?php foreach ($categories ?? [] as $category): ?>
                <a href="<?= htmlspecialchars($basePath . '/oferta?kategoria=' . $category['id'], ENT_QUOTES, 'UTF-8') ?>"
                    class="<?= $categoryId === $category['id'] ? panel_button_classes('primary') : panel_button_classes('secondary') ?>">
                    <?= htmlspecialchars($category['nazwa'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php oferta_view_list(
            $basePath,
            $filtersActive,
            $categories,
            $services,
            $servicesByCategory,
            null
        ); ?>

        <p class="mt-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
            <a href="<?= htmlspecialchars($basePath . '/login', ENT_QUOTES, 'UTF-8') ?>"
                class="font-medium text-zinc-900 underline-offset-4 hover:underline dark:text-zinc-100">
                Zaloguj się
            </a>
        </p>
    </main>
    <?php
    layout_foot();
}
