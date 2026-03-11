# Motorsport Club Manager - Gemini CLI Guidance

This file provides foundational mandates and project context for Gemini CLI.

## Project Overview

An open-source WordPress plugin for motorsport event management. It handles events, dynamic vehicle classes, member vehicle garages (with photos), event registrations (with electronic signatures, minor support, and PoP uploads), race results (with podiums), and PDF/email notifications.

## Architecture & Responsibilities

### Core Plugin
- **`motorsport-club.php`**: Entry point. Defines constants, requires classes, and initializes them via `msc_init()`.
- **`MSC_Activator`**: Handles DB migrations on activation and version bumps.

### Data Model
- **`MSC_Post_Types`**: Registers `msc_event` and `msc_vehicle` CPTs.
- **`MSC_Taxonomies`**: Manages `msc_vehicle_class` taxonomy. Vehicle classes are dynamic and grouped by "Car" or "Motorcycle".
- **`MSC_Results`**: Manages `{prefix}msc_event_results` table. Handles race classification, lap times, and podium logic.

### Member Management
- **`MSC_Account`**: Member dashboard. Handles garage (AJAX add/edit/delete vehicles), profile editing (personal/motorsport/medical details), and profile photo uploads.
- **`MSC_Security`**: Email verification flow, login page branding, and first-login onboarding redirect.

### Event Registration
- **`MSC_Registration`**: AJAX flow for event entry. Validates vehicle eligibility, calculates minor status, handles PoP PDF uploads, and records electronic signatures.
- **`MSC_Indemnity`**: Generates signed PDF indemnity forms. Supports parent/guardian signatures for minors.
- **`MSC_Emails`**: Sends HTML notifications with PDF attachments.

### UI & Utilities
- **`MSC_Shortcodes`**: `[msc_events_list]`, `[msc_register_event]`, `[msc_my_account]`.
- **`MSC_PDF`**: FPDF-based custom PDF generator (no external dependencies).
- **`frontend.js`**: Multi-step registration, AJAX garage management, and signature pad integration.
- **`frontend.css`**: All frontend styling (prefixed with `msc-`).

## Key Conventions

- **Prefix**: All hooks, options, meta keys, and CSS classes must use the `msc_` or `msc-` prefix.
- **Database**: Use `dbDelta` for schema changes in `MSC_Activator` or specific classes (like `MSC_Results`).
- **Security**: 
    - Always use nonces for AJAX and form submissions.
    - Validate file uploads (size and type) on the server side.
    - Ensure access control for PDF downloads and private data.
- **Date/Time**: Use `time()` and `gmdate('Y-m-d H:i:s')` for UTC timestamps. Use `date_i18n()` for localized display.

## Recent Major Updates (v0.5.x)
- Dynamic vehicle classes via taxonomy (stored as IDs in registrations for data integrity).
- Race results module with podium display and class-specific classification.
- Enhanced profile photo and vehicle photo support.
- Mandatory Proof of Payment (PoP) PDF upload for paid events.
- Built-in SMTP configuration and customizable email sender details.
- Configurable "Custom Declarations" (mandatory checkboxes) for registration.
- Per-event indemnity text overrides.
- Onboarding flow for new members and security hardening.

## Deployment & Versioning
- Update `MSC_VERSION` in `motorsport-club.php` (header and define) to trigger migrations.
- No build step required; pure PHP/JS/CSS.
