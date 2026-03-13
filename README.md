# Motorsport Club Manager

A WordPress plugin for end-to-end motorsport event management — from event creation and member entries through to race results and document archival. Built for real clubs running live race days.

**Current version:** 0.7.5 | **License:** GPLv2 or later

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
- Fully dynamic vehicle class taxonomy (`msc_vehicle_class`) — create, rename, and delete classes from the admin UI under **Motorsport Club → Vehicle Classes**.
- Each class carries a **Vehicle Type** meta value (Car or Motorcycle), used to filter compatible classes and vehicles per member.
- Classes are grouped by vehicle type in all entry form dropdowns.

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
- Electronic indemnity signing — drawn signature via `signature_pad` or typed name.
- Automatic minor detection: if the member's date of birth indicates they are under 18, mandatory parent/guardian name and signature fields appear.
- Emergency contact, pit crew, and sponsor fields pre-filled from the member's saved profile; any changes made during entry are saved back to the profile.
- **Sponsors:** optional free-text field (max 33 characters) for the entrant to list their sponsors. Appears on the entry form, the member profile, and the indemnity PDF.
- **Proof of Payment (PoP):** PDF, PNG, or JPG upload (up to 5 MB) required when the entry fee is greater than zero. Banking details configured in Settings are shown alongside the upload field.
- Upon confirmation, each entry is assigned a **sequential entry number** scoped to the event, displayed on the indemnity PDF and the member's entries history.

### Entry Editing
- Members can add or remove classes on a pending or confirmed entry from their **My Account** dashboard.
- Rules enforced:
  - No downgrade below the original paid amount.
  - Same or lower total (within rounding) saves immediately.
  - Higher total requires a top-up PoP upload for the difference only.
- If a **confirmed** entry is edited, it is automatically reset to **pending** and must be re-approved by the organiser.
- Vehicle assignments per class are preserved across edits; only newly added classes fall back to the primary vehicle.

### Document Security
- **Indemnity PDFs** are generated on-demand from stored data — nothing is written to disk permanently. Served via `?msc_indemnity_pdf={id}` with access restricted to the entrant, event creator, and admins/organisers.
- **PoP files** are stored in a protected subdirectory (`wp-content/uploads/msc-pop/`) with an `.htaccess` blocking direct web access. Served via `?msc_pop_file={reg_id}` with the same access control. Links in both wp-admin and the staff dashboard use this endpoint.
- On registration, the signed indemnity PDF is emailed to the entrant; the admin/event creator receives both the indemnity PDF and PoP as attachments.

### Member Account & Profile
- **My Account** (`[msc_my_account]`) — member dashboard with:
  - **Garage:** add, edit, and delete vehicles with make, model, year, registration number, class, and photo.
  - **My Entries:** history of all entries with status badges, entered class names, and view links for indemnity PDFs and PoPs.
  - **Profile:** edit personal details (first/last name, phone, address, gender, date of birth), motorsport info (competition number, MSA licence, medical aid), emergency contact, pit crew names, and sponsors.
  - **Profile photo:** upload, preview, and remove a custom photo.
- Role display on the profile card (Subscriber → Guest, msc_event_creator → Event Creator, Administrator → Admin).

### Custom Auth Flow
- Styled shortcode pages for login (`[msc_login]`), registration (`[msc_register]`), and password setup (`[msc_set_password]`).
- Registration sends an email verification link; verified users are redirected to the set-password page to choose their password before being redirected to login.
- First-login onboarding redirect prompts new members to complete their profile.
- Compatible with Cloudflare Turnstile / CAPTCHA.

### Staff Dashboard
- Unified frontend staff tool via `[msc_event_dashboard]` — accessible to administrators and the `msc_event_creator` role.
- **Events tab:** list all events, create new events (inline form with all meta fields), close/reopen events, and navigate to the entries or results for a specific event.
- **Entries tab:** filter by event and/or status; update individual entry status (pending → confirmed / rejected / cancelled) via inline AJAX selects; bulk status updates with a select-all checkbox; rejection and cancellation emails dispatched automatically; CSV export of the filtered entry list.
- **Results tab:** select a closed event and enter results per class — position, lap time, and status (Finished / DNF / DNS / DSQ) for both registered entrants and manually added walk-in drivers.
- **Participants tab:** searchable CRM table of all members who have ever entered an event, with expandable rows showing personal details, motorsport credentials, and emergency contact information.
- **Vehicle Classes tab:** create and manage vehicle class taxonomy terms and their vehicle type assignments.
- **Pricing tab:** create and manage Pricing Sets and configure per-class fees.

### Race Results
- Results stored per registration with position, lap time, and status enum.
- Per-class podium display (Top 3 finishers) on the public event page.
- Manual driver entries (name + vehicle description) for walk-ins not in the system.

### Admin (wp-admin)
- **Motorsport Club → Events:** event list with per-event entries sub-pages, paginated at 50 rows, with inline status update, PoP view, and indemnity PDF links.
- **Motorsport Club → Participants:** same expandable CRM view as the frontend dashboard.
- **Motorsport Club → Vehicle Classes:** taxonomy term management.
- **Motorsport Club → Settings:** banking details, auth page URLs, and SMTP configuration.

### Roles & Capabilities
| Role | Capabilities |
|---|---|
| `administrator` | Full access |
| `msc_event_creator` | Create/manage events, view and manage entries and participants via the frontend dashboard |
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

4. Configure banking details and SMTP in **Motorsport Club → Settings**.
5. Create vehicle classes under **Motorsport Club → Vehicle Classes**.
6. Create a Pricing Set under the **Pricing** tab of the staff dashboard, then assign it to events.
7. Create your first event as an `msc_event` post and publish it.

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
| `{prefix}msc_registrations` | Entry records — status, fee, indemnity data, PoP attachment ID |
| `{prefix}msc_registration_classes` | Per-entry class rows with fee, vehicle, and primary flag |
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
| `class-account.php` | `MSC_Account` | Member dashboard — garage, entries history, profile editing |
| `class-auth.php` | `MSC_Auth` | Login, register, set-password shortcodes and URL helpers |
| `class-security.php` | `MSC_Security` | Email verification, login branding, onboarding redirect |
| `class-emails.php` | `MSC_Emails` | HTML email wrappers and SMTP configuration |
| `class-frontend-dashboard.php` | `MSC_Frontend_Dashboard` | Staff dashboard shortcode and all its tab logic |
| `class-admin-events.php` | `MSC_Admin_Events` | wp-admin event meta boxes and admin entries pages |
| `class-admin-garage.php` | `MSC_Admin_Garage` | Admin vehicle list; vehicle-to-event class matching |
| `class-admin-participants.php` | `MSC_Admin_Participants` | Participant CRM (admin menu + dashboard tab) |

### Key Conventions
- All hooks, options, meta keys, and nonces use the `msc_` prefix.
- All CSS classes use the `msc-` prefix.
- Vehicle meta keys: `_msc_make`, `_msc_model`, `_msc_year`, `_msc_type`, `_msc_reg_number`.
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
