<?php

declare(strict_types=1);
?>
<section id="timesheets-record-reset"
    data-status-url="<?php p($_['resetStatusUrl']); ?>"
    data-approve-url="<?php p($_['resetApproveUrl']); ?>"
    data-cancel-url="<?php p($_['resetCancelUrl']); ?>"
    aria-labelledby="timesheets-reset-heading">
    <h3 id="timesheets-reset-heading"><?php p($l->t('Delete testing records')); ?></h3>
    <p><?php p($l->t('Permanently delete all Timesheets shift and Job logs from the database, including active intervals. Everyone will be clocked out. Settings, access assignments, and existing Excel files are not deleted or modified. This cannot be undone in the app.')); ?></p>
    <p><?php p($l->t('Every enabled Nextcloud administrator and delegated Timesheets administrator, including administrator group members, must confirm individually. Requests expire after 24 hours. New or changed records, or changes to the administrator list, reset approvals. Any administrator can cancel a pending request.')); ?></p>
    <p data-reset-summary></p>
    <ul data-reset-administrators aria-label="<?php p($l->t('Administrator deletion approvals')); ?>"></ul>
    <div class="timesheets-admin-actions">
        <button type="button" data-reset-open><?php p($l->t('Delete all records')); ?></button>
        <button type="button" data-reset-refresh><?php p($l->t('Refresh approval status')); ?></button>
        <button type="button" data-reset-cancel disabled><?php p($l->t('Cancel deletion request')); ?></button>
    </div>
    <p data-reset-status role="status" aria-live="polite"></p>
    <dialog id="timesheets-reset-dialog" aria-labelledby="timesheets-reset-dialog-title" aria-describedby="timesheets-reset-warning">
        <h2 id="timesheets-reset-dialog-title"><?php p($l->t('Delete all records?')); ?></h2>
        <p id="timesheets-reset-warning"><?php p($l->t('Are you sure you want to permanently delete all Timesheets records from the database? Active shift and Job timers will also be removed. Existing Excel files and administration settings will remain untouched.')); ?></p>
        <p data-reset-dialog-summary></p>
        <form id="timesheets-reset-confirm-form">
            <label for="timesheets-reset-confirmation"><?php p($l->t('Type DELETE to give your approval')); ?></label>
            <input id="timesheets-reset-confirmation" name="confirmation" type="text" required autocomplete="off" spellcheck="false">
            <div class="timesheets-admin-actions">
                <button type="submit" data-reset-confirm disabled><?php p($l->t('Yes - Delete')); ?></button>
                <button type="button" data-reset-close><?php p($l->t('No - Go back')); ?></button>
            </div>
            <p data-reset-dialog-status role="status" aria-live="polite"></p>
        </form>
    </dialog>
</section>
