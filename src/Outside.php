<?php
namespace RevisionHistory;

use RevisionHistory\Capture\Diff;
use RevisionHistory\Capture\Recorder;
use RevisionHistory\Capture\StateReader;
use RevisionHistory\Watch\Watchman;

/**
 * A write that does not go through Omeka's entities — SQL of a module or a page, a column
 * Omeka's API cannot set — recorded the way a save is (1.0.10).
 *
 * The recorder hears Doctrine. A write in plain SQL it cannot hear, and the data check later
 * finds the change and can only say "nobody this history knows". Code that has to write that
 * way says which resources it is about to change and hands over the write:
 *
 *   if (class_exists(\RevisionHistory\Outside::class)) {
 *       \RevisionHistory\Outside::fromServices($services)->around([$mediaId], function () use ($conn) {
 *           $conn->executeStatement('UPDATE media SET …');
 *       }, 'the file was replaced');
 *   }
 *
 * What the resources hold is read before and after; what changed is written as an entry with
 * who was signed in and how the request arrived (web and the page's path, cli and the script's
 * name), exactly as for a save. A resource that had already changed without a record is reported
 * first, as before a save. Then the data check's number and copy are written again.
 *
 * Whatever the write returns is returned. If the write throws, nothing is recorded and the
 * exception goes on. If the recording fails, the write still stands, and the failure is logged.
 */
class Outside
{
    private $services;

    public function __construct($services)
    {
        $this->services = $services;
    }

    public static function fromServices($services): self
    {
        return new self($services);
    }

    /**
     * @param int[] $ids items, media or item sets the write changes
     * @return mixed what $write returned
     */
    public function around(array $ids, callable $write, string $note = '')
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $ready = false;
        try {
            $conn = $this->services->get('Omeka\Connection');
            $settings = $this->services->get('Omeka\Settings');
            $reader = new StateReader($conn, (string) $settings->get('revision_history_accession_term', 'dcterms:identifier'));
            $watchman = new Watchman($conn, $settings);
            $base = (new Recorder($this->services))->moment();
            $stale = $ids ? $watchman->beforeSave($ids) : [];
            $before = $ids ? $reader->read($ids) : [];
            $ready = true;
        } catch (\Throwable $e) {
            $this->failed('before', $e);
        }
        $result = $write();
        if (!$ready || !$ids) {
            return $result;
        }
        try {
            $after = $reader->read($ids);
            foreach ($stale as $sid) {
                $watchman->reportStale((int) $sid, $before[$sid] ?? null);
            }
            $store = new Store($conn);
            if ($note !== '') {
                $base['note'] = $note;
            }
            $gone = [];
            foreach ($ids as $id) {
                $b = $before[$id] ?? null;
                $a = $after[$id] ?? null;
                if (!$b && !$a) {
                    continue;
                }
                $changes = Diff::changes($b, $a);
                if (!$changes) {
                    continue;
                }
                $action = !$b ? 'created' : (!$a ? 'deleted' : 'edited');
                $eventId = $store->write($base + self::about($action, $a ?: $b), $changes);
                if ($action === 'deleted') {
                    $store->snapshot($eventId, $b);
                    $gone[] = $id;
                }
            }
            $watchman->afterSave(array_keys($after), $gone, $after);
        } catch (\Throwable $e) {
            $this->failed('after', $e);
        }
        return $result;
    }

    private static function about(string $action, array $s): array
    {
        $title = $s['title'];
        if (($title === null || $title === '') && $s['kind'] === 'media' && !empty($s['media']['source'])) {
            $title = (string) $s['media']['source'];
        }
        return [
            'action' => $action,
            'resource_id' => $s['id'],
            'resource_kind' => $s['kind'],
            'item_id' => $s['item'],
            'title' => $title,
            'accession' => $s['accession'],
        ];
    }

    private function failed(string $when, \Throwable $e): void
    {
        try {
            $this->services->get('Omeka\Logger')->err('RevisionHistory: a write from outside Omeka could not be recorded (' . $when . '): ' . $e->getMessage());
        } catch (\Throwable $ignored) {
        }
    }
}
