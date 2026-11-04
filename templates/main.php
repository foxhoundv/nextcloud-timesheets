<?php

declare(strict_types=1);

script('timesheets', 'main');
script('timesheets', 'locations');
style('timesheets', 'main');

$stats = $_['stats'];
$entries = $_['entries'];
?>
<div id="app-content">
<main id="timesheets" aria-labelledby="timesheets-title">
    <header class="timesheets-header">
        <div>
            <h1 id="timesheets-title"><?php p($l->t('Timesheets dashboard')); ?></h1>
            <p><?php p($l->t('Your work time at a glance. Review your saved shifts and see what is still open.')); ?></p>
        </div>
        <a class="timesheets-text-link" href="#timesheets-history"><?php p($l->t('View shift history')); ?></a>
        <?php if ($_['canManageSettings']): ?>
            <a class="timesheets-text-link" href="<?php p($_['adminSettingsUrl']); ?>"><?php p($l->t('Timesheet Administration')); ?></a>
        <?php endif; ?>
    </header>

    <section id="timesheets-long-shift-confirmation" class="timesheets-notice" role="status" aria-live="polite" tabindex="-1" hidden>
        <h2><?php p($l->t('Shift length reminder')); ?></h2>
        <p><?php p($l->t('Your completed shift met or exceeded the configured net shift threshold. The saved actual and reported time are unchanged.')); ?></p>
        <button type="button" class="secondary" data-dismiss-long-shift><?php p($l->t('Dismiss')); ?></button>
    </section>

    <section class="timesheets-notice" aria-labelledby="timesheets-availability">
        <h2 id="timesheets-availability"><?php p($l->t('Your current shift')); ?></h2>
        <?php if ($_['activeShift'] !== null): ?>
            <p><?php p($l->t('Work location: %s', [$_['activeShift']['location']])); ?></p>
            <p><?php p($l->t('You are clocked in. Started %s.', [$_['activeShift']['startedAt']])); ?></p>
            <p class="timesheets-elapsed"><?php p($l->t('Elapsed time:')); ?>
                <span id="timesheets-elapsed" data-seconds="<?php p((string) $_['activeShift']['elapsedSeconds']); ?>"><?php p(sprintf('%dh %02dm %02ds', intdiv($_['activeShift']['elapsedSeconds'], 3600), intdiv($_['activeShift']['elapsedSeconds'] % 3600, 60), $_['activeShift']['elapsedSeconds'] % 60)); ?></span>
            </p>
            <?php if (!$_['trackingEnabled']): ?>
                <p><?php p($l->t('Shift tracking is now disabled, but you can still finish this open shift.')); ?></p>
            <?php endif; ?>
        <?php elseif (!$_['canStartShift']): ?>
            <p><?php p($l->t('You do not have permission to start shifts. You can still view your history. Ask an administrator for access.')); ?></p>
        <?php elseif ($_['trackingEnabled']): ?>
            <p><?php p($l->t('You are clocked out. Start a shift when you begin work or return from a break.')); ?></p>
        <?php else: ?>
            <p><?php p($l->t('Shift tracking is disabled. Ask an administrator to enable it before starting a shift.')); ?></p>
        <?php endif; ?>
        <?php if (($_['trackingEnabled'] && $_['canStartShift']) || $_['activeShift'] !== null): ?>
            <form id="timesheets-clock-form" method="post" action="<?php p($_['activeShift'] === null ? $_['clockInUrl'] : $_['clockOutUrl']); ?>">
                <?php if ($_['activeShift'] !== null): ?>
                    <input type="hidden" name="shiftId" value="<?php p((string) $_['activeShift']['id']); ?>">
                <?php elseif ($_['locationSettings']['shiftEnabled']): ?>
                    <?php $locationOptions = $_['shiftLocationOptions']; $locationScope = 'shift'; include __DIR__ . '/location-picker.php'; ?>
                <?php endif; ?>
                <button type="submit" class="primary"><?php p($l->t($_['activeShift'] === null ? 'Clock in' : 'Clock out')); ?></button>
                <p id="timesheets-clock-status" role="status" aria-live="polite"></p>
            </form>
            <noscript><p><?php p($l->t('Enable JavaScript to clock in and out.')); ?></p></noscript>
        <?php endif; ?>
        <p class="timesheets-clock-help"><?php p($l->t('Taking a break? Clock out when your break starts, then clock in again when you return. Each work period is a separate shift; time between shifts is excluded from recorded work time.')); ?></p>
        <p class="timesheets-clock-help"><?php p($l->t('There is no paused shift or automatic break deduction. Time while clocked in counts as work. Starting again requires shift tracking and your shift permission to remain enabled.')); ?></p>
        <p class="timesheets-clock-help"><?php p($l->t('Actual elapsed time is saved using the server clock. Optional rounding is saved separately using the settings at clock-out. End active jobs before taking an unpaid break; a job left running continues recording time even outside a shift.')); ?></p>
    </section>

    <section class="timesheets-notice" aria-labelledby="timesheets-job-title">
        <h2 id="timesheets-job-title"><?php p($l->t('Your current job')); ?></h2>
        <?php if ($_['activeJob'] !== null): ?>
            <p><?php p($l->t('Work location: %s', [$_['activeJob']['location']])); ?></p>
            <p><?php p($l->t('Active job: %s', [$_['activeJob']['reference']])); ?></p>
            <p><?php p($l->t('Started %s.', [$_['activeJob']['startedAt']])); ?></p>
            <p class="timesheets-elapsed"><?php p($l->t('Job elapsed time:')); ?>
                <span id="timesheets-job-elapsed" data-seconds="<?php p((string) $_['activeJob']['elapsedSeconds']); ?>"><?php p(sprintf('%dh %02dm %02ds', intdiv($_['activeJob']['elapsedSeconds'], 3600), intdiv($_['activeJob']['elapsedSeconds'] % 3600, 60), $_['activeJob']['elapsedSeconds'] % 60)); ?></span>
            </p>
            <?php if (!$_['jobTrackingEnabled'] || !$_['canStartJob']): ?>
                <p><?php p($l->t('New job tracking is unavailable, but you can still end this active job and view its history.')); ?></p>
            <?php endif; ?>
        <?php elseif (!$_['jobTrackingEnabled']): ?>
            <p><?php p($l->t('Job tracking is disabled. Ask an administrator to enable it.')); ?></p>
        <?php elseif (!$_['canStartJob']): ?>
            <p><?php p($l->t('You do not have permission to start jobs. You can still view your job history.')); ?></p>
        <?php elseif ($_['jobRequiresShift'] && $_['activeShift'] === null): ?>
            <p><?php p($l->t('Clock into a shift before starting a job.')); ?></p>
        <?php else: ?>
            <p><?php p($l->t('No job is active. Enter a Job ID to begin.')); ?></p>
        <?php endif; ?>
        <?php if ($_['activeJob'] !== null || ($_['jobTrackingEnabled'] && $_['canStartJob'] && (!$_['jobRequiresShift'] || $_['activeShift'] !== null))): ?>
            <form id="timesheets-job-form" method="post" action="<?php p($_['activeJob'] === null ? $_['jobClockInUrl'] : $_['jobClockOutUrl']); ?>">
                <?php if ($_['activeJob'] !== null): ?>
                    <input type="hidden" name="jobId" value="<?php p((string) $_['activeJob']['id']); ?>">
                <?php else: ?>
                    <label for="timesheets-job-reference"><?php p($l->t('Job ID')); ?></label>
                    <input id="timesheets-job-reference" name="reference" type="text" required maxlength="255" aria-describedby="timesheets-job-help">
                    <?php if ($_['locationSettings']['jobEnabled']): ?>
                        <?php $locationOptions = $_['jobLocationOptions']; $locationScope = 'job'; include __DIR__ . '/location-picker.php'; ?>
                    <?php endif; ?>
                <?php endif; ?>
                <button type="submit" class="primary"><?php p($l->t($_['activeJob'] === null ? 'Start job' : 'End job')); ?></button>
                <p role="status" aria-live="polite"></p>
            </form>
            <noscript><p><?php p($l->t('Enable JavaScript to start and end jobs.')); ?></p></noscript>
        <?php endif; ?>
        <p id="timesheets-job-help" class="timesheets-clock-help"><?php p($l->t('One job can be active at a time. The Job folder must already exist; contact your administrator if it is missing. Starting and ending a Job automatically updates its shared workbook. Job intervals are recorded separately from shifts; do not add job time to shift time as extra work.')); ?></p>
    </section>

    <section aria-labelledby="timesheets-overview">
        <h2 id="timesheets-overview"><?php p($l->t('Your overview')); ?></h2>
        <p class="timesheets-intro"><?php p($l->t('Reported time uses the rounding rule saved when each interval ended: nearest interval, exact halfway up. Off uses actual time. Older records with no captured rule also use actual time and are labeled below. Settings changes never recalculate completed history. Totals sum seconds before displaying whole minutes. Actual job/shop calculations are not rounded.')); ?></p>
        <p class="timesheets-intro"><?php p($l->t('Job and shop totals include completed shifts only. Jobs outside shifts and breaks do not count toward these totals. An active job contributes only the part inside a completed shift. Calculations use exact seconds; each displayed duration shows whole minutes, so displayed parts can differ from the total by a minute. Reload to see newly saved intervals.')); ?></p>
        <?php if ($_['breakdown']['unavailableShifts'] > 0): ?>
            <p class="timesheets-intro"><?php p($l->t('%s completed shifts lack recorded start or end times. Their saved duration is retained in Recorded time, but their job/shop breakdown is unavailable and excluded from job/shop totals.', [(string) $_['breakdown']['unavailableShifts']])); ?></p>
        <?php endif; ?>
        <div class="timesheets-stats">
        <?php foreach ($stats as $stat): ?>
            <div class="timesheets-stat-card">
                <h3 class="timesheets-stat-label"><?php p($l->t($stat['label'])); ?></h3>
                <div class="timesheets-stat-value"><?php p($stat['value']); ?></div>
                <p><?php p($l->t($stat['description'])); ?></p>
            </div>
        <?php endforeach; ?>
        </div>
    </section>

    <section id="timesheets-history" class="timesheets-panel" aria-labelledby="timesheets-history-title" tabindex="-1">
        <h2 id="timesheets-history-title"><?php p($l->t('Shift history')); ?></h2>
        <p class="timesheets-intro"><?php p($l->t('Your saved shifts, newest first. Work periods before and after a break appear as separate rows. Durations are shown in hours and minutes, not decimal hours.')); ?></p>
        <?php if ($entries === []): ?>
            <div class="timesheets-empty">
                <h3><?php p($l->t('No shifts recorded yet')); ?></h3>
                <p><?php p($l->t('Your shifts will appear here after you clock in. If tracking is disabled, ask an administrator to enable it.')); ?></p>
            </div>
        <?php else: ?>
            <div class="timesheets-table-wrap" role="region" aria-label="<?php p($l->t('Shift history table')); ?>" tabindex="0">
            <table>
                <caption><?php p($l->t('Saved shift dates, durations, and current status')); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php p($l->t('Shift date')); ?></th>
                        <th scope="col"><?php p($l->t('Clock in')); ?></th>
                        <th scope="col"><?php p($l->t('Clock out')); ?></th>
                        <th scope="col"><?php p($l->t('Recorded duration')); ?></th>
                        <th scope="col"><?php p($l->t('Job time in shift')); ?></th>
                        <th scope="col"><?php p($l->t('Shop time')); ?></th>
                        <th scope="col"><?php p($l->t('Reported duration')); ?></th>
                        <th scope="col"><?php p($l->t('Saved rounding rule')); ?></th>
                        <th scope="col"><?php p($l->t('Status')); ?></th>
                        <th scope="col"><?php p($l->t('Work location')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <td><?php p($entry['date']); ?></td>
                            <td><?php p($entry['clockIn'] ?? $l->t('Not recorded')); ?></td>
                            <td><?php p($entry['clockOut'] ?? $l->t('Not recorded')); ?></td>
                            <td class="timesheets-duration"><?php p($entry['total'] ?? $l->t('Not finalized')); ?></td>
                            <td class="timesheets-duration"><?php p($entry['jobTime'] ?? $l->t($entry['isOpen'] ? 'Not finalized' : 'Unavailable')); ?></td>
                            <td class="timesheets-duration"><?php p($entry['shopTime'] ?? $l->t($entry['isOpen'] ? 'Not finalized' : 'Unavailable')); ?></td>
                            <td class="timesheets-duration"><?php p($entry['reported'] ?? $l->t('Not finalized')); ?></td>
                            <td><?php p($entry['isOpen'] ? $l->t('Set at clock-out') : ($entry['roundingInterval'] === null ? $l->t('Not captured (actual)') : ($entry['roundingInterval'] === 0 ? $l->t('Off (actual)') : $l->t('Nearest %s minutes; halfway up', [(string) $entry['roundingInterval']])))); ?></td>
                            <td>
                                <span class="timesheets-status <?php echo $entry['isOpen'] ? 'open' : 'closed'; ?>">
                                    <?php p($l->t($entry['status'])); ?>
                                </span>
                            </td>
                            <td><?php p($l->t($entry['location'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>
    <section id="timesheets-job-history" class="timesheets-panel" aria-labelledby="timesheets-job-history-title">
        <h2 id="timesheets-job-history-title"><?php p($l->t('Job history')); ?></h2>
        <?php if ($_['jobEntries'] === []): ?>
            <p><?php p($l->t('No job intervals recorded yet.')); ?></p>
        <?php else: ?>
            <div class="timesheets-table-wrap" role="region" aria-label="<?php p($l->t('Job history table')); ?>" tabindex="0">
            <table>
                <caption><?php p($l->t('Your job intervals, newest first. Actual and reported durations are separate and show whole minutes.')); ?></caption>
                <thead><tr>
                    <th scope="col"><?php p($l->t('Job reference')); ?></th>
                    <th scope="col"><?php p($l->t('Start date')); ?></th>
                    <th scope="col"><?php p($l->t('Started')); ?></th>
                    <th scope="col"><?php p($l->t('Ended')); ?></th>
                    <th scope="col"><?php p($l->t('Actual duration')); ?></th>
                    <th scope="col"><?php p($l->t('Reported duration')); ?></th>
                    <th scope="col"><?php p($l->t('Saved rounding rule')); ?></th>
                    <th scope="col"><?php p($l->t('Work location')); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($_['jobEntries'] as $entry): ?>
                    <tr>
                        <td><?php p($entry['reference']); ?></td>
                        <td><?php p($entry['date']); ?></td>
                        <td><?php p($entry['clockIn']); ?></td>
                        <td><?php p($entry['clockOut'] ?? $l->t('In progress')); ?></td>
                        <td><?php p($entry['total'] ?? $l->t('Not finalized')); ?></td>
                        <td><?php p($entry['reported'] ?? $l->t('Not finalized')); ?></td>
                        <td><?php p($entry['clockOut'] === null ? $l->t('Set at clock-out') : ($entry['roundingInterval'] === null ? $l->t('Not captured (actual)') : ($entry['roundingInterval'] === 0 ? $l->t('Off (actual)') : $l->t('Nearest %s minutes; halfway up', [(string) $entry['roundingInterval']])))); ?></td>
                        <td><?php p($l->t($entry['location'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</div>