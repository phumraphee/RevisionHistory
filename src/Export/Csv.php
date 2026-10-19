<?php
namespace RevisionHistory\Export;

use Doctrine\DBAL\Connection;
use RevisionHistory\Reader;

/**
 * The history, as a file — whatever the page is showing at that moment, with the same
 * filters, oldest last, one line per change.
 *
 * A history that can only be read on a screen is a history somebody else cannot check.
 * This is the same entries, in a file a spreadsheet opens: what was changed, by whom,
 * when, from what to what.
 *
 * Written straight out as it is read, a page of entries at a time, so an archive with
 * a hundred thousand of them does not have to fit in memory first.
 */
class Csv
{
    const COLUMNS = [
        'When (UTC)',
        'When (' . '%tz%' . ')',
        'Who',
        'E-mail',
        'What',
        'Kind',
        'Id',
        'Title',
        'Accession number',
        'Item id',
        'Field',
        'Change',
        'Before',
        'After',
        'Place before',
        'Place after',
        'How it was saved',
        'Where this entry came from',
        'Note',
        'Entry',
    ];

    /** @var Connection */
    private $conn;

    /** @var Reader */
    private $reader;

    /** @var string */
    private $tz;

    /** @var array term => label */
    private $labels = [];

    /** @var array api key identity => what to call it */
    private $keys = [];

    public function __construct(Connection $conn, Reader $reader, string $tz)
    {
        $this->conn = $conn;
        $this->reader = $reader;
        $this->tz = $tz ?: 'UTC';
    }

    /** Every field's label, in one query: a file is read without the page beside it. */
    private function labels(): array
    {
        if ($this->labels) {
            return $this->labels;
        }
        $this->labels = [
            'o:is_public' => 'Visibility',
            'o:resource_template' => 'Resource template',
            'o:resource_class' => 'Class',
            'o:owner' => 'Owner',
            'o:item_set' => 'Item set',
            'o:site' => 'Site',
            'o:thumbnail' => 'Thumbnail',
            'o:primary_media' => 'Primary media',
            'o:media' => 'Media',
            'o:is_open' => 'Open to additions',
            'o:source' => 'Source',
            'o:media_type' => 'Media type',
            'o:alt_text' => 'Alt text',
            'o:lang' => 'Language',
            'o:file' => 'File',
            'o:data' => 'HTML',
            'o-module-image_annotate:annotation' => 'Image Annotate',
            'o-module-vocab_sync:term' => 'Term',
            'rank' => 'Rank',
            'parentItemSet' => 'Parent item set',
            'itemSet' => 'Item set',
        ];
        foreach ($this->conn->fetchAllAssociative(
            "SELECT CONCAT(vo.prefix, ':', p.local_name) AS term, p.label
               FROM property p JOIN vocabulary vo ON vo.id = p.vocabulary_id"
        ) as $r) {
            if (!isset($this->labels[$r['term']])) {
                $this->labels[$r['term']] = $r['label'];
            }
        }
        return $this->labels;
    }

    /** The API key's own name and whose it is, in place of its identity. */
    private function keyNames(string $detail): string
    {
        return (string) preg_replace_callback('/^key (\S+)/', function ($m) {
            if (!array_key_exists($m[1], $this->keys)) {
                try {
                    $r = $this->conn->fetchAssociative('SELECT k.label, u.name FROM api_key k LEFT JOIN user u ON u.id = k.owner_id WHERE k.id = ?', [$m[1]]);
                } catch (\Throwable $e) {
                    $r = null;
                }
                $this->keys[$m[1]] = $r ? trim(($r['label'] ?? '') . ($r['name'] ? ' of ' . $r['name'] : '')) : '';
            }
            return 'key ' . ($this->keys[$m[1]] !== '' ? $this->keys[$m[1]] : $m[1]);
        }, $detail);
    }

    /** One value, in words — the same words the page shows, without its marks. */
    /** A value's place in its field, as "2 of 5"; empty when it had no place. */
    public static function place($place, $size): string
    {
        return $place === null ? '' : (int) $place . ($size ? ' of ' . (int) $size : '');
    }

    public static function plain($v): string
    {
        if (!is_array($v)) {
            return '';
        }
        // A list (the order of an item's media) and a setting that is a number.
        if (isset($v[0]) && is_array($v[0])) {
            return implode(', ', array_map([self::class, 'plain'], $v));
        }
        if (array_key_exists('id', $v) && !isset($v['type'])) {
            return $v['id'] === null ? '' : '#' . $v['id'];
        }
        $out = [];
        if (isset($v['value']) && $v['value'] !== null && $v['value'] !== '') {
            $out[] = (string) $v['value'];
        }
        if (!empty($v['uri'])) {
            $out[] = (string) $v['uri'];
        }
        if (!empty($v['vr'])) {
            $out[] = '#' . (int) $v['vr'];
        }
        if (!empty($v['where'])) {
            $out[] = (string) $v['where'];
        }
        $text = implode(' · ', $out);
        $tail = [];
        $type = (string) ($v['type'] ?? '');
        if ($type !== '' && $type !== 'literal') {
            $tail[] = $type;
        }
        if (!empty($v['lang'])) {
            $tail[] = (string) $v['lang'];
        }
        if (isset($v['pub']) && (int) $v['pub'] === 0) {
            $tail[] = 'private';
        }
        foreach ($v['note'] ?? [] as $n) {
            $tail[] = 'value annotation: ' . self::plain($n);
        }
        return $tail ? trim($text . ' (' . implode(', ', $tail) . ')') : $text;
    }

    private function when(string $utc): array
    {
        try {
            $d = new \DateTime($utc . ' UTC');
            $local = clone $d;
            $local->setTimezone(new \DateTimeZone($this->tz));
            return [$d->format('Y-m-d H:i:s'), $local->format('Y-m-d H:i:s')];
        } catch (\Throwable $e) {
            return [$utc, $utc];
        }
    }

    /**
     * Write the whole of what the filters match.
     *
     * @param resource $handle
     * @return int lines written, not counting the heading
     */
    public function write($handle, array $filters, int $max = 500000): int
    {
        // Excel opens a file as the computer's own language unless it is told otherwise;
        // these three bytes are what tell it the words are UTF-8.
        fwrite($handle, "\xEF\xBB\xBF");
        $head = self::COLUMNS;
        $head[1] = 'When (' . $this->tz . ')';
        fputcsv($handle, $head);

        $labels = $this->labels();
        $verb = ['created' => 'created', 'edited' => 'changed', 'deleted' => 'deleted'];
        $kind = ['item' => 'item', 'media' => 'media', 'item_set' => 'item set', 'vocabsync' => 'Vocab Sync', 'ValueAnnotation' => 'Value annotations'];
        $source = [
            'here' => 'recorded by this archive',
            'historylog' => 'copied from History Log',
            'activitylog' => 'copied from Activity Log',
            'vocabsync' => 'recorded by Vocab Sync',
            'watch' => 'found by checking the data itself',
        ];
        $lines = 0;
        $f = $filters;
        unset($f['after']);
        while (true) {
            $page = $this->reader->events($f, $this->tz, 500);
            foreach ($page['events'] as $e) {
                [$utc, $local] = $this->when((string) $e['happened_at']);
                $who = $e['user_name'] !== '' ? $e['user_name'] : ($e['user_id'] ? 'account #' . (int) $e['user_id'] : 'no one signed in');
                $how = $e['via'] === '' ? '' : trim($e['via'] . ' ' . $this->keyNames((string) $e['via_detail']));
                $base = [
                    $utc,
                    $local,
                    $who,
                    (string) $e['user_email'],
                    $verb[$e['action']] ?? $e['action'],
                    $kind[$e['resource_kind']] ?? $e['resource_kind'],
                    (int) $e['resource_id'],
                    (string) ($e['title'] ?? ''),
                    (string) $e['accession'],
                    $e['item_id'] ? (int) $e['item_id'] : '',
                ];
                $tail = [
                    $how,
                    $source[$e['source']] ?? $e['source'],
                    (string) ($e['note'] ?? ''),
                    (int) $e['id'],
                ];
                if (!$e['changes']) {
                    fputcsv($handle, array_merge($base, ['', '', '', '', '', ''], $tail));
                    $lines++;
                } else {
                    foreach ($e['changes'] as $c) {
                        fputcsv($handle, array_merge($base, [
                            $labels[$c['field']] ?? $c['field'],
                            $c['op'],
                            self::plain($c['before']),
                            self::plain($c['after']),
                            self::place($c['place_before'] ?? null, $c['size_before'] ?? null),
                            self::place($c['place_after'] ?? null, $c['size_after'] ?? null),
                        ], $tail));
                        $lines++;
                    }
                }
                if ($lines >= $max) {
                    return $lines;
                }
            }
            if (!$page['next']) {
                return $lines;
            }
            $f['after'] = $page['next'];
        }
    }
}
