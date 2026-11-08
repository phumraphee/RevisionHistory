# Changelog

## 1.2.2 — 2026-11-08

- **One entry about many records.** `Outside::valuesShared($sql, $params, $note, $opts)` — the same call as
  `valuesRewritten()` — writes one entry for a statement that rewrote values on thousands of records, not one per
  record. Each different change is one line; each record is one row of the new table `revision_history_shared`
  that names its own lines and keeps its name as it was. A record's page shows the entry as its own, with only its
  lines, and Restore there puts back only those; the Everything page shows one card with the records under it.
  `Entries::addShared($entry)`: the same for an entry of another module (a term renamed on every record citing it).
  `add()`, `addMany()`, `around()` and `valuesRewritten()` are unchanged.
- The data check counts such an entry for each of its records. Their full copies (when kept) are taken away by the
  write and made again by the next check, which now makes any copy that is missing.
- New column `shared` on `revision_history_event`, new table `revision_history_shared`; upgrade from 1.2.1:
  Modules → Revision History → Upgrade. `bin/entries.php remove` removes an entry's record rows with it.

## 1.2.1 — 2026-11-08

- **Many values rewritten at once, recorded quickly.** `Outside::valuesRewritten($sql, $params, $note, $opts)`: a module
  that rewrote thousands of values in one statement and kept each value's old content hands over a query over that
  record; one entry per record is written with the same lines a save would have, records are read 500 at a time, and
  the data check's numbers are written down together. A record that had changed without a record before is still
  reported first.
- `Outside::around()` takes `$opts`: `run` (one job written in several calls is one card) and `automatic` (folded
  away on the page like other entries nobody was behind). The three-argument call works as before.
- `Entries` take `run` too, and `addMany($entries, ['limit' => 0])` lifts the 5,000-a-request limit for a job that
  writes one entry on each of many records.
- The data check reads the numbers of the resources one save touched together, not one by one: saves of many
  records through `Outside::around()` are much faster.
- "Show automatic (n)" counts every entry nobody was behind, saves too. New index `k_auto` on
  `revision_history_event`; upgrade from 1.2.0: Modules → Revision History → Upgrade.

## 1.2.0 — 2026-11-08

- **Entries from other modules.** A module can write into a record's history what happened to it that is not a
  value — a work came back from the conservator, a person checked it is in the right place, a set was moved in a
  tree. `RevisionHistory\Entries::fromServices($services)->add([...])`, or, without depending on this module, the
  event `history.entry` on the identifier `HistoryEntry` (README, "Entries from other modules").
- Each entry is a copy that reads on its own: its words, before and after, details, the person and the record's
  name are written down with it. Uninstalling the module that wrote it, or deleting the record, leaves it as it
  was; its links then read "module removed".
- The history pages get a **From** row (Values · each module, with counts) and a **Kind** menu; entries nobody
  was behind (a scheduled run) are kept and folded away behind **Show automatic (n)**. The export has them all.
- Undo stays in the module that wrote an entry: the card links there, and shows "undone … by …" once the module
  writes its undo as an entry naming the first one (`undoes`).
- `Entries::beside($pdo, $who, $via)`: the same entries from code that shares the database without starting
  Omeka (a page, a script), through a plain PDO.
- `php modules/RevisionHistory/bin/entries.php list | remove <module> [ref prefix] [--go]`.
- The checks for "somebody else saved since" leave entries out: an entry changes no value.
- New table `revision_history_entry`; three columns and an index on `revision_history_event`. Upgrade from 1.1.0:
  Modules → Revision History → Upgrade. Uninstall still leaves every table in place.

## 1.1.0 — 2026-11-07

- Field aliases: when a property is renamed, moved to another vocabulary or merged into another property, an
  alias (old term → new term) makes the history read its earlier changes under the new name. The Field filter
  finds them, the Field menu lists the field once, they are labelled as the field is now with a small "was …"
  note, and undo and restore write to the field as it is now. The changes themselves are never rewritten.
  `php modules/RevisionHistory/bin/field-alias.php list | add <old> <new> [note] | remove <old>`.
  New table `revision_history_field_alias`; upgrade from 1.0.13: Modules → Revision History → Upgrade.

## 1.0.13 — 2026-11-06

- Comments in the code rewritten in plain terms. No change in behaviour; no database change;
  upgrade from 1.0.12: Modules → Revision History → Upgrade.

## 1.0.12 — 2026-11-05

- **A change to a note on a value reads as one.** The history line of a value whose note (value
  annotation) changed now says it as a note on that value's field, with the note's words:
  *Note on Dimensions: added 'A4.'*, *Note on Year (Source): edited '1961' → 'c. 1961'*,
  *Note on Title (Description): 'note' Public → Private*, *… removed '…'*, *… language — → th*.
  The note's own field is named in brackets only when it is not the value's. Before, the line said
  *Value annotations: Dimensions added*, which read as if the field itself had been added. Long notes
  are cut at 80 characters on the line; the Before and After boxes still show them whole.
- `RevisionHistoryShow::noteWords()` and `differs()` take the value's field as an optional third
  argument (without it: *Note (Source): …*).
- Nothing recorded changes: notes were already part of the value they hang on (1.0.3). No database
  change; upgrade from 1.0.11: Modules → Revision History → Upgrade.

Earlier versions are described in the README ("How it records" and the sections after it).
