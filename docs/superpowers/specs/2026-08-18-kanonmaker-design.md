# Kanonmaker — design specification

**Date:** 2026-08-18
**Status:** approved, ready for implementation planning
**Repository:** `git@github.com:katakamm/kanonmaker.git`

## 1. Purpose

Czech students assembling their maturita reading list must pick 25 works from
their school's canon while satisfying four selection criteria that span literary
period, national literature, literary form and authorship. The canon is a long
document; the criteria are prose. Checking a half-finished list against them by
hand is slow and error-prone, and two of the criteria are effectively
uncheckable without knowledge the document never states.

Kanonmaker turns that into a live check. A student searches the canon, adds
works to a list, and sees at all times which criteria are met and which are not.
Everything else in the application exists to serve that loop.

The first school is Gymnázium Jana Keplera (GJK), school year 2025/2026.

## 2. Scope

### In scope (v1)

- Search the canon (diacritics-insensitive).
- Browse the whole canon by chapter, filterable by tag.
- Build a personal list by adding and removing works.
- Live rule checking with an explanation of what is still missing.
- Export the list as a PDF whose contents the student chooses.
- Every work carries a label for each category it belongs to.
- Student accounts (e-mail + password).
- Administration of works, tags, rules and canons on a separate domain.

### Out of scope (v1)

Named so that their absence is a decision rather than an oversight:

- Any teacher or admin view of students' lists.
- A user interface for a second school (the schema carries `school_id`, but only
  GJK is seeded).
- Book covers, sharing lists between students, reading progress, per-book notes.
- E-mail. The server sends none, so password reset is an administrator action.

## 3. Users and access

Students register with e-mail and password; each list belongs to an account, so
it can be opened from any device. This was chosen over share links or
browser-local storage because a reading list is built over months and must
survive a cleared browser.

Two hostnames, both resolving to the internal address 192.168.100.173:

| Host | Role |
| --- | --- |
| `kata.doma.slimak.cz` | student application |
| `kata-admin.doma.slimak.cz` | administration, `role = admin` only |

Deployment to production happens on the owner's instruction, given as "put to
production". Production is existing managed hosting at `kata.slimak.cz` and
`kata-admin.slimak.cz`, already holding its own certificates, so no DNS or
certificate work falls to this project. The procedure, paths and credentials are
documented in `~/CLAUDE.md`, which is authoritative and must be read at
deployment time rather than reproduced here.

## 4. The source document

The canon and its criteria come from a Google Doc, exported as HTML and
committed to the repository as `data/canon-2025-2026.html`. The HTML export is
used rather than the plain-text export, because the text export merges adjacent
paragraphs and silently glues unrelated entries together.

The document contains seven chapters, 358 entries and approximately 454
selectable works.

| Chapter | Entries |
| --- | ---: |
| Světová a česká literatura do konce 18. století (kromě preromantismu) | 53 |
| Světová literatura od preromantismu do konce 19. století | 36 |
| Česká literatura od preromantismu do konce 19. století | 17 |
| Světová a česká dramatická tvorba 20.–21. století | 15 |
| Světová a česká poezie 20. a 21. století | 40 |
| Světová próza 20. a 21. století | 127 |
| Česká próza 20. a 21. století | 70 |

### The school's criteria

1. **By literary period** — up to the end of the 18th century: at least 5 titles,
   drawn from at least three of *starověk / středověk / renesance / baroko /
   klasicismus a osvícenství*; from pre-Romanticism to the end of the 19th
   century: at least 3; 20th and 21st century: at least 8.
2. **By national literature** — world literature at least 8 titles, Czech
   literature at least 8.
3. **By literary form** — drama at least 3, poetry at least 3 (of which at least
   one is a Czech poetry collection or composition from the second half of the
   20th century or later), prose at least 3.
4. **By author** — at most two titles by one author, and they must be of
   different literary forms.

Total list length: 25 titles. One work counts toward several criteria at once,
which is why the minima (16 + 16 + 9) exceed 25.

### Tag coverage in the source

The chapter headings do not carry every tag the criteria need:

| Tag | Derivable from chapter |
| --- | --- |
| Period (three buckets) | all 358 entries |
| Czech vs. world | 250 entries; chapters 1, 4 and 5 are mixed |
| Form (poetry / prose / drama) | 252 entries; chapters 1, 2 and 3 are mixed |
| Subperiod (starověk … klasicismus) | none |
| "Czech poetry, 2nd half of 20th century or later" | none |

About 93 entries carry a parenthetical form hint such as `(poezie)` or
`(povídkový soubor)`. After exploiting chapters and hints, roughly 260 tags
remain unknown and must be supplied from literary knowledge.

## 5. Data model

```
school ──┬── canon (school_year, required_total)
         └── (canon owns both its works and its rules)

canon ───┬── chapter    the seven document sections
         ├── work       one selectable title
         ├── tag        group: period | subperiod | nationality | form | special
         └── rule       type + parameters

work ────┬── work_author   (many-to-many)
         └── work_tag      (+ source, + verified)

user ──── list ──── list_item (work, position)
```

Tables and their significant columns:

- `school(id, name)`
- `canon(id, school_id, school_year)` — one canon per school per year. Creating
  next year's canon leaves this year's works, tags and finished lists untouched.
  The required list length is not a column here: it is the `min_total` rule, so
  that there is one place to change it.
- `chapter(id, canon_id, name, sort_order)` — retained for browsing and for
  provenance of chapter-derived tags.
- `author(id, surname, first_name, display_name, sort_key)`
- `work(id, canon_id, chapter_id, title, note, source_line, sort_order,
  search_text)`
- `work_author(work_id, author_id)`
- `tag(id, canon_id, tag_group, code, label)`
- `work_tag(work_id, tag_id, source, verified)` where `source` is one of
  `document`, `inferred`, `human`.
- `user(id, email, password_hash, display_name, role, created_at, active)`
- `list(id, user_id, canon_id, created_at, updated_at)` — one list per student
  per canon, enforced by a unique key on `(user_id, canon_id)`. A student
  starting the next school year gets a new list without losing the old one.
- `list_item(list_id, work_id, position)`
- `rule(id, canon_id, sort_order, type, params, label, enabled)`

### Two modelling decisions

**A work is one title, not one document line.** `SHAKESPEARE, William: Hamlet;
Král Lear; Macbeth; …` becomes nine works sharing one author, because the
student selects one of them. Entries joined with `+`, such as `RIMBAUD: Sezóna v
pekle + Iluminace + Dopisy vidoucího`, remain a single work, because the `+`
means the parts are read together. The importer therefore splits on `;` and
never on `+`. 63 entries list several titles this way.

**Work-to-author is many-to-many.** Four entries have several authors
(`MRŠTÍKOVÉ, Alois a Vilém`, `STRUGAČTÍ, Arkadij a Boris`, `BABAN, MAŠEK,
GRUS`, `ŠINDELKA, MAŠEK, POKORNÝ`). Marek Šindelka appears both alone and as a
co-author, so selecting *Zůstaňte s námi* and *Svatá Barbora* really does place
two Šindelka titles on a list, and criterion 4 must see it. Twelve author strings
appear on more than one line and merge into one author record; Nezval, Jáchym
Topol and Pavel Novotný each appear once under poetry and once under prose.
Authors are matched on the normalized full `SURNAME, First` string, so Karel
Čapek, Josef Čapek and Karel Matěj Čapek Chod remain three people.

Twenty-one entries have no author (Beowulf, Edda, Starý zákon, anthologies such
as `Kéž hoří popel můj (ed. Václav Černý)`). They carry no author row; an
editor's name goes into `note`, never into an author record, so that it cannot
consume the two-titles-per-author budget.

## 6. Rules engine

Rules are rows, not code. The four criteria need exactly four rule types, and
every foreseeable change is then a number edited in the administration rather
than a deployment.

| Type | Parameters | Serves |
| --- | --- | --- |
| `min_total` | `min` | list length (25) |
| `min_count` | one tag, `min` | criteria 1, 2, 3, and the Czech-modern-poetry clause via the `special` tag |
| `min_distinct` | scope tag, tag group, `min` | criterion 1's requirement of at least three different subperiods among the pre-19th-century works |
| `max_per_author` | `max`, `distinct_form` | criterion 4 |

`max_per_author` with `distinct_form = true` fails a list containing *Hamlet* and
*Macbeth* (two dramas by one author) while accepting *Hamlet* and *Sonety*. This
is a common hand-checking mistake and one of the app's main reasons to exist.

A `min_count` filter names exactly one tag, never a combination. A criterion
that reads as a conjunction — "Czech poetry from the second half of the 20th
century or later" — is expressed as a single tag in the `special` group, applied
during review. This keeps the engine trivial to reason about and moves the
judgement to where a human can see it.

Rule *types* live in code; only their parameters are editable, so an
administrative typo cannot produce a rule the engine is unable to evaluate.

Evaluating a list returns, for every rule: its label, whether it is satisfied,
the current count, the required count, and the set of tags that would improve it.
The last item is what lets an unmet rule link to the works that would fix it.

## 7. Import pipeline

The import is a pure function of two committed files — the document snapshot and
a curated tag file — so the same inputs always produce the same database, and
`git diff` shows exactly what a new canon changed. The live Google Doc is never
read at runtime; otherwise the database could change silently while a student is
finishing a list.

Stages:

1. **Parse** — read `data/canon-2025-2026.html`, extract paragraphs, split into
   chapters at the seven known headings, and treat every remaining paragraph as
   an entry. Retain the raw text as `work.source_line`.
2. **Split** — separate author part from titles at the first colon; split titles
   on `;`; extract parenthetical material into `note`. A handful of entries use a
   comma as a further separator (`HAVEL: … Odcházení, Pokoušení`; `AJVAZ: Cesta
   na jih, Lucemburská zahrada`). The importer does not split on commas, because
   commas also occur inside author names and titles; these few entries are
   corrected in the curated data file instead of by a fragile rule.
3. **Tag** — apply, in order: tags implied by the chapter; form hints found in
   parentheses; and the curated file `data/tags-2025-2026.csv`, which supplies
   the roughly 260 tags the document does not state. Chapter-derived tags are
   stored with `source = document`, everything from the curated file with
   `source = inferred, verified = 0`.
4. **Upsert** — match existing works on chapter plus normalized author plus
   normalized title. Update titles, notes and `document` tags; never modify a tag
   whose `source` is `human`. Re-importing must not undo an evening of review.

The curated tag file is authored as data in the repository rather than generated
at run time: it is reviewable, diffable, and keeps the importer deterministic.

Every run ends with a report — works created, updated and unchanged; tags by
source; and anything the importer declined to guess. The administration runs it
as a dry run first and applies it only after the numbers have been read.

## 8. Student application

Interface language is Czech throughout.

The design baseline is a phone: almost all students will use nothing else.
Screens are designed and reviewed at phone width, tap targets are large, wide
tables and hover-only interactions are avoided, and the controls used most —
search, add to list, rule progress — sit within thumb reach. The desktop layout
is the adaptation.

Six screens: login and registration; my list (home); search; browse the canon by
chapter with tag filters; work detail; export.

**My list** shows the works in order, each with a chip for every tag it carries,
above a sticky bar giving the count and the number of unmet rules. Tapping the
bar raises the full rule check: one line per rule, with its current and required
counts, and for criterion 4 the offending author named. Every unmet line is
tappable and opens the canon filtered to the works that would satisfy it, which
turns the rule check from a verdict into a shortcut.

**Search** is diacritics-insensitive: `capek`, `sindelka` and `zert` must find
*Čapek*, *Šindelka* and *Žert*, because students do not switch keyboard layouts
on a phone. This is served by a normalized, accent-stripped `search_text` column
rather than by collation, so behaviour does not depend on database configuration.

**Export** produces a server-rendered PDF (mPDF, with a font covering Czech
diacritics). The student chooses what it contains — name, school year, grouping
by chapter or a flat numbered list, category labels, and a summary of the rule
check — because not every student wants the same page handed in.

### Visual design

Warm, pastel, minimalist, on a subtle yellow ground. All colours are CSS custom
properties in a single file, `assets/tokens.css`, so replacing this placeholder
palette with the owner's own hex codes is one edit.

| Role | Value |
| --- | --- |
| Background | `#FBF6E9` |
| Card | `#FFFDF7` |
| Text | `#453D2E` |
| Primary action | `#5E877B` |
| Rule met / unmet | `#6E9A6E` / `#C98A3E` |
| Chip — období | `#DCE6F2` |
| Chip — podobdobí | `#F2E3DC` |
| Chip — národní literatura | `#DFEDDC` |
| Chip — forma | `#EFE0EF` |
| Chip — speciální | `#F5E6C8` |

Chips use pastel backgrounds with dark text, never white text: pastels cannot
carry white legibly, and the interface has to be readable on a phone outdoors.
An unmet rule is warm amber, never alarm red. Two self-hosted font families, a
serif for titles and a sans for the interface; no external font requests, since
the server is internal.

## 9. Administration

Same codebase, second virtual host, separate front controller, every route
behind a login with `role = admin`.

**Review queue.** The import leaves roughly 260 unverified tags. The queue groups
them by chapter and by inferred value, so a group of 41 works inferred as
*světová* can be confirmed in one action, or expanded to correct the two that are
wrong. Roughly 260 individual decisions become about twenty. Anything confirmed
or corrected becomes `source = human` and is protected from future imports. The
queue is ordered by consequence: subperiod and the Czech-poetry flag first, since
they drive the two rules that cannot be checked by hand, then form, then
nationality.

**Works** — create, edit and delete; fix titles, notes and tags; merge two author
records that prove to be the same person.

**Rules** — edit the minima of criteria 1 to 4, and enable or disable a rule.

**Canons** — create a new school year, point it at a new snapshot, import.

**Import** — dry run showing what would be created, updated and left alone;
apply only on confirmation.

**Users** — list registrations, grant administrator rights, deactivate an
account. The first administrator is created by a command-line script at setup,
not by a registration form guarded by a shared secret.

## 10. Technical approach

Plain PHP 8.3, no framework: a small router, PDO, server-rendered templates, and
vanilla JavaScript only for live search and the live rule counter. The
application is a database, a form and a rules engine; a framework would add more
concepts than it removes, and the codebase stays readable a year from now.
Composer is used for mPDF and PHPUnit. The cost accepted in exchange is that
session authentication, password hashing and CSRF protection are written by hand
and must be done carefully.

### Layout

```
/data/www/kanonmaker/
├── public/                 docroot: kata.doma.slimak.cz  → production www/
│   ├── index.php
│   └── assets/tokens.css
├── admin/                  docroot: kata-admin.doma.slimak.cz → production admin/
│   └── index.php
├── src/                    router, models, rules engine, importer
├── templates/
├── data/                   canon snapshot, curated tags CSV
├── db/migrations/          numbered .sql, applied by bin/migrate
├── bin/                    import, migrate, create-admin, backup
└── tests/
```

Only `public/` and `admin/` are ever web-served. Everything else — `src/`,
`templates/`, `data/`, `db/`, `bin/`, `vendor/` and the configuration — must sit
outside the document roots, because in production anything inside them can be
downloaded from the internet.

A front controller therefore must not assume the application root is its own
parent directory. Each reads an optional `approot.php` sitting beside it:

```php
$appRoot = is_file(__DIR__ . '/approot.php')
    ? (string) require __DIR__ . '/approot.php'
    : dirname(__DIR__);
```

In development the fallback applies and nothing extra exists. In production the
deployment writes an `approot.php` returning the directory one level above the
document roots. This needs no server configuration, which matters because we
have none.

### Infrastructure

Development follows the conventions already used on this server, with host and
container paths mapped identically so that no path translation is needed while
working.

- `kanon-www` — image `slimakcz/images:php83_2026_default`, `127.0.0.1:9058`.
- `kanon-db` — image `mariadb:10.11`, `127.0.0.1:3309`, named volume.
- One Apache configuration, `kanonmaker.conf`, with two virtual hosts whose
  document roots are `public/` and `admin/`, both handing `.php` to
  `proxy:fcgi://127.0.0.1:9058`.

`vendor/` and `config.local.php`, which holds the database password, stay out of
the repository. The canon snapshot and the curated tag file belong in it: they
are the inputs the import is reproducible from.

### Deployment to production

Production is a restricted shared-hosting container reached over SSH on port
8053. It has no root, no sudo, no Docker, and nothing on it can be installed or
restarted. Three consequences shape the build:

- **`rsync` is not available.** Whole directories go over as `tar` piped through
  SSH, single files over `scp`. `tar` overwrites but never deletes, so a file
  removed from the project lingers in production until it is deleted by hand.
- **Composer cannot be assumed on the far end**, so `vendor/` is shipped as part
  of the deployment rather than installed there. It stays out of git regardless.
- **The database is an external MariaDB host**, not a container. Only the
  connection details in `config.local.php` differ between environments; that file
  lives above the document roots and is never committed. Migrations are run from
  the development server, which can reach the production database directly.

PHP's `error_log` is locked by FPM and cannot be overridden, so the application
includes the site's own error-capturing handler (`chyby.php`) at the top of each
front controller and reads errors from the site's `tmp/php-error.log`.

The exact hostnames, paths, credentials and commands live in `~/CLAUDE.md` and
are read at deployment time, not copied into this specification.

### Security

Real classmates will hold real passwords here, so, although the application is
internal today: `password_hash` with the default algorithm; prepared statements
without exception; CSRF tokens on every POST; `HttpOnly` and `SameSite` session
cookies regenerated on login; rate-limited login attempts; errors logged and not
displayed; all output escaped.

### Testing

Test-driven, with effort concentrated where a defect is both likely and costly.

1. **Rules engine** — table-driven tests written before the engine. A miscount
   is the worst failure this application can have, because it tells a student
   their list is finished when it is not. Explicit cases: two dramas by one
   author must fail; a poem and a novel by one author must pass; five
   pre-19th-century works drawn from only two subperiods must satisfy the count
   and fail the distinct-period clause.
2. **Importer** — against a small committed HTML fixture: the Shakespeare entry
   yields nine works, the Rimbaud entry one, `Beowulf` an authorless work,
   `BABAN, MAŠEK, GRUS` three authors; and a second run creates nothing and
   overwrites no `human` tag.
3. **Search normalization** — `capek` finds `Čapek`.

Authentication, CSRF and PDF generation are verified by use rather than by
automated tests.

## 11. Open items

- The owner will supply the final colour palette as hex codes; until then the
  placeholder palette in `assets/tokens.css` stands.
- Production deployment happens on the owner's instruction "put to production",
  following `~/CLAUDE.md`, and not before.
