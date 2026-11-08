<?php
declare(strict_types=1);

/*
    Publiczne szczegóły pojedynczej usługi.

    Strona nie wymaga logowania i nie pokazuje terminów. Dostępność jest
    ograniczona do liczby oraz listy aktywnych pracowników wykonujących usługę.
*/
require_once dirname(__DIR__) . '/app/layout.php';
require_once dirname(__DIR__) . '/app/panel_ui.php';
require_once dirname(__DIR__) . '/app/oferta.php';

$basePath = auth_base_path();
$serviceId = oferta_id_value($_GET['id'] ?? null);
$service = $serviceId !== null ? oferta_usluga_find($serviceId) : null;

if ($service === null) {
    http_response_code(404);
    layout_head('Nie znaleziono usługi — WeFixIT', LAYOUT_PUBLIC_BODY_CLASS);
    ?>
    <main class="mx-auto w-full max-w-xl p-4 sm:p-8">
        <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-8">
            <h1 class="text-2xl font-semibold tracking-tight">Nie znaleziono usługi</h1>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                Ta usługa nie jest dostępna w ofercie.
            </p>
            <p class="mt-6">
                <a href="<?= htmlspecialchars($basePath . '/oferta', ENT_QUOTES, 'UTF-8') ?>"
                    class="<?= panel_button_classes('secondary') ?>">
                    Wróć do oferty
                </a>
            </p>
        </div>
    </main>
    <?php
    layout_foot();
    exit;
}

$employees = oferta_pracownicy_uslugi($service['id']) ?? [];
$employeeCount = count($employees);

layout_head($service['nazwa'] . ' — WeFixIT', LAYOUT_PUBLIC_BODY_CLASS);
?>
    <main class="mx-auto w-full max-w-xl p-4 sm:p-8">
        <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-8">
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                <a href="<?= htmlspecialchars($basePath . '/oferta?kategoria=' . $service['kategoria_id'], ENT_QUOTES, 'UTF-8') ?>"
                    class="underline-offset-4 hover:underline">
                    <?= htmlspecialchars($service['kategoria_nazwa'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            </p>

            <h1 class="mt-1 text-2xl font-semibold tracking-tight">
                <?= htmlspecialchars($service['nazwa'], ENT_QUOTES, 'UTF-8') ?>
            </h1>

            <?php if ($service['opis'] !== null && $service['opis'] !== ''): ?>
                <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">
                    <?= htmlspecialchars($service['opis'], ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>

            <dl class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950/50">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Cena</dt>
                    <dd class="mt-1 text-lg font-semibold">
                        <?= number_format((float) $service['cena'], 2, ',', ' ') ?> zł
                    </dd>
                </div>
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950/50">
                    <dt class="text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Czas trwania</dt>
                    <dd class="mt-1 text-lg font-semibold"><?= $service['czas_trwania_minuty'] ?> min</dd>
                </div>
            </dl>

            <section class="mt-6">
                <h2 class="text-lg font-semibold tracking-tight">Dostępność</h2>

                <?php if ($employeeCount === 0): ?>
                    <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200">
                        Usługa jest chwilowo niedostępna — brak aktywnego pracownika.
                    </p>
                <?php else: ?>
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                        Aktywni pracownicy wykonujący usługę: <?= $employeeCount ?>
                    </p>
                    <ul class="mt-3 space-y-2">
                        <?php foreach ($employees as $employee): ?>
                            <li class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-sm dark:border-zinc-800 dark:bg-zinc-950/50">
                                <span class="font-medium">
                                    <?= htmlspecialchars($employee['imie'] . ' ' . $employee['nazwisko'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php if ($employee['opis'] !== null && $employee['opis'] !== ''): ?>
                                    <span class="mt-1 block text-zinc-500 dark:text-zinc-400">
                                        <?= htmlspecialchars($employee['opis'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <div class="mt-6 flex flex-col gap-2 sm:flex-row">
                <a href="<?= htmlspecialchars($basePath . '/login', ENT_QUOTES, 'UTF-8') ?>"
                    class="<?= panel_button_classes('primary') ?>">
                    Zaloguj się, aby zarezerwować
                </a>
                <a href="<?= htmlspecialchars($basePath . '/oferta', ENT_QUOTES, 'UTF-8') ?>"
                    class="<?= panel_button_classes('secondary') ?>">
                    Wróć do oferty
                </a>
            </div>
        </div>
    </main>
<?php
layout_foot();
