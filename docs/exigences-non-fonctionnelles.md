# Non-functional requirements

Numbers, not prose. Everything on this page is a figure something else
reads: a review that has to answer "is that fast enough?", an alert that
has to answer "is that too full?", a default that has to answer "how
often?".

**A figure here is a decision, not a measurement.** It is what the project
commits to, and the thing to change when reality proves it wrong — never
the thing to quietly stop checking. When a change makes one of these
numbers unreachable, the number moves in the same pull request, with the
reason written next to it.

The target installation this whole page describes: **one Belgian scout
unit, on shared hosting, administered by a volunteer who passes by once a
week.** Not a fleet, not a datacentre, and nobody watching a dashboard.

---

## 1. Sizing target

| Figure | Value | Where it comes from |
|---|---|---|
| Members per scout year, typical | 178 | What `tests/fixtures/reference-dataset/`'s **committed** exports actually hold: 176 / 178 / 178 distinct `Tiers` across its three years, matching that fixture's own `README.md` |
| Members per scout year, design ceiling | 500 | `Core\Member\Service\MemberSearchService` reasons explicitly in "a few hundred members"; twice that is the point past which its in-memory, decrypt-everything search stops being defensible |
| Scout years kept online | 5 | Same docblock: "across five scout years it is five times the AES work" |
| Members in the whole database, ceiling | 2 500 | 500 × 5, the two rows above |
| Photos published per scout year | 800 | Roughly ten albums — camp, weekends, a few section outings. `gallery_max_media_per_album` (200) is a ceiling per album, not a typical figure |
| Stored bytes per photo | 2 MiB | **Three renditions are kept, not one**: `Modules\Gallery\Service\ImageProcessingService` writes large (`gallery_photo_max_dimension`, 3000 px, q90), medium (1200 px, q85) and thumb (300 px, q80), and `ProcessPhotoHandler` deletes only the original |
| Gallery volume expected | 8 GiB | The two rows above, over five years |
| Backups and attachments | 2 GiB | Around ten retained archives at roughly 150 MiB each — a `full_no_gallery` archive carries `vendor/`, which dominates it — plus five years of receipts, rental documents, mail attachments and member photos |
| `storage/` total expected | 10 GiB | The sum of the two rows above, and nothing else |

Three things that figure has to be read with.

**Video is not in it, and can dwarf it.** `gallery_max_video_upload_mb`
defaults to 2 048, so a dozen camp videos outweigh five years of photos.
A unit that publishes video sizes its hosting for video; nothing here
predicts that for them.

**One archive that includes the gallery breaks the sum.** A
`full_with_gallery` backup is larger than everything else in `storage/`
put together, which is why only one of them is ever kept — the
cross-family cap. The 2 GiB row above assumes that cap holds.

**10 GiB is more than the cheapest shared hosting gives**, which is the
point rather than an oversight: a unit keeping five years of gallery
online needs a plan sized for it, or it keeps fewer years online, or it
moves the gallery to object storage
(`Modules\Gallery\Service\Storage\S3StorageBackend`). Saying so is what
makes the disk block on Configuration > Maintenance worth reading — and
the declared quota worth filling in.

Past the ceiling the site is not expected to fail — it is expected to get
slow in the way §2 describes, and that is the signal to revisit the search
architecture rather than the hosting.

## 2. Rendering budget

Server-side render time, measured by `Core\Debug\RequestTimeline` on the
shared hosting described above, at the sizing ceiling of §1. These are the
numbers a timeline is read against; without them "is that slow?" has no
answer a review can hold anyone to.

| Page class | Budget | Examples |
|---|---|---|
| Ordinary page | **500 ms** | Home, a section's page, the calendar, a member's page |
| Costly page | **2 000 ms** | Member search across past years, statistics, « Justesse des tarifs », the invoice report |
| Background task pass | **20 s** | One `SchedulerRunner` pass; the budget `Core\Notification\Task\SendNotificationsHandler` already works under, and the shape every long task reuses (do some, reschedule the rest) |

Two figures that bound the ones above, and that come from the host rather
than from this project:

| Constraint | Assumed value |
|---|---|
| `max_execution_time` on shared hosting | **30 s**, and never more than 120 s |
| PHP memory limit | **128 MiB** |

A feature that cannot finish inside `max_execution_time` does not get a
bigger budget: it gets split into passes that reschedule themselves.

## 3. Recovery objectives

| Objective | Value | What it means |
|---|---|---|
| RPO — maximum data loss accepted | **7 days** | A restore may lose at most one week of the unit's work. This is what fixes the target default frequency of scheduled backups at *weekly* — **`backup_auto_frequency` still ships as `monthly`**, which is issue #286 |
| RTO — target time to service | **4 hours** | From "the site is gone" to "the site answers again", by a volunteer following the help topic, on a host they may have to sign up with that morning |
| Off-site copies | **at least 1** | A backup that only exists on the server that died is not a backup |
| Restorable on a *new* installation | **required** | A backup restorable only onto the installation that made it does not meet the RTO above in the one scenario that matters |

The last row is why the portable backup exists at all: an archive without
`storage/keys/master.key` restores onto this installation and nowhere
else.

## 4. Alert thresholds

Every operational check is *armed* or *triggered*, and only ever
re-arms below a **strictly lower** value than the one that triggered it.
Without that gap, a value oscillating around a single threshold notifies
on every scheduler pass, and the alert is switched off within days.

| Check | Triggers at | Re-arms at |
|---|---|---|
| Disk usage | 85 % | 75 % |
| Age of the last successful backup | 10 days | 3 days |
| Age of the last real cron pass | 48 h | 6 h |
| Failed e-mail sends, over a 24 h window | 5 | 0 |
| A request answered without encryption | seen | none seen for 24 h |
| A portable backup left on the server | present for 7 days | deleted |
| Age of the last successful off-site backup | 10 days | 3 days |
| Off-site storage quota used | 90 % | 80 % |

The HTTPS row is the one whose trigger side is not a number, and it is the
rule's limit case rather than an exception to it. Every other check watches
a level that drifts and has to decide how far it must come back; that one
watches an event — a request answered in clear, seen as it happens — so the
trigger is the event and the only number left is how long the site has to
stay quiet before the alert believes it. A site answering on *both* schemes
therefore never goes quiet, which is the right answer and not a tolerated
one: it is still handing passwords to the network.

Read the backup-age rows together with §3. A 10-day alert only makes sense
on top of a weekly scheduled backup, which is the RPO's other half — one
missed run plus the time an upload takes, and no more.

That half **is** shipped: `backup_auto_frequency` defaults to `weekly`, and
it had to change in the very commit that turned this alert on. A monthly
default would have left the alert *triggered* on a default installation two
thirds of the time — D1's own extinction mechanism, aimed at the alert it
exists to protect — so the two landed together (issue #286).

The **off-site** row carries the same two numbers as the local one on
purpose, and it is the more important of the two: in the scenario the RPO
is actually about — the server is gone — the off-site copy's staleness
*is* the realised data loss, so letting it drift further than the local
copy would be tolerating exactly what §3 refuses.

Notification and attention point read the same check, run once. The
notification says "this has just tipped over"; the attention point says
"this is still true".

## 4bis. Backup retention

How many backups the server keeps, **per family**. The family is deduced
from the type and never stored (`Core\Maintenance\BackupFamily`).

| Family | Types | Setting | Default |
|---|---|---|---|
| Manual | `database`, `full_config`, `full_no_gallery`, `full_with_gallery` | `backup_keep_manual` | 3 |
| Scheduled | `auto_backup` | `backup_keep_scheduled` | 3 |
| Pre-operation | `auto_update`, `auto_reset` | `backup_keep_operational` | 3 |

Plus one cap across all families: **a single archive containing the photo
gallery**, not configurable — and it applies to `full_with_gallery`,
`auto_update` and `auto_reset` alike.

**Those last two are why the pre-operation quota is an upper bound rather
than a number an installation observes.** The name of a type says nothing
about its contents: a pre-operation backup calls `createFileBackup(true)`,
because the operation it guards against can wipe `storage/gallery/` and
its safety copy has to hold it. So every pre-operation archive is
gallery-bearing, the cap of one binds before `backup_keep_operational`
does, and an installation keeps **one** of them. The setting says so.

This is a divergence from the chantier document, which set the cap on the
premise that « une `full_with_gallery` peut peser plus que les huit autres
réunies » — that arithmetic assumes the other eight exclude the gallery,
and three of them do not. Three gallery-sized safety copies is six GiB
against the §1 sizing of a two-GiB gallery on shared hosting, so the cap
is what has to win. Whether an update's safety copy needs the gallery at
all is a separate question, raised as issue #298.

One number for everything was the previous rule, and it kept the wrong
backups: the automatic ones outnumber the deliberate ones on any
installation actually being maintained, so three consecutive updates
evicted the full backup an administrator had taken five minutes earlier.
A single ordered list keeps the noise and drops the signal.

The gallery cap is the one that decides whether the disk holds. Two
gallery archives are routinely more than every other backup combined,
which is also why that one is not a setting: an administrator who wants
a second copy has somewhere better to put it than the server it is
meant to survive.

Only a **completed** backup counts towards a quota or the cap. A row is
inserted `pending` before its background job runs and a failed job leaves
it behind with no file, so counting every row let an empty failure evict
the archive that had actually succeeded. One failure per family is kept —
it is what says the backup stopped working — and rows still being written
are never removed.

Purging runs **on creation and nowhere else**, and only for the family
just written. At boot or during a migration, an installation holding
five backups would watch two of them vanish in the middle of an update
nobody connected to retention.

## 5. Technical baseline

| Item | Requirement |
|---|---|
| PHP | **>= 8.4** (`README.md` § Prérequis) |
| MySQL | **>= 8.0**; the reference production installation is **MariaDB 10.11** and both engines are supported (`AGENTS.md` § Database) |
| PHP extensions | `openssl`, `pdo_mysql`, `zip` (with libzip crypto support for encrypted archives — `BackupService::supportsZipEncryption()` degrades cleanly without it), `mbstring`, `sodium` when available |
| Server-side runtime | No shell, no `mysqldump` binary, no Composer, no Node on the hosting server |
| Cron | `php public/cron.php` every minute (`README.md` § La tâche cron) |

### Supported browsers

The last two major versions of Chrome, Firefox, Edge and Safari, desktop
and mobile, plus the installed PWA on Android and iOS. That is what
`tsconfig.json`'s `ES2020` target already assumes; production JavaScript
is shipped unbundled and untranspiled (`AGENTS.md` § CSS / frontend), so
the language level in the source *is* the browser requirement.

### Accessibility

**WCAG 2.2 level AA** is the target for every page an end user reaches.
Two consequences already written down elsewhere, restated here only
because they are the ones with a number: touch targets meet the 24×24 CSS
pixel minimum AA requires, with 44 px as a comfort goal for small controls
(`design.md` §7.2), and every interactive control has an accessible name —
which is what the end-to-end suite asserts by reaching elements through
`getByRole`/`getByLabel` (`README.md` § Tests de bout en bout).

Nothing measures this automatically yet. Adding `axe-core` to the existing
Playwright suite is the natural next step and is deliberately not in this
document's scope.
