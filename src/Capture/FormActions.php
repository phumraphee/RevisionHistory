<?php
namespace RevisionHistory\Capture;

/**
 * What the buttons another module puts on Omeka's own edit page did, before the save.
 *
 * Batch Values 0.10.0 put buttons in Omeka's add and edit pages that put the values of
 * a field in order, or move and copy them to another field, there and then. Pressing
 * Save afterwards is an ordinary save by an ordinary person — no run, no mark, nothing
 * of that module in the request — so the record of it is this module's alone. But the
 * record on its own reads oddly: a field whose values were put in order looks like every
 * value taken out and put back, and a value moved reads as one taken out of one field
 * and a different one added to another.
 *
 * So the form says what its buttons did, in one field of its own
 * (`batchvalues_form_actions`, 0.11.0), and that sentence is kept with the save. The
 * words are Batch Values' own, as it writes them on the page itself; nothing here is
 * invented, and nothing is believed: what the field says is only what was asked for,
 * while the lines of the entry remain what really changed.
 */
class FormActions
{
    /** The field Batch Values puts in the form when its buttons were used. */
    const FIELD = 'batchvalues_form_actions';

    /** Batch Values' own sentences for what its buttons did, as it writes them. */
    const SORT = '“%s” put in order, %s';
    const MOVE = '%d value(s) moved from “%s” to “%s”';
    const COPY = '%d value(s) copied from “%s” to “%s”';

    /** More than this many in one save is somebody playing with the form, not working. */
    const MOST = 20;

    /**
     * One sentence for the entry, or null when the form said nothing.
     *
     * @param mixed $raw the form's field, exactly as it arrived
     */
    public static function sentence($raw): ?string
    {
        if (!is_string($raw) || $raw === '' || strlen($raw) > 20000) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['actions']) || !is_array($data['actions'])) {
            return null;
        }
        $said = [];
        foreach ($data['actions'] as $action) {
            if (count($said) >= self::MOST || !is_array($action)) {
                continue;
            }
            $kind = (string) ($action['kind'] ?? '');
            $field = self::plain($action['field'] ?? '');
            $target = self::plain($action['target'] ?? '');
            $n = (int) ($action['n'] ?? 0);
            if ($field === '') {
                continue;
            }
            if ($kind === 'sort') {
                $said[] = sprintf(self::SORT, $field, ($action['direction'] ?? 'asc') === 'desc' ? 'Z → A' : 'A → Z');
            } elseif (($kind === 'move' || $kind === 'copy') && $target !== '' && $n > 0) {
                $said[] = sprintf($kind === 'copy' ? self::COPY : self::MOVE, $n, $field, $target);
            }
        }
        if (!$said) {
            return null;
        }
        return 'With the Batch Values buttons on this page: ' . implode(' · ', $said);
    }

    /** A name from the form, as text and no longer than a name ever is. */
    private static function plain($v): string
    {
        if (!is_string($v)) {
            return '';
        }
        $v = trim(preg_replace('/\s+/u', ' ', strip_tags($v)));
        return mb_substr($v, 0, 120);
    }
}
