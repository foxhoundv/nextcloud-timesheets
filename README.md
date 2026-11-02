# Timesheets for Nextcloud

Native Nextcloud app for employee work shifts, Sales Order job intervals, shop-time totals, printable employee views, and yearly Excel workbooks.

## Compatibility

- Production compatibility target: Nextcloud Server 34 and newer.
- Development validation target: Nextcloud Server 35.0.2.
- App identifier: timesheets.
- PHP classic app architecture; the UI will use Nextcloud's supported front-end components.

## Product rules

- Administrators grant app access to individual Nextcloud users and groups, assign admin rights, configure storage roots, and set global rounding and shift-length rules.
- Per employee, administrators enable shift tracking, job tracking, or both.
- Shift and job rounding can be enabled independently. The administrator selects 5, 10, 15, or 30 minutes for each enabled rule.
- A configurable net-shift threshold triggers a confirmation after clock-out. Breaks are outside the shift and excluded from net shift duration.
- Shift intervals crossing midnight are attributed to their start date. Their duration remains the full elapsed interval.
- Job intervals are separate rows, one for each clock-in/out pair. Time in a shift outside job intervals is shop time.
- Completed times display using the employee's current time-zone setting. An open interval retains its clock-in time zone through clock-out.
- Employee workbooks have monthly sheets 01 through 12, plus a yearly cumulative sheet. Yearly totals use rounded durations.
- Employees use a print-ready app view; only administrators can access or edit workbook files.
- Job workbooks are saved in each Sales Order folder under Time logs.

## Development workflow

1. Clone the repository and check out the `main` branch.
2. Place this app in your Nextcloud `apps/` directory, for example `nextcloud/apps/timesheets`.
3. Enable the app in the Nextcloud admin UI or via the CLI:
   `php occ app:enable timesheets`
4. Use the separate Nextcloud 35.0.2 testing server for runtime validation, while keeping the app compatible with Nextcloud 34+. This development machine cannot run PHP commands.
5. Run PHP syntax checks on the testing server, from the deployed app directory (not on this machine):
   `php -l lib/AppInfo/Application.php`
   `php -l lib/Controller/PageController.php`
   `php -l lib/Db/Shift.php`
   `php -l lib/Db/ShiftMapper.php`
   `php -l lib/Service/ShiftService.php`
   `php -l lib/Settings/AdminSettings.php`
   `php -l lib/Migration/Version000103Date20261102170000.php`
   `php -l tests/persistence.php`
6. Commit changes with descriptive messages and push to GitHub when ready.

### Database migrations and persistence checks

Shift tables are created by Nextcloud migrations during installation or upgrade, never during page requests. Version 0.1.3 introduces the initial migration and leaves an existing, correctly prefixed shift table unchanged. Nextcloud applies its configured database table prefix automatically.

After deploying the updated app to the testing server, run these commands from the Nextcloud installation directory as its web-server user:

```sh
php occ upgrade
php occ migrations:status timesheets
php apps/timesheets/tests/persistence.php /path/to/nextcloud
```

Adjust the app path if it is installed in `custom_apps`. For Docker, execute these commands inside the testing instance's Nextcloud container as its web-server user, for example with `docker exec -u www-data <testing-container> ...`; PHP does not run on this development machine.

The regression checks use Nextcloud's installed libraries, check migration creation and repeat execution in memory, verify service error propagation, and test real shift insert/read behavior in a rolled-back database transaction. Run them only against the testing instance after applying migrations. Database failures now reach Nextcloud's standard exception handling and logging instead of appearing as an empty dashboard.

The old request-time SQL could create an unprefixed `timesheets_shifts` table even when Nextcloud was configured to use a prefix. This migration neither deletes that table nor imports it automatically. If it contains historical data, back it up and inspect it before planning a separate data migration.

### Local testing credentials

Copy [.env.example](.env.example) to `.env` if it does not already exist, then fill in the testing instance URL, username, app password, and SSH connection details. Prefer a dedicated test account and SSH key authentication; store only the private key's local path, not its contents.

The existing `.gitignore` rules exclude `.env` from Git. Keep real credentials only in that local file, never in the example template. Git ignore rules are not encryption: protect the file locally and do not share it. These values are for local testing/deployment tooling and are not loaded automatically by the app.

## Development status

This repository contains the initial app shell and migration-managed shift persistence. The separate development server is Nextcloud 35.0.2; production compatibility must remain at Nextcloud 34 or newer. Access controls, clock flows, storage integration, printable views, and workbook generation are planned next.

## Longer-term direction

Keep the app's authenticated API versioned so native Android and iOS clients can be added later. App Store publication is a later release goal.
