<?php

declare(strict_types=1);

script('timesheets', 'admin');
style('timesheets', 'main');

$settings = $_['settings'];
?>
<div id="timesheets-admin" class="section">
    <h2><?php p($l->t('Timesheet Administration')); ?></h2>
    <p class="timesheets-intro"><?php p($l->t('Configure tracking preferences and time-recording rules for your organization.')); ?></p>
    <p class="timesheets-notice"><?php p($l->t('These preferences can be saved now. Clock-in/out, job tracking, rounding, and shift confirmations are still in development and are not yet applied to recorded time.')); ?></p>

    <form id="timesheets-admin-form" method="post" action="<?php p($_['saveUrl']); ?>">
        <fieldset>
            <legend><?php p($l->t('Tracking preferences')); ?></legend>
        <div class="timesheets-admin-row">
            <div class="timesheets-setting-copy">
                <label for="shift_tracking"><?php p($l->t('Enable shift tracking')); ?></label>
                <p id="shift_tracking_help"><?php p($l->t('Record when employees start and finish a work shift. Shift time will provide the basis for attendance and total work hours.')); ?></p>
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
</div>
