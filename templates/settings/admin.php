<?php

declare(strict_types=1);

script('timesheets', 'admin');
script('timesheets', 'access');
style('timesheets', 'main');

$settings = $_['settings'];
?>
<div id="timesheets-admin" class="section">
    <h2><?php p($l->t('Timesheet Administration')); ?></h2>
    <p class="timesheets-intro"><?php p($l->t('Configure tracking preferences and time-recording rules for your organization.')); ?></p>
    <p><?php p($l->t('Saved settings, shift history, and active shifts are retained when the app is updated. After an update, reload the dashboard to see your continuing shift; elapsed time includes the update period.')); ?></p>
    <p class="timesheets-notice"><?php p($l->t('Shift tracking enables clock-in/out with actual elapsed time. Job tracking, breaks, rounding, and shift confirmations are still in development; their preferences are saved but not yet applied.')); ?></p>

    <form id="timesheets-admin-form" method="post" action="<?php p($_['saveUrl']); ?>">
        <fieldset>
            <legend><?php p($l->t('Tracking preferences')); ?></legend>
        <div class="timesheets-admin-row">
            <div class="timesheets-setting-copy">
                <label for="shift_tracking"><?php p($l->t('Enable shift tracking')); ?></label>
                <p id="shift_tracking_help"><?php p($l->t('Allow users with app access to clock in and out. Disabling this prevents new shifts; users can still clock out of a shift that is already open. Time is saved to the second; totals display completed whole minutes without rounding.')); ?></p>
            </div>
            <input type="checkbox" id="shift_tracking" name="shift_tracking" value="1" aria-describedby="shift_tracking_help" <?php echo !empty($settings['shift_tracking']) ? 'checked' : ''; ?>>
        </div>

        <div class="timesheets-admin-row">
            <div class="timesheets-setting-copy">
                <label for="job_tracking"><?php p($l->t('Enable job tracking')); ?></label>
                <p id="job_tracking_help"><?php p($l->t('Record time spent on individual Sales Order jobs separately from overall shift time. Time within a shift that is not assigned to a job will count as shop time.')); ?></p>
            </div>
            <input type="checkbox" id="job_tracking" name="job_tracking" value="1" aria-describedby="job_tracking_help" <?php echo !empty($settings['job_tracking']) ? 'checked' : ''; ?>>
        </div>
        </fieldset>

        <fieldset>
            <legend><?php p($l->t('Time rounding')); ?></legend>
            <p class="timesheets-fieldset-help"><?php p($l->t('Choose the interval for each type of time. Smaller intervals keep more detail; larger intervals group time into larger blocks.')); ?></p>
        <div class="timesheets-admin-row">
            <div class="timesheets-setting-copy">
                <label for="shift_rounding"><?php p($l->t('Shift rounding interval')); ?></label>
                <p id="shift_rounding_help"><?php p($l->t('The interval intended for shift-time rounding. For example, 15 minutes represents quarter-hour blocks. This preference is separate from job rounding.')); ?></p>
            </div>
            <select name="shift_rounding" id="shift_rounding" aria-describedby="shift_rounding_help">
                <?php foreach (['5', '10', '15', '30'] as $interval): ?>
                    <option value="<?php p($interval); ?>" <?php echo $settings['shift_rounding'] === $interval ? 'selected' : ''; ?>><?php p($l->t('%s minutes', [$interval])); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="timesheets-admin-row">
            <div class="timesheets-setting-copy">
                <label for="job_rounding"><?php p($l->t('Job rounding interval')); ?></label>
                <p id="job_rounding_help"><?php p($l->t('The interval intended for rounding individual job entries. Choose 5, 10, 15, or 30 minutes independently of the shift interval.')); ?></p>
            </div>
            <select name="job_rounding" id="job_rounding" aria-describedby="job_rounding_help">
                <?php foreach (['5', '10', '15', '30'] as $interval): ?>
                    <option value="<?php p($interval); ?>" <?php echo $settings['job_rounding'] === $interval ? 'selected' : ''; ?>><?php p($l->t('%s minutes', [$interval])); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        </fieldset>

        <fieldset>
            <legend><?php p($l->t('Shift review')); ?></legend>
        <div class="timesheets-admin-row">
            <div class="timesheets-setting-copy">
                <label for="net_shift_threshold"><?php p($l->t('Net shift threshold (minutes)')); ?></label>
                <p id="net_shift_threshold_help"><?php p($l->t('The net work duration intended to trigger a confirmation after clock-out, excluding breaks. Enter minutes: for example, 480 is 8 hours. Set 0 for no threshold.')); ?></p>
            </div>
            <input type="number" name="net_shift_threshold" id="net_shift_threshold" min="0" step="1" aria-describedby="net_shift_threshold_help" value="<?php p((string) $settings['net_shift_threshold']); ?>">
        </div>
        </fieldset>

        <div class="timesheets-admin-actions">
            <button type="submit" class="primary"><?php p($l->t('Save settings')); ?></button>
            <p id="timesheets-status" role="status" aria-live="polite"></p>
        </div>
    </form>

    <h2><?php p($l->t('User and group access')); ?></h2>
    <p class="timesheets-intro"><?php p($l->t('By default, everyone with Nextcloud app access can use enabled tracking features. Restricted mode limits new activity to assigned users and groups. It does not override Nextcloud app-level restrictions.')); ?></p>
    <p class="timesheets-intro"><?php p($l->t('Assignments combine: a user receives each permission granted directly or by any group. An assignment without tracking permissions grants dashboard access only. Global tracking switches above still apply. Job permissions are saved for future job tracking; job recording is not available yet.')); ?></p>
    <p class="timesheets-intro"><?php p($l->t('After access is removed, users can still view their own saved history and finish an open shift, but cannot start new shifts. Nextcloud administrators always manage these settings; assignments do not grant administration rights.')); ?></p>
    <form id="timesheets-access-form" method="post" action="<?php p($_['accessSaveUrl']); ?>" data-search-url="<?php p($_['accessSearchUrl']); ?>">
        <div class="timesheets-admin-row">
            <div class="timesheets-setting-copy">
                <label for="timesheets-restricted"><?php p($l->t('Restrict access to assigned users and groups')); ?></label>
                <p id="timesheets-restricted-help"><?php p($l->t('Off preserves existing access. On applies the assignments below. You can prepare assignments before enabling this mode. Save access settings separately from tracking preferences.')); ?></p>
            </div>
            <input id="timesheets-restricted" type="checkbox" aria-describedby="timesheets-restricted-help" <?php echo $_['accessPolicy']['restricted'] ? 'checked' : ''; ?>>
        </div>
        <fieldset>
            <legend><?php p($l->t('Find a user or group')); ?></legend>
            <div class="timesheets-access-search">
                <label for="timesheets-principal-type"><?php p($l->t('Assignment type')); ?></label>
                <select id="timesheets-principal-type">
                    <option value="user"><?php p($l->t('User')); ?></option>
                    <option value="group"><?php p($l->t('Group')); ?></option>
                </select>
                <label for="timesheets-principal-query"><?php p($l->t('Name or ID')); ?></label>
                <input id="timesheets-principal-query" type="text" minlength="2" maxlength="255" aria-describedby="timesheets-search-help">
                <button type="button" id="timesheets-access-search"><?php p($l->t('Search')); ?></button>
                <p id="timesheets-search-help"><?php p($l->t('Enter at least 2 characters. Up to 20 results are shown; narrow your search if needed.')); ?></p>
                <label for="timesheets-principal-results"><?php p($l->t('Search results')); ?></label>
                <select id="timesheets-principal-results"></select>
                <button type="button" id="timesheets-access-add"><?php p($l->t('Add assignment')); ?></button>
            </div>
            <p id="timesheets-search-status" role="status" aria-live="polite"></p>
        </fieldset>
        <div class="timesheets-access-table">
            <table>
                <caption><?php p($l->t('Assignments (applied only in restricted mode)')); ?></caption>
                <thead><tr>
                    <th scope="col"><?php p($l->t('Type and ID')); ?></th>
                    <th scope="col"><?php p($l->t('Shift tracking')); ?></th>
                    <th scope="col"><?php p($l->t('Job tracking (planned)')); ?></th>
                    <th scope="col"><?php p($l->t('Actions')); ?></th>
                </tr></thead>
                <tbody id="timesheets-access-grants">
                <?php foreach ($_['accessPolicy']['grants'] as $grant): ?>
                    <tr data-type="<?php p($grant['type']); ?>" data-id="<?php p($grant['id']); ?>">
                        <th scope="row"><?php p($l->t($grant['type'] === 'user' ? 'User' : 'Group') . ': ' . $grant['id']); ?></th>
                        <td><input type="checkbox" data-permission="shift" aria-label="<?php p($l->t('Shift tracking for %s', [$grant['id']])); ?>" <?php echo $grant['shift'] ? 'checked' : ''; ?>></td>
                        <td><input type="checkbox" data-permission="job" aria-label="<?php p($l->t('Job tracking for %s', [$grant['id']])); ?>" <?php echo $grant['job'] ? 'checked' : ''; ?>></td>
                        <td><button type="button" data-remove aria-label="<?php p($l->t('Remove assignment for %s', [$grant['id']])); ?>"><?php p($l->t('Remove')); ?></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="timesheets-intro"><?php p($l->t('Adding, removing, or changing assignments takes effect only after you save access settings. An empty list in restricted mode prevents all new tracking activity.')); ?></p>
        <div class="timesheets-admin-actions">
            <button type="submit" class="primary"><?php p($l->t('Save access settings')); ?></button>
            <p id="timesheets-access-status" role="status" aria-live="polite"></p>
        </div>
    </form>
</div>
