(function() {
    function init() {
        document.querySelectorAll('[data-work-location]').forEach(function(picker) {
            const options = JSON.parse(picker.dataset.options);
            const country = picker.querySelector('[name="country"]');
            const region = picker.querySelector('[name="region"]');
            if (!region) {
                return;
            }
            const update = function(preferred) {
                region.replaceChildren(new Option(t('timesheets', 'Choose a state / province / region'), ''));
                const entry = options.countries[country.value];
                (entry ? entry.regions : []).forEach(function(name) {
                    region.add(new Option(name, name, false, name === preferred));
                });
            };
            update(options.defaultRegion);
            country.addEventListener('change', function() { update(''); });
        });

        const form = document.getElementById('timesheets-location-settings');
        if (!form) {
            return;
        }
        const settings = JSON.parse(form.dataset.settings);
        const countries = JSON.parse(form.dataset.countries);
        const usStates = JSON.parse(form.dataset.usStates);
        const regions = new Map(Object.entries(settings.regions).map(function(entry) {
            return [entry[0], new Set(entry[1])];
        }));
        const controls = {};
        form.querySelectorAll('[data-location-setting]').forEach(function(control) {
            controls[control.dataset.locationSetting] = control;
        });
        const countryChecks = Array.from(form.querySelectorAll('[data-location-country]'));
        const regionPanels = form.querySelector('#timesheets-region-checklists');
        const status = form.querySelector('[role="status"]');
        function selectedCountries() {
            return controls.multipleCountries.value === '1'
                ? countryChecks.filter(check => check.checked).map(check => check.dataset.locationCountry)
                : (controls.workingCountry.value ? [controls.workingCountry.value] : []);
        }
        function updateDefault() {
            const preferred = controls.defaultRegion.value || settings.defaultRegion;
            const values = Array.from(regions.get(controls.workingCountry.value) || []).sort();
            controls.defaultRegion.replaceChildren(new Option(t('timesheets', 'Choose a default region'), ''));
            values.forEach(function(name) {
                controls.defaultRegion.add(new Option(name, name, false, name === preferred));
            });
        }
        function renderRegions() {
            form.querySelector('#timesheets-country-checklist').hidden = controls.multipleCountries.value !== '1';
            form.querySelector('#timesheets-region-settings').hidden = controls.multipleRegions.value !== '1';
            regionPanels.replaceChildren();
            selectedCountries().forEach(function(code) {
                if (!regions.has(code)) {
                    regions.set(code, new Set());
                }
                const selected = regions.get(code);
                const fieldset = document.createElement('fieldset');
                const legend = document.createElement('legend');
                legend.textContent = countries[code];
                fieldset.appendChild(legend);
                const list = document.createElement('div');
                list.className = 'timesheets-location-checklist';
                fieldset.appendChild(list);
                function addChoice(name, checked) {
                    const label = document.createElement('label');
                    const check = document.createElement('input');
                    check.type = 'checkbox';
                    check.checked = checked;
                    check.addEventListener('change', function() {
                        if (check.checked) {
                            selected.add(name);
                        } else {
                            selected.delete(name);
                        }
                        updateDefault();
                    });
                    label.appendChild(check);
                    label.appendChild(document.createTextNode(' ' + name));
                    list.appendChild(label);
                }
                (code === 'US' ? usStates.slice().sort() : Array.from(selected).sort()).forEach(function(name) {
                    addChoice(name, selected.has(name));
                });
                if (code !== 'US') {
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.maxLength = 100;
                    input.setAttribute('aria-label', t('timesheets', 'New state / province / region for %s', [countries[code]]));
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = t('timesheets', 'Add region');
                    button.addEventListener('click', function() {
                        const name = input.value.trim();
                        if (!name || selected.has(name)) {
                            status.textContent = t('timesheets', 'Enter a new region name before adding it.');
                            return;
                        }
                        selected.add(name);
                        renderRegions();
                        status.textContent = '';
                    });
                    fieldset.append(input, button);
                }
                regionPanels.appendChild(fieldset);
            });
            updateDefault();
        }
        [controls.multipleCountries, controls.multipleRegions, controls.workingCountry].concat(countryChecks)
            .forEach(control => control.addEventListener('change', renderRegions));
        renderRegions();

        form.addEventListener('submit', async function(event) {
            event.preventDefault();
            const button = form.querySelector('[type="submit"]');
            button.disabled = true;
            status.textContent = '';
            const value = {
                shiftEnabled: controls.shiftEnabled.checked,
                jobEnabled: controls.jobEnabled.checked,
                multipleCountries: controls.multipleCountries.value === '1',
                multipleRegions: controls.multipleRegions.value === '1',
                workingCountry: controls.workingCountry.value,
                countries: countryChecks.filter(check => check.checked).map(check => check.dataset.locationCountry),
                regions: Object.fromEntries(Array.from(regions, ([code, names]) => [code, Array.from(names)])),
                defaultRegion: controls.defaultRegion.value,
            };
            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'requesttoken': OC.requestToken,
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    },
                    body: new URLSearchParams({ settings: JSON.stringify(value) }).toString(),
                });
                const data = await response.json();
                if (!response.ok) {
                    status.textContent = data.message || t('timesheets', 'Unable to save work-location settings.');
                    return;
                }
                if (data.status !== 'ok') {
                    throw new Error('Unexpected work-location response');
                }
                status.textContent = t('timesheets', 'Work-location settings saved successfully.');
            } catch (error) {
                console.error('Timesheets work-location settings could not be saved.', error);
                status.textContent = t('timesheets', 'Unable to save work-location settings. Check the server logs and try again.');
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
