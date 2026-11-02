<?php

declare(strict_types=1);

script('timesheets', 'main');
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
    </header>

    <section class="timesheets-notice" aria-labelledby="timesheets-availability">
        <h2 id="timesheets-availability"><?php p($l->t('Your current shift')); ?></h2>
        <?php if ($_['activeShift'] !== null): ?>
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
                <?php endif; ?>
                <button type="submit" class="primary"><?php p($l->t($_['activeShift'] === null ? 'Clock in' : 'Clock out')); ?></button>
                <p id="timesheets-clock-status" role="status" aria-live="polite"></p>
            </form>
            <noscript><p><?php p($l->t('Enable JavaScript to clock in and out.')); ?></p></noscript>
        <?php endif; ?>
        <p class="timesheets-clock-help"><?php p($l->t('Taking a break? Clock out when your break starts, then clock in again when you return. Each work period is a separate shift; time between shifts is excluded from recorded work time.')); ?></p>
        <p class="timesheets-clock-help"><?php p($l->t('There is no paused shift or automatic break deduction. Time while clocked in counts as work. Starting again requires shift tracking and your shift permission to remain enabled.')); ?></p>
        <p class="timesheets-clock-help"><?php p($l->t('Actual elapsed time is saved using the server clock. Rounding, job tracking, and long-shift confirmations are not available yet.')); ?></p>
    </section>

    <section aria-labelledby="timesheets-overview">
        <h2 id="timesheets-overview"><?php p($l->t('Your overview')); ?></h2>
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
                        <th scope="col"><?php p($l->t('Status')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <td><?php p($entry['date']); ?></td>
                            <td><?php p($entry['clockIn'] ?? $l->t('Not recorded')); ?></td>
                            <td><?php p($entry['clockOut'] ?? $l->t('Not recorded')); ?></td>
                            <td class="timesheets-duration"><?php p($entry['total'] ?? $l->t('Not finalized')); ?></td>
                            <td>
                                <span class="timesheets-status <?php echo $entry['isOpen'] ? 'open' : 'closed'; ?>">
                                    <?php p($l->t($entry['status'])); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</div>