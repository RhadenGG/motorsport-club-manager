# Motorsport Club Manager

A comprehensive WordPress plugin for **kznrrc.co.za** to manage motorsport events, participant vehicle garages, event registrations, race results, and electronic indemnity forms.

---

## 🏎️ Key Features

- **Event Management** — Create racing events with specific dates, locations, capacity limits, and entry fees.
- **Member Garage** — Users can manage their own fleet of vehicles (Cars & Motorcycles) within their WordPress profile.
- **Intelligent Registration** — Members register for events using vehicles from their garage. The system automatically filters eligible vehicles based on the event's allowed classes.
- **Digital Indemnity** — Integrated electronic signature support (via `signature_pad`) for indemnity forms. Members can sign digitally or choose to bring a physical copy.
- **Automated PDFs** — Generates A4 PDF indemnity forms with participant details and signatures.
- **Race Results** — Record lap times and finishing positions for closed events.
- **Shortcode Powered** — Easy integration into any WordPress theme using a suite of shortcodes for lists, registrations, and account management.

---

## 🛠️ Technical Stack

- **PHP / WordPress Plugin API**
- **Custom Post Types:** `msc_event` (Events), `msc_vehicle` (Member Vehicles)
- **Custom Database Tables:**
    - `{prefix}msc_registrations`: Stores entrant details, vehicle selection, and indemnity status.
    - `{prefix}msc_event_results`: Stores lap times and finishing positions.
- **Frontend:** Vanilla CSS (`msc-` prefixed) and jQuery.
- **Libraries:**
    - `signature_pad` (CDN) for electronic signatures.
    - `MSC_PDF` (Custom lib) for A4 PDF generation.

---

## 🚀 Installation & Setup

1. **Upload:** Move the `motorsport-club` folder to your `/wp-content/plugins/` directory.
2. **Activate:** Enable the plugin through the **Plugins** menu in WordPress.
3. **Configure Account Page:** Create a new WordPress page, add the `[msc_my_account]` shortcode, and link to it in your site menu.
4. **Create Events:** Start adding events under the **Motorsport Club → Events** menu.

### Detailed Setup Steps

1. **Classifications:** Vehicle Types and Classes are centralized in `includes/class-taxonomies.php`. 
   - **Cars:** Modifieds, Super GT's, Retro Racing, Sports Cars, Porsche Challenge, Time Challenge.
   - **Motorcycles:** Juniors, Motards, Powersport, CBR150, 300 Class, 600/1000, MiniGP.
2. **Event Configuration:** When adding an event, you can set the registration window, entry fee, and allowed vehicle classes. If no classes are selected, all are allowed.
3. **Indemnity Text:** Each event can have its own unique indemnity wording, which is pulled into the signed PDF.

---

## 📋 Shortcodes

| Shortcode | Purpose |
|---|---|
| `[msc_events_list]` | Displays a grid of upcoming racing events. |
| `[msc_events_list count="5"]` | Limit number of events shown. |
| `[msc_events_list show_past="1"]` | Include past events. |
| `[msc_my_account]` | The member dashboard (Garages + Registrations). |
| `[msc_register_event event_id="X"]` | Manual embed for a specific event registration form. |

> **Note:** The registration form is automatically appended to the bottom of single event posts; the shortcode is only needed for manual placement on other pages.

---

## 🔧 Admin Usage

### Registrations Management
Navigate to **Motorsport Club → Registrations** to:
- Filter entries by event or status (Pending, Confirmed, Rejected).
- Confirming a registration automatically triggers the confirmation email to the member.
- View and download signed indemnity forms as printable PDFs.

### Race Results
Once an event's registration is closed (via the event editor), you can record lap times and finishing positions. Results are then displayed on the frontend event page.

---

## 📧 Notifications & PDFs

| Trigger | Recipient | Content |
|---|---|---|
| New Registration | Member + Admin | Submission receipt and alert. |
| Status Confirmed | Member | Official confirmation and next steps. |

### Indemnity PDF
Access formatted indemnity PDFs via:
`yoursite.com/?msc_indemnity_pdf={registration_id}`

---

## 📐 Architecture & Mandates

### Centralized Metadata
The plugin uses `MSC_Taxonomies` as the single source of truth for vehicle classifications. This ensures synchronization between the Admin Garage, Event Management, and Frontend registration forms.

### Event Lifecycle
Events follow a strict lifecycle managed via `MSC_Results::is_closed($event_id)`. Closing an event:
1. Locks all new registrations.
2. Enables the results recording interface for admins.
3. Displays the official race results on the frontend.

---

## 📂 Key Files

- `motorsport-club/motorsport-club.php`: Plugin entry point and class initialization.
- `includes/class-taxonomies.php`: Source of truth for vehicle types and classes.
- `includes/class-registration.php`: Core AJAX registration logic and validation.
- `includes/class-indemnity.php`: PDF construction and email delivery logic.
- `includes/class-results.php`: Logic for event closing and results management.
- `includes/lib/class-msc-pdf.php`: Custom PDF generation wrapper.

---

## 📄 License
This plugin is developed specifically for the **KZN Road Racing Club**. All rights reserved.
