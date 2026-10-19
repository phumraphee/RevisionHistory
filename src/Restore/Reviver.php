<?php
namespace RevisionHistory\Restore;

use Omeka\Api\Request;
use Omeka\Stdlib\ErrorStore;
use RevisionHistory\Capture\StateReader;
use RevisionHistory\Store;

/**
 * Makes a deleted item, media or item set again, under the number it had.
 *
 * Omeka's API always gives a new resource a new number, and other systems of the
 * archive keep numbers (a conservation record, a scan, a page of the site). So the
 * resource's own row is written here, with its old number, and everything else goes
 * through Omeka: its values and item sets through the API, a media's file through the
 * same downloader and file store Omeka uses when a media is added from a URL. What this
 * module writes itself it also writes down in the history.
 *
 * What the history never had cannot come back: a file that was uploaded (only its name
 * was kept), and — for things deleted before 1.7.0 — the settings that were not yet
 * written down. The page says so, one by one, before anything is done.
 */
class Reviver
{
    const TYPES = ['item' => 'Omeka\Entity\Item', 'media' => 'Omeka\Entity\Media', 'item_set' => 'Omeka\Entity\ItemSet'];

    /** The columns this writes, as Omeka S 4.2 has them. Anything else and nothing is written. */
    const COLUMNS = [
        'resource' => ['id', 'owner_id', 'resource_class_id', 'resource_template_id', 'thumbnail_id', 'title', 'is_public', 'created', 'modified', 'resource_type'],
        'item' => ['id', 'primary_media_id'],
        'item_set' => ['id', 'is_open'],
        'media' => ['id', 'item_id', 'ingester', 'renderer', 'data', 'source', 'media_type', 'storage_id', 'extension', 'sha256', 'size', 'has_original', 'has_thumbnails', 'position', 'lang', 'alt_text'],
    ];

    private $services;
    private $conn;
    private $store;

    public function __construct($services)
    {
        $this->services = $services;
        $this->conn = $services->get('Omeka\Connection');
        $this->store = new Store($this->conn);
    }

    public static function can(array $event): bool
    {
        return ($event['action'] ?? '') === 'deleted'
            && in_array($event['source'] ?? '', ['here', 'watch'], true)
            && isset(self::TYPES[$event['resource_kind'] ?? '']);
    }

    private function exists(string $table, $id): bool
    {
        return $id !== null && (bool) $this->conn->fetchOne("SELECT 1 FROM `$table` WHERE id = ?", [(int) $id]);
    }

    private function changes(int $eventId): array
    {
        $out = [];
        foreach ($this->conn->fetchAllAssociative('SELECT op, field, property_id, before_json, after_json FROM revision_history_change WHERE event_id = ? ORDER BY ord', [$eventId]) as $c) {
            $out[] = [
                'op' => $c['op'],
                'field' => $c['field'],
                'property_id' => $c['property_id'] === null ? null : (int) $c['property_id'],
                'before' => $c['before_json'] === null ? null : json_decode($c['before_json'], true),
                'after' => $c['after_json'] === null ? null : json_decode($c['after_json'], true),
            ];
        }
        return $out;
    }

    /**
     * What the resource held, from the copy kept when it was deleted (1.7.0 on), or put
     * together from the entry's lines for older ones — marked 'partial', with what is
     * missing named.
     */
    public function stateOf(array $event): array
    {
        $snap = $this->store->getSnapshot((int) $event['id']);
        if ($snap) {
            return $snap + ['partial' => false, 'missing' => []];
        }
        $s = [
            'id' => (int) $event['resource_id'], 'kind' => $event['resource_kind'], 'values' => [], 'props' => [],
            'item_sets' => [], 'sites' => [], 'is_public' => null, 'owner' => null, 'template' => null, 'class' => null,
            'thumbnail' => null, 'primary_media' => null, 'is_open' => null, 'item' => $event['item_id'] ? (int) $event['item_id'] : null,
            'media' => null, 'image_notes' => [], 'partial' => true, 'missing' => [],
        ];
        $own = array_flip(Planner::OWN);
        $media = [];
        foreach ($this->changes((int) $event['id']) as $c) {
            $f = $c['field'];
            $b = $c['before'];
            if (isset($own[$f])) {
                $s[$own[$f]] = $b['id'] ?? null;
            } elseif ($f === 'o:item_set') {
                $s['item_sets'][] = (int) $b['id'];
            } elseif ($f === 'o:site') {
                $s['sites'][] = (int) $b['id'];
            } elseif ($f === 'o-module-image_annotate:annotation') {
                $s['image_notes'][$b['id'] ?? count($s['image_notes'])] = $b;
            } elseif (isset(Planner::MEDIA[$f]) || in_array($f, ['o:source', 'o:media_type'], true)) {
                $media[substr($f, 2)] = $b['value'] ?? null;
            } elseif (strpos($f, 'o:') !== 0 && strpos($f, 'o-module-') !== 0 && strpos($f, ':') !== false && $b !== null) {
                $s['values'][$f][] = $b;
                if ($c['property_id']) {
                    $s['props'][$f] = $c['property_id'];
                }
            }
        }
        if ($event['resource_kind'] === 'media') {
            $s['media'] = $media ?: null;
        }
        if ($s['is_public'] === null) {
            $s['missing'][] = 'o:is_public';
        }
        return $s;
    }

    /**
     * Everything the page shows before it is confirmed.
     */
    public function plan(array $event): array
    {
        $id = (int) $event['resource_id'];
        $kind = $event['resource_kind'];
        $state = $this->stateOf($event);
        $stop = [];
        $notes = [];

        if ($this->exists('resource', $id)) {
            $stop[] = sprintf('Number #%d is already used by something else in the archive.', $id);
        }
        if ($kind === 'media') {
            $item = (int) ($state['item'] ?? $event['item_id'] ?? 0);
            if (!$item || !$this->exists('item', $item)) {
                $stop[] = 'The item this media belonged to is not in the archive. Restore the item first.';
            }
        }
        foreach ($this->columnsDiffer() as $t) {
            $stop[] = sprintf('The table "%s" is not the way this module knows it (Omeka S 4.2). Nothing is written.', $t);
        }

        // Settings that point at something that is no longer there are left empty.
        $checks = ['owner' => ['user', 'Owner'], 'template' => ['resource_template', 'Resource template'], 'class' => ['resource_class', 'Class'], 'thumbnail' => ['asset', 'Thumbnail']];
        foreach ($checks as $key => [$table, $label]) {
            if (($state[$key] ?? null) !== null && !$this->exists($table, $state[$key])) {
                $notes[] = sprintf('%s #%d no longer exists — left empty.', $label, $state[$key]);
                $state[$key] = null;
            }
        }
        $state['item_sets'] = array_values(array_filter($state['item_sets'] ?? [], function ($sid) use (&$notes) {
            if ($this->exists('item_set', $sid)) {
                return true;
            }
            $notes[] = sprintf('Item set #%d no longer exists — not put back.', $sid);
            return false;
        }));
        $state['sites'] = array_values(array_filter($state['sites'] ?? [], function ($sid) use (&$notes) {
            if ($this->exists('site', $sid)) {
                return true;
            }
            $notes[] = sprintf('Site #%d no longer exists — not put back.', $sid);
            return false;
        }));
        // Values that link to a deleted resource cannot link again.
        foreach ($state['values'] as $term => $list) {
            foreach ($list as $i => $v) {
                if (!empty($v['vr']) && !$this->exists('resource', $v['vr']) && (int) $v['vr'] !== $id) {
                    $notes[] = sprintf('A value of %s linked to #%d, which no longer exists — not put back.', $term, $v['vr']);
                    unset($state['values'][$term][$i]);
                }
            }
            $state['values'][$term] = array_values($state['values'][$term]);
            if (!$state['values'][$term]) {
                unset($state['values'][$term]);
            }
        }
        if ($state['partial']) {
            $notes[] = 'This was deleted before this module kept a full copy of what is deleted (1.7.0). Only what the entry lists comes back'
                . (in_array('o:is_public', $state['missing'], true) ? '; whether it was public is not known, so it comes back Private.' : '.');
        }

        $media = [];
        if ($kind === 'item') {
            // Its media went with it. History Log (and other modules) flush once per file
            // while an item is deleted, so they are not all in the item's own save: they
            // are the media of this item deleted by the same page, by the same person,
            // within the same minute or two.
            $seen = [];
            foreach ($this->conn->fetchAllAssociative(
                "SELECT * FROM revision_history_event WHERE action = 'deleted' AND resource_kind = 'media' AND item_id = ?
                    AND via_detail = ? AND user_id <=> ? AND happened_at BETWEEN ? - INTERVAL 120 SECOND AND ? + INTERVAL 120 SECOND
                  ORDER BY id DESC",
                [$id, $event['via_detail'], $event['user_id'], $event['happened_at'], $event['happened_at']]
            ) as $m) {
                if (isset($seen[(int) $m['resource_id']])) {
                    continue;
                }
                $seen[(int) $m['resource_id']] = true;
                $media[] = $this->mediaPlan($m);
            }
            usort($media, function ($x, $y) {
                return [$x['position'] ?? PHP_INT_MAX, $x['id']] <=> [$y['position'] ?? PHP_INT_MAX, $y['id']];
            });
        } elseif ($kind === 'media') {
            $media[] = $this->mediaPlan($event) + ['self' => true];
        }

        // Links from other resources, which the database removed with it.
        $links = [];
        if ($event['via_detail'] !== '' || $event['save_key'] !== '') {
            foreach ($this->conn->fetchAllAssociative(
                "SELECT * FROM revision_history_event WHERE action = 'edited' AND resource_id <> ? AND resource_kind IN ('item', 'media', 'item_set')
                    AND (save_key = ? AND save_key <> '' OR (via_detail = ? AND user_id <=> ? AND happened_at BETWEEN ? - INTERVAL 120 SECOND AND ? + INTERVAL 120 SECOND))",
                [$id, $event['save_key'], $event['via_detail'], $event['user_id'], $event['happened_at'], $event['happened_at']]
            ) as $other) {
                $rows = array_values(array_filter($this->changes((int) $other['id']), function ($c) use ($id, $kind) {
                    if ($c['op'] !== 'removed' || !is_array($c['before'])) {
                        return false;
                    }
                    // A value that linked to it, or (for an item set) an item that was in it.
                    return (int) ($c['before']['vr'] ?? 0) === $id
                        || ($kind === 'item_set' && $c['field'] === 'o:item_set' && (int) ($c['before']['id'] ?? 0) === $id);
                }));
                if ($rows) {
                    $links[] = ['event' => $other, 'rows' => $rows];
                }
            }
        }

        $restorer = new Restorer($this->conn);
        $unsure = $restorer->content(['values' => $state['values'], 'props' => $state['props'] ?? [], 'own' => [], 'item_sets' => null], $kind)['unsure'];
        if ($unsure) {
            $stop[] = 'Some Vocab Sync values cannot be sent back exactly (two terms answer to the same words). Restore this by hand.';
        }

        return ['event' => $event, 'state' => $state, 'stop' => $stop, 'notes' => $notes, 'media' => $media, 'links' => $links];
    }

    /** One media: can its file come back, and why not. */
    private function mediaPlan(array $m): array
    {
        $state = $this->stateOf($m);
        $info = $state['media'] ?? [];
        $ingester = (string) ($info['ingester'] ?? '');
        $source = (string) ($info['source'] ?? '');
        $out = ['id' => (int) $m['resource_id'], 'event' => $m, 'state' => $state, 'position' => $info['position'] ?? null,
            'title' => (string) ($m['title'] ?? ''), 'source' => $source, 'ingester' => $ingester, 'ok' => false, 'why' => ''];
        if ($this->exists('resource', $out['id'])) {
            $out['why'] = sprintf('number #%d is already used', $out['id']);
        } elseif ($ingester === 'url' && $source !== '') {
            $status = $this->reach($source);
            if ($status === true) {
                $out['ok'] = true;
            } else {
                $out['why'] = 'the file at its URL cannot be opened (' . $status . ')';
            }
        } elseif ($ingester === 'html' && is_array($info['data'] ?? null) && isset($info['data']['html'])) {
            $out['ok'] = true;
        } elseif ($ingester === 'upload') {
            $out['why'] = 'it was an uploaded file; only its name was kept';
        } elseif ($ingester === '') {
            $out['why'] = 'how it was added was not written down (deleted before 1.7.0)';
        } else {
            $out['why'] = 'it was added as "' . $ingester . '"; only files added from a URL, and HTML, can come back';
        }
        return $out;
    }

    /** Can the file be downloaded? true, or what went wrong. */
    public function reach(string $url)
    {
        try {
            $client = $this->services->get('Omeka\HttpClient');
            $client->reset();
            $client->setOptions(['timeout' => 15]);
            $client->setUri($url);
            $client->setMethod('HEAD');
            $response = $client->send();
            if ($response->getStatusCode() === 405) {
                $client->setMethod('GET');
                $response = $client->send();
            }
            return $response->isSuccess() ? true : ('HTTP ' . $response->getStatusCode());
        } catch (\Throwable $e) {
            return mb_substr($e->getMessage(), 0, 120);
        }
    }

    /** @return string[] tables whose columns are not the ones written here */
    public function columnsDiffer(): array
    {
        $out = [];
        foreach (self::COLUMNS as $table => $want) {
            $have = $this->conn->fetchFirstColumn(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            );
            if (array_diff($want, $have)) {
                $out[] = $table;
            }
        }
        return $out;
    }

    /**
     * Do it. Returns what happened, line by line.
     *
     * @return array{ok: bool, lines: string[], warn: string[]}
     */
    public function apply(array $plan, int $restoreOf): array
    {
        $lines = [];
        $warn = [];
        if ($plan['stop']) {
            return ['ok' => false, 'lines' => [], 'warn' => $plan['stop']];
        }
        $event = $plan['event'];
        $kind = $event['resource_kind'];
        $state = $plan['state'];
        $id = (int) $event['resource_id'];
        $api = $this->services->get('Omeka\ApiManager');
        $note = 'Restore #' . $restoreOf;

        if ($kind !== 'media') {
            $since = $this->lastEvent();
            $this->insertResource($id, $kind, $state, $event);
            $content = $this->valuesContent($state);
            if ($kind === 'item') {
                $content['o:item_set'] = array_map(function ($s) {
                    return ['o:id' => $s];
                }, $state['item_sets'] ?? []);
                $content['o:site'] = array_map(function ($s) {
                    return ['o:id' => $s];
                }, $state['sites'] ?? []);
            }
            $api->update(Restorer::apiName($kind), $id, $content, [], ['isPartial' => true, 'collectionAction' => 'replace']);
            $this->mark($id, $note, $since);
            $lines[] = sprintf('%s #%d is back, with %d value%s.', ucfirst(str_replace('_', ' ', $kind)), $id, $this->count($state), $this->count($state) === 1 ? '' : 's');
        }

        $madeMedia = [];
        foreach ($plan['media'] as $m) {
            if (!$m['ok']) {
                $warn[] = sprintf('Media #%d (%s) was not put back: %s.', $m['id'], $m['title'] !== '' ? $m['title'] : $m['source'], $m['why']);
                continue;
            }
            $r = $this->reviveMedia($m, $kind === 'media' ? (int) ($m['state']['item'] ?? $event['item_id']) : $id, $note);
            if ($r === true) {
                $madeMedia[] = $m['id'];
                $lines[] = sprintf('Media #%d is back%s.', $m['id'], $m['ingester'] === 'url' ? ', downloaded again from ' . $m['source'] : '');
            } else {
                $warn[] = sprintf('Media #%d (%s) was not put back: %s.', $m['id'], $m['title'] !== '' ? $m['title'] : $m['source'], $r);
            }
        }

        // The item's primary media, once its media are there again.
        if ($kind === 'item' && ($state['primary_media'] ?? null) && in_array((int) $state['primary_media'], $madeMedia, true)) {
            $since = $this->lastEvent();
            $this->services->get('Omeka\EntityManager')->clear('Omeka\Entity\Item');
            $api->update('items', $id, ['o:primary_media' => ['o:id' => (int) $state['primary_media']]], [], ['isPartial' => true]);
            $this->mark($id, $note, $since, false);
        }

        // Links from other resources.
        $restorer = new Restorer($this->conn);
        $reader = $this->reader();
        foreach ($plan['links'] as $l) {
            $rid = (int) $l['event']['resource_id'];
            $now = $reader->read([$rid])[$rid] ?? null;
            if (!$now) {
                continue;
            }
            $p = Planner::plan($l['rows'], $now, function ($x) {
                return $this->exists('resource', $x);
            });
            if (!$p['any']) {
                continue;
            }
            $built = $restorer->content($p, $l['event']['resource_kind']);
            if ($built['unsure']) {
                $warn[] = sprintf('The link from #%d was not put back (Vocab Sync values could not be sent back exactly).', $rid);
                continue;
            }
            $since = $this->lastEvent();
            $api->update(Restorer::apiName($l['event']['resource_kind']), $rid, $built['content'], [], ['isPartial' => true, 'collectionAction' => 'replace']);
            $this->mark($rid, $note, $since, false);
            $lines[] = sprintf('The link from #%d (%s) is back.', $rid, (string) $l['event']['title']);
        }

        // Read it back and say what does not read as it did.
        $after = $reader->read([$id])[$id] ?? null;
        if (!$after) {
            $warn[] = 'It could not be read back.';
        } elseif ($kind !== 'media') {
            $diff = Restorer::differences(['values' => $state['values'], 'own' => [], 'item_sets' => $kind === 'item' ? ($state['item_sets'] ?? []) : null, 'sites' => $kind === 'item' ? ($state['sites'] ?? []) : null], $after);
            if ($diff) {
                $warn[] = 'These fields do not read as they did before: ' . implode(', ', $diff) . '. Check them.';
            }
        }
        return ['ok' => true, 'lines' => $lines, 'warn' => $warn];
    }

    private function lastEvent(): int
    {
        return (int) $this->conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM revision_history_event');
    }

    private function count(array $state): int
    {
        $n = 0;
        foreach ($state['values'] as $list) {
            $n += count($list);
        }
        return $n;
    }

    private function reader(): StateReader
    {
        return new StateReader($this->conn, (string) $this->services->get('Omeka\Settings')->get('revision_history_accession_term', 'dcterms:identifier'));
    }

    private function valuesContent(array $state): array
    {
        $restorer = new Restorer($this->conn);
        return $restorer->content(['values' => $state['values'], 'props' => $state['props'] ?? [], 'own' => [], 'item_sets' => null], $state['kind'] ?? 'item')['content'];
    }

    /** When it was made: what the copy says, else the history's own "created", else now. */
    private function created(int $id, array $state): string
    {
        if (!empty($state['created'])) {
            return (string) $state['created'];
        }
        $first = $this->conn->fetchOne("SELECT MIN(happened_at) FROM revision_history_event WHERE resource_kind IN ('item','media','item_set') AND resource_id = ? AND action = 'created'", [$id]);
        return $first ? substr((string) $first, 0, 19) : gmdate('Y-m-d H:i:s');
    }

    private function insertResource(int $id, string $kind, array $state, array $event): void
    {
        $this->conn->insert('resource', [
            'id' => $id,
            'owner_id' => $state['owner'] ?? null,
            'resource_class_id' => $state['class'] ?? null,
            'resource_template_id' => $state['template'] ?? null,
            'thumbnail_id' => $state['thumbnail'] ?? null,
            'title' => null,
            'is_public' => $state['is_public'] === null ? 0 : (int) $state['is_public'],
            'created' => $this->created($id, $state),
            'modified' => gmdate('Y-m-d H:i:s'),
            'resource_type' => self::TYPES[$kind],
        ]);
        if ($kind === 'item') {
            $this->conn->insert('item', ['id' => $id, 'primary_media_id' => null]);
        } elseif ($kind === 'item_set') {
            $this->conn->insert('item_set', ['id' => $id, 'is_open' => (int) ($state['is_open'] ?? 0)]);
        }
    }

    /** @return true|string */
    private function reviveMedia(array $m, int $itemId, string $note)
    {
        $state = $m['state'];
        $info = $state['media'] ?? [];
        $id = $m['id'];
        $since = $this->lastEvent();
        try {
            $this->insertResource($id, 'media', $state, $m['event']);
            $this->conn->insert('media', [
                'id' => $id,
                'item_id' => $itemId,
                'ingester' => $info['ingester'],
                'renderer' => $info['renderer'] ?? ($info['ingester'] === 'html' ? 'html' : 'file'),
                'data' => isset($info['data']) && $info['data'] !== null ? json_encode($info['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'source' => $info['source'] ?? null,
                'media_type' => $info['ingester'] === 'html' ? ($info['media_type'] ?? null) : null,
                'storage_id' => null,
                'extension' => null,
                'sha256' => null,
                'size' => null,
                'has_original' => 0,
                'has_thumbnails' => 0,
                'position' => $info['position'] ?? ((int) $this->conn->fetchOne('SELECT COALESCE(MAX(position), 0) + 1 FROM media WHERE item_id = ?', [$itemId])),
                'lang' => $info['lang'] ?? null,
                'alt_text' => $info['alt_text'] ?? null,
            ]);
            if ($info['ingester'] === 'url') {
                // The same steps Omeka takes when a media is added from a URL.
                $em = $this->services->get('Omeka\EntityManager');
                $media = $em->find('Omeka\Entity\Media', $id);
                $errors = new ErrorStore;
                $temp = $this->services->get('Omeka\File\Downloader')->download($info['source'], $errors);
                if (!$temp) {
                    throw new \RuntimeException('the file could not be downloaded' . ($errors->hasErrors() ? ' (' . json_encode($errors->getErrors(), JSON_UNESCAPED_UNICODE) . ')' : ''));
                }
                $temp->setSourceName((string) parse_url($info['source'], PHP_URL_PATH));
                $temp->mediaIngestFile($media, new Request(Request::UPDATE, 'media'), $errors, true, true, true);
                if ($errors->hasErrors()) {
                    throw new \RuntimeException('Omeka did not take the file (' . json_encode($errors->getErrors(), JSON_UNESCAPED_UNICODE) . ')');
                }
                $em->flush();
                $em->detach($media);
                if (!empty($info['sha256']) && $this->conn->fetchOne('SELECT sha256 FROM media WHERE id = ?', [$id]) !== $info['sha256']) {
                    $note .= ' · the file at the URL is not the same file as before';
                }
            }
            // Its values, through the API.
            $content = $this->valuesContent($state + ['kind' => 'media']);
            $this->services->get('Omeka\ApiManager')->update('media', $id, $content, [], ['isPartial' => true, 'collectionAction' => 'replace']);
            // What was drawn on it.
            $this->reviveImageNotes($id, $state['image_notes'] ?? []);
            $this->mark($id, $note, $since);
            return true;
        } catch (\Throwable $e) {
            try {
                $this->conn->executeStatement('DELETE FROM value WHERE resource_id = ?', [$id]);
                $this->conn->executeStatement('DELETE FROM resource WHERE id = ?', [$id]);
            } catch (\Throwable $ignored) {
            }
            return $e->getMessage();
        }
    }

    private function reviveImageNotes(int $mediaId, array $notes): void
    {
        $raw = [];
        foreach ($notes as $n) {
            if (is_array($n) && isset($n['raw']) && is_array($n['raw'])) {
                $raw[] = $n['raw'];
            }
        }
        if (!$raw || !class_exists('ImageAnnotate\Entity\ImageAnnotateMedia')) {
            return;
        }
        try {
            $module = $this->services->get('Omeka\ModuleManager')->getModule('ImageAnnotate');
            if (!$module || $module->getState() !== 'active') {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }
        $em = $this->services->get('Omeka\EntityManager');
        $entity = new \ImageAnnotate\Entity\ImageAnnotateMedia;
        $entity->setMedia($em->getReference('Omeka\Entity\Media', $mediaId));
        $entity->setAnnotations($raw);
        $em->persist($entity);
        $em->flush();
    }

    /**
     * The history's own words for what this module just did: the first entry this
     * restore made for the resource is where it came back; each says which entry it
     * restores.
     */
    private function mark(int $rid, string $note, int $since, bool $created = true): void
    {
        $first = $this->conn->fetchOne("SELECT MIN(id) FROM revision_history_event WHERE resource_kind IN ('item','media','item_set') AND resource_id = ? AND id > ?", [$rid, $since]);
        $rows = $this->conn->fetchFirstColumn(
            "SELECT id FROM revision_history_event WHERE resource_kind IN ('item','media','item_set') AND resource_id = ? AND id > ? AND (note IS NULL OR note = '')",
            [$rid, $since]
        );
        foreach ($rows as $eid) {
            $this->conn->executeStatement('UPDATE revision_history_event SET note = ? WHERE id = ?', [$note, (int) $eid]);
        }
        if ($created && $first) {
            $this->conn->executeStatement("UPDATE revision_history_event SET action = 'created' WHERE id = ? AND action = 'edited'", [(int) $first]);
        }
    }
}
