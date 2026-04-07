# Motorsport Club Manager

A WordPress plugin for end-to-end motorsport event management — from event creation and member entries through to race results and document archival. Built for real clubs running live race days.

**Current version:** 0.8.6 | **License:** GPLv2 or later

---

## Features

### Events
- Create events as a custom post type with date/time, location, capacity, entry fee, and an optional registration window (open/close dates).
- Featured image with inline lightbox on event pages.
- Rich event description displayed alongside the featured image on the public event page.
- Manual or automatic entry approval per event.
- Close or reopen events from the staff dashboard; closed events lock new entries and enable results entry.
- Event cards show **"Enter Now"** for unentered visitors and **"View your entry"** for already-entered members.

### Vehicle Classes & Types
- Fully dynamic vehicle class taxonomy (`msc_vehicle_class`) — create, rename, and delete classes from the admin UI under **Motorsport Club → Vehicle Classes** or the **Vehicle Classes tab** of the staff dashboard.
- Each class carries a **Vehicle Type** meta value (Car or Motorcycle), used to filter compatible classes and vehicles per member.
- Classes are grouped by vehicle type in all entry form dropdowns.
- **Per-class entry conditions** — optional declarations attached to a class (managed in wp-admin or the staff dashboard Vehicle Classes tab):
  - **Confirm** — a single checkbox the entrant must tick (e.g., "I accept the supplementary regulations for this class").
  - **Select One** — a radio group; the entrant must choose exactly one option (e.g., tyre specification).
  - **Select Many** — a checkbox group; the entrant must choose at least one option.
- Conditions appear in Step 1 of the entry form as soon as a class is selected, gate the "Next" button until answered, appear in the signed indemnity PDF, the staff entries view, and the entrant's My Entries history.

### Pricing
- **Base fee:** optional fixed amount added to every entry.
- **Pricing Sets:** named fee schedules (e.g., "2026 Season") assignable per event, with per-class configuration:
  - **Primary Fee** — cost when the class is the entrant's main class.
  - **Additional Fee** — cost when added as a secondary class.
  - **Global Override** — allows one class (e.g., Endurance) to override the additional fee for all other classes in the same entry.
  - **Exemption** — specific classes can ignore overrides to maintain a fixed cost (e.g., transponder rentals).
  - **Primary Only** — restrict a class to be selected only as the primary entry.
- A dynamic **"from R X"** price is automatically calculated and displayed on event cards (base fee + cheapest eligible primary class fee).

### Member Entry Flow
- Multi-step entry form with real-time fee calculation as classes are added or removed.
- Classes grouped by vehicle type (Cars / Motorcycles) in all dropdowns.
- Each class (primary and additional) has its own independent vehicle selector, pre-populated from the member's garage.
- Vehicles are identified throughout by **Year Make Model Engine** (e.g. *2007 Subaru Impreza STI 2.5T*) rather than a nickname, across entry tables, the indemnity PDF, results, and confirmation emails.
- Electronic indemnity signing — drawn signature via `signature_pad` or typed name.
- An **"I accept"** checkbox below the indemnity text is required before the Submit button enables; acceptance is also enforced server-side.
- Automatic minor detection: if the member's date of birth indicates they are under 18, mandatory parent/guardian name and signature fields appear.
- Emergency contact, pit crew, and sponsor fields pre-filled from the member's saved profile; any changes made during entry are saved back to the profile.
- **Sponsors:** optional free-text field (max 33 characters) for the entrant to list their sponsors. Appears on the entry form, the member profile, and the indemnity PDF.
- **Proof of Payment (PoP):** PDF, PNG, or JPG upload (up to 5 MB) required when the entry fee is greater than zero. Banking details configured in Settings are shown alongside the upload field.
- Upon confirmation, each entry is assigned a **sequential entry number** scoped to the event, displayed on the indemnity PDF and the member's entries history.
- A sticky **"Submitting your entry…"** banner with a spinner appears on submit and blocks accidental page refresh until the server responds. On a temporary server error (HTTP 503), the form automatically retries once after 5 seconds with a "Server busy, retrying…" status update; if the retry also fails, a clear message explains the server is busy and the entry was not submitted.

### Entry Editing
- Members can add or remove classes and update pit crew names on a pending or confirmed entry from their **My Account** dashboard.
- Editing is locked once the event is closed — the Edit and Cancel buttons are hidden in the UI, and the server rejects any direct POST attempts.
- Rules enforced:
  - No downgrade below the original paid amount.
  - Same or lower total (within rounding) saves immediately.
  - Higher total requires a top-up PoP upload for the difference only.
- The top-up PoP is stored as a separate **PoP 2** file (served via `?msc_pop_file={id}&pop=2`) and shown as a distinct button alongside the original PoP in both the staff dashboard and wp-admin entries table, so organisers can review both payments before confirming an entry.
- If a **confirmed** entry is edited, it is automatically reset to **pending** and must be re-approved by the organiser.
- Vehicle assignments per class are preserved across edits; only newly added classes fall back to the primary vehicle.

### Document Security
- **Indemnity PDFs** are generated on-demand from stored data — nothing is written to disk permanently. Served via `?msc_indemnity_pdf={id}` with access restricted to the entrant, event creator, and admins/organisers.
- **PoP files** are stored in a protected subdirectory (`wp-content/uploads/msc-pop/`) with an `.htaccess` blocking direct web access. Served via `?msc_pop_file={reg_id}` with the same access control. Links in both wp-admin and the staff dashboard use this endpoint.
- On registration, the signed indemnity PDF is emailed to the entrant; the admin/event creator receives both the indemnity PDF and PoP as attachments.

### Member Account & Profile
- **My Account** (`[msc_my_account]`) — member dashboard with:
  - **Garage:** add, edit, and delete vehicles with make, model, year, race number, class, and photo.
  - **My Entries:** history of all entries with status badges, entered class names, and view links for indemnity PDFs and PoPs.
  - **Profile:** edit personal details (first/last name, phone, address, gender, date of birth), motorsport info (MSA licence, medical aid), emergency contact, pit crew names, and sponsors.
  - **Profile photo:** upload, preview, and remove a custom photo.
  - **Logout button** at the bottom of the dashboard for easy sign-out.
- Role display on the profile card (Subscriber → Guest, msc_event_creator → Event Creator, msc_class_rep → Class Rep, Administrator → Admin).

### Custom Auth Flow
- Styled shortcode pages for login (`[msc_login]`), registration (`[msc_register]`), and password setup (`[msc_set_password]`).
- Registration sends an email verification link; verified users are redirected to the set-password page to choose their password before being redirected to login.
- First-login onboarding redirect prompts new members to complete their profile.
- Compatible with Cloudflare Turnstile / CAPTCHA.

### Staff Dashboard
- Unified frontend staff tool via `[msc_event_dashboard]` — accessible to administrators, `msc_event_creator`, and `msc_class_rep` roles (class reps see the Entries tab only, in read-only mode).
- **Events tab:** list all events, create new events (inline form with all meta fields), close/reopen events, and navigate to the entries or results for a specific event.
- **Entries tab:** filter by event, status, and/or one or more classes (checkbox dropdown — no Ctrl+click required); each entry shows all class/vehicle pairs as separate rows with dedicated **Class**, **Vehicle**, and **Race #** columns; entrant's **Full Name** (first + last) shown as primary identifier; **Phone** and **Sponsors** (displayed as pills) visible per entrant; update individual entry status (pending → confirmed / rejected / cancelled) via inline AJAX selects; **Mark Paid** toggle button per entry (AJAX, no reload); bulk status updates with a select-all checkbox; rejection and cancellation emails dispatched automatically; CSV export (one row per class/vehicle pair, includes Full Name, Phone and Sponsors columns, Race # aligned with its vehicle). Class reps see all events' entries but have no status controls, bulk bar, or Actions column.
- **Results tab:** select a closed event and enter results per class — position, lap time, and status (Finished / DNF / DNS / DSQ) for both registered entrants and manually added walk-in drivers.
- **Participants tab:** searchable CRM table of all members; **First Name / Last Name** shown as primary identifier (username as secondary); expandable rows show personal details, motorsport credentials, and emergency contact information.
- **Vehicle Classes tab:** create and manage vehicle class taxonomy terms and their vehicle type assignments.
- **Pricing tab:** create and manage Pricing Sets and configure per-class fees.

### Race Results
- Results stored per registration with position, lap time, and status enum.
- Per-class podium display (Top 3 finishers) on the public event page.
- Manual driver entries (name + vehicle description) for walk-ins not in the system.

### Admin (wp-admin)
- **Motorsport Club → Events:** event list with per-event entries sub-pages, paginated at 50 rows; each entry shows Class, Vehicle, Race #, Phone, and Sponsors as separate columns with multi-vehicle sub-rows; inline status update, paid checkbox, PoP view, and indemnity PDF links.
- **Motorsport Club → Participants:** same expandable CRM view as the frontend dashboard; First/Last name shown as primary identifier.
- **Motorsport Club → Vehicle Classes:** taxonomy term management.
- **Motorsport Club → Settings:** banking details, auth page URLs, SMTP configuration, and **debug logging** toggle.
- **Motorsport Club → Logs:** in-browser log viewer — select a daily log file, read colour-coded entries (INFO / WARNING / ERROR, newest first), clear a file, or purge logs older than 7 days. Only visible when logging is enabled.

### Roles & Capabilities
| Role | Capabilities |
|---|---|
| `administrator` | Full access |
| `msc_event_creator` | Create/manage events, view and manage entries and participants via the frontend dashboard |
| `msc_class_rep` | Read-only access to the Entries tab of the staff dashboard (all events); can filter by class and export CSV; no status changes or bulk actions |
| Subscriber / other | Entry submission and My Account only |

The custom `msc_view_participants` capability gates participant data and indemnity/PoP document access.

### Email Notifications
| Trigger | Recipients | Attachments |
|---|---|---|
| Entry submitted | Entrant | — |
| Indemnity signed | Entrant | Signed indemnity PDF |
| Indemnity signed | Admin + event creator (deduplicated) | Signed indemnity PDF + PoP |
| Entry confirmed | Entrant | — |
| Entry rejected | Entrant | — |
| Entry cancelled | Entrant | — |

---

## Installation & Setup

1. Upload the plugin folder to `/wp-content/plugins/` and activate.
2. Enable **Anyone can register** in **Settings → General**.
3. Create pages with the following shortcodes and configure their URLs in **Motorsport Club → Settings**:

| Page | Shortcode |
|---|---|
| Login | `[msc_login]` |
| Register | `[msc_register]` |
| Set Password | `[msc_set_password]` |
| My Account | `[msc_my_account]` |
| Events | `[msc_events_list]` |
| Staff Dashboard | `[msc_event_dashboard]` |

4. To add a **Logout** link to a nav menu, create a Custom Link with URL `#msc-logout` — the plugin will replace it with the correct WordPress logout URL at render time.
5. Configure banking details and SMTP in **Motorsport Club → Settings**.
6. Create vehicle classes under **Motorsport Club → Vehicle Classes**.
7. Create a Pricing Set under the **Pricing** tab of the staff dashboard, then assign it to events.
8. Create your first event as an `msc_event` post and publish it.

---

## Shortcodes

| Shortcode | Purpose | Attributes |
|---|---|---|
| `[msc_events_list]` | Public grid of upcoming events | `count` (default 10), `show_past` (default 0) |
| `[msc_next_event]` | Compact featured event card (sidebar/footer) | — |
| `[msc_register_event]` | Embed the entry form for a specific event | `event_id` |
| `[msc_my_account]` | Member garage, entries, and profile dashboard | — |
| `[msc_event_dashboard]` | Staff management dashboard | — |
| `[msc_login]` | Styled login form | — |
| `[msc_register]` | Styled registration form with email verification | — |
| `[msc_set_password]` | Password setup page (used after email verification) | — |

---

## Architecture

### Tech Stack
- **Language:** PHP 7.4+ / WordPress Plugin API — no Composer, no build step.
- **Frontend:** jQuery + vanilla CSS (all classes prefixed `msc-`).
- **PDF:** Custom generator (`includes/lib/class-msc-pdf.php`) — no FPDF or other external library required.
- **Email:** WordPress `wp_mail` with optional custom SMTP override. SMTP password can alternatively be set via the `MSC_SMTP_PASSWORD` PHP constant to keep it out of the database.

### Database Tables
| Table | Purpose |
|---|---|
| `{prefix}msc_registrations` | Entry records — status, fee, indemnity data, original PoP attachment ID (`pop_file_id`), top-up PoP ID (`pop_file_id_2`) |
| `{prefix}msc_registration_classes` | Per-entry class rows with fee, vehicle, primary flag, and conditions answers (JSON) |
| `{prefix}msc_event_results` | Race results per entry (position, lap time, status) |
| `{prefix}msc_pricing_sets` | Named pricing schedules |
| `{prefix}msc_pricing_set_classes` | Per-class fee config within a pricing set |

Migrations run automatically on every WordPress `init` when the stored `msc_db_version` option differs from `MSC_VERSION`. New columns are added via explicit `ALTER TABLE` with `DESCRIBE` existence checks.

### Class Map
| File | Class | Responsibility |
|---|---|---|
| `motorsport-club.php` | — | Entry point, constants, bootstrap |
| `class-activator.php` | `MSC_Activator` | DB table creation/migration, role setup, protected upload dir |
| `class-post-types.php` | `MSC_Post_Types` | `msc_event` and `msc_vehicle` custom post types |
| `class-taxonomies.php` | `MSC_Taxonomies` | `msc_vehicle_class` taxonomy; grouped class lookups |
| `class-registration.php` | `MSC_Registration` | Entry submission, editing, cancellation AJAX; PoP file serving |
| `class-indemnity.php` | `MSC_Indemnity` | On-demand PDF generation; post-signing email dispatch |
| `class-results.php` | `MSC_Results` | Results table, event open/closed lifecycle |
| `class-pricing.php` | `MSC_Pricing` | Pricing set CRUD and fee calculation helpers |
| `class-shortcodes.php` | `MSC_Shortcodes` | All public-facing shortcodes and single event page injection |
| `class-account.php` | `MSC_Account` | Member dashboard — garage, entries history, profile editing; nav menu logout URL filter |
| `class-auth.php` | `MSC_Auth` | Login, register, set-password shortcodes and URL helpers |
| `class-security.php` | `MSC_Security` | Email verification, login branding, onboarding redirect |
| `class-emails.php` | `MSC_Emails` | HTML email wrappers and SMTP configuration |
| `class-frontend-dashboard.php` | `MSC_Frontend_Dashboard` | Staff dashboard shortcode and all its tab logic |
| `class-admin-events.php` | `MSC_Admin_Events` | wp-admin event meta boxes and admin entries pages |
| `class-admin-garage.php` | `MSC_Admin_Garage` | Admin vehicle list; vehicle-to-event class matching |
| `class-admin-participants.php` | `MSC_Admin_Participants` | Participant CRM (admin menu + dashboard tab) |
| `class-msc-logger.php` | `MSC_Logger` | Toggleable debug logger — writes to `uploads/msc-logs/msc-debug-YYYY-MM-DD.log`; client-side error AJAX endpoint |

### Key Conventions
- All hooks, options, meta keys, and nonces use the `msc_` prefix.
- All CSS classes use the `msc-` prefix.
- Vehicle meta keys: `_msc_make`, `_msc_model`, `_msc_year`, `_msc_type`, `_msc_engine_size`, `_msc_comp_number` (Race Number).
- `MSC_Registration::format_vehicle_label($vehicle_id)` is the single source of truth for building the human-readable vehicle string from meta.
- Event meta keys: `_msc_event_date`, `_msc_event_end_date`, `_msc_event_location`, `_msc_entry_fee`, `_msc_capacity`, `_msc_event_status` (`closed` locks entries).
- AJAX nonce: `msc_nonce`.

---

## Security Notes

- All AJAX endpoints verify nonces via `check_ajax_referer('msc_nonce', 'nonce')`.
- File uploads are validated server-side with `wp_check_filetype_and_ext()` against an explicit allowlist.
- PoP files are stored outside the public upload path (`uploads/msc-pop/` with `.htaccess` `deny from all`) and served through a PHP endpoint with ownership/capability checks.
- Indemnity PDFs are never written to disk permanently; generated in memory per request.
- Drawn signatures are validated via regex; typed signatures are sanitised via `sanitize_text_field()`.
- Vehicle ownership uses strict integer comparison (`(int)$post->post_author !== $user_id`).
- All database queries use `$wpdb->prepare()`.

---

## License

GPLv2 or later. See `LICENSE` for details.
