# Motorsport Club Manager

A comprehensive WordPress plugin for managing motorsport events, vehicle garages, event entries with electronic signatures, race results, and PDF/email notifications.

---

## 🚀 Key Features

### Event & Dynamic Pricing (v0.6.x)
- **Base Admin Fee:** Optional fixed fee added to every entry.
- **Dynamic "From" Pricing:** Automatically calculates the lowest entry fee (Base + cheapest Primary Class) for display.
- **Pricing Sets:** Named fee schedules (e.g., "2026 Season") with:
  - **Primary Fee:** Cost when a class is the main entry.
  - **Additional Fee:** Cost when added as a secondary class.
  - **Global Override:** Allows specific classes (like Endurance) to override fees for all other classes in the entry.
  - **Exemptions:** Specific classes can ignore overrides to maintain fixed costs (e.g., transponder rentals).
  - **Primary Only:** Restrict certain classes to be selected only as the main entry.
- **Vehicle Types:** Configure events for Cars, Motorcycles, or both.

### Member Experience
- **Frontend Garage:** Drag-and-drop vehicle management with photos.
- **Smart Entry Form:**
  - Real-time fee calculation and multi-class selection.
  - Electronic signatures (drawn or typed) via `signature_pad`.
  - Automatic minor detection with mandatory parent/guardian fields.
  - Pre-filled emergency contact and pit crew details from profile.
- **Entry Editing:** Entrants can add or remove classes after submitting. Rules enforced: no downgrade below original paid amount; same total saves immediately; higher total prompts a top-up PoP upload for the delta. Per-class vehicle assignments are preserved on edit.
- **Proof of Payment:** Required PDF upload for paid events; stored persistently and viewable by event staff in both wp-admin and the frontend dashboard.
- **Document Access:** Signed indemnity PDFs and PoPs are accessible to entrants, event creators, and admins at any time via view buttons in admin and the staff dashboard.
- **Custom Auth:** Styled login, registration (with email verification), and password-set pages. CAPTCHA/Turnstile compatible.

### Event Cards & Dashboard UX (v0.7.0)
- **Smart CTAs:** Event cards show "Enter Now" for unentered events and "View your entry" for already-entered users.
- **Entered Class Display:** Entry cards in "My Entries" and the event page "already entered" notice both show the class names the entrant is entered in.
- **Role Display:** The member profile card shows the user's actual WordPress role (subscriber → Guest, msc_event_creator → Event Creator, etc.).

### Management & Results
- **Event Dashboard:** Unified staff interface for managing Events, Entries, Results, and Participants.
- **Race Results:**
  - Class-specific results with podium displays (Top 3 finishers).
  - Manual driver entry for walk-ins.
  - Status tracking (Finished, DNF, DNS, DSQ) and lap time recording.
- **Participant CRM:** Expandable detail cards showing personal, motorsport, and emergency data.
- **PDF & SMTP:** Built-in FPDF generator for indemnity forms and integrated SMTP configuration for reliable email delivery.

---

## 🛠 Technical Stack
- **Core:** PHP / WordPress Plugin API (No external PHP dependencies).
- **Storage:** Custom tables for Entries, Results, and Pricing Sets.
- **Frontend:** jQuery + Vanilla CSS (prefixed `msc-`).
- **Security:** Nonce verification, strict ownership checks, server-side validation, and administrator-only `wp-admin` access.

---

## 📥 Installation & Setup
1. Upload to `/wp-content/plugins/` and activate.
2. Enable **Anyone can register** in WordPress settings.
3. Configure URLs and Banking in **Motorsport Club → Settings**.
4. **Create Pages with Shortcodes:**
   - `[msc_my_account]` - Dashboard
   - `[msc_login]`, `[msc_register]`, `[msc_set_password]` - Auth flow
   - `[msc_events_list]` - Event grid
   - `[msc_event_dashboard]` - Staff management

---

## 📋 Shortcodes
| Shortcode | Purpose | Attributes |
|---|---|---|
| `[msc_events_list]` | Grid of upcoming events | `count`, `show_past` |
| `[msc_next_event]` | Featured sidebar/footer card | — |
| `[msc_my_account]` | Member garage & profile | — |
| `[msc_event_dashboard]` | Staff/Admin management tool | — |
| `[msc_login]` | Styled login with Turnstile support | — |

---

## 📂 Architecture & Files
| File/Path | Responsibility |
|---|---|
| `motorsport-club.php` | Entry point & constants |
| `includes/class-pricing.php` | Dynamic fee logic & Pricing Sets |
| `includes/class-registration.php`| AJAX flow, validation & entry editing |
| `includes/class-results.php` | Race standings & podiums |
| `includes/class-admin-events.php`| Menu consolidation & Event UI |
| `includes/class-indemnity.php` | PDF & Email logic |
| `assets/` | Frontend JS/CSS (prefixed) |

---

## ⚖️ License
GPLv2 or later. See `LICENSE` for details.
