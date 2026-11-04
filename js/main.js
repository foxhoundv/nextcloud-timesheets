(function() {
    function init() {
        const longShiftConfirmation = document.getElementById('timesheets-long-shift-confirmation');
        if (window.location.hash === '#timesheets-long-shift-confirmation' && longShiftConfirmation) {
            longShiftConfirmation.hidden = false;
            window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search);
            longShiftConfirmation.focus();
        }
        if (longShiftConfirmation) {
            longShiftConfirmation.querySelector('[data-dismiss-long-shift]').addEventListener('click', function() {
                longShiftConfirmation.remove();
            });
        }

        document.querySelectorAll('#timesheets-elapsed, #timesheets-job-elapsed').forEach(function(elapsed) {
            const initialSeconds = Number(elapsed.dataset.seconds);
            const started = performance.now();
            const update = function() {
                const seconds = initialSeconds + Math.floor((performance.now() - started) / 1000);
                elapsed.textContent = Math.floor(seconds / 3600) + 'h '
                    + String(Math.floor(seconds % 3600 / 60)).padStart(2, '0') + 'm '
                    + String(seconds % 60).padStart(2, '0') + 's';
            };
            window.setInterval(update, 1000);
        });

        const forms = Array.from(document.querySelectorAll('#timesheets-clock-form, #timesheets-job-form'));
        const buttons = forms.map(form => form.querySelector('button[type="submit"]'));
        forms.forEach(form => form.addEventListener('submit', async function(event) {
            const status = form.querySelector('[role="status"]');
            const button = form.querySelector('button[type="submit"]');
            event.preventDefault();
            if (button.disabled) {
                return;
            }
            buttons.forEach(button => { button.disabled = true; });
            status.textContent = t('timesheets', 'Saving your time...');
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
                    if ([400, 403, 404, 409].includes(response.status)) {
                        const data = await response.json();
                        status.textContent = data.message || t('timesheets', 'Unable to record time. Refresh the page and try again.');
                        return;
                    }
                    throw new Error('Time request failed: ' + response.status);
                }
                const data = await response.json();
                if (data.status !== 'ok') {
                    throw new Error('Unexpected time response');
                }
                if (data.warning) {
                    window.alert(data.warning);
                }
                if (form.id === 'timesheets-clock-form' && data.longShiftConfirmation === true) {
                    const target = new URL(window.location.href);
                    target.hash = 'timesheets-long-shift-confirmation';
                    window.history.replaceState(window.history.state, '', target.toString());
                    window.location.reload();
                    return;
                }
                window.location.reload();
            } catch (error) {
                console.error('Timesheets could not record time.', error);
                status.textContent = t('timesheets', 'Unable to confirm that your time was saved. Refresh the page to check before trying again.');
            } finally {
                buttons.forEach(button => { button.disabled = false; });
            }
        }));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
