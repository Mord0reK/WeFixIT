/*
    Powiadomienia (toasty) w rogu ekranu.

    Używane po udanych operacjach wywoływanych przez fetch(), np. zapisie profilu.
    Kontener #toast-container renderuje wspólny layout; tutaj tworzymy i usuwamy
    pojedyncze komunikaty. Odrzucenie działa kliknięciem w cały toast, a pasek na
    dole pokazuje pozostały czas. Animacje pomijamy przy prefers-reduced-motion.
*/
(function () {
    const DURATION = 5000;
    const LEAVE_MS = 200;

    // Ikony to stałe, zaufane fragmenty SVG (żadnych danych użytkownika).
    const ICONS = {
        success: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5"><circle cx="12" cy="12" r="10"/><path d="m16 9-5.5 5.5L8 12"/></svg>',
        error: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>',
    };

    // Pełne nazwy klas są literałami, żeby Tailwind je wykrył przy budowaniu CSS.
    const TYPES = {
        success: {
            border: 'border-green-500',
            icon: 'text-green-600 dark:text-green-400',
            bar: 'bg-green-500',
        },
        error: {
            border: 'border-red-500',
            icon: 'text-red-600 dark:text-red-400',
            bar: 'bg-red-500',
        },
    };

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function dismiss(toast) {
        if (toast.dataset.closing === 'true') return;

        toast.dataset.closing = 'true';

        if (reduceMotion) {
            toast.remove();
            return;
        }

        toast.style.animation = 'toast-out 0.15s ease-in forwards';
        window.setTimeout(function () {
            toast.remove();
        }, LEAVE_MS);
    }

    function showToast(message, type) {
        const container = document.getElementById('toast-container');

        if (!container) return;

        const style = TYPES[type] || TYPES.success;
        const icon = ICONS[type] || ICONS.success;

        const toast = document.createElement('div');
        toast.className = 'pointer-events-auto relative cursor-pointer overflow-hidden rounded-lg border-2 bg-white/95 shadow-lg backdrop-blur '
            + style.border + ' dark:bg-gray-800/95';
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

        if (!reduceMotion) {
            toast.style.animation = 'toast-in 0.2s ease-out';
        }

        const body = document.createElement('div');
        body.className = 'flex items-start gap-3 px-4 py-3';

        const iconWrap = document.createElement('span');
        iconWrap.className = 'shrink-0 ' + style.icon;
        iconWrap.innerHTML = icon;

        const text = document.createElement('span');
        text.className = 'flex-1 break-words text-sm text-gray-800 dark:text-gray-100';
        text.textContent = message;

        const close = document.createElement('button');
        close.type = 'button';
        close.setAttribute('aria-label', 'Zamknij powiadomienie');
        close.className = 'shrink-0 rounded leading-none text-gray-400 hover:text-gray-600 dark:hover:text-gray-200';
        close.textContent = '\u00d7';

        body.append(iconWrap, text, close);

        const bar = document.createElement('div');
        bar.className = 'h-0.5 ' + style.bar;

        if (!reduceMotion) {
            bar.style.animation = 'toast-progress ' + DURATION + 'ms linear forwards';
        }

        toast.append(body, bar);
        toast.addEventListener('click', function () {
            dismiss(toast);
        });

        container.appendChild(toast);
        window.setTimeout(function () {
            dismiss(toast);
        }, DURATION);
    }

    window.showToast = showToast;
})();
