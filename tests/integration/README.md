# Integration test

Needs a real Omeka S with this module, History Log, Activity Log, Log and Common installed,
MySQL 8, and an API key for a global admin written as `identity credential` in a file.

    OMEKA_API=http://127.0.0.1:8080/api KEY_FILE=/path/key.txt MYSQL_DB=omeka python3 scenario.py RUN1

Through the REST API, one HTTP request each: creates an item set, an item with a file and an item it
links to; edits title, subjects, visibility of a value, a description in Thai, adds a link; adds a note
to a value; reorders; makes the item private; deletes the file; deletes the linked item; adds a file.
Then checks the whole story in `revision_history_event` / `revision_history_change`.

Passed (under the module's earlier name) on 2026-09-16 against Omeka S 4.2.1 + MySQL 8.0.46, with History Log and Activity Log active,
and again with both deactivated. The admin edit form and a BatchUpdate background job were checked
in a browser the same day.

**Image Annotate 1.1.0 (omeka-s-modules/ImageAnnotate, commit b1e8e86) answers `DELETE /api/media/:id`
with HTTP 500** — `repResourceJson()` asks for the deleted file's notes with an id that is already null.
The file *is* deleted (and this module records it); only the response fails. Checked with this module
deactivated: same 500. `scenario.py` deletes a file through the API, so run it with Image Annotate
deactivated, or expect that one step to fail.
