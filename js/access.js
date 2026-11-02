(function() {
    function init() {
        const form = document.getElementById('timesheets-access-form');
        if (!form) {
            return;
        }
        const grants = document.getElementById('timesheets-access-grants');
        const type = document.getElementById('timesheets-principal-type');
        const query = document.getElementById('timesheets-principal-query');
        const results = document.getElementById('timesheets-principal-results');
        const searchButton = document.getElementById('timesheets-access-search');
        const addButton = document.getElementById('timesheets-access-add');
        const searchStatus = document.getElementById('timesheets-search-status');
        const status = document.getElementById('timesheets-access-status');
        const submit = form.querySelector('button[type="submit"]');
        let resultType = null;
        let searching = false;
        let saving = false;

        async function responseData(response) {
            if ([400, 403, 409].includes(response.status)) {
                const data = await response.json();
                throw new Error(data.message || t('timesheets', 'The request was rejected. Refresh and try again.'));
            }
            if (!response.ok) {
                throw new Error(t('timesheets', 'The request could not be confirmed. Refresh the page before trying again.'));
            }
            return response.json();
        }

        function clearResults() {
            results.replaceChildren();
            resultType = null;
        }
        type.addEventListener('change', clearResults);
        query.addEventListener('input', clearResults);
        searchButton.addEventListener('click', async function() {
            if (searching || saving) {
                return;
            }
            clearResults();
            const text = query.value.trim();
            if (text.length < 2) {
                searchStatus.textContent = t('timesheets', 'Enter at least 2 characters to search.');
                return;
            }
            const selectedType = type.value;
            searching = true;
            searchButton.disabled = true;
            searchStatus.textContent = t('timesheets', 'Searching...');
            try {
                const url = new URL(form.dataset.searchUrl, window.location.href);
                url.searchParams.set('type', selectedType);
                url.searchParams.set('query', text);
                const data = await responseData(await fetch(url, {
                    headers: {'requesttoken': OC.requestToken, 'Accept': 'application/json'},
                }));
                if (!Array.isArray(data.results)) {
                    throw new Error(t('timesheets', 'Unexpected search response.'));
                }
                if (type.value !== selectedType || query.value.trim() !== text) {
                    searchStatus.textContent = t('timesheets', 'Search changed. Search again to refresh results.');
                    return;
                }
                for (const match of data.results) {
                    results.add(new Option(match.label + ' (' + match.id + ')', match.id));
                }
                resultType = selectedType;
                searchStatus.textContent = data.results.length
                    ? t('timesheets', 'Choose a result, then add an assignment.')
                    : t('timesheets', 'No matching users or groups.');
            } catch (error) {
                console.error('Timesheets access search failed.', error);
                searchStatus.textContent = t('timesheets', 'Unable to search: ') + error.message;
            } finally {
                searching = false;
                searchButton.disabled = false;
            }
        });
        addButton.addEventListener('click', function() {
            if (saving || searching) {
                return;
            }
            const id = results.value;
            if (!id || resultType !== type.value) {
                searchStatus.textContent = t('timesheets', 'Search for and select a user or group first.');
                return;
            }
            if (Array.from(grants.rows).some(row => row.dataset.type === resultType && row.dataset.id === id)) {
                searchStatus.textContent = t('timesheets', 'That assignment already exists.');
                return;
            }
            const row = grants.insertRow();
            row.dataset.type = resultType;
            row.dataset.id = id;
            const heading = document.createElement('th');
            heading.scope = 'row';
            heading.textContent = t('timesheets', resultType === 'user' ? 'User' : 'Group') + ': ' + id;
            row.append(heading);
            for (const permission of ['shift', 'job']) {
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.dataset.permission = permission;
                checkbox.setAttribute('aria-label', t('timesheets', permission === 'shift'
                    ? 'Shift tracking for {id}' : 'Job tracking for {id}', {id}));
                row.insertCell().append(checkbox);
            }
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.dataset.remove = '';
            remove.textContent = t('timesheets', 'Remove');
            remove.setAttribute('aria-label', t('timesheets', 'Remove assignment for {id}', {id}));
            row.insertCell().append(remove);
            searchStatus.textContent = t('timesheets', 'Assignment added. Choose permissions and save access settings.');
        });
        grants.addEventListener('click', function(event) {
            const remove = event.target.closest('button[data-remove]');
            if (remove && !saving) {
                remove.closest('tr').remove();
            }
        });
        form.addEventListener('submit', async function(event) {
            event.preventDefault();
            if (saving || searching) {
                return;
            }
            const policy = {
                restricted: document.getElementById('timesheets-restricted').checked,
                grants: Array.from(grants.rows, row => ({
                    type: row.dataset.type,
                    id: row.dataset.id,
                    shift: row.querySelector('[data-permission="shift"]').checked,
                    job: row.querySelector('[data-permission="job"]').checked,
                })),
            };
            saving = true;
            const controls = Array.from(form.querySelectorAll('input, select, button'));
            controls.forEach(control => { control.disabled = true; });
            status.textContent = t('timesheets', 'Saving access settings...');
            try {
                const data = await responseData(await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'requesttoken': OC.requestToken,
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Accept': 'application/json',
                    },
                    body: new URLSearchParams({policy: JSON.stringify(policy)}).toString(),
                }));
                if (data.status !== 'ok') {
                    throw new Error(t('timesheets', 'Unexpected access settings response.'));
                }
                status.textContent = t('timesheets', 'Access settings saved successfully.');
            } catch (error) {
                console.error('Timesheets access settings could not be saved.', error);
                status.textContent = t('timesheets', 'Unable to confirm access settings were saved. Refresh before retrying. ') + error.message;
            } finally {
                saving = false;
                controls.forEach(control => { control.disabled = false; });
                submit.disabled = false;
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
