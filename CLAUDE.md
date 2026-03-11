# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

An open-source WordPress plugin for motorsport event management. It handles events, member vehicle garages, event registrations with electronic indemnity signing, EFT proof-of-payment uploads, race results, PDF/email notifications, email verification, custom profile photos, and login page branding.

## Development Commands

There is no build system, test suite, or package manager. This is a pure PHP/JS WordPress plugin.

To deploy, copy the plugin folder to a WordPress installation's `/wp-content/plugins/` directory and activate it.

To test changes locally, load the plugin in a WordPress environment. Database migrations run automatically on every WordPress `init` hook when `msc_db_version` option differs from `MSC_VERSION`.

## Architecture

### Entry Point & Initialization

`motorsport-club.php` defines constants (`MSC_VERSION`, `MSC_PATH`, `MSC_URL`, `MSC_BASENAME`), requires all class files, and initializes every class via static `::init()` calls inside `msc_init()` (hooked to `plugins_loaded`).

### Class Responsibilities

- **`MSC_Activator`** — `activate()` creates/updates DB tables via `dbDelta`. Runs on activation and also on every page load when the stored `msc_db_version` option doesn't match `MSC_VERSION`.
- **`MSC_Post_Types`** — Registers `msc_event` and `msc_vehicle` custom post types.
- **`MSC_Taxonomies`** — Single source of truth for vehicle classifications. Registers the `msc_vehicle_class` taxonomy (shared between `msc_vehicle` and `msc_event` post types) with `msc_vehicle_type` term meta (Car/Motorcycle). Classes are fully dynamic — managed via admin UI under Motorsport Club → Vehicle Classes. Provides `get_classes_by_type()` for grouped class lists and `seed_defaults()` for fresh installs.
- **`MSC_Admin_Events`** — Meta boxes for the `msc_event` editor (date, location, fee, capacity, registration window, indemnity text, allowed classes).
- **`MSC_Admin_Garage`** — Admin UI for viewing/editing member vehicles; `get_user_vehicles_for_event()` filters a user's vehicles to only those whose class matches the event's allowed classes.
- **`MSC_Registration`** — AJAX handlers for `msc_submit_registration`, `msc_get_vehicles`, and `msc_cancel_registration`. Validates signatures, minor status, emergency contacts, and PoP upload before inserting into `{prefix}msc_registrations`.
- **`MSC_Indemnity`** — Serves PDF indemnity forms at `?msc_indemnity_pdf={id}` via `template_redirect`. Access-controlled to participant, event author, or admin. Uses `MSC_PDF` for generation.
- **`MSC_Emails`** — Sends HTML emails on registration submission and status confirmation, attaching the indemnity PDF and PoP file.
- **`MSC_Shortcodes`** — Registers `[msc_events_list]`, `[msc_register_event]`, and `[msc_my_account]` shortcodes. Also auto-appends event meta, registration form, and results to single `msc_event` posts via `the_content` filter. Enqueues `signature_pad` from CDN, `assets/js/frontend.js`, and `assets/css/frontend.css`.
- **`MSC_Account`** — Member dashboard for garage management (add/edit/delete vehicles with photo upload), registration history, profile editing (personal details, emergency contacts, password), and custom profile photo upload/remove.
- **`MSC_Security`** — Email verification flow (token-based with nonce-protected resend), login page branding (site logo on login/register/password pages), admin profile fields (birthday, competition number, MSA licence, medical aid, gender), and first-login onboarding redirect.
- **`MSC_Results`** — Manages the `{prefix}msc_event_results` table. `is_closed($event_id)` controls the event lifecycle: closed events lock registrations and enable the results entry interface.
- **`MSC_PDF`** (`includes/lib/class-msc-pdf.php`) — Custom PDF generator with no external PHP extension dependencies.

### Database Tables

- `{prefix}msc_registrations` — Stores registration records including indemnity signatures (base64 data URLs in `indemnity_sig` / `parent_sig`), emergency contacts, PoP file ID, and payment status.
- `{prefix}msc_event_results` — Stores per-registration race results (position, lap times, status enum: Finished/DNF/DNS/DSQ).

### Frontend (`assets/js/frontend.js`)

Handles the multi-step registration form: vehicle selection via AJAX, real-time validation, `signature_pad` integration for electronic signatures, and PoP PDF upload. The submit button is only enabled when all required fields (emergency contacts, indemnity signature, PoP if fee > 0, and parent/guardian fields for minors) are completed.

### Key Conventions

- All CSS classes are prefixed `msc-`.
- All WordPress hooks, options, meta keys, and nonces use the `msc_` prefix.
- Vehicle meta keys: `_msc_make`, `_msc_model`, `_msc_year`, `_msc_type`, `_msc_reg_number`.
- Event meta keys: `_msc_event_date`, `_msc_event_end_date`, `_msc_event_location`, `_msc_entry_fee`, `_msc_capacity`, `_msc_event_status` (value `closed` locks the event).
- AJAX nonce: `msc_nonce`.
- User meta keys: `msc_birthday`, `msc_emergency_name`, `msc_emergency_phone`.

### Context Files

Never push `CLAUDE.md` or `GEMINI.md` to the remote repository.

### Version Bumping

Update `MSC_VERSION` in both the plugin header comment and the `define('MSC_VERSION', ...)` line in `motorsport-club.php`. The migration hook uses this to re-run `MSC_Activator::activate()` on next page load.
