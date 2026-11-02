(function() {
    function init() {
        const form = document.getElementById('timesheets-admin-form');
        const status = document.getElementById('timesheets-status');

        if (!form || !status) {
            return;
        }

        form.addEventListener('submit', async function(event) {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            status.textContent = '';

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'requesttoken': OC.requestToken,
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    },
                    body: new URLSearchParams(new FormData(form)).toString(),
                });

                if (!response.ok) {
                    throw new Error('Settings request failed: ' + response.status);
                }

                const data = await response.json();
                if (!data || data.status !== 'ok') {
                    throw new Error('Unexpected settings response');
                }

                status.textContent = t('timesheets', 'Settings saved successfully.');
                status.style.color = 'var(--color-success)';
            } catch (error) {
                console.error('Timesheets settings could not be saved.', error);
                status.textContent = t('timesheets', 'Unable to save settings. Please try again.');
                status.style.color = 'var(--color-error)';
            } finally {
                button.disabled = false;
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
