<?php

declare(strict_types=1);

script('timesheets', 'admin');
script('timesheets', 'access');
style('timesheets', 'main');

$settings = $_['settings'];
$assigned = ['group' => [], 'user' => []];
$names = ['group' => [], 'user' => []];
foreach ($_['accessPolicy']['grants'] as $grant) {
    $assigned[$grant['type']][$grant['id']] = true;
}
foreach ($_['accessDirectory'] as $type => $entries) {
    foreach ($entries as $entry) {
        $names[$type][$entry['id']] = $entry['name'];
    }
}
?>
<div id="timesheets-admin" class="section">
    <h2><?php p($l->t('Timesheet Administration')); ?></h2>
    <p class="timesheets-intro"><?php p($l->t('Configure tracking preferences and time-recording rules for your organization.')); ?></p>
    <p><?php p($l->t('Saved settings, shift/job history, and active intervals are retained when the app is updated. After an update, reload the dashboard to see continuing timers; elapsed time includes the update period.')); ?></p>
    <p class="timesheets-notice"><?php p($l->t('Shift tracking and job tracking can each be used independently. Enable both to configure their interaction below. Users take unpaid breaks by clocking out and starting a new shift when they return; time between shifts is not counted. There is no separate break switch or automatic deduction. End active jobs before a break. Rounding and shift confirmations are still in development.')); ?></p>

    <form id="timesheets-admin-form" method="post" action="<?php p($_['saveUrl']); ?>">
        <fieldset>
            <legend><?php p($l->t('Tracking preferences')); ?></legend>
        <div class="timesheets-admin-row">
            <div class="timesheets-setting-copy">
                <label for="shift_tracking"><?php p($l->t('Enable shift tracking')); ?></label>
                <p id="shift_tracking_help"><?php p($l->t('Allow users with app access and shift permission to clock in and out. Restricted mode below controls individual and group permissions. Disabling this prevents new shifts; users can still clock out of a shift that is already open. Time is saved to the second; totals display completed whole minutes without rounding.')); ?></p>
            </div>
            <input type="checkbox" id="shift_tracking" name="shift_tracking" value="1" aria-describedby="shift_tracking_help" <?php echo !empty($settings['shift_tracking']) ? 'checked' : ''; ?>>
        </div>

        <div class="timesheets-admin-row">
            <div class="timesheets-setting-copy">
                <label for="job_tracking"><?php p($l->t('Enable job tracking')); ?></label>
                <p id="job_tracking_help"><?php p($l->t('Allow permitted users to start and end one job at a time using a Sales Order or job reference. Can be used without shift tracking. Completed shifts show job time and remaining shop time. References are not linked to folders yet. Disabling this prevents new jobs but lets users finish an active job.')); ?></p>
            </div>
            <input type="checkbox" id="job_tracking" name="job_tracking" value="1" aria-describedby="job_tracking_help" <?php echo !empty($settings['job_tracking']) ? 'checked' : ''; ?>>
        </div>
        </fieldset>

        <fieldset id="timesheets-combined-settings" <?php echo !$settings['shift_tracking'] || !$settings['job_tracking'] ? 'hidden' : ''; ?>>
            <legend><?php p($l->t('Combined shift and job tracking')); ?></legend>
            <div class="timesheets-admin-row">
                <div class="timesheets-setting-copy">
                    <label for="job_require_shift"><?php p($l->t('Require a shift before starting a job')); ?></label>
                    <p id="job_require_shift_help"><?php p($l->t('Applies only when both global tracking modes and both permissions are enabled for the user. Off allows independent job clock-in. Job-only users can always work without a shift.')); ?></p>
                </div>
                <select id="job_require_shift" name="job_require_shift" aria-describedby="job_require_shift_help">
                    <option value="0" <?php echo !$_['jobRequireShift'] ? 'selected' : ''; ?>><?php p($l->t('No (default)')); ?></option>
                    <option value="1" <?php echo $_['jobRequireShift'] ? 'selected' : ''; ?>><?php p($l->t('Yes')); ?></option>
                </select>
            </div>
            <div class="timesheets-admin-row">
                <div class="timesheets-setting-copy">
                    <label for="shift_end_job_policy"><?php p($l->t('Shift clock-out with an active job')); ?></label>
                    <p id="shift_end_job_policy_help"><?php p($l->t('Block requires the user to end the job first. Allow ends only the shift; the job keeps counting until explicitly ended, including breaks and time outside a shift. This protects existing intervals even if tracking or permissions are later disabled.')); ?></p>
                </div>
                <select id="shift_end_job_policy" name="shift_end_job_policy" aria-describedby="shift_end_job_policy_help">
                    <option value="block" <?php echo $_['shiftEndJobPolicy'] === 'block' ? 'selected' : ''; ?>><?php p($l->t('Block until job ends (default)')); ?></option>
                    <option value="allow" <?php echo $_['shiftEndJobPolicy'] === 'allow' ? 'selected' : ''; ?>><?php p($l->t('Allow; leave job running')); ?></option>
                </select>
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
    <p class="timesheets-intro"><?php p($l->t('Assignments combine: a user receives each permission granted directly or by any group. An assignment without tracking permissions grants dashboard access only. Global tracking switches above still apply. Shift and job permissions can be granted independently.')); ?></p>
    <p class="timesheets-intro"><?php p($l->t('After access is removed, users can still view their own saved shift/job history and finish active intervals, but cannot start new shifts or jobs. Nextcloud administrators always manage these settings; assignments do not grant administration rights.')); ?></p>
    <form id="timesheets-access-form" method="post" action="<?php p($_['accessSaveUrl']); ?>">
        <div class="timesheets-admin-row">
            <div class="timesheets-setting-copy">
                <label for="timesheets-restricted"><?php p($l->t('Restrict access to assigned users and groups')); ?></label>
                <p id="timesheets-restricted-help"><?php p($l->t('Off preserves existing access. On applies the assignments below. You can prepare assignments before enabling this mode. Save access settings separately from tracking preferences.')); ?></p>
            </div>
            <input id="timesheets-restricted" type="checkbox" aria-describedby="timesheets-restricted-help" <?php echo $_['accessPolicy']['restricted'] ? 'checked' : ''; ?>>
        </div>
        <fieldset>
            <legend><?php p($l->t('Select groups or users')); ?></legend>
            <p id="timesheets-selection-help"><?php p($l->t('Lists refresh when this page loads. Users are sorted by account name, not display name; groups by group name. Only unassigned entries are shown. Select an entry, then choose Add.')); ?></p>
            <div class="timesheets-access-picker">
                <div role="tablist" aria-label="<?php p($l->t('Groups and users')); ?>">
                <?php foreach (['group' => 'Groups', 'user' => 'Users'] as $type => $label): ?>
                    <button type="button" role="tab" id="timesheets-tab-<?php p($type); ?>" aria-controls="timesheets-panel-<?php p($type); ?>" aria-selected="<?php p($type === 'group' ? 'true' : 'false'); ?>" tabindex="<?php p($type === 'group' ? '0' : '-1'); ?>" data-type="<?php p($type); ?>"><?php p($l->t($label)); ?></button>
                <?php endforeach; ?>
                </div>
                <?php foreach (['group' => 'Groups', 'user' => 'Users'] as $type => $label): ?>
                    <div role="tabpanel" id="timesheets-panel-<?php p($type); ?>" aria-labelledby="timesheets-tab-<?php p($type); ?>" <?php echo $type === 'user' ? 'hidden' : ''; ?>>
                        <select size="8" data-directory="<?php p($type); ?>" aria-label="<?php p($l->t($label)); ?>" aria-describedby="timesheets-selection-help">
                        <?php foreach ($_['accessDirectory'][$type] as $entry): ?>
                            <option value="<?php p($entry['id']); ?>" data-name="<?php p($entry['name']); ?>" <?php echo isset($assigned[$type][$entry['id']]) ? 'hidden disabled' : ''; ?>><?php p($entry['name']); ?></option>
                        <?php endforeach; ?>
                        </select>
                        <p data-empty="<?php p($type); ?>" hidden><?php p($l->t('No unassigned entries available.')); ?></p>
                    </div>
                <?php endforeach; ?>
                <button type="button" id="timesheets-access-add"><?php p($l->t('Add')); ?></button>
            </div>
            <p id="timesheets-selection-status" role="status" aria-live="polite"></p>
        </fieldset>
        <h3 id="timesheets-access-heading"><?php p($l->t('Groups/Users With Access')); ?></h3>
        <p><?php p($l->t('Assignments apply only in restricted mode. Use the x button to remove an entry and return it to the selection list.')); ?></p>
        <ul id="timesheets-access-grants" aria-labelledby="timesheets-access-heading">
                <?php foreach ($_['accessPolicy']['grants'] as $grant): ?>
                    <?php
                    $name = $names[$grant['type']][$grant['id']] ?? $grant['id'];
                    ?>
                    <li class="timesheets-access-chip" data-type="<?php p($grant['type']); ?>" data-id="<?php p($grant['id']); ?>">
                        <button type="button" data-remove aria-label="<?php p($l->t('Remove assignment for %s', [$name])); ?>"><span aria-hidden="true">&times;</span></button>
                        <span><?php p($l->t($grant['type'] === 'user' ? 'User' : 'Group') . ': ' . $name); ?></span>
                        <label><input type="checkbox" data-permission="shift" <?php echo $grant['shift'] ? 'checked' : ''; ?>><?php p($l->t('Shift tracking')); ?></label>
                        <label><input type="checkbox" data-permission="job" <?php echo $grant['job'] ? 'checked' : ''; ?>>                        <?php p($l->t('Job tracking')); ?></label>
                    </li>
                <?php endforeach; ?>
        </ul>
        <p id="timesheets-grants-empty" <?php echo $_['accessPolicy']['grants'] !== [] ? 'hidden' : ''; ?>><?php p($l->t('No groups or users assigned.')); ?></p>
        <p class="timesheets-intro"><?php p($l->t('Adding, removing, or changing assignments takes effect only after you save access settings. An empty list in restricted mode prevents all new tracking activity.')); ?></p>
        <div class="timesheets-admin-actions">
            <button type="submit" class="primary"><?php p($l->t('Save access settings')); ?></button>
            <p id="timesheets-access-status" role="status" aria-live="polite"></p>
        </div>
    </form>
</div>
