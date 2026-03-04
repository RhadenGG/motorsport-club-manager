# Motorsport Club Manager — Context

## Project Overview
A WordPress plugin developed for **kznrrc.co.za** to manage motorsport events, participant vehicle garages, event registrations, and race results.

## Technical Stack
- **WordPress Plugin API** (PHP)
- **Custom Post Types:** `msc_event` (Racing Events), `msc_vehicle` (Member Garage)
- **Custom Tables:** 
    - `{prefix}msc_registrations`: Stores entrant details, vehicle selection, and indemnity status.
    - `{prefix}msc_event_results`: Stores lap times and finishing positions.
- **Frontend:** Vanilla CSS (`msc-` prefixed) and JS (jQuery).
- **Libraries:** `signature_pad` (CDN) for indemnity signing; custom `MSC_PDF` for A4 PDF generation.

## Version Control & Environment
- **Repository:** `https://github.com/RhadenGG/motorsport-club-manager`
- **Default Branch:** `main`
- **Authentication:** Configured with a Personal Access Token (PAT) in the remote URL for seamless CLI access.

## Core Architectural Mandates
- **Centralized Metadata:** 
    - `MSC_Taxonomies::get_vehicle_types()`: Returns the official supported types (`Car`, `Motorcycle`).
    - `MSC_Taxonomies::get_hardcoded_classes()`: Returns the official list of classes grouped by type.
    - Always use these methods in both Admin (`class-admin-*`) and Frontend (`class-account.php`, `class-shortcodes.php`) to maintain synchronization.
- **Event Lifecycle:** Use `MSC_Results::is_closed($event_id)` to check if an event is completed. Closing an event locks registrations and enables result displays.
- **Indemnity Method:** The `indemnity_method` in the registration table MUST be `'signed'` (for electronic signatures) or `'bring'` (for manual physical copies).

## Recent Major Changes
- **Legal Compliance:** Enhanced the registration system to handle minors (under 18). Added mandatory fields for **Emergency Contacts** and **Parent/Guardian signatures** (dual signature capability).
- **Dynamic Branding:** Refactored PDF and email generation to dynamically pull the **WordPress Site Logo** and **Site Title**, replacing hardcoded club branding with a professional, portable design.
- **Form UX:** Reordered the registration process to present the Indemnity Declaration first. Integrated automatic participant name retrieval from user profiles.
- **Database Architecture:** Expanded `{prefix}msc_registrations` with columns for emergency contacts, minor status, and parent signatures. Fixed `dbDelta` formatting for reliable table updates.
- **Restructuring:** Moved all source files from `motorsport-club/` to the root for a flatter plugin structure.
- **Bug Fixes:** Resolved multiple database insertion errors (missing columns, null status) and fixed signature pad initialization for hidden elements.
- **Cleanup:** Removed redundant PDF template upload options from the event editor.

## Key Files
- `motorsport-club.php`: Main initialization and inclusion of core classes.
- `includes/class-taxonomies.php`: The "Source of Truth" for vehicle classifications.
- `includes/class-registration.php`: Core AJAX registration logic and validation.
- `includes/class-indemnity.php`: PDF construction and email delivery.
- `includes/class-results.php`: Logic for closing events and recording/displaying race results.
