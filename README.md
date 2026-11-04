# Appraisals (local_appraisal)

Staff appraisals for Moodle: an appraiser and the person being appraised each complete their own parts of a structured
form, and the appraiser signs it off with an optional overall grade.

Appraisals is a site-wide layer that sits outside courses and categories. Administrators build reusable appraisal
templates and grading scales. An appraiser opens a person's appraisal dashboard, assigns a template and starts a cycle.
The person being appraised (the appraisee) completes their fields and submits them; the appraiser completes theirs,
reviews both sides and signs the appraisal off. A new cycle is started by hand when the next appraisal is due.

## Features

- **Reusable templates with versions.** Templates are edited as drafts and published. Publishing a new version never
  changes appraisals already completed: each cycle stays pinned to the version it started on, and later cycles use
  the latest published version.
- **Seven field types**: short text, long text, single choice (radio buttons or a dropdown), multiple choice, graded
  rating, date and number (with optional limits and decimal places). Optional sections have a heading and a
  description, and fields and sections are reordered by drag and drop (with keyboard-accessible Move up and Move down
  buttons).
- **Both people, one form.** Each field is completed by the appraiser, the appraisee or both. A "both" field holds two
  separate answers, so a self-rating can sit next to the appraiser's rating and nobody ever edits the other person's
  answer. A field is either required or optional; a required "both" field needs both people's answers.
- **Hidden until submitted.** Until the appraisee submits their part, each person sees only their own answers. After
  that, both see everything. This is enforced on the server, not just in the page.
- **Grading scales.** Build ordered levels (a label, a numeric value and an optional description) once and use them
  for graded-rating fields and for the overall grade. People choosing a level see its label and description, never its
  number. A scale is locked once a published template version uses it, so past grades keep their meaning.
- **Overall comments and grade**, set per template version as Off, Optional or Required.
- **Autosaving form.** Every answer saves as it changes, so an appraisal can be paused and finished days later.
  Progress counts for each person show what is still outstanding.
- **A clear lifecycle**: Not started, In progress, Submitted and Completed. The appraisee submits their part, which
  locks it. The appraiser can hand it back with a note or sign it off, with a completion date that can be backdated.
  Reset, cancel, change the due date or label, take over from another appraiser, and delete are all available to the
  right people. Status changes and ownership transfers are kept in a history, and the main steps fire Moodle events.
- **Due dates and reminders.** A cycle can have a due date, shown with an Overdue badge once it has passed. A
  reminder goes out a set number of days before it is due, once per due date.
- **Dashboards.** Each person has an appraisal dashboard with their current cycles and history. Appraisees see their
  own under **My appraisals**; appraisers get a **People I appraise** worklist that puts overdue appraisals and those
  waiting for sign-off first.
- **Three ways to give appraisers access**: a profile field naming each person's appraiser, links between cohorts, and
  a site-wide role for HR. See *Configuration*.
- **Notifications** through Moodle messaging when an appraisal starts, is submitted, is handed back, is signed off,
  falls due or changes owner, each worded for the person receiving it.
- **Audit trail**: every status change and ownership transfer is kept, and the plugin fires standard Moodle events that
  appear in the logs.
- **Privacy API** support for export and deletion of personal data.

## Requirements

- Moodle 5.2 or 5.3 (`$plugin->requires = 2026041000`). No other Moodle versions are supported at present; later
  releases will be added as they are tested.
- Any database your Moodle release supports. The plugin uses only XMLDB and the DML API and is tested on MySQL and
  PostgreSQL.
- No other plugins, no external services and no Composer or build step on the server: the release ZIP works as
  downloaded.
- Moodle cron must be running for due date reminders to be sent.

## Installation

### Installing via uploaded ZIP file

1. Log in to your Moodle site as an administrator and go to *Site administration > Plugins > Install plugins*.
2. Upload the ZIP file with the plugin code. If the plugin type is not detected automatically, choose *Local plugin
   (local)* under *Show more...*, and set the root directory name to `appraisal`.
3. Check the plugin validation report and finish the installation.

### Installing manually

Moodle 5.2 and 5.3 serve their web pages from the `public/` directory, so the plugin goes in:

    {your/moodle/dirroot}/public/local/appraisal

Then log in as an administrator and go to *Site administration > Notifications* to complete the installation, or run

    $ php admin/cli/upgrade.php

from the Moodle root.

### What installation creates

- Twelve database tables, all prefixed `local_appraisal_`.
- An **Appraisal administrator** role (shortname `appraisaladmin`), assignable at system level, which grants access to
  every user's appraisal dashboard. It is meant for HR and administrators, not for everyday appraisers. The role is left
  in place if the plugin is uninstalled, so existing role assignments are not silently lost; a reinstall reuses it.
- A daily scheduled task, *Send appraisal due date reminders* (`\local_appraisal\task\send_due_reminders`), which runs
  at 02:15 by default.

## Configuration

All the plugin's admin pages are under *Site administration > Plugins > Local plugins > Appraisals*: **Manage
templates**, **Grading scales**, **Appraiser cohorts** and **Settings**.

### 1. Decide who can appraise whom

Who can open a person's appraisal dashboard (and so appraise them) is decided by three independent gates. A person gets
in if any one of them allows it. Nobody can ever appraise themselves, whatever their roles.

| Gate | Best for | Who it reaches |
| --- | --- | --- |
| **Profile field** (optional setting) | Line management, where each person has one appraiser. **Start here.** | The one appraiser named in the person's profile field |
| **Cohort links** | Appraisers who share a group of people, such as co-managers over a team or HR business partners over a department | Every member of the appraiser cohort reaches every member of the linked appraisee cohort |
| **Site-wide role** (`accessalldashboards`) | HR and administrators | Everyone |

**Profile field (recommended for line management).** Go to *Settings*, tick **Enable profile-based access** and choose
the **Appraiser profile field**: a custom text profile field. Put the appraiser's **username** in that field for each
person, and the named appraiser can reach that person's dashboard. The field can be filled by *Upload users* (a
`profile_field_<shortname>` column) or by an HR sync, so it can stay up to date automatically.

> **Important:** the appraiser profile field must be locked so users cannot edit it themselves, otherwise a user could
> name their own appraiser. The settings page shows a warning if the chosen field is not locked. In *Site administration >
> Users > User profile fields*, edit the field and set *Is this field locked?* to *Yes*.

**Cohort links.** Go to *Appraiser cohorts* and click **Add link**, choosing an appraiser cohort and an appraisee
cohort. Members of the first can appraise members of the second. For example, linking *Sales managers* to *Sales team*
lets every sales manager appraise every member of the sales team. Cohorts and their members stay in Moodle's own cohort
tools (*Site administration > Users > Cohorts*, cohort upload, or an HR sync): add someone to *Sales team* and they are
reachable at once, remove a manager from *Sales managers* and their access ends at once, with nothing to change in the
plugin. Deleting a cohort deletes the links that name it.

Cohort links are all-to-all, so they are a poor fit for line management: it would need a one-person appraiser cohort and
a team cohort for every manager. Use the profile field for that.

**Site-wide role.** Assign the **Appraisal administrator** role at system level (*Site administration > Users >
Permissions > Assign system roles*) to HR staff and administrators. Managers hold the capability by default. Because
many people on a site are appraisers, avoid giving the capability to everyday appraisers: it reaches everyone.

### 2. Build a grading scale (optional)

Go to *Grading scales* and click **New grading scale**. Add levels from lowest to highest; each has a label, a numeric value that
must rise from one level to the next, and an optional description shown to the person choosing it. A scale needs at
least two levels to be usable. Once a published template version uses a scale, only wording corrections are allowed; to
change its levels, duplicate it and use the copy in a new version.

### 3. Build a template

Go to *Manage templates* and click **New template**. Add sections and fields to the draft, choose who completes each
field and whether it is required, set the overall comments and overall grade options and, if wanted, a due date
reminder, then click **Publish**. Only published templates can be assigned.

To change a template later, use **Create new draft**, edit the draft and publish it. Typos in the current version can be
fixed in place with **Correct wording** without creating a new version.

### 4. Run an appraisal

Appraisers find the plugin on their own profile page, under the **Appraisals** section:

- **People I appraise** lists the people the appraiser already reaches through a gate (for holders of
  `accessalldashboards`, the people they already have an appraisal with). Holders of `accessalldashboards` also get a
  **Find a user** search here to reach someone new.
- On a person's dashboard, **Assign template** creates the appraisal, with an optional cycle label and due date.
  **Start new appraisal** begins the next cycle once the last one is complete.
- The appraisee opens **My appraisals**, completes their fields and clicks **Submit my part**.
- The appraiser completes their fields and either **Hand back** (with an optional note, which reopens the appraisee's
  fields) or **Sign off**, choosing an overall grade where the template asks for one.

### Settings

| Setting | Default | Purpose |
| --- | --- | --- |
| Enable profile-based access | Off | Lets the appraiser named in a profile field reach that person's dashboard |
| Appraiser profile field | Not chosen | The custom text profile field that holds each person's appraiser's username |

## Capabilities

All capabilities are at system level.

| Capability | Allows | Default |
| --- | --- | --- |
| `local/appraisal:managetemplates` | Create, edit, publish, archive and delete templates and grading scales (deleting a template that has appraisals also needs `deleteappraisals`) | Manager |
| `local/appraisal:managecohortlinks` | Add and remove appraiser cohort links | Manager |
| `local/appraisal:appraise` | Act as an appraiser for anyone the user can reach through a gate | Authenticated user |
| `local/appraisal:completeown` | View one's own appraisals and complete and submit one's own fields | Authenticated user |
| `local/appraisal:accessalldashboards` | Reach any user's appraisal dashboard | Manager, Appraisal administrator |
| `local/appraisal:deleteappraisals` | Permanently delete a person's appraisal with all its cycles | Manager |
| `local/appraisal:viewreports` | Reserved for a future release; grants nothing yet | Manager |

`appraise` is granted to every authenticated user because it is dashboard access, not this capability, that controls who
can appraise whom.

## Notifications

The plugin adds six message providers. Users can change how they receive each one under their notification
preferences; all default to web and email. Where a message can reach both people, each gets wording written to them.

| Notification | Sent to |
| --- | --- |
| New appraisal started for you | The appraisee, when the template has fields for them to complete |
| Appraisal submitted for you to review | The appraiser, when the appraisee submits |
| Your appraisal handed back | The appraisee, with the appraiser's note if there is one |
| Your appraisal signed off | The appraisee |
| Appraisal due date reminder | The appraiser, and the appraisee while their part is still open |
| Appraisal ownership changed | The previous appraiser, when someone takes the appraisal over |

A due date reminder is sent once per due date, only while the due date has not passed. Changing the due date to a new
day means the new date gets its own reminder.

## Privacy

The plugin stores personal data: which appraisal templates apply to each person, the answers, comments and grades
recorded about them, who recorded each answer and when, and the history of status changes and ownership transfers.
Message content is stored by Moodle's messaging system.

The plugin implements the Moodle Privacy API, so this data is included in data exports and handled by data deletion
requests:

- Exporting a person's data as the **appraisee** includes their appraisals. The appraiser's answers, overall comments
  and grade are included only once the appraisee could see them in the plugin (after they have submitted, or when the
  appraisal is complete).
- Deleting a person's data as the **appraisee** removes their assignments and appraisals entirely.
- Deleting a person's data as an **appraiser** or as the author of a template anonymises them (shown as *Deleted
  user*) rather than deleting what they recorded, because those records are other people's appraisals.
- Template, scale and cohort link definitions are site content and are kept; only the record of who last edited them is
  cleared.

The plugin stores its data in the system context. Moodle's data registry can protect system context data through a
retention period, in which case an erasure request appears to do nothing, so check *Site administration > Users >
Privacy and policies > Data registry* (the system context's retention period) before relying on erasure.

## Known limitations

- One appraiser per record. There is no second-line countersign and no appraisee acknowledgement after sign-off.
- Appraisals are assigned one person at a time, and cycles are started by hand: there are no appraisal rounds and no
  bulk assignment.
- Cohort links are all-to-all: every appraiser in the cohort reaches every member of the linked cohort.
- The profile field names one appraiser per person.
- Appraisees cannot choose their own appraiser.
- There is no calculated overall score: the overall grade is chosen by the appraiser.
- There are no attachments, and answers are plain text, not rich text.
- Overdue is shown on screen only: there is no repeating overdue reminder.

## Support

- Bug reports and feature requests: <https://github.com/anoraklabs/moodle-local_appraisal-issues/issues>

When reporting a bug, please include your Moodle version (*Site administration > Notifications* shows it), the plugin
version, your database type and the steps to reproduce it.

## License

2026 Anorak Labs

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program. If not, see
<https://www.gnu.org/licenses/>.
