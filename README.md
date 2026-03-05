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
- Mandatory emergency contact fields pre-filled from user profile
- Submit button only enables when all required fields are complete

### Proof of Payment (EFT)
- Configurable banking details shown to members during registration
- PDF upload required for events with an entry fee
- Admins can view uploaded PoP documents and toggle paid status

### PDF & Email Notifications
- Auto-generated A4 indemnity PDFs with participant details, emergency contacts, and signatures
- Branded with your WordPress site logo and name
- Emails sent on registration submission, confirmation, and indemnity signing
- PDF and PoP attachments included in relevant emails

### Member Profiles
- Custom profile photo upload (avatar displayed in dashboard header)
- Mandatory fields: date of birth, competition number, MSA licence, medical aid, gender
- Emergency contact details saved to profile and pre-filled during registration
- First-login onboarding prompt to complete missing profile fields

### Email Verification
- New registrations require email verification before login
- Custom branded login/register/password pages using site logo
- Resend verification link with rate limiting and CSRF protection

### Race Results
- Record lap times and finishing positions once an event is closed
- Results displayed on the frontend event page
- Status tracking: Finished, DNF, DNS, DSQ

### Security
- Nonce verification on all AJAX endpoints and form handlers
- Strict vehicle ownership checks with type-safe comparisons
- Server-side file type validation for all uploads
- Parameterised database queries with format specifiers
- XSS prevention via safe DOM construction and output escaping
- Capability checks on all admin pages

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
3. Go to **Motorsport Club → Settings**:
   - Set your EFT banking details
   - Configure the default indemnity text
   - Set the account page URL
4. Create a page with the `[msc_my_account]` shortcode and add it to your menu
5. Start creating events under **Motorsport Club → Events**

Database tables are created automatically on activation. Schema migrations run automatically when the plugin version changes.

---

## Shortcodes

| Shortcode | Purpose |
|---|---|
| `[msc_events_list]` | Grid of upcoming events with date, location, fee, and registration status |
| `[msc_my_account]` | Member dashboard — garage, registrations, and profile management |
| `[msc_register_event event_id="X"]` | Embed a registration form for a specific event |

Single event pages (`msc_event` post type) automatically display event details, the registration form, and results via `the_content` filter.

---

## Admin Guide

### Dashboard
**Motorsport Club → Dashboard** shows at-a-glance stats: upcoming events, total vehicles, registrations, and pending approvals.

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
- **Banking Details** — shown to members registering for paid events
- **Default Indemnity Text** — used across all events and rendered in signed PDFs

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
