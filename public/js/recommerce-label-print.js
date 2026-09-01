(function () {
    function failureMessage(response, body) {
        try {
            var parsed = JSON.parse(body);
            if (parsed && typeof parsed.message === 'string' && parsed.message.trim() !== '') {
                return parsed.message;
            }
        } catch (error) {
            // The print endpoint normally returns HTML; its safe JSON message
            // is used only when label generation was rejected.
        }

        return response.status === 429
            ? 'Too many label requests. Please wait a moment and try again.'
            : 'The SAVERBRO label could not be opened. Please try again.';
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-recommerce-label-print] button[type="button"]');
        if (!button || button.disabled) {
            return;
        }

        var form = button.closest('form');
        if (!form) {
            return;
        }

        event.preventDefault();
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');

        fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'text/html',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams(new FormData(form)).toString()
        }).then(async function (response) {
            var body = await response.text();
            if (!response.ok) {
                throw new Error(failureMessage(response, body));
            }

            // The label endpoint deliberately returns a complete, print-safe
            // document. Replacing this tab avoids a popup dependency while
            // preserving the normal browser print dialog in that document.
            document.open();
            document.write(body);
            document.close();
        }).catch(function (error) {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            window.alert(error.message || 'The SAVERBRO label could not be opened. Please try again.');
        });
    });
}());
