# Motorsport Club Manager

A comprehensive WordPress plugin for managing motorsport events, participant vehicle garages, event registrations with electronic indemnity signing, race results, and PDF/email notifications.

Built for any motorsport club or racing organisation running their website on WordPress.

---

## Key Features

### Event Management
- Create events with dates, locations, capacity limits, entry fees, and registration windows
- Per-event configuration of allowed vehicle types (Cars, Motorcycles, or Both) and classes
- Instant or manual approval modes for registrations
- Event lifecycle: open for registration → closed → results published

### Member Garage
- Members manage their own fleet of vehicles from a frontend dashboard
- Vehicle photos with drag-and-drop upload
- Vehicle classification by type and class (taxonomy-driven)
- Only vehicles matching an event's allowed classes appear during registration

### Registration & Indemnity
- Multi-step registration form with real-time validation
- Electronic signature support via `signature_pad` (drawn or typed)
- Automatic minor detection from date of birth — enforces parent/guardian name and signature
- Mandatory emergency contact fields (name and phone) pre-filled from user profile
- Optional emergency contact relationship and pit crew names (1 & 2) pre-filled from profile, editable during registration and saved back automatically
- Submit button only enables when all required fields are complete
- **Event-specific indemnity text** overrides the global default when set
- **Custom mandatory declarations** (configurable via settings) appear as checkboxes

### Proof of Payment (EFT)
- Configurable banking details shown to members during registration
- PDF upload required for events with an entry fee
- PoP is emailed to the admin/event creator with the signed indemnity, then deleted from the server — no permanent storage
- Admin registrations table shows "✓ Emailed" once the PoP has been sent and removed

### PDF & Email Notifications
- Auto-generated A4 indemnity PDFs with participant details, emergency contacts, signatures, and accepted declarations
- Branded with your WordPress site logo and name
- **Participant** receives a registration confirmation email, then a separate email with their signed indemnity PDF attached
- **Admin and event creator** receive a single combined email with both the signed indemnity PDF and proof of payment attached (deduplicated if both share the same address)
- Neither the indemnity PDF nor the PoP file are stored permanently on the server — both are generated/attached in memory and deleted immediately after emailing
- **Configurable SMTP settings** built-in — no separate SMTP plugin required
- **Customizable sender details** (From Name and From Email address)

### Member Profiles
- Custom profile photo upload (avatar displayed in dashboard header)
- Mandatory fields: first name, last name, phone number, date of birth, street address, city/town, province, postal code, competition number, MSA licence, medical aid, gender
- Optional fields: pit crew name #1 and #2
- Emergency contact details (name, phone, relationship) saved to profile and pre-filled during registration
- First-login onboarding prompt to complete missing profile fields

### Custom Frontend Auth Pages
- **`[msc_login]`** — fully styled login form with username/email + password, "keep me logged in", and "forgot password?" link
- **`[msc_register]`** — registration form (username + email only); verification email sent automatically on submit
- **`[msc_set_password]`** — secure set-password form reached after email verification; includes password strength indicator and match check
- All three shortcodes redirect logged-in users to the account page
- Designed to work alongside **WPS Hide Login** or any plugin that hides `wp-login.php`

### Email Verification
- New registrations require email verification before login
- After clicking the verification link, members are sent directly to the `[msc_set_password]` page to choose their password
- Resend verification link with rate limiting and CSRF protection

### Race Results
- Record lap times and finishing positions once an event is closed
- Results displayed on the frontend event page with podium cards for the top 3 finishers
- Status tracking: Finished, DNF, DNS, DSQ
- **Manual driver entry** — add drivers who aren't registered on the website directly from the results meta box
- Compact, data-dense results entry UI designed for quick post-event data entry

### Security
- Nonce verification on all AJAX endpoints and form handlers
- Strict vehicle ownership checks with type-safe comparisons
- Server-side file type validation for all uploads
- Parameterised database queries with format specifiers
- XSS prevention via safe DOM construction and output escaping
- Capability checks on all admin pages
- **wp-admin restricted to administrators only** — unauthenticated and non-admin users are redirected to the homepage (not `wp-login.php`); frontend AJAX via `admin-ajax.php` is unaffected

---

## Technical Stack

- **PHP / WordPress Plugin API** — no external PHP dependencies
- **Custom Post Types:** `msc_event`, `msc_vehicle`
- **Custom Taxonomy:** `msc_vehicle_class` (shared across post types)
- **Custom Database Tables:**
    - `{prefix}msc_registrations` — entrant details, signatures, emergency contacts, PoP references
    - `{prefix}msc_event_results` — lap times, positions, finish status
- **Frontend:** jQuery + vanilla CSS (all classes prefixed `msc-`)
- **Libraries:**
    - [`signature_pad`](https://github.com/nicholasgasior/signature_pad) (CDN) — electronic signatures
    - `MSC_PDF` (bundled) — A4 PDF generation with no PHP extension requirements

---

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate through **Plugins** in WordPress admin
3. Enable **Settings → General → Anyone can register** in WordPress
4. Go to **Motorsport Club → Settings**:
   - Set your EFT banking details
   - Configure the default indemnity text
   - Set the Account, Login, Registration, and Set Password page URLs
5. Create the following pages and add them to your menu:

   | Page | Shortcode |
   |---|---|
   | My Account | `[msc_my_account]` |
   | Login | `[msc_login]` |
   | Register | `[msc_register]` |
   | Set Password | `[msc_set_password]` |
   | Event Dashboard *(staff only)* | `[msc_event_dashboard]` |

6. Start creating events under **Motorsport Club → Events** or via the frontend dashboard

Database tables are created automatically on activation. Schema migrations run automatically when the plugin version changes.

---

## Shortcodes

| Shortcode | Purpose |
|---|---|
| `[msc_login]` | Frontend login form — username/email + password, remember me, forgot password link |
| `[msc_register]` | Frontend registration form — username + email; sends verification email automatically |
| `[msc_set_password]` | Set-password form reached after email verification; includes strength indicator |
| `[msc_events_list]` | Grid of upcoming events with date, location, fee, and registration status. Accepts `count` (default 10) and `show_past` (default 0) attributes. Events are ordered newest first. |
| `[msc_next_event]` | Compact card showing the next upcoming open event — featured image (clickable to enlarge), date, location, fee, and a link. Closed events are excluded. Designed for sidebars and footers. |
| `[msc_my_account]` | Member dashboard — garage, registrations, and profile management |
| `[msc_register_event event_id="X"]` | Embed a registration form for a specific event |
| `[msc_event_dashboard]` | Unified management dashboard for admins and Event Creators (Events, Registrations, Results, Participants tabs) |

Single event pages (`msc_event` post type) automatically display the featured image (left, clickable lightbox) alongside the description (right), followed by event details, the registration form, and results. All date/time comparisons respect the timezone configured in **Settings → General**.

---

## Admin Guide

### Dashboard
**Motorsport Club → Dashboard** shows at-a-glance stats: upcoming events, total vehicles, registrations, and pending approvals.

### Participants Dashboard
**Motorsport Club → Participants** (admin-only) and the **Participants tab** of `[msc_event_dashboard]` (frontend) both provide:
- Search by name or email
- Table view showing name, email, phone, competition number, and event count
- Click any row to expand a three-panel detail card:
  - **Personal Details** — date of birth, gender, phone, full address
  - **Motorsport Details** — competition number, MSA licence, medical aid provider & number, pit crew names
  - **Emergency Contact** — name, phone, relationship

### Event Manager Dashboard
`[msc_event_dashboard]` is a unified frontend management shortcode for **administrators** and **Event Creators**. Place it on any page — access is restricted by role.

Operational scope for Event Creators is configurable in **Motorsport Club → Settings**:
- **Strict ownership** — creators manage only events they authored
- **Shared ops** — any Event Creator can manage all events, registrations, and results

| Tab | Features |
|---|---|
| **Events** | List all events with status, registration count, and quick actions. Create new events via an inline form (including featured image picker and optional per-event indemnity text). Close or reopen events. |
| **Registrations** | Filter by event and status. View entrant details, update registration status (triggers confirmation email), and download indemnity PDFs. |
| **Results** | Select a closed event and enter results for registered and manual (walk-in) drivers — position, laps, lap times, finish status, and notes. |
| **Participants** | Full participant lookup with expandable detail cards (same as admin Participants page). |

### Event Creator Role
The plugin automatically creates an **Event Creator** (`msc_event_creator`) WordPress role on activation. Assign this role to staff who need access to the frontend Event Manager Dashboard without full WordPress admin access.

### Managing Registrations
**Motorsport Club → Registrations** lets you:
- Filter by event or status (Pending / Confirmed / Rejected / Cancelled)
- View and download uploaded Proof of Payment PDFs
- Toggle paid status per registration
- Update registration status (confirming triggers a confirmation email)
- View and download signed indemnity PDFs
- Delete registrations

### Vehicle Classes
Manage classes under **Motorsport Club → Vehicle Classes**. Each class has a Vehicle Type (Car or Motorcycle). Events select which types and classes are allowed, and only matching vehicles from a member's garage appear during registration. A set of default classes is created on first activation — add, rename, or remove them to suit your club.

### Settings
- **Account Page URL** — full URL of the page containing `[msc_my_account]`
- **Login Page URL** — full URL of the page containing `[msc_login]` (leave blank to use WordPress default)
- **Registration Page URL** — full URL of the page containing `[msc_register]` (leave blank to use WordPress default)
- **Set Password Page URL** — full URL of the page containing `[msc_set_password]` (leave blank to use WordPress default)
- **Dashboard Event Access Mode** — choose **Strict ownership** or **Shared ops** for Event Creator permissions in `[msc_event_dashboard]`
- **Email Settings** — configure the "From" name and email address for automated messages
- **SMTP Configuration** — built-in support for external SMTP servers
- **Banking Details** — shown to members registering for paid events
- **Default Indemnity Text** — used across all events and rendered in signed PDFs
- **Custom Registration Declarations** — add mandatory checkboxes (one per line) to the registration form

---

## Indemnity PDFs

Access formatted indemnity PDFs at:
```
yoursite.com/?msc_indemnity_pdf={registration_id}
```
Access is restricted to the participant, the event author, and administrators.

---

## Architecture

### Event Lifecycle
1. **Open** — members can register with eligible vehicles
2. **Closed** (`_msc_event_status = 'closed'`) — registrations locked, results interface enabled
3. **Results published** — displayed on the frontend event page

### Centralised Classification
`MSC_Taxonomies` is the single source of truth for vehicle types and classes, backed by the `msc_vehicle_class` taxonomy with `msc_vehicle_type` term meta. Classes are managed entirely through the WordPress admin UI, ensuring consistency between the admin garage, event configuration, and frontend registration filtering.

---

## Key Files

| File | Purpose |
|---|---|
| `motorsport-club.php` | Plugin entry point, constants, class loading |
| `includes/class-taxonomies.php` | Taxonomy registration, term meta UI, and class queries |
| `includes/class-registration.php` | AJAX registration logic and validation |
| `includes/class-account.php` | Frontend member dashboard |
| `includes/class-indemnity.php` | PDF generation and email delivery |
| `includes/class-auth.php` | Frontend login, register, and set-password shortcodes |
| `includes/class-security.php` | Email verification, login branding, profile fields |
| `includes/class-results.php` | Event closing and results management |
| `includes/class-admin-events.php` | Admin event meta boxes, registrations page, settings |
| `includes/class-admin-garage.php` | Admin vehicle management |
| `includes/class-shortcodes.php` | Shortcode registration and frontend rendering |
| `includes/class-emails.php` | HTML email templates and sending |
| `includes/lib/class-msc-pdf.php` | Custom PDF generator |
| `assets/js/frontend.js` | Multi-step registration, signatures, garage UI |
| `assets/css/frontend.css` | Frontend styles |

---

## License

This plugin is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any later version.

This plugin is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the [GNU General Public License](https://www.gnu.org/licenses/gpl-2.0.html) for more details.
