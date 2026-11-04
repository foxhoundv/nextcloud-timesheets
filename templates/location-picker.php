<?php

declare(strict_types=1);
?>
<div data-work-location data-options="<?php p(json_encode($locationOptions, JSON_THROW_ON_ERROR)); ?>">
    <?php if ($locationOptions['multipleCountries']): ?>
        <label for="timesheets-<?php p($locationScope); ?>-country"><?php p($l->t('Work country')); ?></label>
        <select id="timesheets-<?php p($locationScope); ?>-country" name="country" required>
            <option value=""><?php p($l->t('Choose a country')); ?></option>
            <?php foreach ($locationOptions['countries'] as $code => $country): ?>
                <option value="<?php p($code); ?>" <?php echo $locationOptions['defaultCountry'] === $code ? 'selected' : ''; ?>><?php p($country['name']); ?></option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <input type="hidden" name="country" value="<?php p(array_key_first($locationOptions['countries']) ?? ''); ?>">
        <p><?php p($l->t('Work country: %s', [$locationOptions['countries'][$locationOptions['defaultCountry']]['name'] ?? ''])); ?></p>
    <?php endif; ?>
    <?php if ($locationOptions['multipleRegions']): ?>
        <label for="timesheets-<?php p($locationScope); ?>-region"><?php p($l->t('State / province / region')); ?></label>
        <select id="timesheets-<?php p($locationScope); ?>-region" name="region" required></select>
    <?php endif; ?>
</div>
