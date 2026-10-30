<?php

declare(strict_types=1);

script('timesheets', 'main');
style('timesheets', 'main');

$stats = $_['stats'];
$entries = $_['entries'];
?>
<div id="timesheets" class="section">
    <div class="timesheets-header">
        <div>
            <h1><?php p($l->t('Timesheets dashboard')); ?></h1>
            <p><?php p($l->t('Track shifts, job time, and shop time in one place.')); ?></p>
        </div>
        <button type="button" class="primary">
            <?php p($l->t('Clock in')); ?>
        </button>
    </div>

    <div class="timesheets-stats">
        <?php foreach ($stats as $stat): ?>
            <div class="timesheets-stat-card">
                <div class="timesheets-stat-label"><?php p($stat['label']); ?></div>
                <div class="timesheets-stat-value"><?php p($stat['value']); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="timesheets-panels">
        <div class="timesheets-panel">
            <h2><?php p($l->t('Recent entries')); ?></h2>
            <table>
                <thead>
                    <tr>
                        <th><?php p($l->t('Date')); ?></th>
                        <th><?php p($l->t('Shift')); ?></th>
                        <th><?php p($l->t('Total')); ?></th>
                        <th><?php p($l->t('Status')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <td><?php p($entry['date']); ?></td>
                            <td><?php p($entry['shift']); ?></td>
                            <td><?php p($entry['total']); ?></td>
                            <td>
                                <span class="timesheets-status <?php echo $entry['status'] === 'In progress' ? 'open' : 'closed'; ?>">
                                    <?php p($entry['status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="timesheets-panel">
            <h2><?php p($l->t('Quick summary')); ?></h2>
            <ul class="timesheets-summary-list">
                <li><?php p($l->t('Shift tracking enabled')); ?></li>
                <li><?php p($l->t('Job tracking enabled')); ?></li>
                <li><?php p($l->t('Rounding set to 15 minutes')); ?></li>
                <li><?php p($l->t('Next review: weekly closeout')); ?></li>
            </ul>
        </div>
    </div>
</div>