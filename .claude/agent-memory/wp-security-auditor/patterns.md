# Security Patterns — motorsport-club-manager

## Audit v0.5.7 — 2026-03-06

### CRITICAL Findings

**1. Unparameterized SQL in registrations_page()**
File: includes/class-admin-events.php
Function: registrations_page()
Pattern: $where built via string concat then interpolated into $wpdb->get_results("... WHERE $where ...")
Fix: Build array of ($clause, $arg) pairs, call $wpdb->prepare() on full assembled SQL

**2. Plaintext SMTP Password Storage**
File: includes/class-admin-events.php settings_page() + includes/class-emails.php configure_smtp()
Pattern: update_option('msc_smtp_pass', sanitize_text_field(...)) then $phpmailer->Password = get_option('msc_smtp_pass')
Fix: Encrypt before DB storage, or define as PHP constant in wp-config.php

**3. DOM XSS via .html(res.data.message)**
File: assets/js/frontend.js line 191
Pattern: $('#msc-reg-wrap').html('<div>...' + res.data.message + '</div>')
Fix: Use .text() or DOM element creation, never .html() with server-supplied strings

### HIGH Findings

**4. Client-trusted class_id in ajax_submit()**
File: includes/class-registration.php
Pattern: $class_id = intval($_POST['class_id'] ?? 0) stored directly
Fix: Derive class_id server-side from wp_get_post_terms($vehicle_id, 'msc_vehicle_class')

**5. Class name/ID mismatch between JS and PHP**
File: frontend.js populateClassDropdown() sends name strings; ajax_add_vehicle() receives as class_id
Pattern: $opt.val(name) in JS; wp_set_post_terms($id, array(sanitize_text_field($_POST['class_id'])), taxonomy) in PHP
Fix: Send integer term IDs from JS, use absint() on PHP side

**6. Registration PDF ID enumeration**
File: includes/class-indemnity.php maybe_output_pdf()
Pattern: Different error messages for "not found" vs "no permission" reveal whether reg ID exists
Fix: Return identical generic message for both failure cases

**7. Implicit CSRF reliance in save_birthdate_field()**
File: includes/class-security.php
Pattern: Relies on WP core nonce from profile form, does not call check_admin_referer() explicitly
Fix: Add check_admin_referer('update-user_' . $user_id) at top of function

**8. msc_event_vehicle_type not allowlist-validated**
File: includes/class-admin-events.php save_meta()
Pattern: $vehicle_type = sanitize_text_field($_POST['msc_event_vehicle_type']) with no allowlist check
Fix: in_array($vehicle_type, array('Both','Car','Motorcycle'), true) with fallback

### wp_unslash() Missing Locations (consistent finding)
- class-admin-events.php save_meta(): foreach fields
- class-admin-garage.php save_meta(): foreach fields
- class-security.php save_birthdate_field(): text_fields loop
- Pattern: sanitize_text_field($_POST[$f]) without wp_unslash()
- Correctly done: settings_page() uses wp_unslash() before sanitize

### Output Escaping Gaps (consistent finding)
- get_permalink() not wrapped in esc_url() in class-shortcodes.php events_list()
- admin_url() not wrapped in esc_url() in dashboard_page()
- Inline style values echoed without esc_attr() (color hex codes from arrays)
- $icon (emoji from hardcoded array) echoed without esc_html()

### Performance
- N+1 query in events_list(): one COUNT(*) per event in loop
- registrations_page() has no LIMIT — full table scan on every admin load
- msc_run_migration() runs on every init hook for all users, not gated

### Infrastructure
- signature_pad CDN script loaded without SRI integrity hash
- class-msc-pdf.php required twice (motorsport-club.php + class-indemnity.php)
- Short array syntax [] in class-results.php, array() everywhere else
