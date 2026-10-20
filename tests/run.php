<?php
/**
 * Tests that need no Omeka and no database: how before/after becomes
 * added · removed · edited, how History Log's rows are replayed, and how
 * two texts are marked where they differ.
 *
 *   php tests/run.php
 *
 * The capture itself (Doctrine, History Log running beside it, the admin form,
 * the API, batch jobs) can only be tested against a real Omeka — see
 * tests/integration/README.md.
 */
spl_autoload_register(function ($c) {
    if (strpos($c, 'RevisionHistory\\') === 0) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($c, 16)) . '.php';
    }
});
if (!class_exists('Laminas\View\Helper\AbstractHelper')) {
    eval('namespace Laminas\View\Helper; abstract class AbstractHelper { protected $view; }');
}
if (!interface_exists('Doctrine\DBAL\Connection') && !class_exists('Doctrine\DBAL\Connection')) {
    eval('namespace Doctrine\DBAL; class Connection {}');
}

use RevisionHistory\Capture\Diff;
use RevisionHistory\Copy\Copier;
use RevisionHistory\View\Helper\RevisionHistoryShow;

$pass = 0;
$fail = 0;
function check(string $what, $got, $want): void
{
    global $pass, $fail;
    if ($got === $want) {
        $pass++;
        return;
    }
    $fail++;
    echo "FAIL  $what\n  got:  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n  want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}
function v(string $text, array $extra = []): array
{
    return array_merge(['type' => 'literal', 'value' => $text, 'uri' => null, 'vr' => null, 'lang' => null, 'pub' => 1], $extra);
}
function ops(array $changes): array
{
    return array_map(function ($c) {
        $b = $c[1] ?? ($c['before'] ?? null);
        $a = $c[2] ?? ($c['after'] ?? null);
        $op = $c[0] ?? $c['op'];
        $show = function ($x) {
            if ($x === null) return '-';
            if (isset($x['type'])) return ($x['value'] ?? '') . (isset($x['pub']) && !$x['pub'] ? '(private)' : '') . (!empty($x['lang']) ? '@' . $x['lang'] : '');
            if (isset($x[0])) return implode(',', array_map(function ($y) { return $y['value'] ?? ''; }, $x));
            return json_encode($x);
        };
        return (isset($c['field']) ? $c['field'] . ' ' : '') . $op . ' ' . $show($b) . ' > ' . $show($a);
    }, $changes);
}

/* ---------------------------------------------------------------- Diff::field */

check('nothing changed', Diff::field([v('Oil'), v('Canvas')], [v('Oil'), v('Canvas')]), []);
check('one value replaced is an edit', ops(Diff::field([v('1975')], [v('1976')])), ['edited 1975 > 1976']);
check('a value added at the end', ops(Diff::field([v('Oil')], [v('Oil'), v('Canvas')])), ['field Oil > Oil,Canvas', 'added - > Canvas']);
// Which place in the field, and of how many, so a line can say "the second of five".
$places = array_map(function ($c) { return [$c[3] ?? null, $c[4] ?? null, $c[5] ?? null, $c[6] ?? null]; },
    Diff::field([v('Oil'), v('Canvas'), v('Linen')], [v('Oil'), v('Linen')]));
check('a value taken out keeps the place it had and how many there were', $places, [[null, null, 3, 2], [2, null, 3, 2]]);
$places = array_map(function ($c) { return [$c[3] ?? null, $c[4] ?? null, $c[5] ?? null, $c[6] ?? null]; },
    Diff::field([v('Oil')], [v('Oil'), v('Canvas'), v('Linen')]));
check('values put in keep the place they went to', $places, [[null, null, 1, 3], [null, 2, 1, 3], [null, 3, 1, 3]]);
check('a value removed from the middle stays a removal, not two edits',
    ops(Diff::field([v('Oil'), v('Canvas'), v('Linen')], [v('Oil'), v('Linen')])), ['field Oil,Canvas,Linen > Oil,Linen', 'removed Canvas > -']);
// Putting a save back reads the lines, not the picture: doing both would undo one with
// the other.
$rows = array_map(function ($c) { return ['op' => $c[0], 'field' => 'dcterms:subject', 'property_id' => 3, 'before' => $c[1], 'after' => $c[2]]; },
    Diff::field([v('Oil'), v('Canvas'), v('Linen')], [v('Oil'), v('Linen')]));
$plan = \RevisionHistory\Restore\Planner::plan($rows, ['values' => ['dcterms:subject' => [v('Oil'), v('Linen')]], 'props' => ['dcterms:subject' => 3]]);
check('the whole field is not something to put back', count($plan['rows']), 1);
check('putting that save back gives the field its value again',
    array_map(function ($x) { return $x['value']; }, $plan['values']['dcterms:subject']), ['Oil', 'Linen', 'Canvas']);

check('only the order changed', ops(Diff::field([v('Canvas'), v('Linen')], [v('Linen'), v('Canvas')])), ['order Canvas,Linen > Linen,Canvas']);
check('one kept, one replaced, one added',
    ops(Diff::field([v('Canvas'), v('Oil')], [v('Canvas'), v('Linen'), v('Jute')])), ['field Canvas,Oil > Canvas,Linen,Jute', 'edited Oil > Linen', 'added - > Jute']);
check('made private is an edit of the same words', ops(Diff::field([v('1975')], [v('1975', ['pub' => 0])])), ['edited 1975 > 1975(private)']);
check('a language set is an edit', ops(Diff::field([v('Blue')], [v('Blue', ['lang' => 'en'])])), ['edited Blue > Blue@en']);
check('the field emptied', ops(Diff::field([v('a'), v('b')], [])), ['removed a > -', 'removed b > -']);
check('two equal values, one of them removed', ops(Diff::field([v('x'), v('x')], [v('x')])), ['field x,x > x', 'removed x > -']);
// A field that is all new, or one that has been emptied, is told in full by its lines.
check('a field that came from nothing is not written down whole as well', ops(Diff::field([], [v('a'), v('b')])), ['added - > a', 'added - > b']);
check('a field emptied is not written down whole as well', ops(Diff::field([v('a'), v('b')], [])), ['removed a > -', 'removed b > -']);

/* ------------------------------------------------------------- Diff::changes */

$state = function (array $values, array $extra = []) {
    return array_merge(['values' => $values, 'props' => array_fill_keys(array_keys($values), 1), 'item_sets' => [],
        'is_public' => 1, 'template' => null, 'class' => null, 'owner' => 1], $extra);
};
check('created: everything is added, settings are not "changed"',
    ops(Diff::changes(null, $state(['dcterms:title' => [v('A')]], ['item_sets' => [4]]))),
    ['o:item_set added - > {"id":4}', 'dcterms:title added - > A']);
check('deleted: everything is removed, its own settings too (so it can be made again)',
    ops(Diff::changes($state(['dcterms:title' => [v('A')], 'dcterms:date' => [v('1975')]]), null)),
    ['o:is_public removed {"id":1} > -', 'o:owner removed {"id":1} > -', 'dcterms:title removed A > -', 'dcterms:date removed 1975 > -']);
check('sites, primary media and the order of media are part of an item', ops(Diff::changes(
    $state([], ['sites' => [1], 'primary_media' => 5, 'media_order' => [5, 6, 7]]),
    $state([], ['sites' => [1, 2], 'primary_media' => 6, 'media_order' => [6, 5, 7, 8]])
)), ['o:primary_media edited {"id":5} > {"id":6}', 'o:site added - > {"id":2}', 'o:media order ,, > ,,,']);
check('the order of media is the ids in order', array_column(Diff::changes($state([], ['media_order' => [5, 6]]), $state([], ['media_order' => [6, 5]]))[0]['after'], 'id'), [6, 5]);
check('a media added to the end is not a reorder', ops(Diff::changes(
    $state([], ['media_order' => [5, 6]]), $state([], ['media_order' => [5, 6, 7]])
)), []);
check('a media\'s file, alt text and language', ops(Diff::changes(
    $state([], ['kind' => 'media', 'media' => ['source' => 'https://img/a.jpg', 'sha256' => 'aaa', 'size' => 10, 'alt_text' => null, 'lang' => null]]),
    $state([], ['kind' => 'media', 'media' => ['source' => 'https://img/a.jpg', 'sha256' => 'bbb', 'size' => 12, 'alt_text' => 'A poem', 'lang' => 'th']])
)), ['o:file edited aaa · 10 bytes > bbb · 12 bytes', 'o:alt_text added - > A poem', 'o:lang added - > th']);
check('a media put into another item says which one it came from', ops(Diff::changes(
    $state([], ['kind' => 'media', 'item' => 255]),
    $state([], ['kind' => 'media', 'item' => 258])
)), ['o:item edited {"id":255} > {"id":258}']);
check('an item is not said to have been put into itself', ops(Diff::changes(
    $state([], ['kind' => 'item', 'item' => 255]),
    $state([], ['kind' => 'item', 'item' => 255, 'is_public' => 0])
)), ['o:is_public edited {"id":1} > {"id":0}']);
check('a media that was deleted is not said to have left its item', ops(Diff::changes(
    $state([], ['kind' => 'media', 'item' => 255]), null
)), ['o:is_public removed {"id":1} > -', 'o:owner removed {"id":1} > -']);
check('what a media keeps besides its file is one line per name', ops(Diff::changes(
    $state([], ['kind' => 'media', 'media' => ['source' => 'a.jpg', 'data' => []]]),
    $state([], ['kind' => 'media', 'media' => ['source' => 'a.jpg', 'data' => ['o-module-batchvalues:thumbnails_made' => '2026-09-25T08:44:12Z']]])
)), ['o:data:o-module-batchvalues:thumbnails_made added - > 2026-09-25T08:44:12Z']);
check('the HTML of an HTML media is still the HTML', ops(Diff::changes(
    $state([], ['kind' => 'media', 'media' => ['source' => null, 'data' => ['html' => '<p>Old</p>']]]),
    $state([], ['kind' => 'media', 'media' => ['source' => null, 'data' => ['html' => '<p>New</p>']]])
)), ['o:data edited <p>Old</p> > <p>New</p>']);
check('visibility and item sets',
    ops(Diff::changes($state([], ['item_sets' => [1, 2]]), $state([], ['is_public' => 0, 'item_sets' => [2, 3]]))),
    ['o:is_public edited {"id":1} > {"id":0}', 'o:item_set added - > {"id":3}', 'o:item_set removed {"id":1} > -']);
check('a new field appears after the old ones',
    ops(Diff::changes($state(['dcterms:title' => [v('A')]]), $state(['dcterms:title' => [v('A')], 'dcterms:subject' => [v('Oil')]]))),
    ['dcterms:subject added - > Oil']);

/* ------------------------------------------------------ Copier::replay (History Log) */

$row = function ($action, $field, $value, $extra = []) {
    return array_merge(['id' => rand(), 'event_id' => 1, 'action' => $action, 'field' => $field, 'type' => 'literal',
        'is_public' => 1, 'lang' => null, 'value' => $value, 'uri' => null, 'value_resource_id' => null, 'value_annotation_id' => null], $extra);
};
$empty = ['values' => [], 'known' => [], 'is_public' => null, 'created_seen' => false];
[$c, $st] = Copier::replay(['operation' => 'create'], [
    $row('create', 'o:is_public', 'true', ['type' => null, 'is_public' => null]),
    $row('create', 'dcterms:title', 'First'),
    $row('create', 'dcterms:subject', 'Oil'),
    $row('create', 'dcterms:subject', 'Canvas'),
], $empty, []);
check('HL create', ops($c), ['dcterms:title added - > First', 'dcterms:subject added - > Oil', 'dcterms:subject added - > Canvas']);
[$c, $st, $note] = Copier::replay(['operation' => 'update'], [
    $row('update', 'dcterms:title', 'Second'),
    $row('none', 'dcterms:subject', 'Canvas'),
    $row('update', 'dcterms:subject', 'Linen'),
    $row('update', 'dcterms:title', 'Second'),
    $row('none', 'dcterms:subject', 'Canvas'),
    $row('update', 'dcterms:subject', 'Linen'),
], $st, []);
check('HL update after a known create: the replaced value is worked out; repeated blocks read once',
    ops($c), ['dcterms:title edited First > Second', 'dcterms:subject field Oil,Canvas > Canvas,Linen', 'dcterms:subject edited Oil > Linen']);
check('HL update after a known create has no "not recorded" note', $note, null);

[$c, $st2, $note] = Copier::replay(['operation' => 'update'], [
    $row('update', 'dcterms:date', '1976'),
], ['values' => [], 'known' => [], 'is_public' => null, 'created_seen' => false], []);
check('HL update of an item created before History Log: before is unknown', ops($c), ['dcterms:date edited - > 1976']);
check('…and says so', is_string($note), true);
[$c] = Copier::replay(['operation' => 'update'], [$row('update', 'dcterms:date', '1977')], $st2, []);
check('…the next change to that field is known', ops($c), ['dcterms:date edited 1976 > 1977']);

[$c] = Copier::replay(['operation' => 'update'], [$row('update', 'o:is_public', 'false', ['type' => null, 'is_public' => null])], $st, []);
check('HL visibility', ops($c), ['o:is_public edited {"id":1} > {"id":0}']);
[$c, , $note] = Copier::replay(['operation' => 'delete'], [$row('delete', 'dcterms:title', 'Second')], $st, []);
check('HL delete keeps what it held', ops($c), ['dcterms:title removed Second > -']);

/* ------------------------------------------------------------ RevisionHistoryShow::marked */

$e = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
check('Thai: the changed word is marked whole', RevisionHistoryShow::marked('ภาพวาดสีน้ำมันบนผ้าใบ', 'ภาพวาดสีอะคริลิกบนผ้าใบ', $e),
    ['ภาพวาดสี<del>น้ำมัน</del>บนผ้าใบ', 'ภาพวาดสี<ins>อะคริลิก</ins>บนผ้าใบ']);
check('Thai: a vowel mark never split from its letter', RevisionHistoryShow::marked('น้ำ', 'นำ', $e), ['<del>น้ำ</del>', '<ins>นำ</ins>']);
check('English words', RevisionHistoryShow::marked('painted in Bangkok', 'painted in Thonburi', $e),
    ['painted in <del>Bangkok</del>', 'painted in <ins>Thonburi</ins>']);
check('HTML is escaped', RevisionHistoryShow::marked('<b>', '<i>', $e), ['<del>&lt;b&gt;</del>', '<ins>&lt;i&gt;</ins>']);
check('emptied', RevisionHistoryShow::marked('x', '', $e)[1], '<span class="rh-none">empty</span>');

/* ------------------------------------------------------- image notes (Image Annotate) */

$anno = function ($id, $text, $xywh) {
    return ['type' => 'Annotation', 'id' => $id, 'body' => [['type' => 'TextualBody', 'value' => $text, 'purpose' => 'commenting']],
        'target' => ['source' => 'x.jpg', 'selector' => ['type' => 'FragmentSelector', 'value' => 'xywh=percent:' . $xywh]]];
};
$n = RevisionHistory\Capture\StateReader::imageNotes([$anno('#a', 'ลายเซ็น', '80,85,15,10'), $anno('#b', 'รอยฉีก', '10,2,30,5')]);
check('notes keyed by their own id, with words and place', array_keys($n), ['#a', '#b']);
check('…the place kept as written', $n['#a']['where'], 'xywh=percent:80,85,15,10');
$m = RevisionHistory\Capture\StateReader::imageNotes([$anno('#a', 'ลายเซ็นศิลปิน', '80,85,15,10'), $anno('#c', 'คราบน้ำ', '1,1,5,5')]);
check('a note edited, one removed, one added',
    ops(Diff::changes(['values' => [], 'item_sets' => [], 'image_notes' => $n], ['values' => [], 'item_sets' => [], 'image_notes' => $m])),
    ['o-module-image_annotate:annotation edited ลายเซ็น > ลายเซ็นศิลปิน', 'o-module-image_annotate:annotation added - > คราบน้ำ', 'o-module-image_annotate:annotation removed รอยฉีก > -']);
check('a note with no body has no words, and is still a note', RevisionHistory\Capture\StateReader::imageNotes([['id' => '#z', 'target' => ['selector' => ['value' => 'xywh=1,2,3,4']]]])['#z']['value'], null);

/* ------------------------------------------------------------ RevisionHistoryShow::differs */

$show = new RevisionHistoryShow(new Doctrine\DBAL\Connection(), 'UTC');
check('a link that changed only its data type says so, in Omeka\'s words',
    $show->differs(v('', ['type' => 'resource:item', 'value' => null, 'vr' => 52]), v('', ['type' => 'resource', 'value' => null, 'vr' => 52])),
    'Data types: resource:item → resource');
check('the selector is shown as Image Annotate stores it', $show->where('xywh=percent:78.5,84,18,12'), 'xywh=percent:78.5,84,18,12');
check('language and visibility together', $show->differs(v('A'), v('A', ['lang' => 'th', 'pub' => 0])), 'Language: — → th · Visibility: Public → Private');

/* ------------------------------------------- value annotations, in words and on the page (1.0.3) */

$n = function (string $f, string $text, array $extra = []) {
    return ['f' => $f] + v($text, $extra);
};
$withNotes = function (string $text, array $notes) {
    return v($text) + ['note' => $notes];
};
// Called through this, so that against a version without it the checks fail one by one.
$words = function (array $b, array $a) use ($show) {
    return method_exists($show, 'noteWords') ? $show->noteWords($b, $a) : '(no noteWords)';
};
check('a value of an annotation made private is said so, by its field',
    $words($withNotes('A', [$n('dcterms:description', 'n')]), $withNotes('A', [$n('dcterms:description', 'n', ['pub' => 0])])),
    'Value annotations: dcterms:description Public → Private');
check('…and made public again',
    $words($withNotes('A', [$n('dcterms:description', 'n', ['pub' => 0])]), $withNotes('A', [$n('dcterms:description', 'n')])),
    'Value annotations: dcterms:description Private → Public');
check('an annotation added to a value that had none',
    $words(v('A'), $withNotes('A', [$n('dcterms:description', 'n')])),
    'Value annotations: dcterms:description added');
check('one edited, one removed, one left as it was',
    $words(
        $withNotes('A', [$n('dcterms:description', 'n'), $n('dcterms:date', '1961'), $n('dcterms:source', 's')]),
        $withNotes('A', [$n('dcterms:description', 'n2'), $n('dcterms:source', 's')])
    ),
    'Value annotations: dcterms:description edited · dcterms:date removed');
check('its language',
    $words($withNotes('A', [$n('dcterms:description', 'n')]), $withNotes('A', [$n('dcterms:description', 'n', ['lang' => 'th'])])),
    'Value annotations: dcterms:description language — → th');
check('differs() says the same words', $show->differs($withNotes('A', [$n('dcterms:description', 'n')]), $withNotes('A', [])),
    'Value annotations: dcterms:description removed');
$priv = $show->notes($withNotes('A', [$n('dcterms:description', 'n', ['pub' => 0, 'lang' => 'th'])]));
check('a private annotation value is marked Private on the page', strpos($priv, '<span class="rh-private">Private</span>') !== false, true);
check('…and its language is shown', strpos($priv, '<span class="rh-lang">th</span>') !== false, true);
$both = $show->edit(['op' => 'edited', 'field' => 'dcterms:title',
    'before' => $withNotes('A', [$n('dcterms:description', 'n')]), 'after' => $withNotes('A', [$n('dcterms:description', 'n', ['pub' => 0])])]);
check('an annotation made private: the two sides of the edit no longer read the same',
    substr_count($both, 'rh-private'), 1);
check('…and the line says what happened to it', strpos($both, 'dcterms:description Public → Private') !== false, true);

/* ------------------------------------------------------------ how a save arrived */

check('a web request that is not admin, API or site has words of its own',
    $show->via(['source' => 'here', 'via' => 'web', 'via_detail' => '/login']),
    'saved by a web request outside the admin pages, the API and the sites · /login');
check('…also without an address', $show->via(['source' => 'here', 'via' => 'web', 'via_detail' => '']),
    'saved by a web request outside the admin pages, the API and the sites');

/* ---------------------------------------------- Vocab Sync's own record */

$vs = function ($action, $detail = null) {
    return Copier::vocabSyncEntry(['action' => $action, 'detail' => $detail === null ? null : json_encode($detail)]);
};
check('a vocabulary made', $vs('vocab.create')[0], 'created');
check('a term deleted', $vs('term.delete', ['before' => 'Linen', 'after' => null])[0], 'deleted');
check('…with the wording it had', ops($vs('term.delete', ['before' => 'Linen', 'after' => null])[1]),
    ['o-module-vocab_sync:term removed Linen > -']);
check('a term renamed', ops($vs('term.rename', ['before' => 'Linnen', 'after' => 'Linen'])[1]),
    ['o-module-vocab_sync:term edited Linnen > Linen']);
check('a term added', ops($vs('term.add', ['before' => null, 'after' => 'Canvas'])[1]),
    ['o-module-vocab_sync:term added - > Canvas']);
check('anything else is a change', $vs('term.retire')[0], 'edited');
check('…and without two sides, the sentence Vocab Sync wrote is all there is', $vs('value.recheck', ['mode' => 'best-effort'])[1], []);

/* ------------------------------------------- everything else saved through Omeka */

use RevisionHistory\Capture\Others;

check('the session and the other log modules are left alone', [Others::skip('Omeka\\Entity\\Session'), Others::skip('Log\\Entity\\Log'), Others::skip('Omeka\\Entity\\Site')], [true, true, false]);
check('Omeka\'s own word where it has one', Others::kind('Omeka\\Entity\\ResourceTemplate'), 'resource template');
check('a module\'s entity keeps its own name', Others::kind('SomeModule\\Entity\\ThingSaved'), 'ThingSaved');
check('a stand-in Doctrine hands out is the entity it stands for', [Others::realClass('DoctrineProxies\\__CG__\\Omeka\\Entity\\ValueAnnotation'), Others::realClass('Omeka\\Entity\\Site')], ['Omeka\\Entity\\ValueAnnotation', 'Omeka\\Entity\\Site']);
check('a value annotation handed over as a stand-in is still part of its item', Others::skip('DoctrineProxies\\__CG__\\Omeka\\Entity\\ValueAnnotation'), true);
check('a stand-in keeps Omeka\'s word', Others::kind('DoctrineProxies\\__CG__\\Omeka\\Entity\\User'), 'user');

// Where an item set sits in the tree: one row of ItemSetsTree's own table, written to
// by Item Sets Tree Manager. It has no name of its own — the card is named after the
// item set the row is about, and belongs on that item set's history page.
/* ------------------------------------------------------- FormState (before a save) */
use RevisionHistory\Capture\FormState;

$json = json_encode([
    'dcterms:title' => [['property_id' => 1, 'type' => 'literal', 'is_public' => '1', '@language' => '', '@value' => 'A name']],
    'dcterms:subject' => [
        ['property_id' => 3, 'type' => 'literal', 'is_public' => '0', '@language' => 'th', '@value' => 'หนึ่ง'],
        ['property_id' => 3, 'type' => 'uri', 'is_public' => '1', '@id' => 'http://example.org/x', 'o:label' => 'Ex'],
        ['property_id' => 3, 'type' => 'resource', 'is_public' => '1', 'value_resource_id' => '42'],
        ['property_id' => 3, 'type' => 'literal', 'is_public' => '1', '@value' => ''],
    ],
], JSON_UNESCAPED_UNICODE);
[$vals, $props] = FormState::values($json);
check('the form is read field by field, in its own order', array_keys($vals), ['dcterms:title', 'dcterms:subject']);
check('an empty box is not a value', count($vals['dcterms:subject']), 3);
check('a link keeps its address and what it is called', $vals['dcterms:subject'][1], ['type' => 'uri', 'value' => 'Ex', 'uri' => 'http://example.org/x', 'vr' => null, 'lang' => null, 'pub' => 1]);
check('a link to another resource keeps its number', $vals['dcterms:subject'][2]['vr'], 42);
check('a private value in a language keeps both', [$vals['dcterms:subject'][0]['pub'], $vals['dcterms:subject'][0]['lang']], [0, 'th']);
check('each field keeps its property', $props, ['dcterms:title' => 1, 'dcterms:subject' => 3]);

// Vocab Sync keeps the term's number in the table but the form sends the term's label.
// Asked the way Vocab Sync asks (label first — a term may be called "1961"), the two
// sides read as the same value and nobody is told about an edit that did not happen.

$find = function (int $vocab, string $raw): ?int {
    $byLabel = [1 => ['N/A' => 747, '1961' => 3, 'Public' => 395]];
    if (isset($byLabel[$vocab][$raw])) {
        return $byLabel[$vocab][$raw];
    }
    return ctype_digit($raw) && in_array((int) $raw, [747, 3, 395], true) ? (int) $raw : null;
};
$vs = function (string $value) {
    return ['type' => 'vocabsync:1', 'value' => $value, 'uri' => null, 'vr' => null, 'lang' => null, 'pub' => 1];
};
$fixed = FormState::vocabSync(['dcterms:language' => [$vs('N/A')], 'dcterms:date' => [$vs('1961')]], $find);
check('a Vocab Sync label is read as the term it names', $fixed['dcterms:language'][0]['value'], '747');
check('a term whose name is a number is still read by its name', $fixed['dcterms:date'][0]['value'], '3');
$kept = FormState::vocabSync(['dcterms:language' => [$vs('Nothing answers to this')]], $find);
check('a name no term answers to is left as the form sent it', $kept['dcterms:language'][0]['value'], 'Nothing answers to this');
$note = ['type' => 'literal', 'value' => 'x', 'uri' => null, 'vr' => null, 'lang' => null, 'pub' => 1,
    'note' => [['f' => 'dcterms:language'] + $vs('N/A')]];
$withNote = FormState::vocabSync(['dcterms:title' => [$note]], $find);
check('a Vocab Sync value inside a value annotation is read too', $withNote['dcterms:title'][0]['note'][0], ['f' => 'dcterms:language'] + $vs('747'));

$before = FormState::blank('item', 7);
$before['values'] = ['dcterms:title' => [v('Old name')]];
$before['props'] = ['dcterms:title' => 1];
$before['media_order'] = [10, 11];
$after = FormState::after([
    'values_json' => json_encode(['dcterms:title' => [['property_id' => 1, 'type' => 'literal', '@value' => 'Old name']]]),
    'o:is_public' => '0',
    'o:item_set' => [['o:id' => '3'], ['o:id' => '4']],
    'o:media' => [['o:id' => '11']],
], $before);
check('what the form does not change stays as it is', $after['values'], ['dcterms:title' => [v('Old name')]]);
check('a form that hides the item says so', $after['is_public'], 0);
check('the item sets the form carries are the ones it will have', $after['item_sets'], [3, 4]);
check('a file the form no longer carries is not in what it would leave', $after['media_order'], [11]);
$noForm = FormState::after([], $before);
check('a form that carries no values leaves them alone', $noForm['values'], $before['values']);

/* --------------------------------------------- Levels (one level of the tree) */
use RevisionHistory\Capture\Levels;

$ids = function (array $list) { return array_map(function ($id) { return ['id' => $id]; }, $list); };
$moved = Levels::changes([0 => [10, 11, 12, 13]], [0 => [13, 10, 11, 12]]);
check('a level whose order changed is one line', array_keys($moved), [0]);
check('the line says the level before and after', [$moved[0]['op'], $moved[0]['field'], $moved[0]['before'], $moved[0]['after'], $moved[0]['size_before'], $moved[0]['size_after']],
    ['order', 'rank', $ids([10, 11, 12, 13]), $ids([13, 10, 11, 12]), 4, 4]);
check('a level nobody touched says nothing', Levels::changes([0 => [10, 11]], [0 => [10, 11]]), []);
check('a level that was not read before is not compared', Levels::changes([], [3 => [10]]), []);
// Only the item set somebody really moved is marked; the rest were pushed along.
$view = \RevisionHistory\View\FieldView::build($ids([10, 11, 12, 13]), $ids([13, 10, 11, 12]));
check('one item set moved, three were pushed along', $view['moved'], 1);

check('the item sets tree is called by its own name', Others::kind('ItemSetsTree\\Entity\\ItemSetsTreeEdge'), 'item sets tree');
class FakeItemSet { public function getId() { return 77; } public function getTitle() { return 'Paintings'; } }
class FakeEdge { public function getId() { return 5; } public function getItemSet() { return new FakeItemSet(); } }
if (!class_exists('ItemSetsTree\\Entity\\ItemSetsTreeEdge')) {
    eval('namespace ItemSetsTree\\Entity; class ItemSetsTreeEdge extends \\FakeEdge {}');
}
$edgeClass = 'ItemSetsTree\\Entity\\ItemSetsTreeEdge';
check('a row of the tree is about the item set it names', Others::about(new $edgeClass()), ['id' => 77, 'title' => 'Paintings', 'resource' => true]);
check('anything else is about nothing in particular', Others::about(new FakeItemSet()), null);

// An empty list holds nothing. Saying a field went from [] to nothing reads as a removal
// nobody made — the same trap an empty string is, and it is closed the same way.
check('an empty list is nothing at all', [Others::plain([]), Others::plain(''), Others::plain(null)], [null, null, null]);
check('nothing is written when a field went from an empty list to nothing',
    Others::lines([['dataType', [], null]], 'edited'), []);
// A deleted row says the same thing twice: the number it points at and the thing itself.
class FakeSite { public function getId() { return 2; } public function getTitle() { return 'Probe site'; } }
$gone = Others::entry(new FakeItemSet(), 'deleted', [], ['id' => 9, 'role' => 'admin', 'site_id' => 2, 'site' => new FakeSite()]);
check('a deleted row keeps the thing, not the number of it, and not its own id',
    array_map(function ($r) { return $r[0]; }, $gone['raw']), ['role', 'site']);
check('an old value annotation row knows its item from the page it was saved from', [
    RevisionHistory\Reader::annotationItem(['resource_kind' => 'ValueAnnotation', 'item_id' => null, 'via_detail' => '/admin/item/1234/edit'])['item_id'],
    RevisionHistory\Reader::annotationItem(['resource_kind' => 'ValueAnnotation', 'item_id' => null, 'via_detail' => 'PUT /api/items/9'])['item_id'],
    RevisionHistory\Reader::annotationItem(['resource_kind' => 'page', 'item_id' => null, 'via_detail' => '/admin/item/5/edit'])['item_id'],
], [1234, null, null]);
check('the same conditions said of another row, and only of e', RevisionHistory\Reader::other("e.action = ? AND EXISTS (SELECT 1 FROM revision_history_change c WHERE c.event_id = e.id) AND de.action = 'deleted' AND x.save_key = e.save_key"),
    "e2.action = ? AND EXISTS (SELECT 1 FROM revision_history_change c WHERE c.event_id = e2.id) AND de.action = 'deleted' AND x.save_key = e2.save_key");
check('a run of Batch Values is known by how it arrived', [
    RevisionHistory\Capture\Recorder::batchRun(['admin', '/admin/batch-values/apply']),
    RevisionHistory\Capture\Recorder::batchRun(['admin', '/admin/batch-values/cancel/4']),
    RevisionHistory\Capture\Recorder::batchRun(['job', '#12 BatchValues\\Job\\ApplyChangeSet']),
    RevisionHistory\Capture\Recorder::batchRun(['job', '#13 Omeka\\Job\\BatchUpdate']),
    RevisionHistory\Capture\Recorder::batchRun(['admin', '/admin/item/5/edit']),
], [['job' => 0], ['job' => 0], ['job' => 12], null, null]);
check('a note that did not change is not part of what changed', RevisionHistory\Reader::sameNote(
    ['type' => 'literal', 'value' => 'B', 'note' => [['f' => 'dcterms:description', 'value' => 'n']]],
    ['type' => 'literal', 'value' => 'B2', 'note' => [['f' => 'dcterms:description', 'value' => 'n']]]
), [['type' => 'literal', 'value' => 'B'], ['type' => 'literal', 'value' => 'B2']]);
check('a note that changed stays', count(RevisionHistory\Reader::sameNote(['value' => 'B', 'note' => [1]], ['value' => 'B', 'note' => [2]])[1]), 2);
/* ------------------------------------------- putting one save back */

use RevisionHistory\Restore\Planner;
$L = function ($v) { return ['type' => 'literal', 'value' => $v, 'uri' => null, 'vr' => null, 'lang' => null, 'pub' => 1]; };
$now = ['values' => ['dcterms:title' => [$L('Kept')], 'dcterms:subject' => [$L('New')]], 'props' => ['dcterms:title' => 1, 'dcterms:subject' => 3], 'is_public' => 0, 'item_sets' => [7]];
$plan = Planner::plan([
    ['op' => 'removed', 'field' => 'dcterms:description', 'property_id' => 4, 'before' => $L('Gone'), 'after' => null],
    ['op' => 'edited', 'field' => 'dcterms:subject', 'property_id' => 3, 'before' => $L('Old'), 'after' => $L('New')],
    ['op' => 'edited', 'field' => 'dcterms:title', 'property_id' => 1, 'before' => $L('First'), 'after' => $L('Second')],
    ['op' => 'edited', 'field' => 'o:is_public', 'property_id' => null, 'before' => ['id' => 1], 'after' => ['id' => 0]],
    ['op' => 'added', 'field' => 'o:item_set', 'property_id' => null, 'before' => null, 'after' => ['id' => 7]],
    ['op' => 'added', 'field' => 'o-module-image_annotate:annotation', 'property_id' => null, 'before' => null, 'after' => ['id' => 'x']],
], $now);
check('a removed value comes back, an edit is undone, the rest of the item is kept', [
    array_column($plan['values']['dcterms:description'], 'value'), array_column($plan['values']['dcterms:subject'], 'value'), array_column($plan['values']['dcterms:title'], 'value'),
], [['Gone'], ['Old'], ['Kept']]);
check('a value somebody changed since is left as it is, and the page says why', array_column($plan['rows'], 'why'), ['', '', 'changed since', '', '', 'cannot be put back here']);
check('a field that comes back goes where it was', array_keys(Planner::plan([
    ['op' => 'removed', 'field' => 'dcterms:title', 'property_id' => 1, 'before' => $L('T'), 'after' => null],
    ['op' => 'removed', 'field' => 'dcterms:creator', 'property_id' => 2, 'before' => $L('C'), 'after' => null],
    ['op' => 'edited', 'field' => 'dcterms:subject', 'property_id' => 3, 'before' => $L('Old'), 'after' => $L('New')],
    ['op' => 'removed', 'field' => 'dcterms:date', 'property_id' => 7, 'before' => $L('1978'), 'after' => null],
], ['values' => ['dcterms:subject' => [$L('New')], 'dcterms:extent' => [$L('x')]], 'props' => []])['values']),
    ['dcterms:title', 'dcterms:creator', 'dcterms:subject', 'dcterms:date', 'dcterms:extent']);
check('the item\'s own settings go back too', [$plan['own'], $plan['item_sets']], [['o:is_public' => 1], []]);
$again = Planner::plan([['op' => 'removed', 'field' => 'dcterms:subject', 'property_id' => 3, 'before' => $L('New'), 'after' => null]], $now);
check('a value that is already there again is not added twice', [$again['any'], $again['rows'][0]['why']], [false, 'already there']);
$gone = Planner::plan([['op' => 'removed', 'field' => 'dcterms:relation', 'property_id' => 13, 'before' => ['type' => 'resource:item', 'value' => null, 'uri' => null, 'vr' => 172, 'lang' => null, 'pub' => 1], 'after' => null]], $now, function ($id) { return false; });
$site = Planner::plan([
    ['op' => 'added', 'field' => 'o:site', 'property_id' => null, 'before' => null, 'after' => ['id' => 2]],
    ['op' => 'removed', 'field' => 'o:site', 'property_id' => null, 'before' => ['id' => 3], 'after' => null],
    ['op' => 'order', 'field' => 'o:media', 'property_id' => null, 'before' => [['id' => 5], ['id' => 6]], 'after' => [['id' => 6], ['id' => 5]]],
], ['values' => [], 'props' => [], 'sites' => [1, 2], 'media_order' => [6, 5, 9]]);
check('sites and the order of media go back', [$site['sites'], $site['media_order']], [[1, 3], [5, 6, 9]]);
$alt = Planner::plan([
    ['op' => 'edited', 'field' => 'o:alt_text', 'property_id' => null, 'before' => ['type' => 'literal', 'value' => 'Old'], 'after' => ['type' => 'literal', 'value' => 'New']],
    ['op' => 'edited', 'field' => 'o:file', 'property_id' => null, 'before' => ['type' => 'literal', 'value' => 'aaa'], 'after' => ['type' => 'literal', 'value' => 'bbb']],
], ['values' => [], 'props' => [], 'media' => ['alt_text' => 'New', 'lang' => null]]);
check('a media\'s alt text goes back; its file cannot', [$alt['media'], $alt['rows'][1]['why']], [['o:alt_text' => 'Old'], 'cannot be put back here']);
$moved = Planner::plan([
    ['op' => 'edited', 'field' => 'o:item', 'property_id' => null, 'before' => ['id' => 255], 'after' => ['id' => 258]],
], ['values' => [], 'props' => [], 'media' => ['alt_text' => null, 'lang' => null]]);
check('a media put into another item is not put back by Restore', [$moved['any'], $moved['rows'][0]['why']], [false, 'cannot be put back here']);
check('a link to a deleted resource is not made again', [$gone['any'], $gone['rows'][0]['why']], [false, 'no longer exists']);
check('a value is sent the way each data type reads it', [
    Planner::apiValue($L('Text'), 3),
    Planner::apiValue(['type' => 'uri', 'value' => 'Label', 'uri' => 'http://x', 'vr' => null, 'lang' => 'th', 'pub' => 0], 3),
    Planner::apiValue(['type' => 'resource:item', 'value' => null, 'uri' => null, 'vr' => 12, 'lang' => null, 'pub' => 1], 3),
    Planner::apiValue($L('Note on it') + ['note' => [['f' => 'dcterms:description'] + $L('The note')]], 3, null, function ($t) { return 4; }),
], [
    ['type' => 'literal', 'property_id' => 3, 'is_public' => true, '@value' => 'Text'],
    ['type' => 'uri', 'property_id' => 3, 'is_public' => false, '@id' => 'http://x', 'o:label' => 'Label', '@language' => 'th'],
    ['type' => 'resource:item', 'property_id' => 3, 'is_public' => true, 'value_resource_id' => 12],
    ['type' => 'literal', 'property_id' => 3, 'is_public' => true, '@value' => 'Note on it', '@annotation' => ['dcterms:description' => [['type' => 'literal', 'property_id' => 4, 'is_public' => true, '@value' => 'The note']]]],
]);

check('a date is written as a date', Others::plain(new DateTime('2026-09-18 10:11:12')), '2026-09-18 10:11:12');
check('true and false are written as words', [Others::plain(true), Others::plain(false)], ['true', 'false']);
$lines = Others::lines([['title', 'Old title', 'New title'], ['slug', null, 'new-page'], ['theme', 'default', null]], 'edited');
check('a field changed, one filled in, one emptied', ops($lines), ['title edited Old title > New title', 'slug added - > new-page', 'theme removed default > -']);
check('nothing is written twice when a field did not really change', Others::lines([['title', 'same', 'same']], 'edited'), []);

/* --------------------------------------------------- the history as a file */

use RevisionHistory\Export\Csv;

check('a value is written as it reads', Csv::plain(v('ink on paper')), 'ink on paper');
check('a private value says so', Csv::plain(v('ink on paper', ['pub' => 0])), 'ink on paper (private)');
check('a link says which resource', Csv::plain(v('', ['type' => 'resource:item', 'value' => null, 'vr' => 52])), '#52 (resource:item)');
check('a language is kept', Csv::plain(v('ผ้าใบ', ['lang' => 'th'])), 'ผ้าใบ (th)');
check('nothing at all is an empty box', Csv::plain(null), '');

/* ------------------ what the buttons on Omeka's own edit page did */

use RevisionHistory\Capture\FormActions;

$acts = function (array $actions) {
    return json_encode(['fields' => ['dcterms:coverage'], 'actions' => $actions], JSON_UNESCAPED_UNICODE);
};
check('values put in order', FormActions::sentence($acts([
    ['kind' => 'sort', 'direction' => 'asc', 'field' => 'Coverage', 'term' => 'dcterms:coverage', 'n' => 5],
])), 'With the Batch Values buttons on this page: “Coverage” put in order, A → Z');
check('the other way round', FormActions::sentence($acts([
    ['kind' => 'sort', 'direction' => 'desc', 'field' => 'Coverage'],
])), 'With the Batch Values buttons on this page: “Coverage” put in order, Z → A');
check('moved and copied, in the order they were done', FormActions::sentence($acts([
    ['kind' => 'move', 'n' => 1, 'field' => 'Coverage', 'target' => 'Spatial Coverage'],
    ['kind' => 'copy', 'n' => 3, 'field' => 'Coverage', 'target' => 'Subject'],
])), 'With the Batch Values buttons on this page: 1 value(s) moved from “Coverage” to “Spatial Coverage”'
    . ' · 3 value(s) copied from “Coverage” to “Subject”');
check('a form that said nothing says nothing', [
    FormActions::sentence(null), FormActions::sentence(''), FormActions::sentence('not json'),
    FormActions::sentence($acts([])), FormActions::sentence($acts([['kind' => 'sort']])),
    FormActions::sentence($acts([['kind' => 'move', 'n' => 0, 'field' => 'A', 'target' => 'B']])),
], [null, null, null, null, null, null]);
check('nothing of a name survives that is not its name', FormActions::sentence($acts([
    ['kind' => 'sort', 'field' => "<b>Cover\n  age</b>"],
])), 'With the Batch Values buttons on this page: “Cover age” put in order, A → Z');

/* --------------------------- a link saved with its exact data type */

$link = function (string $type, int $vr) {
    return ['type' => $type, 'value' => null, 'uri' => null, 'vr' => $vr, 'lang' => null, 'pub' => 1];
};
check('a link made exact is known for what it is', RevisionHistoryShow::exactType([
    'op' => 'edited', 'field' => 'dcterms:relation', 'before' => $link('resource', 264), 'after' => $link('resource:item', 264),
]), true);
check('an item set and a media count too', [
    RevisionHistoryShow::exactType(['op' => 'edited', 'before' => $link('resource', 9), 'after' => $link('resource:itemset', 9)]),
    RevisionHistoryShow::exactType(['op' => 'edited', 'before' => $link('resource', 9), 'after' => $link('resource:media', 9)]),
], [true, true]);
check('a link that now points somewhere else is a real change', RevisionHistoryShow::exactType([
    'op' => 'edited', 'before' => $link('resource', 264), 'after' => $link('resource:item', 265),
]), false);
check('a link made private at the same time is a real change', RevisionHistoryShow::exactType([
    'op' => 'edited', 'before' => $link('resource', 264), 'after' => $link('resource:item', 264) + [] , 
]) && !RevisionHistoryShow::exactType([
    'op' => 'edited', 'before' => $link('resource', 264), 'after' => array_merge($link('resource:item', 264), ['pub' => 0]),
]), true);
check('the other way round is not this', RevisionHistoryShow::exactType([
    'op' => 'edited', 'before' => $link('resource:item', 264), 'after' => $link('resource', 264),
]), false);
check('an added or removed link is not this', [
    RevisionHistoryShow::exactType(['op' => 'added', 'before' => null, 'after' => $link('resource:item', 264)]),
    RevisionHistoryShow::exactType(['op' => 'removed', 'before' => $link('resource', 264), 'after' => null]),
], [false, false]);

/* ------------------------------------- saves the admin pages refused */

use RevisionHistory\Watch\Refused;

check('nothing of the form arrived, and it was too big for this server', Refused::reason([
    'token_sent' => 0, 'vars' => 0, 'bytes' => 9123456, 'limits' => 'max_input_vars=1000 post_max_size=8M',
]), 'The browser sent the form and this server received none of it: the form was larger than this server accepts.');
check('nothing arrived but the form was small: nothing is claimed about why', Refused::reason([
    'token_sent' => 0, 'vars' => 0, 'bytes' => 129, 'limits' => 'max_input_vars=1000 post_max_size=128M',
]), 'The browser sent the form and this server read nothing out of it.');
check('how big a form may be on this server', [
    Refused::bytes('max_input_vars=1000 post_max_size=8M'),
    Refused::bytes('post_max_size=128M session.gc_maxlifetime=1209600'),
    Refused::bytes('post_max_size=2048'),
    Refused::bytes('nothing here'),
], [8388608, 134217728, 2048, 0]);
check('the form was read up to this server\'s limit', Refused::reason(['token_sent' => 0, 'vars' => 1000, 'bytes' => 91234, 'limits' => 'max_input_vars=1000 post_max_size=8M']),
    'This server reads at most 1000 fields of a form and the form had at least that many, so its last field — the token — never arrived.');
check('a form that simply had no token says so', Refused::reason(['token_sent' => 0, 'vars' => 21, 'bytes' => 2415, 'limits' => 'max_input_vars=1000 post_max_size=8M']),
    'The form arrived with no token in it at all, and it was not this server that dropped it.');
check('this session has no token at all', Refused::reason(['token_sent' => 1, 'tokens' => 0, 'vars' => 20, 'bytes' => 900]),
    'This session holds no token at all: it is not the session the page was opened in.');
check('the session was made moments before the save', Refused::reason([
    'token_sent' => 1, 'tokens' => 1, 'tokens_most' => 0, 'vars' => 20, 'bytes' => 900,
    'at' => '2026-09-28 10:00:30.000000', 'session_at' => '2026-09-28 10:00:26',
]), 'The session was made 4 seconds before this save: the one the page was opened in was gone by then.');
check('the session lost tokens it once held', Refused::reason([
    'token_sent' => 1, 'tokens' => 1, 'tokens_most' => 6, 'vars' => 20, 'bytes' => 900,
    'at' => '2026-09-28 10:00:30.000000', 'session_at' => '2026-09-28 08:00:00',
]), 'This session holds 1 tokens and once held 6: something wrote over the session.');
check('otherwise it says only what it knows', Refused::reason([
    'token_sent' => 1, 'tokens' => 4, 'tokens_most' => 4, 'vars' => 20, 'bytes' => 900,
    'at' => '2026-09-28 10:00:30.000000', 'session_at' => '2026-09-28 08:00:00',
]), 'The token sent was not one this session knows.');
check('how old the session was', Refused::age(['at' => '2026-09-28 10:00:30.000000', 'session_at' => '2026-09-28 09:59:00']), 90);
check('no token, no password and no key is ever kept', Refused::keep([
    'values_json' => '{}', 'csrf' => 'abc', 'itemStub_csrf' => 'def', 'o:is_public' => '1',
    'password' => 'x', 'new_credential' => 'y', 'api_token' => 'z', 'o:media' => [['o:id' => '4', 'secret_word' => 'q']],
]), ['values_json' => '{}', 'o:is_public' => '1', 'o:media' => [['o:id' => '4']]]);
check('a list counts as its parts', Refused::count(['a' => '1', 'b' => ['c' => '2', 'd' => ['e' => '3', 'f' => '4']]]), 4);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
