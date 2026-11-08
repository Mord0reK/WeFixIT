<?php
declare(strict_types=1);

/*
    Publiczna lista kategorii i usług.

    Strona nie wymaga logowania. Dane pochodzą wyłącznie z app/oferta.php,
    a filtr kategorii działa jako zwykłe linki SSR.
*/
require_once dirname(__DIR__) . '/app/layout.php';
require_once dirname(__DIR__) . '/app/panel_ui.php';
require_once dirname(__DIR__) . '/app/oferta.php';

$basePath = auth_base_path();
$categoryId = oferta_category_filter($_GET['kategoria'] ?? null);
$categories = oferta_kategorie();
$services = oferta_uslugi($categoryId);
$servicesByCategory = [];

foreach ($services ?? [] as $service) {
    $servicesByCategory[$service['kategoria_id']][] = $service;
}

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

        <?php if ($services === null): ?>
            <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
                Nie udało się pobrać oferty. Spróbuj ponownie później.
            </div>
        <?php elseif ($services === []): ?>
            <div class="rounded-xl border border-dashed border-zinc-200 bg-zinc-50/60 p-8 text-center dark:border-zinc-800 dark:bg-zinc-950/50">
                <p class="text-sm font-medium text-zinc-900 dark:text-zinc-50">Brak usług w ofercie</p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Wybierz inną kategorię albo wróć do pełnej oferty.
                </p>
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
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <p class="mt-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
            <a href="<?= htmlspecialchars($basePath . '/login', ENT_QUOTES, 'UTF-8') ?>"
                class="font-medium text-zinc-900 underline-offset-4 hover:underline dark:text-zinc-100">
                Zaloguj się
            </a>
        </p>
    </main>
<?php
layout_foot();
