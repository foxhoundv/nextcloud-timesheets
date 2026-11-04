(function() {
    function init() {
        const section = document.getElementById('timesheets-record-reset');
        if (!section) {
            return;
        }
        const dialog = section.querySelector('dialog');
        const form = section.querySelector('form');
        const input = form.querySelector('input');
        const confirm = section.querySelector('[data-reset-confirm]');
        const cancel = section.querySelector('[data-reset-cancel]');
        const status = section.querySelector('[data-reset-status]');
        const dialogStatus = section.querySelector('[data-reset-dialog-status]');
        let current = null;
        let busy = false;

        function updateButtons() {
            section.querySelectorAll('button').forEach(button => { button.disabled = busy; });
            confirm.disabled = busy || !current || input.value !== 'DELETE' || current.approvedByYou
                || current.counts.shifts + current.counts.jobs === 0;
            cancel.disabled = busy || !current || !current.requestId;
        }
        function show(result) {
            current = result;
            const summary = t('timesheets', '{shifts} shift records and {jobs} Job records; {activeShifts} active shifts and {activeJobs} active Jobs.', {
                shifts: result.counts.shifts, jobs: result.counts.jobs,
                activeShifts: result.counts.activeShifts, activeJobs: result.counts.activeJobs,
            });
            const approval = result.requestId
                ? t('timesheets', '{approved} of {administrators} administrators approved. Expires: {expires}.', {
                    approved: result.approved.length, administrators: result.administrators.length,
                    expires: new Date(result.expiresAt * 1000).toLocaleString(),
                }) : t('timesheets', 'No deletion request is pending.');
            section.querySelector('[data-reset-summary]').textContent = summary + ' ' + approval;
            section.querySelector('[data-reset-dialog-summary]').textContent = summary + ' ' + approval;
            const list = section.querySelector('[data-reset-administrators]');
            list.replaceChildren();
            result.administrators.forEach(function(id) {
                const item = document.createElement('li');
                item.textContent = id + ' - ' + (result.approved.includes(id)
                    ? t('timesheets', 'Approved') : t('timesheets', 'Approval required'));
                list.appendChild(item);
            });
            if (result.notice) {
                status.textContent = t('timesheets', result.notice);
            }
            updateButtons();
        }
        async function request(url, payload) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Accept': 'application/json',
                },
                body: new URLSearchParams(payload || {}).toString(),
            });
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.message || t('timesheets', 'Unable to manage the deletion request. Reload and try again.'));
            }
            if (data.status !== 'ok' || !data.result) {
                throw new Error(t('timesheets', 'Unexpected deletion response. Reload and check the current records.'));
            }
            return data.result;
        }
        async function refresh(open) {
            busy = true;
            updateButtons();
            status.textContent = '';
            try {
                show(await request(section.dataset.statusUrl));
                if (open) {
                    input.value = '';
                    dialogStatus.textContent = '';
                    dialog.showModal();
                    input.focus();
                }
            } catch (error) {
                current = null;
                console.error('Timesheets deletion status could not be loaded.', error);
                status.textContent = error.message;
            } finally {
                busy = false;
                updateButtons();
            }
        }
        section.querySelector('[data-reset-open]').addEventListener('click', function() { refresh(true); });
        section.querySelector('[data-reset-refresh]').addEventListener('click', function() { refresh(false); });
        section.querySelector('[data-reset-close]').addEventListener('click', function() { dialog.close(); });
        input.addEventListener('input', updateButtons);
        dialog.addEventListener('close', function() { input.value = ''; updateButtons(); });
        form.addEventListener('submit', async function(event) {
            event.preventDefault();
            if (confirm.disabled) {
                return;
            }
            busy = true;
            updateButtons();
            dialogStatus.textContent = '';
            try {
                const result = await request(section.dataset.approveUrl, {
                    confirmation: input.value, snapshot: current.snapshot, requestId: current.requestId,
                });
                input.value = '';
                if (result.deleted) {
                    dialog.close();
                    show(await request(section.dataset.statusUrl));
                    status.textContent = t('timesheets', 'All Timesheets database records were deleted. Active timers were reset. Excel files and settings were not changed.');
                } else {
                    show(result);
                    dialog.close();
                    status.textContent = t('timesheets', 'Your approval was saved. No records will be deleted until every administrator approves this request.');
                }
            } catch (error) {
                console.error('Timesheets deletion approval failed.', error);
                input.value = '';
                try {
                    show(await request(section.dataset.statusUrl));
                } catch (refreshError) {
                    current = null;
                    console.error('Timesheets deletion status refresh failed.', refreshError);
                }
                dialogStatus.textContent = error.message;
            } finally {
                busy = false;
                updateButtons();
            }
        });
        cancel.addEventListener('click', async function() {
            if (!current || !current.requestId || busy) {
                return;
            }
            busy = true;
            updateButtons();
            try {
                await request(section.dataset.cancelUrl, { requestId: current.requestId });
                show(await request(section.dataset.statusUrl));
                status.textContent = t('timesheets', 'The deletion request was canceled. No records were deleted.');
            } catch (error) {
                console.error('Timesheets deletion cancellation failed.', error);
                status.textContent = error.message;
            } finally {
                busy = false;
                updateButtons();
            }
        });
        refresh(false);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
