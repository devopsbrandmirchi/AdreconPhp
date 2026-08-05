ADRECON — What this application is for
======================================

Adrecon is a competitive Google Ads spy tool (PHP + MySQL).

In plain terms:
  You pick keywords and locations (e.g. "polaris dealer" in Lakeland, FL).
  On a schedule the app asks Google what paid ads appear for that search.
  It stores who advertised (domain, headline, position: top/middle/bottom).
  Over many checks you see which competitors show up often, and how consistently.

Words used in the UI:
  Agency   = the company/contract you work under
  Client   = one business you track for (e.g. McKibben)
  Website  = that client's domain
  Keyword  = one search term + one location + one device (= a "tracker")
  Check    = one look at Google (one run) — this is what costs money via SerpApi/etc.

It does NOT do reputation / sentiment. That stays for Serpulix later.
This build is ad-tracking only.

---------------------------------------------------------------------
Import remaining dump data (you already did users + trackers)
---------------------------------------------------------------------

Use folder: sql_by_table/

phpMyAdmin → select DB → Import → one file at a time, in order:

  1. insert_agencies.sql
  2. insert_clients.sql
  3. insert_locations.sql
  4. insert_sites.sql
  5. insert_runs_part01.sql … part45.sql   (~1.5 MB each, in order)
  6. insert_ad_placements_part01.sql … part03.sql

Details: sql_by_table/README_IMPORT.txt

---------------------------------------------------------------------
Smoke test (proves the app stack is working)
---------------------------------------------------------------------

1. Start the app (if not already):
     php -S localhost:8000

2. Run:
     php smoke_test.php

3. Read:
     SMOKE_TEST_RESULT.txt

That file records PASS/FAIL for DB connection, table reads, the
keyword→runs→ads path, and login/CSS pages.

---------------------------------------------------------------------
Build order (product work still planned)
---------------------------------------------------------------------

  1. UI reskin          (done / in progress)
  2. Frequency limits
  3. Cap + auto-pause
  4. Cost rates
  5. Scan lifecycle

Prototype UI target: adrecon-prototype.html
Phase checklist:     UI_REBUILD_STEPS.md
