# Merge notes: CFS-SYS1.6.2.1 (foundation) + features from CFSMAIN2

## First, a correction about the database
In 6.2.1, **Supabase only holds the website content** (news, announcements, events: `config/cloud.php`, `api/cms/*`).
Users, enrollment, drafts, billing, payments and the registrar data have always lived in the **local MariaDB**
(`config/database.php`, XAMPP). So the restored features use that existing MariaDB code. **Nothing was changed in
Supabase, no Supabase file was edited, and no database engine was added or replaced.**

## Run it locally
1. Copy the folder to `C:\xampp\htdocs\` and start **Apache** and **MySQL** in XAMPP.
2. Keep using your existing `cfs_sis` database. **Do not re-import `cfs_sis_mariadb.sql`** (it wipes the database).
3. In phpMyAdmin open database `cfs_sis` -> **SQL** tab -> paste the CONTENTS of
   `database/migrations/001_add_section_to_applications.sql` -> Go. (Adds one empty column so sections can be saved. Safe to run twice.)
   Until you do this the Masterlist still works, but every student shows "Unassigned" and sections cannot be saved.
4. Optional, GCash test payments only: see `database/migrations/002_optional_demo_gcash_transactions.sql` (read the note inside first).
5. Open http://localhost/CFS-SYS1.6.2.1-main/ and press Ctrl+F5.

## What was restored
| Feature | Where |
|---|---|
| Sign-up password rules (live checklist, show/hide, confirm password, errors) | `index.html`, `script.js`, `styles.css`, and the server-side checks in `api/auth/register.php` |
| Homepage "View Requirements" popup (also the footer link) | `index.html`, `script.js`, `styles.css` |
| Enrollment **Save**, **Save & exit**, **Cancel**; "Continue application" in the portal | `index.html`, `script.js`, `config/enrollment.php`, `api/enrollment/save_draft.php`, `draft.php`, `submit.php` |
| Registrar **Student Masterlist** tab with search, grade / section / school-year filters, Fully Paid / Pending Payments tabs, section assignment | `index.html`, `script.js`, `styles.css`, `cms-staff.js` (tab switch), `api/registrar/masterlist.php`, `update_section.php`, `config/registrar.php` |
| Document viewer zoom (+, -, %, Reset), only inside the document popup | `index.html`, `script.js`, `styles.css` |
| Simulated GCash test check in `api/billing/pay.php` (6.2.1 had lost it); skipped safely if the test table does not exist | `api/billing/pay.php` |

## What was NOT copied (on purpose)
`zoom.js` from the older version zoomed the **whole page**, which you asked not to do, so it was left out.
`index.html` no longer needs it (6.2.1 never loaded it).

## Preserved from 6.2.1 (untouched)
Homepage design and images, website content management (Supabase CMS, `public-content.js`, `cms-staff.js` tabs), login,
guardian portal, enrollment steps and validation, document uploads, billing, registrar dashboard and roles, screening,
and every file under `api/cms`, `api/content`, `config/cloud.php`. Uploaded files in `storage/` are unchanged.

## Files
**Added:** `config/enrollment.php`, `api/enrollment/save_draft.php`, `api/enrollment/draft.php`,
`api/registrar/masterlist.php`, `api/registrar/update_section.php`,
`database/migrations/001_add_section_to_applications.sql`, `database/migrations/002_optional_demo_gcash_transactions.sql`, `MERGE_NOTES.md`
**Modified:** `index.html`, `script.js`, `styles.css`, `cms-staff.js`, `api/auth/register.php`, `api/billing/pay.php`,
`api/enrollment/submit.php` (now shares code with save_draft; behaves the same when there is no draft),
`api/registrar/list.php` (unfinished drafts are hidden from the registrar), `config/registrar.php` (one helper added),
`database/cfs_sis_mariadb.sql` (`section_name` column added for brand-new installs)
**Removed:** nothing.

## Behaviour worth knowing
- A draft is an enrollment application with status `draft` (that status already existed). Saving never submits. Submitting a draft
  turns the same record into a submitted one, so nothing is duplicated. Drafts survive refresh and logout.
- Masterlist = **approved** applications of the school year. Fully Paid = an invoice exists and the balance from verified payments is zero or less.
  Everything else (including approved students who have no invoice yet) is Pending Payments. Nobody is removed for owing money.
- "Student ID" shows the LRN (the database has no separate student number).

## Tested here vs. not tested
**Tested locally** (PHP 8 + a MariaDB built from the merged `cfs_sis_mariadb.sql` + headless Chromium): everything in the table above
(sign-up checks, popup open/close by button/outside click/Escape and on a phone width, Save / Save & exit / resume / Cancel warning /
submit, Masterlist access (no login 401, guardian 403, registrar 200), categories, section save, tab switching, image zoom, GCash test check with
and without the test table, missing-`section_name` fallback, running the migration twice).
**Not tested:** anything that needs Supabase (the sandbox cannot reach it), so the website-content tab only confirms the tab switches;
**PDF** zoom (needs pdf.js from cdnjs, blocked here; if it cannot load, the browser's own PDF viewer is shown with zoom buttons disabled);
the older real data in your own database.

## Heads-up
`config/cloud.php` contains the Supabase password in plain text. Do not share the project or ZIP publicly; consider rotating that password.
