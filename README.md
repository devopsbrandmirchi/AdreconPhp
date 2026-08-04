# Google ads SERP tracker

Throughout the interface one word means one thing:

| Word | Means |
| --- | --- |
| **Agency** | A contract you work under, holding several clients |
| **Client** | A business you track for |
| **Website** | One of that client's domains |
| **Keyword** | One search term, in one location, on one device |
| **Check** | One look at Google, costing one search or task |
| **Account** | A login for a person who uses this tool |

A PHP dashboard that checks Google paid results for a keyword in a specific
location on a schedule, and records which sites appear in the top and bottom ad
blocks over time.

Runs on standard DirectAdmin or cPanel shared hosting. Needs PHP 8.0 or newer
with PDO MySQL and cURL, MySQL, and cron. mbstring is used if present and
falls back cleanly if it is not.

## Upgrading an existing install

Do not delete anything. Your history is what makes the coverage figures mean
something, and the upgrade preserves all of it.

1. **Back up the database.**

   ```
   mysqldump -u USER -p DBNAME > ~/serp-backup.sql
   ```

2. **Upload every file over the top, except `config.php`.** That file holds your
   settings and is not in the package.

3. **Run the upgrade, dry run first.**

   ```
   php upgrade.php            # shows exactly what it would change
   php upgrade.php --apply    # makes the changes
   ```

   It only adds missing tables and columns, never drops or rewrites anything.
   Safe to run twice; the second run reports that there is nothing to do. It
   prints row counts before and after and fails loudly if any count drops.

   On an upgrade it also promotes your existing account to administrator,
   because roles are new and everyone would otherwise default to member with
   nobody able to manage people, and it moves existing schedules into a client
   called "Unassigned" so they stay visible.

4. **Hard refresh** the browser, then rename "Unassigned" or create real
   clients and move schedules to them.

Your cron job, schedules, next run times and provider settings are untouched.
Checks keep running throughout, because the worker does not load any of the
pages that changed.

## Install

1. **Upload** the `serp-tracker` folder into `public_html`, or a subdomain root.

2. **Create a database** in DirectAdmin, MySQL Management. Note the name, user
   and password.

3. **Create the config.** Copy `config.example.php` to `config.php` and fill in
   the database details, then set `provider` and the matching credentials.

   **DataForSEO** is the cheapest and the default:

   ```php
   'provider'            => 'dataforseo',
   'dataforseo_login'    => 'your login',
   'dataforseo_password' => 'your password',
   'site_url'            => 'https://your-site.com',
   'postback_token'      => 'a long random string',
   ```

   Checks go through their **Standard queue**: the worker posts a task, and
   DataForSEO pushes the finished result to `postback.php`. That is why it
   costs a fraction of the alternatives, and why a check takes a few minutes
   rather than seconds. "Check now" queues like everything else.

   If a push ever fails to reach you, the task stays on their side and the
   worker collects it on a later run, so nothing is lost when the site is
   briefly down.

   The older providers still work:

   ```php
   'provider'    => 'serpapi',
   'serpapi_key' => 'your key',
   ```

   ```php
   'provider'    => 'serpapi',      // or 'scrapingdog'
   'serpapi_key' => 'your key',
   ```

   Both answer immediately rather than queuing. Nothing else changes: the
   scheduler, the reports and the interface are provider agnostic.

   More secure option: name it `serp-tracker-config.php` and put it one level
   **above** the app folder, outside the webroot. The app checks there first.
   That way the key is unreachable over HTTP even if the host ignores
   `.htaccess`.

4. **Check the key works before building schedules.** Over SSH:

   ```
   php test_provider.php "rv for sale" "Arlington, Texas, United States"
   ```

   One check. It prints the request, whether the location was honoured, and the
   ads found in each block. Fix any problem here rather than after you have
   twenty schedules running.

5. **Run the installer.** Visit `/serp-tracker/install.php`, pick a username and
   password, then **delete install.php from the server.** The installer also
   loads 3,418 US locations for the autocomplete.

6. **Add the cron job.** Sign in and open `/cron_setup.php`. It reads the
   real path of the install and finds a working PHP command line binary, then
   prints the exact line to paste into DirectAdmin, Advanced Features,
   Cronjobs. Set the schedule to every 5 minutes:

   | Field | Value |
   | --- | --- |
   | Minute | */5 |
   | Hour | * |
   | Day of month | * |
   | Month | * |
   | Day of week | * |

   That page also tells you whether cron has ever actually fired, which is the
   fastest way to confirm it is working. **Delete cron_setup.php once it is.**

Without cron the dashboard still works, but only the "Check now" button pulls
data.

**If system cron does not work on your host**, and some shared hosts do disable
it, use the HTTP trigger instead. Set a long random `cron_token` in config.php,
then point a free external cron service at:

```
https://your-site/cron.php?token=YOUR_TOKEN
```

Every 5 minutes. cron-job.org, EasyCron and UptimeRobot all do this. The
endpoint refuses to run unless the token is set and matches, processes only
what is due, and stops itself before a typical HTTP timeout. It shares its
implementation with the CLI worker, so both behave identically.

The worker accepts either the CLI or the CGI PHP binary, so `php -q` style
commands work too. It refuses to run over HTTP regardless. If it is started
with a PHP older than 8.0 it says so in the log instead of failing obscurely.

## Accounts and access

The first account created during install is an **administrator**: it sees every
client and is the only role that can manage people.

Everyone else is a **standard user**. A standard user can do everything an
administrator can inside the clients they are assigned to: add keywords, start, stop, check,
delete, and create new clients. They cannot manage accounts, and they cannot
see the raw API payloads or the cron diagnostics.

Add logins under **Accounts** in the sidebar. You set their password when you
create the account, and it is shown once in plain text so you can copy it
before saving. Tick the clients they should see. Access can be changed or
revoked at any time from the same page. Deleting an account leaves its
schedules untouched.

This is what makes it safe to give an outside client a login: they see their
own clients and nothing else, enforced in the database on every query rather
than by hiding links.

## Agencies

Clients sit under an agency, so the several you work under stay separate rather
than piling into one flat list. Add and rename them under **Client access**;
each client has an agency picker on the same page. A client with no agency still
works, it just groups under "No agency".

## Getting around

A sidebar on the left lists every client you can see, grouped under its agency,
so any client is one click away from anywhere. "All clients" sits at the top,
and breadcrumbs across each page show where you are.

Client and keyword pages are **tabbed** rather than one long scroll:

- A client has **Overview** (headline numbers and the competitor leaderboard),
  **Keywords**, **Competitors** and **Websites**.
- A keyword has **Snapshot** (the ads at the last check), **Check by check**
  (the presence grid), **How often each shows** and **Check log**.
Administrators also get a **Settings** group:

- **Accounts** is where logins are created and removed. These are people who
  sign in: your team, and clients you want to give a view of their own data.
  They are not the clients themselves.
- **Client access** is a grid of accounts against clients. Tick the boxes, save
  once. Clients are also created, renamed and deleted here.
- **Server checks** shows whether the scheduler is running, and the exact cron
  line for your server.

Standard users see only their own clients in the sidebar, and no Settings group
at all.

## Clients

Schedules belong to a client. The front page lists your clients with keyword,
location and schedule counts; click one to see and manage its keywords.

A client can have one or more domains. Those are flagged as theirs throughout
the reports, so you can tell at a glance whether the client is showing up
against its competitors.

## Using it

**Sign in** with the username and password you set during install.

**Add one schedule.** Keyword, location, interval, device. The location field
autocompletes as you type, in the exact form the provider expects.

On SerpApi the autocomplete queries their own Locations API, which is free and
consumes no search. That covers far more than cities: **postal codes**,
counties, DMA regions, universities and airports. Type a ZIP such as `33801`
and pick the Postal Code entry to track a single ZIP. Each result shows its
target type, so you always know whether you picked a city, a county or a ZIP.
Results are cached locally as you use them.

**Add many at once.** Paste keywords one per line and locations one per line.
Every keyword is paired with every location, so 5 keywords by 4 locations
creates 20 schedules in one go. The cap is 250 per submission.

**Start and stop in bulk** from the command line, which is easier than clicking
through thirty of them:

```
php manage.php list  --interval=1
php manage.php start --interval=1 --yes
php manage.php stop  --keyword="polaris for sale" --yes
php manage.php check --interval=1 --yes
```

`check` runs the matched schedules immediately rather than waiting for their
next slot, and reports the one-off cost before doing it.

Filters combine: `--interval`, `--keyword`, `--location`, `--device`, `--status`,
or `--all`. Nothing changes without `--yes`; without it you get a preview and
the monthly search cost of the change, checked against your ceiling.

**Bulk actions.** On a client page, tick individual rows or use the header
checkbox on a keyword to take its whole group. A bar appears with Start, Stop,
Check now and Delete. Start all, Stop all and Check all for the entire client
are in the page header. Check now runs inline and is capped at 25 per click so
a single click cannot start a hundred API calls.

**One-time checks.** Choose "Once" as the interval for a single check. It runs
once when started, keeps its result, then marks itself Completed rather than
rescheduling. Useful for a spot check on a market you are not monitoring
continuously. One-off schedules count nothing towards your monthly projection.

**Run windows.** Every schedule runs for a bounded number of days and then
stops itself. The default is **7 days** and the maximum is **60**. Set it on
the add form, or change any selection from the bulk bar.

The clock starts when a schedule is **started**, not when it is created, so
adding a batch on Monday and starting it on Friday still gives a full window.
Restarting a finished schedule grants a fresh one.

This is the guard against runaway spend. A schedule cannot outlive its window,
enforced in three places: the worker expires them before it picks work, the
selection query itself excludes anything past its date, and the pages expire on
load. The monthly projection also counts only the days a schedule will still be
running, so a one week run is not projected as if it lasted the month.

A schedule that reaches its end date shows as **Finished its run**, which is
deliberately distinct from Stopped, so you can tell what you paused from what
ran its course.

**Start and stop.** Every schedule is created stopped. Start begins checking on
the next cron pass, then settles onto interval boundaries anchored to local
midnight, so a 3 hour schedule lands on 00:00, 03:00, 06:00 in its own timezone
and results stay comparable day to day. Stop halts it immediately and spends
nothing further. Both buttons are on the dashboard row and the tracker page.

**Devices.** Desktop, mobile, or both. Choosing both creates a paired set of
schedules and the tracker page gets a switch between the two. Mobile sends
`mob_search=true` and usually returns fewer ads, which is true of real mobile
results.

**While a check is running** the status pill shows "Checking now" with elapsed
seconds and the page updates itself the moment the check completes. A check
that dies mid-flight is closed out automatically after 5 minutes rather than
showing as permanently in progress.

**Right rail and middle ads are kept separate too.** SerpApi reports
`block_position` as one of top, middle, bottom or right. Only top and bottom
feed the counts and the consistency table; middle and right are recorded under
their own names rather than being folded into top.

**Local services ads are kept separate.** They come back under their own
`local_ads` key and they are a different ad product from the top and bottom
text blocks. They are recorded and shown as a note, but deliberately excluded
from the top and bottom counts, the consistency table and the presence grid,
because mixing them would inflate the top block with something it is not.

**No ads** is a real result, not an error. When Google serves no paid results
for a keyword and location, the check is recorded as a zero, both ad panels say
"No ads", and the run counts in the denominator of the coverage figures. That
is the point: a competitor cannot be at 100% coverage across a window where
nobody was bidding.

## Reading the results

The **How often each advertiser shows** table is the main answer to "sometimes
a dealer shows up, sometimes not". Over 24 hours, 7 days, 30 days or all time
it gives each advertiser:

- **Coverage**, the share of checks they appeared in, with a split bar for time
  spent in the top block versus the bottom block
- **Consistency**, one of Always, Most checks, Intermittent or Rare
- Top and bottom hit counts, and average position in the top block

An advertiser at 100% never dropped out of the auction. Anything under 75% is
cycling, and the usual cause is a daily budget running dry. The presence grid
below it shows the same data check by check, so you can see the pattern by hour
and spot dealers who pause overnight.

## Credits

SerpApi bills one **search** per check. ScrapingDog bills 10 credits, because
ads require `advance_search=true`. Per schedule, per month:

| Interval | Checks/month | SerpApi searches | ScrapingDog credits |
| --- | --- | --- | --- |
| 1 hour | 720 | 720 | 7,200 |
| 3 hours | 240 | 240 | 2,400 |
| 6 hours | 120 | 120 | 1,200 |
| 12 hours | 60 | 60 | 600 |
| 24 hours | 30 | 30 | 300 |

Set `monthly_credit_ceiling` to your plan's monthly allowance in whichever unit
your provider uses. The interface labels it correctly for the active provider.

SerpApi serves cached results for an hour and those are free but stale. Since
this is a monitor, `serpapi_no_cache` defaults to true so every check is fresh.
Set it to false if you would rather save searches than have current data.

Tracking a keyword on both devices doubles its figure. Set
`monthly_credit_ceiling` in the config; the worker stops schedules rather than
crossing it. A schedule that fails 5 checks in a row stops itself.

## Security

- Username and password required for every page, including the JSON endpoints.
- Passwords stored with `password_hash`.
- Failed logins are throttled in the database, keyed on IP and username, so
  discarding cookies does not get around it. Five failures for one username in
  15 minutes locks that username; 15 from one IP locks the address.
- CSRF tokens on every state changing form.
- All output escaped.
- `.htaccess` in the root, `inc/`, `data/` and `assets/`. These block the
  config, the CLI scripts, logs, CSV data and dotfiles, disable directory
  listing, force HTTPS, and stop anything in `assets/` from executing.
  If your host has `AllowOverride None`, `.htaccess` is ignored, which is why
  the config can live outside the webroot instead.

## The one thing to verify after the first check

The parser has to tell top ads from bottom ads. It checks several likely field
names and records which one it used. After the first successful check, click
**raw** on any run. That page lists the response keys and the field names inside
the ads array. Find whichever field marks placement and add its name to
`$blockKeys` in `inc/scrapingdog.php`.

If the tracker page shows an amber warning saying everything was recorded as top
of page, that is this situation and the bottom block data is not trustworthy
until you fix it.

## Small towns

The bundled location list covers US cities above roughly 15,000 people, every
state, and the country. Smaller towns are not in it. Peru, Indiana, for example,
is missing. For full coverage including small towns, counties, neighborhoods,
postal codes and airports:

1. Download the latest zipped CSV from
   https://developers.google.com/google-ads/api/data/geotargets and unzip it
2. Upload it to the server
3. Over SSH: `php seed_locations.php /path/to/geotargets.csv US`

Use `all` instead of `US` for every country. Safe to re-run.

## Files

```
config.example.php      copy to config.php, holds all secrets
install.php             creates tables and your account, delete after use
login.php / logout.php  username and password sign in, throttled
index.php               client overview
client.php              one client's keywords, bulk actions, add form
admin.php               access console: who sees what, plus client management
users.php               accounts, administrators only
upgrade.php             CLI, migrates an existing install in place
tracker.php             ad blocks, consistency table, presence grid, history
action.php              POST handler for every action
status.php              JSON status for the live "checking now" display
locations.php           JSON autocomplete for the location field
raw.php                 raw payload inspector
cron_setup.php          cron diagnostics and command generator, delete after use
cron_check.php          minimal cron probe, runs on any PHP version, delete after use
cron.php                token protected HTTP trigger, for hosts without working cron
manage.php              CLI, list, start and stop schedules in bulk by filter
inspect_run.php         CLI, shows what the API returned for a stored run, costs nothing
test_api.php            CLI, one live API call, --compare pits location against uule
test_params.php         CLI, changes one request parameter at a time to find what kills ads
test_repeat.php         CLI, repeats one request to detect intermittent ads or locations
test_provider.php       CLI, one live call through the configured provider, run this first
worker.php              cron worker, CLI only
seed_locations.php      optional Google geo targets importer, CLI only
inc/bootstrap.php       config, database, session, CSRF
inc/provider.php        dispatches to the configured provider
inc/dataforseo.php      DataForSEO client and parser, Standard queue
inc/serpapi.php         SerpApi client and parser, engine=google_ads
postback.php            where DataForSEO delivers finished checks
inc/scrapingdog.php     ScrapingDog client and parser
inc/scheduler.php       interval maths, credit guards, run, diff, live status
inc/geo.php             location seeding, importing and search
inc/layout.php          page chrome
assets/app.css          styles
assets/app.js           autocomplete and live status polling
data/locations-seed.csv bundled US location list
```

## Notes

- All timestamps are stored in UTC and displayed in each schedule's timezone.
- Raw payloads live in the database and are pruned after
  `raw_retention_days`. At hourly cadence they are the only table that grows
  quickly.
- To add another user, insert a row into `users` with a `password_hash` value.
