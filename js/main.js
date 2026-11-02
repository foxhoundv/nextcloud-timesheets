(function() {
    function init() {
        const elapsed = document.getElementById('timesheets-elapsed');
        if (elapsed) {
            const initialSeconds = Number(elapsed.dataset.seconds);
            const started = performance.now();
            const update = function() {
                const seconds = initialSeconds + Math.floor((performance.now() - started) / 1000);
                elapsed.textContent = Math.floor(seconds / 3600) + 'h '
                    + String(Math.floor(seconds % 3600 / 60)).padStart(2, '0') + 'm '
                    + String(seconds % 60).padStart(2, '0') + 's';
            };
            window.setInterval(update, 1000);
        }

        const form = document.getElementById('timesheets-clock-form');
        if (!form) {
            return;
        }
        const status = document.getElementById('timesheets-clock-status');
        const button = form.querySelector('button[type="submit"]');
        form.addEventListener('submit', async function(event) {
            event.preventDefault();
            if (button.disabled) {
                return;
            }
            button.disabled = true;
            status.textContent = t('timesheets', 'Saving your shift...');
            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'requesttoken': OC.requestToken,
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Accept': 'application/json',
                    },
                    body: new URLSearchParams(new FormData(form)).toString(),
                });
                if (!response.ok) {
                    if ([400, 403, 409].includes(response.status)) {
                        const data = await response.json();
                        status.textContent = data.message || t('timesheets', 'Unable to record time. Refresh the page and try again.');
                        return;
                    }
                    throw new Error('Shift request failed: ' + response.status);
                }
                const data = await response.json();
                if (data.status !== 'ok') {
                    throw new Error('Unexpected shift response');
                }
                window.location.reload();
            } catch (error) {
                console.error('Timesheets could not record the shift.', error);
                status.textContent = t('timesheets', 'Unable to confirm that your shift was saved. Refresh the page to check before trying again.');
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
