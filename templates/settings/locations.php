<?php

declare(strict_types=1);

$locations = $_['locations'];
?>
<form id="timesheets-location-settings" method="post" action="<?php p($_['locationSaveUrl']); ?>"
    data-settings="<?php p(json_encode($locations, JSON_THROW_ON_ERROR)); ?>"
    data-countries="<?php p(json_encode($_['locationCountries'], JSON_THROW_ON_ERROR)); ?>"
    data-us-states="<?php p(json_encode($_['locationStates'], JSON_THROW_ON_ERROR)); ?>">
    <fieldset>
        <legend><?php p($l->t('Work locations for payroll')); ?></legend>
        <p><?php p($l->t('Optional. Track countries and states / provinces / regions separately. Enable employee location selection independently for shifts and Jobs. A mode without selection uses the default location; Jobs inherit an active shift location.')); ?></p>
        <?php foreach (['shiftEnabled' => 'Allow location selection on shifts', 'jobEnabled' => 'Allow location selection on Jobs'] as $key => $label): ?>
            <p><label><input type="checkbox" data-location-setting="<?php p($key); ?>" <?php echo $locations[$key] ? 'checked' : ''; ?>> <?php p($l->t($label)); ?></label></p>
        <?php endforeach; ?>
        <div class="timesheets-admin-row">
            <label for="timesheets-multiple-countries"><?php p($l->t('Do you need to track multiple countries?')); ?></label>
            <select id="timesheets-multiple-countries" data-location-setting="multipleCountries">
                <option value="0" <?php echo !$locations['multipleCountries'] ? 'selected' : ''; ?>><?php p($l->t('No')); ?></option>
                <option value="1" <?php echo $locations['multipleCountries'] ? 'selected' : ''; ?>><?php p($l->t('Yes')); ?></option>
            </select>
        </div>
        <fieldset id="timesheets-country-checklist" <?php echo !$locations['multipleCountries'] ? 'hidden' : ''; ?>>
            <legend><?php p($l->t('Countries to track separately')); ?></legend>
            <div class="timesheets-location-checklist">
                <?php foreach ($_['locationCountries'] as $code => $name): ?>
                    <label><input type="checkbox" data-location-country="<?php p($code); ?>" <?php echo in_array($code, $locations['countries'], true) ? 'checked' : ''; ?>> <?php p($name); ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="timesheets-admin-row">
            <label for="timesheets-working-country"><?php p($l->t('Working country (default for everyone)')); ?></label>
            <select id="timesheets-working-country" data-location-setting="workingCountry">
                <option value=""><?php p($l->t('Choose a country')); ?></option>
                <?php foreach ($_['locationCountries'] as $code => $name): ?>
                    <option value="<?php p($code); ?>" <?php echo $locations['workingCountry'] === $code ? 'selected' : ''; ?>><?php p($name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="timesheets-admin-row">
            <label for="timesheets-multiple-regions"><?php p($l->t('Do you need to track multiple states / provinces / regions?')); ?></label>
            <select id="timesheets-multiple-regions" data-location-setting="multipleRegions">
                <option value="0" <?php echo !$locations['multipleRegions'] ? 'selected' : ''; ?>><?php p($l->t('No')); ?></option>
                <option value="1" <?php echo $locations['multipleRegions'] ? 'selected' : ''; ?>><?php p($l->t('Yes')); ?></option>
            </select>
        </div>
        <div id="timesheets-region-settings" <?php echo !$locations['multipleRegions'] ? 'hidden' : ''; ?>>
            <p><?php p($l->t('Select US states from the checklist. For other countries, add the names of the provinces, regions, or other subdivisions your company uses, then select those to offer to employees.')); ?></p>
            <div id="timesheets-region-checklists"></div>
            <div class="timesheets-admin-row">
                <label for="timesheets-default-region"><?php p($l->t('Default state / province / region in the working country')); ?></label>
                <select id="timesheets-default-region" data-location-setting="defaultRegion"></select>
            </div>
        </div>
        <p><?php p($l->t('Location is saved at clock-in and cannot change during an interval. End the Job and/or shift before starting at another location. A Job ID keeps its recorded location across employees. Changing these settings never relabels history or prevents clock-out.')); ?></p>
        <p><?php p($l->t('Payroll uses the shift location, overridden only by overlapping Job locations; Job time outside a shift is not added to payroll. Reported shift seconds are allocated proportionally, without rounding each location again. Older entries without a location remain Not recorded.')); ?></p>
    </fieldset>
    <div class="timesheets-admin-actions">
        <button type="submit" class="primary"><?php p($l->t('Save work-location settings')); ?></button>
        <p role="status" aria-live="polite"></p>
    </div>
</form>
