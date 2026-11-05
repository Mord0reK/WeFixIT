/*
    Przypisania usług do pracownika.

    Formularz wysyła docelowy zbiór usług; nieaktywne usługi są tylko
    informacyjne i nie biorą udziału w żądaniu. Identyfikator pracownika
    pochodzi z data-employee-id formularza.
*/
(function () {
    const form = document.getElementById('employee-services-form');

    if (!form) return;

    const formError = document.getElementById('employee-services-form-error');
    const fieldError = document.getElementById('employee-services-error-usluga_ids');
    const submit = document.getElementById('employee-services-submit');
    const reset = document.getElementById('employee-services-reset');
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';
    const employeeId = Number(form.dataset.employeeId || 0);
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
        // Nieaktywne usługi są tylko informacyjne; ich przypisań nie wysyłamy.
        const serviceIds = Array.from(form.querySelectorAll('input[name="usluga_ids[]"]:checked:not(:disabled)'))
            .map(function (input) {
                return Number(input.value);
            });
        let completed = false;
        submit.textContent = 'Zapisywanie…';

        try {
            const response = await fetch(form.action, {
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
