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
- **Repository:** `https://git.trevorbotha.net/trevor/motorsport-club-manager`
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
- **Documentation:** Consolidated all plugin documentation into a single root `README.md` and removed the redundant duplicate from the `motorsport-club/` directory.
- **Bug Fix:** Resolved a critical typo in `MSC_Registration::ajax_submit` (`'sign'` -> `'signed'`) that blocked indemnity emails.
- **Cleanup:** Purged redundant `class-msc-` prefixed files from the `includes/` directory.
- **Refactoring:** Centralized all vehicle type and class hardcoding into `MSC_Taxonomies` and updated all referencing files (Admin Garage, Admin Events, Shortcodes, and Account Dashboard).
- **Licensing:** Updated the plugin to be open source under the **GNU General Public License v2.0 or later (GPLv2+)**. Added a `LICENSE` file and updated plugin headers.
- **Metadata Update:** Reset the version to **0.1.0**, updated the author to **Trevor Botha**, and set the official URI to `trevorbotha.net`.
- **Git Setup:** Initialized repository, created `.gitignore`, and pushed the documentation cleanup and project state to the remote server.

## Key Files
- `motorsport-club.php`: Main initialization and inclusion of core classes.
- `includes/class-taxonomies.php`: The "Source of Truth" for vehicle classifications.
- `includes/class-registration.php`: Core AJAX registration logic and validation.
- `includes/class-indemnity.php`: PDF construction and email delivery.
- `includes/class-results.php`: Logic for closing events and recording/displaying race results.
