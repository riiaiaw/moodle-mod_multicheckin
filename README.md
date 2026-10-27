# Multi Check-in

An attendance activity for Moodle that records **several check-in points inside one session** —
arrive, mid-session, leave — instead of a single present/absent mark per class.

Each point has its own window, its own rule, and its own way of checking in. Every student's status
is derived from the points they did or did not reach, and the result is pushed to the gradebook.

- Requires **Moodle 5.2** or later
- No composer dependencies, no external service, no API key, no account to create
- Available in English and Simplified Chinese

---

## What it does

### Several points in one session

A session contains one or more check-in points. Each point has a name, a role (`first`, `mid`,
`last`), an opening offset and a closing offset relative to the session start, and its own scoring.
A student can be present at the first point, late at the second and absent from the third.

Points are not fixed in advance: a session template holds the default list, and any single
occurrence can add, drop or retime its points without touching the others.

### Three ways for students to check in

| Method | How it works |
|---|---|
| **Self check-in** | One button on the activity page while a point is open. Can be protected by a session password, overridable per point. |
| **QR code** | The teacher shows a code for the current point; students scan it. A *QR only* mode hides the button and the password entirely, so a scan is the only way in. |
| **Photo** | The student's camera opens with a 4:3 landscape frame, and what is framed is what is stored. Photos can be set to require teacher approval before they count. |

Teachers can also mark any student by hand on the session sheet, which is what makes an approved
photo, a forgotten phone or a broken camera a two-click fix rather than a data repair.

### Against proxy attendance

- **IP fence** — a list of IPv4 and IPv6 addresses or CIDR ranges, for campus networks. Both
  families work in one list; a range only ever matches an address of its own family.
- **GPS fence** — a radius around a classroom, checked against the position the browser reports.
- **Audit log** — every attempt is recorded with the client address, the distance from the
  classroom, and whether it was allowed, together with a reason code. "The system blocked me" is a
  claim that can be checked rather than argued about.
- QR check-ins can bypass the fence (for rooms where projecting the code is itself the proof of
  presence), or be held to it like everything else.

### Statuses and grades

Each student ends a point as present, late, early leave, late and early, absent, or on approved
leave. A point can be worth different amounts for each of those outcomes.

Across a series of sessions, the activity grade can be the average, the best result, or a fixed
value. Grading goes through `grade_update()`, so it behaves like any other graded activity.

### Reports

- A per-session report, and a whole-term matrix of students against sessions that exports to CSV.
- A print-friendly layout, since the common need is a sheet to sign.
- A geolocation audit page listing refused attempts by reason.

### Reminders

Students who have already checked in can be reminded before the next point closes. The rule can be
chained (only those who reached the previous point), independent (everyone eligible), or off.

Times are rendered in each recipient's own timezone, taken from their profile — a named zone such
as `Asia/Shanghai` is passed through as a name, not converted to a number.

### Recurring sessions

A series can be generated weekly or daily over a date range, with an optional holiday skip. The
number of sessions one submission may create is capped by a site setting (sixty by default).

### Classroom library (optional)

If the companion plugin **`availability_gpsloc`** is installed, the activity form offers a
dropdown of shared classrooms and a map for picking coordinates. Without it, the same fields are
filled in by hand and everything else works unchanged. Coordinates are stored in this plugin's own
table, so uninstalling `availability_gpsloc` later does not affect activities that already have
them.

---

## Requirements

- Moodle 5.2 or later
- The PHP version that Moodle release requires
- No other plugin is required. `availability_gpsloc` is optional and only adds the classroom
  library described above.

---

## Installation

### 1. Put the folder in the right place

The folder name must be **`multicheckin`** — no `mod_` prefix, no rename. Moodle builds the
component name from the plugin type and the folder name, so a folder called `mod_multicheckin`
resolves to `mod_mod_multicheckin` and the plugin will not be found.

| Moodle | Path |
|---|---|
| 5.1 and later (including 5.2) | `<site root>/public/mod/multicheckin/` |
| 4.x | `<site root>/mod/multicheckin/` |

On 5.1 and later the authoritative `config.php` sits one level above `public/`.

When the folder is in place you should see `version.php` directly inside it:

```
.../public/mod/multicheckin/version.php
```

### 2. Run the upgrade

Visit **Site administration → Notifications**, or from the command line:

```bash
php admin/cli/upgrade.php
```

The plugin's tables are created and the activity becomes available in the activity chooser.

### Upgrading from an older copy

Replace the folder, then run the upgrade as above. If you are moving from the 4.x layout to the
5.1+ layout, the folder has to move too:

```bash
cd <site root>
mv mod/multicheckin public/mod/multicheckin
php admin/cli/upgrade.php
```

Existing data is kept. Do not uninstall and reinstall to upgrade.

### A warning about uninstalling

Uninstalling the plugin deletes its tables **and the files students uploaded** — leave requests and
check-in photographs — along with the computed results. This cannot be undone. Take a course
backup, or export the reports first.

---

## Setting up an activity

1. Add the activity to a course, as a teacher with the `mod/multicheckin:addinstance` capability.
2. Set the session window, the scoring for each status, and the check-in methods you want to allow.
3. Open **Manage points** and add the check-in points: name, role, and the offsets at which each
   one opens and closes relative to the session start.
4. Optionally set a fence (IP ranges, a GPS radius, or both) and a check-in password.
5. On the day, open the session and either project the QR code or leave the page open for
   self check-in.

For a recurring class, create a series instead: the sessions are generated from a template, and
each one can still be edited on its own.

---

## Capabilities

| Capability | Default |
|---|---|
| `mod/multicheckin:addinstance` | Editing teacher, manager |
| `mod/multicheckin:view` | Student, teacher, editing teacher, manager |
| `mod/multicheckin:checkin` | Student, teacher, editing teacher, manager |
| `mod/multicheckin:manage` | Editing teacher, manager |
| `mod/multicheckin:viewreports` | Teacher, editing teacher, manager |
| `mod/multicheckin:recompute` | Editing teacher, manager |
| `mod/multicheckin:manageplaces` | Editing teacher, manager |

---

## Privacy

The plugin ships a full privacy provider. It declares its tables, implements the metadata and
user-list APIs, and can export, delete and anonymise a user's data — including check-in
photographs, coordinates and leave attachments.

Two tables are deliberately left out of the metadata declaration: the activity configuration row
and the point definitions. They hold no personal data, and listing a table without naming its
fields would say less than leaving it out.

---

## Languages

English (`en`) and Simplified Chinese (`zh_cn`). Translations are welcome as pull requests against
the `lang` folder.

---

## License

GNU General Public License v3 or later. See [LICENSE](LICENSE).

---

## Support

Bugs and feature requests: <https://github.com/riiaiaw/moodle-mod_multicheckin/issues>
