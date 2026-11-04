<?php

declare(strict_types=1);

namespace OCA\Timesheets\Service;

use OCP\IConfig;
use Punic\Territory;

class LocationService {
    public function __construct(private IConfig $config) {
    }

    public function countries(): array {
        $countries = Territory::getCountries('en');
        asort($countries, SORT_NATURAL | SORT_FLAG_CASE);
        return $countries;
    }

    public function usStates(): array {
        return [
            'Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut',
            'Delaware', 'District of Columbia', 'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois',
            'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland',
            'Massachusetts', 'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana',
            'Nebraska', 'Nevada', 'New Hampshire', 'New Jersey', 'New Mexico', 'New York',
            'North Carolina', 'North Dakota', 'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania',
            'Rhode Island', 'South Carolina', 'South Dakota', 'Tennessee', 'Texas', 'Utah',
            'Vermont', 'Virginia', 'Washington', 'West Virginia', 'Wisconsin', 'Wyoming',
            'American Samoa', 'Guam', 'Northern Mariana Islands', 'Puerto Rico', 'U.S. Virgin Islands',
        ];
    }

    public function settings(): array {
        $json = $this->config->getAppValue('timesheets', 'work_locations', '');
        if ($json === '') {
            return [
                'shiftEnabled' => false, 'jobEnabled' => false, 'multipleCountries' => false,
                'multipleRegions' => false, 'workingCountry' => '', 'countries' => [],
                'regions' => [], 'defaultRegion' => '',
            ];
        }
        return $this->validate($json);
    }

    public function save(string $json): void {
        $settings = $this->validate($json);
        $this->config->setAppValue('timesheets', 'work_locations', json_encode($settings, JSON_THROW_ON_ERROR));
    }

    private function validate(string $json): array {
        try {
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \DomainException('The work-location settings are not valid JSON.', 400, $error);
        }
        if (!is_array($value)) {
            throw new \DomainException('Choose valid work-location settings.', 400);
        }
        foreach (['shiftEnabled', 'jobEnabled', 'multipleCountries', 'multipleRegions'] as $key) {
            if (!is_bool($value[$key] ?? null)) {
                throw new \DomainException('Choose valid work-location tracking switches.', 400);
            }
        }
        $catalog = $this->countries();
        $country = $value['workingCountry'] ?? null;
        $countries = $value['countries'] ?? null;
        $regions = $value['regions'] ?? null;
        $defaultRegion = $value['defaultRegion'] ?? null;
        if (!is_string($country) || ($country !== '' && !isset($catalog[$country]))
            || !is_array($countries) || !array_is_list($countries) || count($countries) > count($catalog)
            || !is_array($regions) || !is_string($defaultRegion)) {
            throw new \DomainException('Choose valid countries and state / province / region settings.', 400);
        }
        foreach ($countries as $code) {
            if (!is_string($code) || !isset($catalog[$code])) {
                throw new \DomainException('Choose countries from the country checklist.', 400);
            }
        }
        $countries = array_values(array_unique($countries));
        $normalizedRegions = [];
        foreach ($regions as $code => $names) {
            if (!isset($catalog[$code]) || !is_array($names) || !array_is_list($names) || count($names) > 500) {
                throw new \DomainException('Choose valid regions for each country.', 400);
            }
            foreach ($names as $name) {
                if (!is_string($name) || trim($name) !== $name || $name === '' || !mb_check_encoding($name, 'UTF-8')
                    || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/u', $name)
                    || ($code === 'US' && !in_array($name, $this->usStates(), true))) {
                    throw new \DomainException('Enter region names of 1 to 100 characters; select US states from the checklist.', 400);
                }
            }
            $normalizedRegions[$code] = array_values(array_unique($names));
            sort($normalizedRegions[$code], SORT_NATURAL | SORT_FLAG_CASE);
        }
        if ($value['shiftEnabled'] || $value['jobEnabled']) {
            if ($country === '' || ($value['multipleCountries'] && (count($countries) < 2 || !in_array($country, $countries, true)))) {
                throw new \DomainException('Choose a working country; multiple-country tracking requires at least two countries including the default.', 400);
            }
            if ($value['multipleRegions']) {
                foreach ($value['multipleCountries'] ? $countries : [$country] as $code) {
                    if (empty($normalizedRegions[$code])) {
                        throw new \DomainException('Select at least one state / province / region for every working country.', 400);
                    }
                }
                if (!in_array($defaultRegion, $normalizedRegions[$country] ?? [], true)) {
                    throw new \DomainException('Choose a default state / province / region for the working country.', 400);
                }
            }
        }
        return [
            'shiftEnabled' => $value['shiftEnabled'], 'jobEnabled' => $value['jobEnabled'],
            'multipleCountries' => $value['multipleCountries'], 'multipleRegions' => $value['multipleRegions'],
            'workingCountry' => $country, 'countries' => $countries,
            'regions' => $normalizedRegions, 'defaultRegion' => $defaultRegion,
        ];
    }

    public function options(?string $inherited = null): array {
        $settings = $this->settings();
        $countries = $this->countries();
        $options = [];
        foreach ($settings['multipleCountries'] ? $settings['countries'] : [$settings['workingCountry']] as $code) {
            if ($code !== '') {
                $options[$code] = [
                    'name' => $countries[$code],
                    'regions' => $settings['multipleRegions'] ? ($settings['regions'][$code] ?? []) : [],
                ];
            }
        }
        uasort($options, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        $default = $inherited === null ? null : self::decode($inherited);
        $defaultCountry = $default['countryCode'] ?? $settings['workingCountry'];
        $defaultRegion = $default['region'] ?? $settings['defaultRegion'];
        if (!isset($options[$defaultCountry])) {
            $defaultCountry = $settings['workingCountry'];
            $defaultRegion = $settings['defaultRegion'];
        }
        return [
            'countries' => $options, 'multipleCountries' => $settings['multipleCountries'],
            'multipleRegions' => $settings['multipleRegions'],
            'defaultCountry' => $defaultCountry, 'defaultRegion' => $defaultRegion,
        ];
    }

    public function capture(string $scope, ?string $country, ?string $region, ?string $inherited = null): ?string {
        $settings = $this->settings();
        if (!$settings['shiftEnabled'] && !$settings['jobEnabled']) {
            if ($country !== null || $region !== null) {
                throw new \DomainException('Work-location tracking is disabled. Reload the page before clocking in.', 409);
            }
            return null;
        }
        if (!$settings[$scope . 'Enabled']) {
            if ($country !== null || $region !== null) {
                throw new \DomainException('Location selection is disabled for this tracking mode. Reload the page.', 409);
            }
            if ($inherited !== null) {
                return $inherited;
            }
            $country = $settings['workingCountry'];
            $region = $settings['multipleRegions'] ? $settings['defaultRegion'] : '';
        } else {
            $country ??= $inherited === null ? $settings['workingCountry'] : self::decode($inherited)['countryCode'];
            $region ??= $inherited === null || !$settings['multipleRegions'] ? null : self::decode($inherited)['region'];
        }
        $allowed = $settings['multipleCountries'] ? $settings['countries'] : [$settings['workingCountry']];
        if (!in_array($country, $allowed, true)
            || ($settings['multipleRegions'] && !in_array($region, $settings['regions'][$country] ?? [], true))
            || (!$settings['multipleRegions'] && $region !== null && $region !== '')) {
            throw new \DomainException('Choose an allowed country and state / province / region before clocking in.', 400);
        }
        return json_encode([
            'countryCode' => $country, 'country' => $this->countries()[$country],
            'region' => $settings['multipleRegions'] ? $region : '',
        ], JSON_THROW_ON_ERROR);
    }

    public static function decode(string $snapshot): array {
        $value = json_decode($snapshot, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value) || !is_string($value['countryCode'] ?? null)
            || !is_string($value['country'] ?? null) || !is_string($value['region'] ?? null)) {
            throw new \RuntimeException('A saved work location is invalid. Contact your administrator.');
        }
        return $value;
    }

    public static function label(?string $snapshot): string {
        if ($snapshot === null) {
            return 'Not recorded';
        }
        $value = self::decode($snapshot);
        return $value['region'] === '' ? $value['country'] : $value['country'] . ' / ' . $value['region'];
    }

    public static function key(?string $snapshot): string {
        $value = $snapshot === null ? null : self::decode($snapshot);
        return json_encode([$value['countryCode'] ?? null, $value['region'] ?? null], JSON_THROW_ON_ERROR);
    }
}
