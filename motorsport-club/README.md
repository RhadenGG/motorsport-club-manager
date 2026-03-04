# Motorsport Club Manager — WordPress Plugin

A complete motorsport event management plugin for WordPress, developed for **kznrrc.co.za**.

---

## Features
- **Racing Events** — Create events with date, location, capacity, entry fee, registration window.
- **Vehicle Garage** — Members manage their own vehicles with make/model/year/type details.
- **Centralized Classifications** — Vehicle types (Car, Motorcycle) and classes are hardcoded for consistency (see `MSC_Taxonomies`).
- **Event Registration** — Members register with a vehicle from their garage; only eligible class vehicles shown.
- **Registration Approval** — Per-event: instant or requires admin approval.
- **Entry Fees** — Record the fee; handled separately at the event.
- **Indemnity Form** — Per-event custom text; members sign electronically (draw or type) or select to bring a signed copy.
- **Email Notifications** — Registration received, approval confirmation, reminder to bring indemnity form.
- **Admin Dashboard** — Overview stats, filter/manage all registrations, update statuses, download indemnity PDFs.
- **Member Account** — View registrations, manage garage via `[msc_my_account]` shortcode.

---

## Installation

1. Upload the `motorsport-club` folder to `/wp-content/plugins/`.
2. Activate via **Plugins → Installed Plugins**.
3. Go to **Motorsport Club** in the admin menu to get started.

---

## Setup Steps (After Activation)

### 1. Hardcoded Classifications
Vehicle Types and Classes are centralized in `includes/class-taxonomies.php`.
- **Cars:** Modifieds, Super GT's, Retro Racing, Sports Cars, Porsche Challenge, Time Challenge.
- **Motorcycles:** Juniors, Motards, Powersport, CBR150, 300 Class, 600/1000, MiniGP.

### 2. Create an Event
Go to **Motorsport Club → Events → Add New**.
- Fill in date, location, entry fee, capacity.
- Set the registration window (open / close dates).
- Choose **Instant** or **Manual approval**.
- Select the **Allowed Vehicle Classes** in the sidebar (leave unchecked to allow all).
- Customise the **Indemnity text** for this event.

### 3. Add Shortcodes to Pages

| Shortcode | What it does |
|---|---|
| `[msc_events_list]` | Shows upcoming events grid. |
| `[msc_events_list count="5"]` | Limit number of events shown. |
| `[msc_events_list show_past="1"]` | Include past events. |
| `[msc_my_account]` | Member account — registrations + garage. |
| `[msc_register_event event_id="123"]` | Embed form for specific event. |

> **Tip:** The registration form is also automatically added to the bottom of every single event page — you don't need the shortcode there.

### 4. Create a "My Account" Page
Create a new page, add the shortcode `[msc_my_account]`, and link to it from your menu.

---

## Admin Usage

### Registrations
Go to **Motorsport Club → Registrations** to:
- Filter by event or status.
- Change registration status (Pending → Confirmed / Rejected).
- Confirming a registration automatically sends the confirmation email.
- Download/view a signed indemnity as a printable PDF.

### Event List Columns
The events list in admin shows: Date, Location, Entry count (vs capacity), Entry fee, Approval mode.

---

## Email Notifications

| Trigger | Recipient |
|---|---|
| New registration submitted | Member (receipt) + Admin (alert) |
| Registration confirmed (auto or manual) | Member |

Emails are sent as HTML. The admin email is your site's admin email (Settings → General).

---

## Indemnity PDF
After registration, members and admins can view/print a formatted indemnity PDF via:
`yoursite.com/?msc_indemnity_pdf={registration_id}`

The PDF includes event details, participant info, indemnity text, and the electronic signature (or blank lines for manual signing).

---

## Customisation Tips

- **Styling:** Override styles by adding CSS to your theme. All classes are prefixed with `.msc-`.
- **Indemnity text:** Customised per event in the event editor.
- **Vehicle types/classes:** Managed in `MSC_Taxonomies`.

---

## File Structure
```
motorsport-club/
├── motorsport-club.php          # Main plugin file
├── includes/
│   ├── class-activator.php      # Install / DB table creation
│   ├── class-taxonomies.php     # Source of truth for vehicle classes
│   ├── class-post-types.php     # Events + Vehicles CPTs
│   ├── class-admin-events.php   # Event admin, meta boxes, registrations table
│   ├── class-admin-garage.php   # Vehicle admin
│   ├── class-registration.php   # Registration logic + AJAX
│   ├── class-indemnity.php      # PDF / print view
│   ├── class-emails.php         # Email notifications
│   ├── class-shortcodes.php     # All front-end shortcodes + asset loading
│   ├── class-results.php        # Race results and event closing logic
│   ├── class-account.php        # Member account dashboard
│   └── lib/
│       └── class-msc-pdf.php    # Custom PDF generation
├── assets/
│   ├── css/frontend.css
│   └── js/frontend.js
└── README.md
```

---

## Database
One custom table is created on activation:
- `{prefix}msc_registrations` — stores all event registrations, vehicle, indemnity method/signature, status, entry fee.
