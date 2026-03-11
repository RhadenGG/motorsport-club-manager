# Security Auditor Memory — motorsport-club-manager

## Project State
- Current version: 0.6.1 (as of 2026-03-10)
- No build system, no tests — pure PHP/JS WordPress plugin
- See patterns.md for recurring vulnerability patterns

## Completed Audits
- v0.5.7 full audit completed 2026-03-06. See patterns.md for findings.
- v0.5.9.5 full audit completed 2026-03-06. Report: REPORT4_Claude.txt
- v0.6.1 full audit completed 2026-03-10. Report: report_6_v0.6.1_claude.txt (pending)

## Key Architectural Facts
- Entry point: motorsport-club.php; all classes init via static ::init() on plugins_loaded
- AJAX nonce: msc_nonce (key), verified with check_ajax_referer('msc_nonce','nonce')
- DB tables: {prefix}msc_registrations, {prefix}msc_event_results, {prefix}msc_registration_classes (junction)
- class-msc-pdf.php is required in BOTH motorsport-club.php AND class-indemnity.php (redundant)
- msc_run_migration() is now correctly gated by is_admin() — FIXED in v0.6.0
- msc_event_creator role has edit_others_posts — overly broad (persists)
- MSC_Frontend_Dashboard uses inline <script> blocks; nonces emitted without esc_js()

## Fixed Issues (v0.6.1 vs prior audits)
- msc_run_migration() now gated by is_admin() — FIXED
- ajax_set_event_status() now calls can_manage_event() — FIXED
- ajax_update_reg_status() now calls can_manage_event() — FIXED
- ajax_create_event() now validates class_ids against taxonomy and calls wp_set_post_terms() — FIXED
- Open $_GET loop: both $k and $v are now esc_attr()'d — XSS prevented (arbitrary param injection risk remains)

## Known Persisting Issues (v0.6.1)
- sanitize_textarea_field() on base64 sig data corrupts signatures — class-registration.php (MEDIUM)
- wp_unslash() missing before sanitize_text_field() in save_meta() — class-admin-events, class-admin-garage
- Inline style color values echoed without esc_attr() — class-admin-events
- CDN signature_pad script loaded without SRI hash — class-shortcodes.php
- N+1 queries in events_list() shortcode (one COUNT per event in loop)
- SSRF via wp_remote_get() in class-msc-pdf.php image_from_file() — admin-exploitable only
- class-indemnity.php uses old $reg->class_id column, not msc_registration_classes junction table — functional regression
- class-emails.php send_registration_received() also uses old class_id column — functional regression
- nonces emitted into inline <script> without esc_js() — class-frontend-dashboard.php throughout
- ajax_save_results() does not verify registration belongs to stated event_id — IDOR risk (MEDIUM)

## Established Patterns in This Codebase
- Vehicle ownership: (int)$post->post_author !== $user_id (strict cast + strict compare) — CORRECT
- All AJAX handlers use check_ajax_referer('msc_nonce','nonce') at top — CORRECT
- File uploads all use wp_check_filetype_and_ext() on tmp_name — CORRECT
- Status values always whitelisted with in_array($val, $allowed, true) — CORRECT
- SQL always parameterised via $wpdb->prepare() or insert()/update() — CORRECT
- wp_unslash() missing before sanitize_text_field() in save_meta() functions — recurring

## Recurring Anti-Patterns Found
- Open $_GET loops into hidden form inputs (class-frontend-dashboard, class-admin-participants) — now escaped but arbitrary param injection remains
- Inline <script> blocks in shortcode/dashboard output (class-frontend-dashboard throughout)
- PHP values emitted into script context without esc_js() — nonce vars in class-frontend-dashboard
- N+1 queries in loop (events_list, get_participants)
- CDN scripts loaded without SRI hash
- Short array syntax [] in class-msc-pdf.php vs array() elsewhere — inconsistent
