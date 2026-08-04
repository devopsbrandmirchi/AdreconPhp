# Adrecon — UI Rebuild & Build Order

Goal: make the live PHP tool **look like `adrecon-prototype.html`** and add the new guardrails. This is a **reskin, not a rewrite** — the backend and database stay; you're swapping the front-end and adding a bit of scheduler logic.

**Do it in the order below — easiest and lowest-risk first.** Each phase is independently shippable, so you get value early and never have to touch the hard backend logic before the UI is done and live.

---

## Build order (do them in this sequence)

| # | Phase | What it is | Effort | Ship on its own? |
|---|-------|------------|--------|------------------|
| **1** | **UI reskin** | Front-end only — make the pages look like the prototype. No backend changes. | Easy | ✅ Yes — biggest visual win, ship first |
| **2** | **Frequency limits** | Restrict which scan frequencies are selectable, per agency/account. | Small | ✅ Yes |
| **3** | **Monthly cap + auto-pause** | Pause an agency's scans when it hits its monthly search cap. | Medium (scheduler) | ✅ Yes |
| **4** | **Cost rates + visibility** | Per-agency/account cost-per-search (superadmin-set); show $ to agency owners, hide from single-site users. | Small–medium | ✅ Yes |
| **5** | **Scan lifecycle (auto-decay)** | After 60 days step down to weekly; after 90 days pause until renewed. | Most backend — **do last** | ✅ Yes |

> Don't start on Phase 3 or 4 before Phase 1 is done and live. The UI is pure front-end and carries no risk; the guardrails are backend logic and should come once the visible part is shipped.

---

## Phase 1 — the UI reskin (do this first)

The prototype (`adrecon-prototype.html`) is one self-contained file with every screen and all the styling. Copy its look and structure into the PHP templates, then feed real DB rows into the same markup.

**1a. Lift the design system (once).** At the top of the prototype is a `<style>` block — CSS variables plus component classes (`.card`, `.chip`, `.stat`, `.tabs`/`.tab`, `.btn`, `.matrix`, `.bar`, heatmap cells, `.qbar`, `.cpick`). Copy it into your app's stylesheet and include it everywhere. Brand tweaks then live in the variables at the top.

**1b. Map each PHP page to a prototype screen:**

| Current PHP page | Prototype screen | Key classes |
|---|---|---|
| Clients list | Agency → clients table | `.ctable`, `.stat` |
| A client's page | Account dashboard (tabbed) | `.tabs`, `.stat`, `.card`, `.matrix` |
| Keyword result page | Result detail (tabbed) | `.tabs`, heatmap table, `.lb` |
| Add keywords | The two-mode builder | `.seg`, `.lib-cl`, `.libitem`, chips, sticky summary |

**1c. Rebuild each page's HTML** to match: copy the prototype's block, then replace demo values with PHP variables and `foreach` your DB rows into the same `<tr>`/card structure. Start with the **client dashboard** — biggest visual win.

**1d. Wire the tabs** — copy the small JS at the bottom of the prototype (`clientTab()`, `resultTab()`, `show()`); it's just show/hide by a data attribute.

**1e. Two UI concepts that need a light DB touch:**
- **Clusters** — group keywords by intent cluster (or "No cluster"). If `keywords` has no cluster, add a `cluster` column (or `clusters` table + `cluster_id`) and group by it. Default new keywords to "No cluster".
- **One-Click Spy** — the setup hero (business name/website → "Spy this business"). Full version calls an LLM to read the site and pre-fill clusters/competitors/areas; until that's wired, the button just reveals the manual builder below it (already degrades gracefully).
- **The Add-keywords builder is the screen your team uses most — build it exactly like the prototype.** It has: a *From library / Add your own* toggle; a keyword **library grouped by cluster** with checkboxes (this is your vertical keyword pack — store it as clusters + keywords the team picks from, and let them type custom terms into a chosen cluster); a **locations** box with an "add 4 nearby towns" helper; and a **sticky live summary** that shows `keywords × locations = X checks/run` and the projected searches/month, updating as they click. The keyword-list × location-list cross-product is the whole point — it's how "3 keywords in 5 locations" becomes 15 checks in one action.

That's Phase 1. Ship it — the tool already looks like a real product at this point.

---

## Phase 2 — frequency limits

Store an **allowed-frequency set** on the agency (e.g. `["3h","6h","daily","weekly"]`) and, optionally, a narrower set on each account. Then the **"Check every" dropdown** in the setup flow shows only the account's allowed frequencies.

- Add `allowed_frequencies` to the agency, and an optional `allowed_frequencies` on the account.
- Rule: an account's set can only be **equal to or tighter** than its agency's — never broader.
- In the setup form, build the dropdown from that set (so "1 hour" simply isn't an option for an agency that disabled it).

Small, self-contained, and shippable on its own.

---

## Phase 3 — monthly cap + auto-pause

Give each agency a `monthly_search_cap` and an `auto_pause_at_cap` flag. In the scan cron, **before running due targets**, sum the agency's month-to-date searches; if it's at/over the cap and auto-pause is on, set every target for that agency to `paused` and stop.

- Fields: `agencies.monthly_search_cap`, `agencies.auto_pause_at_cap` (and optionally `accounts.monthly_cap` for a per-account limit).
- This is the runaway-spend guardrail — a mistaken "every hour × 500 keywords" hits the cap and pauses instead of draining SerpApi all month.
- Un-pause happens at the next month rollover, or when someone raises the cap.

---

## Phase 4 — cost rates & visibility (superadmin)

Turns the searches count into a dollar figure that only you control. Comes after the UI and the guardrails — not urgent, but easy once the usage math exists.

- **Rate is per-agency, not global.** Store `cost_per_search` on the **agency**, with an optional override on the **account**. Only the **superadmin** can edit it — not agency users, not regular internal team.
- **Dollar cost = searches × rate.** Reuse the searches-per-month math you already have; multiply by the agency's rate (or the account override) to show "$X/mo".
- **Visibility is gated by scope, and enforced server-side:**
  - Superadmin — sees and sets every rate.
  - Agency-scoped session (agency owner) — sees the dollar cost.
  - Account-scoped session (single-site user) — sees usage/searches only. **The cost fields are never sent to their browser** — omit them in the API for account-scoped users; don't just hide them with CSS, or they'd be findable in the page source.
- Reason: if an agency gives a client a login to check their own account, that client shouldn't discover how cheap it is to run.

---

## Phase 5 — scan lifecycle / auto-decay (do this last)

The most backend-heavy piece, and the reason it's last. Each account (or target) gets a lifecycle clock so scans decay automatically instead of running hard forever:

- **Day 0–60 — Intensive:** runs at the chosen frequency.
- **Day 60–90 — Wind-down:** auto-steps to **weekly**.
- **Day 90+ — Paused:** stops until someone renews.

**How to implement:**
- Add `activated_at` (the lifecycle start) plus configurable `stepdown_days` (default 60) and `pause_days` (default 90) — at the agency level as defaults, overridable per account.
- In the scheduler, compute the **effective cadence** from the age (`now − activated_at`):
  - `age ≥ pause_days` → paused.
  - `age ≥ stepdown_days` → weekly (or, if the account doesn't allow weekly, its **loosest allowed** frequency).
  - else → the chosen cadence.
- **Renew/Extend** = set `activated_at = now` (one click resets to Intensive). Editing an account can reset it too, so active accounts never lapse but abandoned ones wind down.
- Since Alerts were removed, keep the "about to pause" nudge lightweight: a status badge like *"Winding down · pauses in 12 days"* and a Renew button on the account (both are in the prototype), plus maybe a simple email — not a whole alerts system.

The cap (Phase 3) stops *runaway* spend; the lifecycle (Phase 4) stops *stale* spend. Together your monthly SerpApi bill becomes predictable — exactly what you want before scaling Wheeler's accounts.

---

## Other important things (don't skip)

- **Use SerpApi, not DataForSEO** (chosen for accuracy). It returns an explicit **`block_position` = "top"/"bottom"** per ad, so read placement directly — no rank math. Match each ad's domain against the client's own domains to mark "you"; tag `shopping_results` as PMax.
- **Store scan results append-only** — insert a new timestamped row each scan, never overwrite. That's what lets you show "how consistent were our ads vs competitors over 6 months" later.
- **The One-Click Spy needs an LLM key** in the server config (kept out of Git).
- **Ad-tracking only.** Reputation / competitor-sentiment go into Serpulix later — not this build.
- **Alerts were removed on purpose** — a later feature, not a launch blocker.

---

### What "done" looks like

On getadrecon.com, the PHP tool renders the client dashboard, cluster view, result detail, and One-Click Spy setup exactly like `adrecon-prototype.html` — on your real data — with frequency limits, the agency cap + auto-pause, and the scan lifecycle all enforced, built in that order.
