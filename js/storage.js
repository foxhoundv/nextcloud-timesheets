(function() {
    function init() {
        [
            ['timesheets-storage-form', 'timesheets-storage-status'],
            ['timesheets-payroll-report-form', 'timesheets-payroll-status'],
            ['timesheets-job-report-form', 'timesheets-job-status'],
        ].forEach(function(ids) {
            const form = document.getElementById(ids[0]);
            const status = document.getElementById(ids[1]);
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
                    const data = await response.json();
                    if (!response.ok) {
                        status.textContent = data.message || t('timesheets', 'Unable to complete the storage request.');
                        return;
                    }
                    if (!data || data.status !== 'ok') {
                        throw new Error('Unexpected storage response');
                    }
                    status.textContent = data.message || t('timesheets', 'Storage roots saved successfully.');
                } catch (error) {
                    console.error('Timesheets storage request failed.', error);
                    status.textContent = t('timesheets', 'Unable to complete the storage request. Check the server logs and try again.');
                } finally {
                    button.disabled = false;
                }
            });
        });

        document.querySelectorAll('[data-folder-picker]').forEach(function(button) {
            button.addEventListener('click', function() {
                const input = document.getElementById(button.dataset.folderPicker);
                const status = document.getElementById('timesheets-storage-status');
                if (!input || !status) {
                    return;
                }
                if (typeof OC === 'undefined' || !OC.dialogs || typeof OC.dialogs.filepicker !== 'function') {
                    status.textContent = t('timesheets', 'The Nextcloud folder picker is unavailable. Reload the page and try again.');
                    return;
                }
                OC.dialogs.filepicker(
                    t('timesheets', 'Choose a folder'),
                    function(path) {
                        if (typeof path !== 'string') {
                            return;
                        }
                        const relativePath = path.replace(/\\/g, '/').replace(/^\/+|\/+$/g, '');
                        if (!relativePath) {
                            status.textContent = t('timesheets', 'Choose a folder inside your files, not the files root.');
                            return;
                        }
                        input.value = relativePath;
                        status.textContent = '';
                    },
                    false,
                    'httpd/unix-directory',
                    true,
                    OC.dialogs.FILEPICKER_TYPE_CHOOSE,
                    '/',
                    { allowDirectoryChooser: true }
                );
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
