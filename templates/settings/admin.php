<?php

declare(strict_types=1);

$settings = $_['settings'];
?>
<div id="timesheets-admin" class="section">
    <h2>Timesheets administration</h2>

    <form id="timesheets-admin-form" method="post">
        <div class="timesheets-admin-row">
            <label>
                <input type="checkbox" name="shift_tracking" value="1" <?php echo !empty($settings['shift_tracking']) ? 'checked' : ''; ?>>
                Enable shift tracking
            </label>
        </div>

        <div class="timesheets-admin-row">
            <label>
                <input type="checkbox" name="job_tracking" value="1" <?php echo !empty($settings['job_tracking']) ? 'checked' : ''; ?>>
                Enable job tracking
            </label>
        </div>

        <div class="timesheets-admin-row">
            <label for="shift_rounding">Shift rounding</label>
            <select name="shift_rounding" id="shift_rounding">
                <option value="5" <?php echo $settings['shift_rounding'] === '5' ? 'selected' : ''; ?>>5 minutes</option>
                <option value="10" <?php echo $settings['shift_rounding'] === '10' ? 'selected' : ''; ?>>10 minutes</option>
                <option value="15" <?php echo $settings['shift_rounding'] === '15' ? 'selected' : ''; ?>>15 minutes</option>
                <option value="30" <?php echo $settings['shift_rounding'] === '30' ? 'selected' : ''; ?>>30 minutes</option>
            </select>
        </div>

        <div class="timesheets-admin-row">
            <label for="job_rounding">Job rounding</label>
            <select name="job_rounding" id="job_rounding">
                <option value="5" <?php echo $settings['job_rounding'] === '5' ? 'selected' : ''; ?>>5 minutes</option>
                <option value="10" <?php echo $settings['job_rounding'] === '10' ? 'selected' : ''; ?>>10 minutes</option>
                <option value="15" <?php echo $settings['job_rounding'] === '15' ? 'selected' : ''; ?>>15 minutes</option>
                <option value="30" <?php echo $settings['job_rounding'] === '30' ? 'selected' : ''; ?>>30 minutes</option>
            </select>
        </div>

        <div class="timesheets-admin-row">
            <label for="net_shift_threshold">Net shift threshold (minutes)</label>
            <input type="number" name="net_shift_threshold" id="net_shift_threshold" min="0" value="<?php p((string) $settings['net_shift_threshold']); ?>">
        </div>

        <div class="timesheets-admin-row">
            <button type="submit" class="primary">Save settings</button>
        </div>

        <p id="timesheets-status" aria-live="polite"></p>
    </form>
</div>

<script>
(function() {
    const form = document.getElementById('timesheets-admin-form');
    const status = document.getElementById('timesheets-status');

    if (!form || !status) {
        return;
    }

    form.addEventListener('submit', async function(event) {
        event.preventDefault();

        const payload = new URLSearchParams(new FormData(form));
        const response = await fetch('<?php echo \OCP\Util::linkToRoute('timesheets.admin.save'); ?>', {
            method: 'POST',
            headers: {
                'requesttoken': OC.requestToken,
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            body: payload.toString(),
        });

        const data = await response.json();

        if (data && data.status === 'ok') {
            status.textContent = 'Settings saved successfully.';
            status.style.color = 'var(--color-success)';
            return;
        }

        status.textContent = 'Unable to save settings.';
        status.style.color = 'var(--color-error)';
    });
})();
</script>
