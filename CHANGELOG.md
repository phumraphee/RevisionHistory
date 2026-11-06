# Changelog

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
