<?php
declare(strict_types=1);

/*
    Publiczna strona główna serwisu WeFixIT.

    Działa bez sesji i nie przekierowuje na logowanie. Nagłówek i wezwania do
    działania zmieniają się w zależności od zalogowanego użytkownika. Dane
    kategorii pochodzą z modułu oferty, więc widok nie wykonuje zapytań SQL.
*/
require_once dirname(__DIR__) . '/app/layout.php';
require_once dirname(__DIR__) . '/app/panel_ui.php';
require_once dirname(__DIR__) . '/app/oferta.php';

$basePath = auth_base_path();
$user = current_user();
$categories = oferta_kategorie();

layout_head('WeFixIT — Serwis komputerowy', LAYOUT_PUBLIC_BODY_CLASS);
?>
    <div class="mx-auto flex min-h-screen w-full max-w-6xl flex-col px-4 sm:px-8">
        <header class="flex flex-wrap items-center gap-4 border-b border-zinc-200 py-5 dark:border-zinc-800">
            <div class="flex min-w-0 items-center gap-2.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-zinc-900 text-white shadow-sm dark:bg-zinc-50 dark:text-zinc-900">
                    <?= layout_logo('h-5 w-5') ?>
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-semibold tracking-tight">WeFixIT</span>
                    <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">Serwis komputerowy</span>
                </span>
            </div>

            <nav aria-label="Konto" class="ml-auto flex flex-wrap items-center gap-2">
                <?php if ($user === null): ?>
                    <a href="<?= htmlspecialchars($basePath . '/login', ENT_QUOTES, 'UTF-8') ?>"
                        class="<?= panel_button_classes('secondary') ?>">
                        Zaloguj się
                    </a>
                    <a href="<?= htmlspecialchars($basePath . '/register', ENT_QUOTES, 'UTF-8') ?>"
                        class="<?= panel_button_classes('primary') ?>">
                        Zarejestruj się
                    </a>
                <?php else: ?>
                    <?php
                    $logoutUrl = htmlspecialchars($basePath . '/api/auth/logout', ENT_QUOTES, 'UTF-8');
                    $homeUrl = htmlspecialchars($basePath . '/', ENT_QUOTES, 'UTF-8');
                    ?>
                    <div class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 p-1.5 dark:border-zinc-800 dark:bg-zinc-900/60">
                        <a href="<?= htmlspecialchars($basePath . '/' . auth_role_home_path($user['rola']), ENT_QUOTES, 'UTF-8') ?>"
                            class="flex min-w-0 items-center gap-2 rounded-md px-1.5 py-1 transition-colors hover:bg-zinc-200/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-300 dark:hover:bg-zinc-800 dark:focus-visible:ring-zinc-700">
                            <?= panel_icon('user') ?>
                            <span class="truncate text-sm font-medium"><?= htmlspecialchars($user['imie'] . ' ' . $user['nazwisko'], ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                        <form id="home-logout-form" action="<?= $logoutUrl ?>" method="post"
                            class="shrink-0" data-home-url="<?= $homeUrl ?>">
                            <button type="submit" title="Wyloguj" aria-label="Wyloguj"
                                class="inline-flex h-9 w-9 items-center justify-center rounded-md text-red-600 transition-colors hover:bg-red-50 hover:text-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-300 disabled:cursor-wait disabled:opacity-60 dark:text-red-400 dark:hover:bg-red-950/30 dark:hover:text-red-300">
                                <?= panel_icon('logout', 'h-4 w-4 shrink-0') ?>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </nav>
        </header>

        <main class="flex-1">
            <section class="py-14 sm:py-20">
                <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Rezerwacja wizyt online</p>
                <h1 class="mt-3 max-w-4xl text-4xl font-semibold tracking-tight sm:text-5xl">
                    Twoje urządzenie naprawione, bez kolejki i telefonowania.
                </h1>
                <p class="mt-5 max-w-2xl text-base text-zinc-600 dark:text-zinc-300">
                    Wybierz usługę i dogodny termin. Pracownik serwisu potwierdzi
                    rezerwację, a jej status zobaczysz na swoim koncie.
                </p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <?php if ($user === null): ?>
                        <a href="<?= htmlspecialchars($basePath . '/login', ENT_QUOTES, 'UTF-8') ?>"
                            class="<?= panel_button_classes('primary') ?>">
                            Zaloguj się i zarezerwuj wizytę
                        </a>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($basePath . '/' . auth_role_home_path($user['rola']), ENT_QUOTES, 'UTF-8') ?>"
                            class="<?= panel_button_classes('primary') ?>">
                            Przejdź do panelu
                        </a>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars($basePath . '/oferta', ENT_QUOTES, 'UTF-8') ?>"
                        class="<?= panel_button_classes('secondary') ?>">
                        Zobacz ofertę
                    </a>
                </div>
            </section>

            <?php if ($categories === null): ?>
                <section class="border-t border-zinc-200 py-10 dark:border-zinc-800">
                    <h2 class="text-2xl font-semibold tracking-tight">Co naprawiamy</h2>
                    <p role="alert" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
                        Nie udało się pobrać listy kategorii.
                        <a href="<?= htmlspecialchars($basePath . '/oferta', ENT_QUOTES, 'UTF-8') ?>"
                            class="font-medium underline underline-offset-4">Zobacz ofertę</a>
                    </p>
                </section>
            <?php elseif ($categories !== []): ?>
                <section class="border-t border-zinc-200 py-14 dark:border-zinc-800">
                    <h2 class="text-2xl font-semibold tracking-tight">Co naprawiamy</h2>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                        Wybierz kategorię, aby zobaczyć usługi i ceny.
                    </p>
                    <ul class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <?php foreach ($categories as $category): ?>
                            <?php
                            $description = $category['opis'];

                            if (is_string($description) && mb_strlen($description, 'UTF-8') > 120) {
                                $description = mb_substr($description, 0, 120, 'UTF-8') . '…';
                            }
                            ?>
                            <li>
                                <a href="<?= htmlspecialchars($basePath . '/oferta?kategoria=' . $category['id'], ENT_QUOTES, 'UTF-8') ?>"
                                    class="block h-full rounded-xl border border-zinc-200 bg-white p-5 shadow-sm transition-colors hover:border-zinc-400 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-600">
                                    <h3 class="font-semibold tracking-tight">
                                        <?= htmlspecialchars($category['nazwa'], ENT_QUOTES, 'UTF-8') ?>
                                    </h3>
                                    <?php if ($description !== null && $description !== ''): ?>
                                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                            <?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                    <?php endif; ?>
                                    <span class="mt-4 inline-block text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                        Zobacz usługi
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <section class="border-t border-zinc-200 py-14 dark:border-zinc-800">
                <h2 class="text-2xl font-semibold tracking-tight">Jak to działa</h2>
                <ol class="mt-8 grid gap-4 sm:grid-cols-3">
                    <li class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-zinc-900 text-sm font-semibold text-white dark:bg-zinc-50 dark:text-zinc-900">1</span>
                        <h3 class="mt-4 font-semibold tracking-tight">Wybierz usługę</h3>
                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                            Sprawdź ofertę i znajdź naprawę, której potrzebujesz.
                        </p>
                    </li>
                    <li class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-zinc-900 text-sm font-semibold text-white dark:bg-zinc-50 dark:text-zinc-900">2</span>
                        <h3 class="mt-4 font-semibold tracking-tight">Zarezerwuj termin</h3>
                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                            Podaj dogodny dzień i godzinę. Rezerwacja zapisuje się od razu jako oczekująca.
                        </p>
                    </li>
                    <li class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-zinc-900 text-sm font-semibold text-white dark:bg-zinc-50 dark:text-zinc-900">3</span>
                        <h3 class="mt-4 font-semibold tracking-tight">Pracownik potwierdza</h3>
                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                            Serwisant sprawdzi zgłoszenie, a zmianę statusu wizyty zobaczysz w panelu.
                        </p>
                    </li>
                </ol>
            </section>
        </main>

        <footer class="border-t border-zinc-200 py-6 dark:border-zinc-800">
            <nav aria-label="Stopka" class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                <a href="<?= htmlspecialchars($basePath . '/oferta', ENT_QUOTES, 'UTF-8') ?>"
                    class="text-zinc-600 underline-offset-4 hover:underline dark:text-zinc-300">Oferta</a>
                <?php if ($user === null): ?>
                    <a href="<?= htmlspecialchars($basePath . '/login', ENT_QUOTES, 'UTF-8') ?>"
                        class="text-zinc-600 underline-offset-4 hover:underline dark:text-zinc-300">Logowanie</a>
                    <a href="<?= htmlspecialchars($basePath . '/register', ENT_QUOTES, 'UTF-8') ?>"
                        class="text-zinc-600 underline-offset-4 hover:underline dark:text-zinc-300">Rejestracja</a>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($basePath . '/' . auth_role_home_path($user['rola']), ENT_QUOTES, 'UTF-8') ?>"
                        class="text-zinc-600 underline-offset-4 hover:underline dark:text-zinc-300">Panel</a>
                <?php endif; ?>
            </nav>
            <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">WeFixIT — Serwis komputerowy</p>
        </footer>
    </div>
<?php
if ($user !== null) {
    layout_script('home.js');
}
layout_foot();
