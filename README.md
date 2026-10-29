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
4. Use a local Nextcloud 35 development instance for feature work, while keeping the app compatible with Nextcloud 34+.
5. Run PHP syntax checks during development:
   `php -l lib/AppInfo/Application.php`
   `php -l lib/Controller/PageController.php`
   `php -l lib/Db/Shift.php`
   `php -l lib/Db/ShiftMapper.php`
   `php -l lib/Service/ShiftService.php`
   `php -l lib/Settings/AdminSettings.php`
6. Commit changes with descriptive messages and push to GitHub when ready.

## Development status

This repository currently contains the initial app shell. The separate development server is Nextcloud 35.0.2; production compatibility must remain at Nextcloud 34 or newer. Database schema, access controls, clock flows, storage integration, printable views, and workbook generation are planned next.

## Longer-term direction

Keep the app's authenticated API versioned so native Android and iOS clients can be added later. App Store publication is a later release goal.
