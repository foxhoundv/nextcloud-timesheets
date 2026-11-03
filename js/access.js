(function() {
    function init() {
        const form = document.getElementById('timesheets-access-form');
        if (!form) {
            return;
        }
        const grants = document.getElementById('timesheets-access-grants');
        const tabs = Array.from(form.querySelectorAll('[role="tab"]'));
        const addButton = document.getElementById('timesheets-access-add');
        const selectionStatus = document.getElementById('timesheets-selection-status');
        const status = document.getElementById('timesheets-access-status');
        const directories = {};
        const lists = {};
        let activeType = 'group';
        let saving = false;
        for (const type of ['group', 'user']) {
            lists[type] = form.querySelector('[data-directory="' + type + '"]');
            directories[type] = Array.from(lists[type].options, option => ({
                id: option.value,
                name: option.dataset.name,
            }));
        }

        function refresh() {
            for (const type of ['group', 'user']) {
                const list = lists[type];
                const selected = list.value;
                const assigned = new Set(Array.from(grants.children)
                    .filter(chip => chip.dataset.type === type).map(chip => chip.dataset.id));
                list.replaceChildren();
                for (const entry of directories[type]) {
                    if (!assigned.has(entry.id)) {
                        list.add(new Option(entry.name, entry.id));
                    }
                }
                if (Array.from(list.options).some(option => option.value === selected)) {
                    list.value = selected;
                } else {
                    list.selectedIndex = -1;
                }
                form.querySelector('[data-empty="' + type + '"]').hidden = list.options.length > 0;
            }
            addButton.disabled = saving || lists[activeType].selectedIndex < 0;
            document.getElementById('timesheets-grants-empty').hidden = grants.children.length > 0;
        }

        function activate(tab, focus) {
            activeType = tab.dataset.type;
            for (const item of tabs) {
                const selected = item === tab;
                item.setAttribute('aria-selected', String(selected));
                item.tabIndex = selected ? 0 : -1;
                document.getElementById(item.getAttribute('aria-controls')).hidden = !selected;
            }
            addButton.disabled = saving || lists[activeType].selectedIndex < 0;
            if (focus) {
                tab.focus();
            }
        }
        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => activate(tab, false));
            tab.addEventListener('keydown', function(event) {
                if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                    event.preventDefault();
                    const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
                        : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
                    activate(tabs[next], true);
                }
            });
        });
        Object.values(lists).forEach(list => list.addEventListener('change', function() {
            addButton.disabled = saving || lists[activeType].selectedIndex < 0;
        }));
        addButton.addEventListener('click', function() {
            if (saving || lists[activeType].selectedIndex < 0) {
                return;
            }
            const option = lists[activeType].selectedOptions[0];
            const id = option.value;
            const name = option.textContent;
            const chip = document.createElement('li');
            chip.className = 'timesheets-access-chip';
            chip.dataset.type = activeType;
            chip.dataset.id = id;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.dataset.remove = '';
            remove.setAttribute('aria-label', t('timesheets', 'Remove assignment for {name}', {name}));
            const icon = document.createElement('span');
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = '\u00d7';
            remove.append(icon);
            const heading = document.createElement('span');
            heading.textContent = t('timesheets', activeType === 'user' ? 'User' : 'Group') + ': ' + name;
            chip.append(remove, heading);
            for (const permission of ['shift', 'job', 'admin']) {
                const label = document.createElement('label');
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.dataset.permission = permission;
                checkbox.disabled = permission === 'admin' && grants.dataset.canManageAdmins !== 'true';
                label.append(checkbox, document.createTextNode(t('timesheets',
                    permission === 'shift' ? 'Shift tracking'
                        : permission === 'job' ? 'Job tracking' : 'Timesheets administration')));
                chip.append(label);
            }
            grants.append(chip);
            refresh();
            remove.focus();
            selectionStatus.textContent = t('timesheets', 'Assignment added. Choose permissions and save access settings.');
        });
        grants.addEventListener('click', function(event) {
            const remove = event.target.closest('button[data-remove]');
            if (remove && !saving) {
                remove.closest('li').remove();
                refresh();
                lists[activeType].focus();
                selectionStatus.textContent = t('timesheets', 'Assignment removed. Save access settings to apply this change.');
            }
        });
        form.addEventListener('submit', async function(event) {
            event.preventDefault();
            if (saving) {
                return;
            }
            const policy = {
                restricted: document.getElementById('timesheets-restricted').checked,
                grants: Array.from(grants.children, chip => ({
                    type: chip.dataset.type,
                    id: chip.dataset.id,
                    shift: chip.querySelector('[data-permission="shift"]').checked,
                    job: chip.querySelector('[data-permission="job"]').checked,
                    admin: chip.querySelector('[data-permission="admin"]').checked,
                })),
            };
            saving = true;
            const controls = Array.from(form.querySelectorAll('input, select, button'));
            controls.forEach(control => { control.disabled = true; });
            status.textContent = t('timesheets', 'Saving access settings...');
            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'requesttoken': OC.requestToken,
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Accept': 'application/json',
                    },
                    body: new URLSearchParams({policy: JSON.stringify(policy)}).toString(),
                });
                if (!response.ok) {
                    if ([400, 403, 409].includes(response.status)) {
                        const data = await response.json();
                        throw new Error(data.message || t('timesheets', 'The request was rejected. Refresh and try again.'));
                    }
                    throw new Error(t('timesheets', 'The request could not be confirmed. Refresh the page before trying again.'));
                }
                const data = await response.json();
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
                refresh();
            }
        });
        refresh();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
