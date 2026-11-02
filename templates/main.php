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
        <h2 id="timesheets-availability"><?php p($l->t('Time recording is coming soon')); ?></h2>
        <p><?php p($l->t('You can review saved shifts here. Clock-in/out and job tracking are not available yet; no live timer is running on this page.')); ?></p>
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
        <p class="timesheets-intro"><?php p($l->t('Your saved shifts, newest first. Durations are shown in hours and minutes, not decimal hours.')); ?></p>
        <?php if ($entries === []): ?>
            <div class="timesheets-empty">
                <h3><?php p($l->t('No shifts recorded yet')); ?></h3>
                <p><?php p($l->t('There is no saved time to display. Your recorded shifts will appear here when time recording is available.')); ?></p>
            </div>
        <?php else: ?>
            <div class="timesheets-table-wrap" role="region" aria-label="<?php p($l->t('Shift history table')); ?>" tabindex="0">
            <table>
                <caption><?php p($l->t('Saved shift dates, durations, and current status')); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php p($l->t('Shift date')); ?></th>
                        <th scope="col"><?php p($l->t('Recorded duration')); ?></th>
                        <th scope="col"><?php p($l->t('Status')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <td><?php p($entry['date']); ?></td>
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