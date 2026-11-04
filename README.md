# Timesheets for Nextcloud

Native Nextcloud app for employee work shifts, Job intervals, shop-time totals, printable employee views, and on-demand XLSX reports.

## Compatibility

- Production compatibility target: Nextcloud Server 34 and newer.
- Development validation target: Nextcloud Server 35.0.2.
- App identifier: timesheets.
- PHP classic app architecture; the UI will use Nextcloud's supported front-end components.

## App versioning

Use `Major.Minor.Sub-minor` for app releases (`Major.Minor.Sub-minor` maps to the Nextcloud app version in `appinfo/info.xml`). Major is an unbounded nonnegative integer, Minor is 1-9, and Sub-minor is 1-99. The current version `1.3.1` follows this format.

Before changing the app version for an update, ask the project owner whether it is a **Major**, **Minor**, or **Sub-minor** change; do not choose a level or bump the version without that answer. Increment the selected component and reset any lower-order components to 1: a sub-minor update changes only the last component, a minor update resets Sub-minor to 1, and a major update resets Minor and Sub-minor to 1. For example, `0.1.15` to `0.1.16` is Sub-minor, `0.1.99` to `0.2.1` is Minor, and `0.9.99` to `1.1.1` is Major.

## Product rules

- Nextcloud administrators grant Timesheets administration to individual users or groups. Delegated administrators can manage Timesheets settings and tracking access, but cannot add or remove delegated administrators. Nextcloud administrators always retain access.
- Administrators grant app access to individual Nextcloud users and groups, configure storage roots, and set global rounding and shift-length rules.
- Per employee, administrators enable shift tracking, job tracking, or both.
- Shift tracking and job tracking can each operate independently. Enabling both reveals additional settings governing their interaction.
- Administrators choose whether shift clock-out is blocked by an active job (default) or allowed while leaving the job running. Requiring an active shift for job clock-in is optional and defaults off; it applies only to users with both tracking permissions when both global modes are enabled.
- Shift and job rounding can be enabled independently. The administrator selects 5, 10, 15, or 30 minutes for each enabled rule.
- A configurable net-shift threshold triggers a dismissible reminder after clock-out when actual shift time reaches the threshold; it never blocks clock-out or changes recorded time. Unpaid breaks are gaps between separate shifts: clock out for a break, then clock in again when work resumes.
- Shift intervals crossing midnight are attributed to their start date. Their duration remains the full elapsed interval.
- Job intervals are separate rows, one for each clock-in/out pair. Time in a completed shift outside job intervals counts as shop time.
- Completed times display using the employee's current time-zone setting. An open interval retains its clock-in time zone through clock-out.
- Employees use a print-ready app view. Generated workbook access is controlled by Nextcloud's normal file-sharing permissions; configure both storage roots so only the intended administrators can access or edit the reports. App access alone does not grant access to those folders.
- Payroll workbooks are generated on demand per employee for a selected weekly or monthly period. Administrators choose the first day of a seven-day payroll week (Monday by default); its end is automatically the preceding weekday.
- Job workbooks are created by the first clock-in in an existing matching Job folder under `Time logs`, then updated on every Job clock-in and clock-out. Missing or ambiguous folders and workbook write failures block new Job clock-ins; contact an administrator to resolve them.

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

### Opening Timesheets

- For daily use, sign in and choose **Timesheets** in Nextcloud's app menu. The dashboard is available to regular signed-in users for whom the app is enabled, not just administrators.
- For configuration, open **Administration settings > Timesheets** as a Nextcloud administrator. Regular users cannot open or save these settings.
- If an entry is missing after deployment, run `php occ upgrade` on the testing server and reload the page. App version 0.1.4 registers both entries through the app manifest.

To check both entry points over HTTP, run `php apps/timesheets/tests/entrypoints.php /path/to/nextcloud https://testing-instance` on the testing server as its web-server user. Adjust the app path for `custom_apps`. This test creates a temporary account, checks regular-user access, briefly promotes it for admin-only checks, tests saving and CSRF protection, restores the original settings, and deletes the account and cookies. Use only the testing instance; its HTTPS certificate must be trusted by PHP/cURL.

The **Timesheet Administration** form groups tracking, combined-tracking rules, rounding, and shift-review preferences with descriptions for every control. Global shift/job switches control new clock-ins independently. Unpaid breaks use separate clock-out/in work periods. Optional rounding affects separately reported durations only.

The dashboard shows your current shift, a live elapsed-time estimate, all-date recorded duration for completed shifts, completed/open shift counts, and shift history. Durations use hours and minutes (for example, `2h 05m`), not decimal hours. Open shifts have no finalized duration. Empty history is shown as an empty state, not a sample shift.

Both pages use paired Nextcloud background and text colors, with text labels rather than color alone for statuses. Settings controls align in two columns on wide screens and stack on narrow screens.

### User and group access

Version 0.1.9 adds **User and group access** in Timesheet Administration. Existing installations remain unrestricted by default: everyone with Nextcloud app access can use globally enabled tracking features. Nextcloud's own app-level restrictions still apply.

In version 0.1.11, use the **Groups** and **Users** tabs to select an entry, then choose **Add** at the bottom of the selection area. The complete lists refresh on every administration-page load and are alphabetized by group name or user account name (not user display name). Already-assigned entries are hidden from the available lists.

Selected entries appear as chips under **Groups/Users With Access**, with an x removal button at the beginning and **Shift tracking** / **Job tracking** checkboxes alongside. Nextcloud administrators also see a **Timesheets administration** checkbox for each entry. Adding that permission delegates Timesheets settings administration; it does not grant Nextcloud administrator rights. Delegated administrators can change tracking and access settings, but cannot grant or revoke Timesheets administration. Nextcloud administrators always retain access. Removing a chip returns an existing account/group to its alphabetized selection list. Add/remove/permission edits are not applied until saved. Choose either tracking permission, both, or neither (dashboard access only). Enable **Restrict access to assigned users and groups**, then select **Save access settings**. Tracking assignments apply only in restricted mode; delegated administration is independent of it. Disabling restricted mode restores tracking access without deleting assignments.

Permissions are additive across direct assignments and all current group memberships. A false checkbox does not deny a tracking permission granted elsewhere. Global shift/job switches remain independent master switches.

Nextcloud administrators use **Administration settings > Timesheets**. Delegated administrators open **Timesheet Administration** from the Timesheets dashboard. Both settings entry points use the same permission checks on the server; direct HTTP requests cannot bypass delegated-admin restrictions.

Removing assignments or group membership prevents new clock-ins when no remaining grant allows them. Users retain access to their own saved history and can finish an already-open shift; users without an assignment or history receive an explicit access-required page. The menu entry may remain visible, but server-side permissions govern dashboard and API access. Disabling an account or revoking app access through Nextcloud itself can still prevent sign-in/app access, including clock-out.

Access policy is stored in Nextcloud app configuration, not app files, and survives updates along with tracking preferences and time logs. Invalid assignment targets or malformed policies are rejected without replacing the saved configuration.

On the testing server, run `php apps/timesheets/tests/access.php /path/to/nextcloud` for additive grants, group membership, delegated-administrator roles, read-only history, revocation, and invalid-input regression checks. It temporarily changes global access/shift preferences and restores them and deletes its fixtures afterwards; run only when testing users are idle. The entry-point suite also tests user/group delegation, HTTP authorization, immutable delegated-admin assignments, CSRF, directory-search restrictions, and clock-out after revocation.

### Clocking in and out

1. An administrator enables **Enable shift tracking** and saves the settings.
2. A signed-in user with app access (and shift permission in restricted mode) selects **Clock in** on the dashboard.
3. The current shift shows its start time and elapsed time. Select **Clock out** to save the completed duration and refresh the overview/history.

Only one shift can be open per user, enforced by a database unique index. Clock-out updates only the authenticated user's specific open shift; stale tabs and duplicate requests cannot close a newer shift or overwrite a completed duration. If a request cannot be confirmed, refresh before retrying. An already-open shift can still be finished after an administrator disables tracking.

Both POST endpoints (`/api/v1/shifts/clock-in` and `/api/v1/shifts/clock-out`, relative to the app URL) require authentication and a Nextcloud CSRF token. Identity and timestamps come from the server, not submitted fields; clock-out requires the `shiftId` displayed in the dashboard form.

Clock timestamps are UTC. A shift retains the user's Nextcloud time-zone preference at clock-in and is attributed to that local start date, including across midnight and daylight-saving changes. Open timestamps use that saved zone; completed timestamps display in the user's current Nextcloud zone. Elapsed duration is stored in seconds; displayed completed totals use each shift's whole minutes without rounding. The browser timer is an estimate only; the server computes the saved duration. The entire clocked-in interval counts as work time; clock out for unpaid breaks.

### Unpaid breaks between shifts

Version 0.1.10 explains and regression-tests the separate-shift break workflow:

1. Select **Clock out** when a break begins. This finishes the current work period.
2. Stay clocked out during the break. No shift is active and no shift time accrues. End any active job too; a job left running continues recording its own interval.
3. Select **Clock in** when you return. A new shift starts with a new timer; the earlier work period remains in history.

Repeat for additional breaks. For example, working 08:00-10:00, 10:15-12:00, and 12:45-17:00 records **8h 00m**, not the full nine-hour span. The 15- and 45-minute gaps are excluded. History shows three separate shifts, even on the same date; totals sum only saved work-period durations.

There is no paused shift, break timer, automatic deduction, or separate break permission/switch. Gaps are not labeled or totaled as breaks because time off between shifts may also be overnight or other non-work time. If you forget to clock out, that time remains part of the shift. Returning from a break is a new clock-in and requires global shift tracking and your shift permission to still be enabled. Existing saved shifts, active shifts, and settings are unchanged by this update.

### Job clock-in/out and combined tracking

Version 0.1.13 adds independent job intervals. Enable **Enable job tracking** and grant job permission (when restricted mode is on). On the dashboard, enter a Job ID and select **Start job**; select **End job** to close that specific interval. Each user may have only one active job. IDs are trimmed labels of 1-255 characters without control characters. Reusing an ID creates another interval rather than overwriting history.

Job-only users do not need shift tracking. With both global modes enabled, **Combined shift and job tracking** appears in administration:

- **Require a shift before starting a job** defaults to **No**. When enabled, users with both permissions must clock into a shift first; job-only users remain independent.
- **Shift clock-out with an active job** defaults to **Block until job ends**. **Allow; leave job running** closes only the shift. The job continues counting until explicitly ended, including breaks and time outside the shift. The requirement concerns job clock-in, not continuous shift membership.

Rules are preserved when the combined-settings section is hidden. Existing open jobs can always be ended after global tracking is disabled or Timesheets permissions are revoked, and users retain their own job history. The shift-end policy still protects active intervals after such changes. Nextcloud account/app restrictions can still prevent access. Finish both job and shift before taking an unpaid break.

Job timestamps are UTC, attributed to the local start date, with the original timezone retained while active and current user timezone used for completed history. Exact elapsed seconds include midnight, DST, and update downtime. Actual displayed durations use whole minutes without rounding; optional reported durations are separate. Job time is separate from shift time: do not add them together as extra work. Job-folder workbooks update automatically when employees clock in or out.

Both job POST endpoints (`/api/v1/jobs/clock-in`, requiring `reference`, and `/api/v1/jobs/clock-out`, requiring `jobId`) require authentication and CSRF. Identity/time come from the server. A nullable unique open-user slot prevents duplicate active jobs; conditional updates reject stale closes. Shift and job transitions serialize through a per-user database row lock inside transactions so concurrent job-start/shift-end requests cannot bypass interaction rules.

On the testing server, run `php apps/timesheets/tests/job-clock.php /path/to/nextcloud` for permissions, job-only/combined modes, policies, exact timing, stale/cross-user actions, and concurrent job-start/shift-end regression coverage. It changes global preferences temporarily and removes its fixtures afterwards; run only when testing users are idle. The HTTP entry-point suite covers job endpoints and CSRF. The upgrade test now prepares both historical and active jobs for subsequent release checks.

Version 0.1.6 adds the unique open-shift slot, saved timezone, and precise duration fields. Legacy timestamps are treated as UTC because the old schema did not store their timezone. Existing closed records are unchanged. If duplicate open shifts exist, the upgrade stops with an explicit error rather than discarding records; resolve those duplicates before retrying.

On the testing server, run `php apps/timesheets/tests/shift-clock.php /path/to/nextcloud` for database-backed clock transition, stale-request, cross-midnight, daylight-saving, and separate-shift break regression checks. Break checks verify multiple gaps, new timers/IDs, individual history rows, and exact saved seconds and dashboard totals excluding the gaps. Adjust for `custom_apps`. The test restores the tracking/access settings and deletes its temporary account and shifts. The existing entry-point suite also tests HTTP clock actions, break guidance, and CSRF enforcement.

After deploying new routes and running `occ upgrade`, verify the web endpoints as well as CLI checks. If the web process still has an old APCu route list, refresh that testing instance's PHP/web process cache (or restart its container) and verify the login page is HTTP-ready before testing; a successful CLI upgrade alone does not clear a web worker's APCu cache.

### Shop time in completed shifts

Version 0.1.14 adds **Job time in shift** and **Shop time** to shift history, plus all-date **Job time in shifts** and **Shop time** totals. No additional setting is required. Existing permissions still govern access to your own history, including after tracking is disabled or Timesheets permissions are revoked.

Shop time is the part of a completed shift not covered by any of your job intervals. Each job is clipped to the shift's start/end; time outside shifts and gaps between shifts are excluded. A job spanning a break contributes only inside the work periods on either side. An active job left running after shift clock-out contributes only up to that completed shift's end. Overlapping historical job intervals count their union, never twice.

Active shifts have **Not finalized** breakdowns and do not enter these totals. Job-only tracking still records full job intervals without creating shift/shop time; shift-only tracking makes the whole completed shift shop time. Job history continues to show the full job duration, including time outside shifts, so its totals need not match job time in shifts.

Calculations use UTC endpoints and exact seconds, including midnight and daylight-saving transitions, and stay attributed to the shift's start date. Seconds are summed before totals are displayed as completed whole minutes without rounding. Individually truncated displayed parts may sum to one minute less than a displayed total. Reload the dashboard after saving intervals. Legacy completed shifts missing start/end timestamps retain their saved duration in Recorded time, but show **Unavailable** job/shop breakdowns; an explicit warning identifies their exclusion from job/shop totals.

Breakdowns are read-only projections of existing records. No schema migration, historical rewrite, preference reset, or active-timer change is needed. On the testing server, run `php apps/timesheets/tests/shop-time.php /path/to/nextcloud` for interval boundaries, breaks, open jobs, independent tracking, overlap union, exact seconds, DST/midnight, invalid/legacy data, randomized occupancy comparisons, user isolation, and dashboard rendering. It rolls back interval fixtures and removes its temporary account; adjust the app path for `custom_apps`.

### Optional shift and job rounding

Version 0.1.16 adds independent, opt-in shift and job duration rounding.

The independent **Enable shift rounding** and **Enable job rounding** switches in Timesheet Administration both default to **Off**, including on upgrade; previously saved 5/10/15/30-minute interval selections are retained but do not activate rounding. Save tracking preferences after choosing each switch and interval.

Rounding applies to each completed interval's **duration**, not its clock timestamps or a daily sum. The nearest interval wins; exact halfway rounds up. With 15-minute rounding, 7m 29s reports 0m, 7m 30s reports 15m, and 22m 30s reports 30m. A short job/shift can report zero; actual time is always retained. Separate work periods around breaks round independently.

At clock-out, the server atomically saves actual elapsed seconds, the rule (0 for off; otherwise the selected nearest/half-up interval in minutes), and reported seconds. **Settings at clock-out apply**, even if they changed while the interval was active. Later preference changes never recalculate completed records. Rounding still applies when an existing interval is finished after tracking or Timesheets permissions are disabled. The original start date, timezone and UTC endpoints are unchanged, including across midnight, daylight saving, and upgrades.

The dashboard keeps actual durations, Recorded time, and exact job/shop overlap breakdowns. Separate **Reported duration** columns and **Reported shift time / Reported job time** totals use saved results; **Recorded job time** sums actual completed jobs, including time outside shifts. These are alternative views, not additional work to add to shift totals. Job/shop calculations never use rounded job/shift durations, so reported totals need not equal actual job/shop parts.

Off captures exact seconds as reported time. Older records have no captured rule: they show **Not captured (actual)** and contribute actual saved duration to reported totals without rewriting history. Open intervals show **Set at clock-out** and **Not finalized** and do not enter completed totals. Totals sum seconds before displaying whole minutes.

An additive migration adds nullable rounding fields to shifts and jobs without changing existing rows. Malformed rounding settings are rejected with HTTP 400 before any preference writes; older callers omitting enable switches preserve their saved states. Invalid persisted rounding rules surface explicit errors without closing or silently altering the interval.

On the testing server, run `php apps/timesheets/tests/rounding.php /path/to/nextcloud` for every interval's second-level boundaries, half-up ties, independent switches, clock-out snapshots, immutable history, actual overlap preservation, legacy records, invalid-input rollback, and stale close protection. The test restores original settings and removes its temporary account/intervals. The HTTP entry-point suite verifies administration persistence, validation, and omission compatibility. Use only the testing instance when other testing users are idle; adjust for `custom_apps`.

### Shared storage and workbook reports

Nextcloud administrators and delegated Timesheets administrators can choose two existing writable folders with Nextcloud's folder picker: a payroll-timesheets root and a Job files root. The selected paths are stored relative to the account that chose them. Share these folders with the appropriate administrators; generated files follow Nextcloud's normal file-sharing permissions. Timesheets records remain in the database. Payroll workbooks are generated on demand; Job workbooks update automatically.

Payroll reports generate one XLSX per employee with completed shifts in the selected period. Save the **Weekly payroll workweek** in storage settings to choose its first weekday and see the corresponding end weekday. A week always includes seven days, including weekends; the default is Monday through Sunday. For example, Sunday through Saturday includes both boundary dates. Monthly reports use calendar months and are unaffected by the weekly setting. Choose any date in the requested period. Each workbook includes local clock-in/out times, actual and reported durations, the captured rounding interval, and totals. Files are placed in a URL-encoded account-ID subfolder of the payroll root and replaced when regenerated for the same period. Employees without completed shifts in the period do not get an empty workbook. Bi-weekly reports are not enabled until the pay-period anchor date is configured.

When an employee starts a Job, its ID must match the configured ID format. The app resolves its year and sequence, calculates the grouping directory, then finds exactly one existing folder whose name starts with that ID; extra text after the ID is allowed. If the folder is missing, the employee is prompted to contact an administrator and no interval is started. Ambiguous matches, unconfigured storage, and workbook write failures also prevent clock-in. Administrators configure the folders and rules; there is no manual Job report-generation form.

The first successful clock-in creates `Time logs/Time logs.xlsx` in the matched folder. Every clock-in and clock-out rebuilds it from the database, including all employees' active and completed intervals for that exact ID. Each row includes the immutable database interval ID, employee/account, local start/end timestamps, status, actual/reported durations, and rounding settings. Active rows have no finalized end time or duration; totals include completed intervals only. Rows are ordered by interval ID, and database locks serialize updates for the same Job, including different employees. Workbook row positions are not trusted as identity, so concurrent starts and out-of-order clock-outs cannot close another employee's row.

Clock-out always remains available during storage failures: it saves the completed database interval and displays a warning that the workbook needs administrator attention. The next successful clock-in or clock-out for that Job rebuilds all rows, recovering any unsynchronized intervals without duplication. Database records remain authoritative; a workbook is a generated projection, not an editable source of time records. Database and file-storage commits are not a single atomic operation, so a failed database commit may also require the next successful synchronization to repair the workbook.

The company defaults are `SO{year:2}{sequence:4}`, base century `2000`, and `{year}/{group}/{jobId}` below the selected Job files root. Thus `SO260001` resolves to year `2026`, sequence `1`, and grouping folder `1-99`; `SO260100` resolves to `100-199`. The first range ends at `99`, subsequent groups contain `100` sequence numbers, and group names use `{start}-{end}`. Administrators can configure the ID format, two-digit-year century, relative folder template, first-range boundary, subsequent group size, and group-name template for other organizations. ID formats support literal text, one `{sequence:N}` token, and an optional `{year:2}` or `{year:4}` token. Folder templates support `{year}`, `{year2}`, `{sequence}`, `{group}`, and a final `{jobId}` component. Group-name templates support `{start}` and `{end}`.

On the testing server, run `php apps/timesheets/tests/storage.php /path/to/nextcloud` to verify root validation, configurable weekly/monthly payroll boundaries, company/custom folder structures, automatic Job workbook creation, interval-ID row matching, storage-failure behavior, concurrent multi-employee clock transitions, and XLSX contents. The test creates and removes temporary accounts, files, and settings; run it only against the testing instance.

### Long-shift threshold reminder

The **Net shift threshold (minutes)** setting controls a post-clock-out reminder. A value of `0` disables it; a positive value shows a dismissible dashboard reminder when a completed shift's **actual elapsed seconds** meet or exceed the threshold in minutes. Clock-out always completes first; the reminder neither blocks the action nor changes saved time. Separate-shift break gaps are excluded, and rounding does not affect the threshold. Invalid thresholds are rejected before administration preferences are saved. The reminder appears after the dashboard reloads and can be dismissed.

On the testing server, the shift-clock and HTTP entry-point suites verify below/equal/above threshold behavior, zero/off behavior, successful clock-out while tracking is disabled, and the response consumed by the dashboard. Run the existing suites as described above; they restore settings and remove their test accounts and intervals.

### Keeping settings and time logs during updates

Normal in-place app updates preserve all saved administration preferences (including non-default values), historical shift/job logs, and active shifts/jobs. These live in Nextcloud's database, not in replaceable app files or browser storage. Defaults are used only when a preference has never been saved; startup and upgrades must not overwrite saved preferences.

An active shift keeps its original ID, start timestamp, start date, and timezone through an update or web-process restart. Reload the dashboard after an update: the timer resumes from the saved server timestamp, including the update period, and clock-out records the entire interval. History remains available in the same dashboard.

Deploy new app files in place and run `php occ upgrade`; do not uninstall the app, delete its database tables/configuration, or restore an older database as an update procedure. Back up the Nextcloud database and app files together before upgrading. Database restoration or deletion can lose newer settings/time logs. Future schema changes must use additive or data-preserving migrations; never edit an already-applied migration to reset data.

To regression-test an actual version upgrade **on the testing server only**, use the same private state-file path and web-server user for all phases:

```sh
# Before deploying the newer app, prepare with the currently installed test.
php apps/timesheets/tests/upgrade.php /path/to/nextcloud prepare /private/upgrade-state.json
# Deploy the newer app, then run its upgrade.
php occ upgrade
# Refresh the testing instance's web-process cache if necessary.
php apps/timesheets/tests/upgrade.php /path/to/nextcloud verify /private/upgrade-state.json
php apps/timesheets/tests/upgrade.php /path/to/nextcloud cleanup /private/upgrade-state.json
```

The test temporarily changes global tracking and combined-mode preferences to non-default values, enables restricted access with an explicit user grant, and creates a test account with shift/job history and active intervals. Run it when other testing users are idle. It verifies saved settings/assignments and shift/job fields, administration/dashboard data, continued elapsed time, and clock-out across the upgrade in separate PHP processes. **Always run cleanup, even if deployment or verification fails**, to restore the original tracking/access/interaction settings and remove the test account and intervals. Keep the state file until cleanup succeeds. Verification requires a genuinely newer installed app version, not just another request on the same release. Adjust `apps` for `custom_apps`.

### Database migrations and persistence checks

Shift tables are created by Nextcloud migrations during installation or upgrade, never during page requests. Version 0.1.3 introduces the initial migration and leaves an existing, correctly prefixed shift table unchanged. Nextcloud applies its configured database table prefix automatically.

After deploying the updated app to the testing server, run these commands from the Nextcloud installation directory as its web-server user:

```sh
php occ upgrade
php occ config:app:get timesheets installed_version
php apps/timesheets/tests/persistence.php /path/to/nextcloud
```

Adjust the app path if it is installed in `custom_apps`. For Docker, execute these commands inside the testing instance's Nextcloud container as its web-server user, for example with `docker exec -u www-data <testing-container> ...`; PHP does not run on this development machine.

The regression checks use Nextcloud's installed libraries, verify that Nextcloud recorded the migration, check migration creation and repeat execution in memory, verify service error propagation, and test real shift insert/read behavior in a rolled-back database transaction. Run them only against the testing instance after applying migrations. The optional `occ migrations:status` command requires Nextcloud debug mode; these checks do not. Database failures now reach Nextcloud's standard exception handling and logging instead of appearing as an empty dashboard.

The old request-time SQL could create an unprefixed `timesheets_shifts` table even when Nextcloud was configured to use a prefix. This migration neither deletes that table nor imports it automatically. If it contains historical data, back it up and inspect it before planning a separate data migration.

### Local testing credentials

Copy [.env.example](.env.example) to `.env` if it does not already exist, then fill in the testing instance URL, username, app password, and SSH connection details. Prefer a dedicated test account and SSH key authentication; store only the private key's local path, not its contents.

The existing `.gitignore` rules exclude `.env` from Git. Keep real credentials only in that local file, never in the example template. Git ignore rules are not encryption: protect the file locally and do not share it. These values are for local testing/deployment tooling and are not loaded automatically by the app.

## Development status

This repository contains migration-managed shift/job persistence, clock-in/out with actual elapsed time and optional saved rounding, configurable shift/job interaction, completed-shift job/shop breakdowns, post-clock-out long-shift reminders, unpaid breaks as gaps between separate shifts, user/group tracking grants, delegated Timesheets administration, shared storage roots, automatic Job XLSX workbooks, and on-demand payroll reports with configurable weekly boundaries. The separate development server is Nextcloud 35.0.2; production compatibility must remain at Nextcloud 34 or newer. PHP lint and the storage, Job clock, rounding, and HTTP regression suites pass on that server. The 1.3.1 upgrade also verified settings, history, and active shift/Job timer persistence, and removal of the obsolete manual Job-report endpoint. Printable views remain planned.

## Longer-term direction

Keep the app's authenticated API versioned so native Android and iOS clients can be added later. App Store publication is a later release goal.
