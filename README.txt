ADRECON — Competitive Spy Intelligence (short-term PHP build)
=============================================================
Scope: AD-TRACKING only, on PHP/MySQL, moving to your own DigitalOcean droplet
at getadrecon.com. Reputation & competitor-sentiment go into Serpulix later.

BUILD ORDER (easy -> hard; each phase ships on its own):
  1. UI reskin          (front-end only, biggest visual win, do first)
  2. Frequency limits   (allowed frequencies per agency/account)
  3. Cap + auto-pause   (pause an agency at its monthly search cap)
  4. Cost rates         (per-agency/account, superadmin-only, hidden from single-site users)
  5. Scan lifecycle     (auto-decay: 60d -> weekly, 90d -> pause, do LAST)

FILES
  adrecon-prototype.html ...... The UI target. Rebranded Adrecon, One-Click Spy kept,
                                Alerts removed. Includes agency Usage & limits (cap +
                                auto-pause + allowed frequencies) and per-account
                                limits + Scan lifecycle (Renew resets the clock).
  UI_REBUILD_STEPS.md ......... The build order above, spelled out phase by phase, so
                                the dev does the easy UI first and the scheduler logic last.
  DIGITALOCEAN_MIGRATION.md ... Move the PHP/MySQL app to a DO droplet (DNS, SSL, cron, backups).

DO IN THIS ORDER: migrate to droplet -> reskin UI (Phase 1) -> then Phases 2-4 -> give to Wheeler.
