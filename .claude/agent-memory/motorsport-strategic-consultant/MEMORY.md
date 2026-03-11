# Strategic Consultant Memory — Motorsport Club Manager

## Plugin State
- Current version: 0.5.3 (reviewed 2026-03-06)
- Open-source, no build system, no tests, pure PHP/JS WordPress plugin
- Foundation is solid: security hardened (v0.4.3+), taxonomy-driven classes, clean class separation

## Key Patterns
- Version bump: TWO edits in `motorsport-club.php` — plugin header comment + `define('MSC_VERSION', ...)`
- All classes use static `::init()`, hooked to `plugins_loaded` via `msc_init()`
- Never push `CLAUDE.md` or `GEMINI.md` to remote
- Commit format: `Bump version to X.Y.Z: Short description`
- All AJAX uses `check_ajax_referer('msc_nonce','nonce')`

## Architecture Notes
- `MSC_Taxonomies` is single source of truth for vehicle types/classes via `msc_vehicle_class` taxonomy with `msc_vehicle_type` term meta
- Vehicle types are still hardcoded as `['Car', 'Motorcycle']` in `get_vehicle_types()` — known limitation
- `MSC_Security` handles: email verification, login branding, admin profile fields, onboarding redirect
- `MSC_Account` handles: frontend dashboard (garage/registrations/profile tabs), AJAX CRUD
- `MSC_Results::is_closed()` is the single gatekeeper for event lifecycle — checks `_msc_event_status === 'closed'`
- Event lifecycle is currently binary: `open` or `closed` — no operational states in between
- Frontend JS uses safe jQuery DOM builders (not `.html()` with string concatenation)

## Known Bugs / Dead Code (identified in v0.5.3 review)
- Per-event indemnity text: `_msc_indemnity_text` is saved in `save_meta()` and read in `meta_box_details()` but the textarea field is NEVER RENDERED in the meta box. The shortcode reads only from the global option, not from post meta. Dead code path — needs the textarea added back to `meta_box_details()` and the shortcode updated to check post meta first.
- `indemnity_method = 'bring'`: hardcoded to `'signed'` in `frontend.js` line 170. The DB column, admin display, and email template still reference 'bring' but it can never be set by a new registration. Half-removed feature.
- Vehicle class stored as term NAME string in `_msc_event_classes` meta (not term ID). Renaming a class silently breaks event-vehicle matching.

## Critical Race-Day Gaps (v0.5.3 audit)
1. No scrutineering workflow — no scrutineering_status column or UI
2. No competitor arrival/check-in tracking — no arrived_at column
3. Event lifecycle too simple — needs: open, entries_closed, in_progress, results_pending, closed
4. Results model too thin — no session_name (for qualifying/heats/finals), no vehicle_class denorm
5. No bulk status actions on registrations page
6. Registrations admin page has no pagination — N+1 query problem in events list shortcode too

## Performance Issues
- `events_list()` shortcode: ~6 DB queries per event in loop (get_post_meta x3, get_the_terms, is_closed, reg count) — ~60 queries for 10 events
- `registrations_page()`: no LIMIT on query — will time out at scale
- Dashboard "Pending Approval" count: all-time across all events, not filtered to upcoming

## v0.6.0 Recommended Priorities (from strategic review)
1. Scrutineering status field + tablet-optimised admin view (highest race-day impact)
2. Arrival/check-in tracking (safety + grid management)
3. Event lifecycle states (enables above features)
4. Fix per-event indemnity text (20-line bug fix)
5. Bulk status actions on registrations page
6. Rejection reason field + email notification on rejection
7. Pagination on registrations page

## Vision Features (v0.7.0+)
- QR code paddock check-in via signed token in confirmation email
- Digital scrutineering checklist (per-item pass/fail, configurable items)
- Session-based results (qualifying, heats, finals) — needs schema change first
- Season championship points table (`msc_championship` CPT)
- SSE-based live results display (no WebSocket infrastructure needed)
- MSA licence expiry tracking and format validation

## UX Assessment
- Frontend CSS is polished and above-average for a WP plugin (dark theme, race-red accent, responsive)
- Admin is raw WordPress meta box implementation — needs an event-specific "operations view"
- Immediate wins: print-ready scrutineering sheet (`@media print` CSS only), AJAX status updates in admin table, event-filtered dashboard metrics

## DB Schema
- `{prefix}msc_registrations`: id, event_id, user_id, vehicle_id, status, entry_fee, fee_paid, indemnity_method, indemnity_full_name, is_minor, parent_name, parent_sig, emergency_name, emergency_phone, indemnity_sig, indemnity_date, created_at, notes, pop_file_id
- `{prefix}msc_event_results`: id, event_id, registration_id, position, laps_completed, best_lap_time, total_race_time, status (ENUM Finished/DNF/DNS/DSQ), notes, created_at, updated_at
- Missing columns for roadmap: scrutineering_status, arrived_at, briefing_confirmed_at, rejection_reason, fee_paid_at, fee_paid_by, session_name (results table)

## Security Audit (v0.4.3)
- All AJAX endpoints have nonce checks
- File uploads validated server-side with `wp_check_filetype_and_ext()`
- Vehicle ownership uses strict `(int)$post->post_author !== $user_id`
- Parameterised queries with format specifiers throughout
