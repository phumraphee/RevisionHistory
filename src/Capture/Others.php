<?php
namespace RevisionHistory\Capture;

/**
 * Everything else that is saved through Omeka.
 *
 * The recorder watches items, media and item sets closely, because that is what the
 * archive is. But a resource template, a vocabulary, a user account, a site page, a
 * setting, a module being installed — those are changes to the archive too, and until
 * now nothing here said they had happened.
 *
 * Doctrine already knows what changed in each of them: for every entity it is about to
 * write it keeps a change set, field by field, with the value before and after. That is
 * what is read here. The names are the ones Omeka (or the module) uses for those fields
 * inside itself; nothing is renamed or guessed.
 *
 * Not everything is worth keeping: a row written on every single request (the session,
 * the search index) would bury the history in noise, and the three log modules writing
 * their own rows would have this module recording that they recorded something.
 */
class Others
{
    /** Things saved on every request, or the logs of other log modules. */
    const IGNORE = [
        'Omeka\Entity\Session',
        'Omeka\Entity\FulltextSearch',
        'Omeka\Entity\Job',
        'Omeka\Entity\PasswordCreation',
        'Omeka\Entity\Migration',
        'Log\Entity\Log',
        'HistoryLog\Entity\HistoryEvent',
        'HistoryLog\Entity\HistoryChange',
        'ActivityLog\Entity\ActivityLogEvent',
        // Its notes belong to the media they are drawn on, and are recorded there.
        'ImageAnnotate\Entity\ImageAnnotateMedia',
    ];

    /** Handled by the recorder itself, as the archive's own resources. */
    const RESOURCES = [
        'Omeka\Entity\Item',
        'Omeka\Entity\Media',
        'Omeka\Entity\ItemSet',
        'Omeka\Entity\Resource',
        'Omeka\Entity\Value',
        'Omeka\Entity\ValueAnnotation',
    ];

    /** What each one is called where Omeka shows it. Anything else keeps its own name. */
    const KIND = [
        'Omeka\Entity\ResourceTemplate' => 'resource template',
        'Omeka\Entity\ResourceTemplateProperty' => 'resource template field',
        'Omeka\Entity\Vocabulary' => 'vocabulary',
        'Omeka\Entity\Property' => 'property',
        'Omeka\Entity\ResourceClass' => 'class',
        'Omeka\Entity\User' => 'user',
        'Omeka\Entity\UserSetting' => 'user setting',
        'Omeka\Entity\Site' => 'site',
        'Omeka\Entity\SitePage' => 'page',
        'Omeka\Entity\SitePageBlock' => 'page block',
        'Omeka\Entity\SitePermission' => 'site permission',
        'Omeka\Entity\SiteItemSet' => 'site item set',
        'Omeka\Entity\SiteBlockAttachment' => 'block attachment',
        'Omeka\Entity\SiteSetting' => 'site setting',
        'Omeka\Entity\Setting' => 'setting',
        'Omeka\Entity\Asset' => 'asset',
        'Omeka\Entity\Module' => 'module',
        'Omeka\Entity\ApiKey' => 'API key',
        // Omeka's own words for the archive's resources, for when one of them is what
        // another row points at.
        'Omeka\Entity\Item' => 'item',
        'Omeka\Entity\Media' => 'media',
        'Omeka\Entity\ItemSet' => 'item set',
        // Where an item set sits in the hierarchy, kept by the ItemSetsTree module
        // and written to by Item Sets Tree Manager. One row is one item set's place.
        'ItemSetsTree\Entity\ItemSetsTreeEdge' => 'item sets tree',
    ];

    /**
     * Rows that are not a thing of their own but say something about something else:
     * the class, the getter that hands that thing over, and whether it is one of the
     * archive's resources. The card is named after it; when it is a resource, the card
     * also shows on that resource's own history page.
     */
    const ABOUT = [
        'ItemSetsTree\Entity\ItemSetsTreeEdge' => ['getItemSet', true],
        'Omeka\Entity\SiteItemSet' => ['getItemSet', true],
        'Omeka\Entity\SiteBlockAttachment' => ['getItem', true],
        'Omeka\Entity\ResourceTemplateProperty' => ['getProperty', false],
        'Omeka\Entity\SitePermission' => ['getUser', false],
    ];

    /** Rows whose name is a word of their own rather than another row's name. */
    const NAME_BY = [
        // Which kind of block it is, by the word Omeka's own block layouts are known by.
        'Omeka\Entity\SitePageBlock' => 'getLayout',
    ];

    /**
     * Fields Omeka writes by itself when something is USED rather than changed.
     * 1.10.2: an API key's lastAccessed and lastIp move on every call made with it —
     * 1,025 times on 13 October alone. A save that touched only these is not an edit of
     * the key; it is the key being used, and it is kept as that: one entry per key per
     * hour, counting the calls and the addresses they came from (Store::keyUse). Nothing
     * is dropped — the owner asked for everything that can be kept — only folded.
     */
    const BY_ITSELF = [
        'Omeka\Entity\ApiKey' => ['lastIp', 'lastAccessed'],
    ];

    /** Whether one entry is only a thing being used (see BY_ITSELF). */
    public static function isUse($entity, array $entry): bool
    {
        $only = self::BY_ITSELF[self::realClass(get_class($entity))] ?? null;
        if (!$only || ($entry['action'] ?? '') !== 'edited' || empty($entry['raw'])) {
            return false;
        }
        foreach ($entry['raw'] as [$field]) {
            if (!in_array((string) $field, $only, true)) {
                return false;
            }
        }
        return true;
    }

    /** Never written down, whatever a module calls them. */
    const SECRET = ['password', 'credential', 'hash', 'token', 'secret', 'salt'];

    /**
     * The entity's own class. Doctrine hands out stand-ins for entities it has not
     * loaded yet — "DoctrineProxies\__CG__\Omeka\Entity\ValueAnnotation" — and a name
     * compared letter by letter against the lists above must be the real one.
     */
    public static function realClass(string $class): string
    {
        $at = strrpos($class, '\\__CG__\\');
        return $at === false ? $class : substr($class, $at + 8);
    }

    public static function skip(string $class, array $ignore = []): bool
    {
        $class = self::realClass($class);
        return in_array($class, self::RESOURCES, true)
            || in_array($class, self::IGNORE, true)
            || in_array($class, $ignore, true)
            || strpos($class, 'RevisionHistory\\') === 0;
    }

    public static function kind(string $class): string
    {
        $class = self::realClass($class);
        if (isset(self::KIND[$class])) {
            return self::KIND[$class];
        }
        $short = substr($class, strrpos($class, '\\') === false ? 0 : strrpos($class, '\\') + 1);
        return mb_substr($short, 0, 32);
    }

    private static function secret(string $field): bool
    {
        $f = strtolower($field);
        foreach (self::SECRET as $word) {
            if (strpos($f, $word) !== false) {
                return true;
            }
        }
        return false;
    }

    /** One field's value, as a line of text that can be read months later. */
    public static function plain($v): ?string
    {
        // An empty list and nothing at all say the same thing. Kept apart, a field that
        // held [] and now holds null reads as something somebody removed, which nobody did.
        if ($v === null || $v === [] || $v === '') {
            return null;
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d H:i:s');
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_scalar($v)) {
            return mb_substr((string) $v, 0, 2000);
        }
        if (is_object($v)) {
            $class = get_class($v);
            if (method_exists($v, 'getId')) {
                try {
                    $id = $v->getId();
                    // What it is called, when it has a name: a number alone says nothing
                    // to somebody reading the history months later.
                    $name = '';
                    foreach (['getTitle', 'getLabel', 'getName'] as $getter) {
                        if (method_exists($v, $getter)) {
                            $n = $v->$getter();
                            if (is_string($n) && $n !== '') {
                                $name = mb_substr($n, 0, 190);
                                break;
                            }
                        }
                    }
                    if ($name !== '') {
                        return $name . ($id === null ? '' : ' #' . $id);
                    }
                    return self::kind($class) . ($id === null ? '' : ' #' . $id);
                } catch (\Throwable $e) {
                }
            }
            return self::kind($class);
        }
        return mb_substr((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 0, 2000);
    }

    private static function value(?string $text): ?array
    {
        return $text === null ? null : ['type' => 'literal', 'value' => $text, 'uri' => null, 'vr' => null, 'lang' => null, 'pub' => 1];
    }

    /**
     * The resource one of these rows is about, once everything has been written.
     *
     * @return array{id: int, title: string}|null
     */
    public static function about($entity): ?array
    {
        [$getter, $isResource] = self::ABOUT[self::realClass(get_class($entity))] ?? [null, false];
        if (!$getter || !method_exists($entity, $getter)) {
            return null;
        }
        try {
            $r = $entity->$getter();
            if (!$r || !method_exists($r, 'getId')) {
                return null;
            }
            $id = $r->getId();
            if (!is_numeric($id)) {
                return null;
            }
            $title = '';
            foreach (['getTitle', 'getLabel', 'getName'] as $getter) {
                if (!method_exists($r, $getter)) {
                    continue;
                }
                $t = $r->$getter();
                if (is_string($t) && $t !== '') {
                    $title = mb_substr($t, 0, 190);
                    break;
                }
            }
            return ['id' => (int) $id, 'title' => $title, 'resource' => $isResource];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The name to show for one of these. A label, a name, a title — whichever the
     * entity itself has; a setting is named by its own key.
     */
    public static function title($entity, array $data): string
    {
        $own = self::NAME_BY[self::realClass(get_class($entity))] ?? null;
        if ($own && method_exists($entity, $own)) {
            try {
                $v = $entity->$own();
                if (is_string($v) && $v !== '') {
                    return mb_substr($v, 0, 190);
                }
            } catch (\Throwable $e) {
            }
        }
        foreach (['getLabel', 'getTitle', 'getName', 'getSlug', 'getEmail'] as $getter) {
            if (method_exists($entity, $getter)) {
                try {
                    $v = $entity->$getter();
                    if (is_string($v) && $v !== '') {
                        return mb_substr($v, 0, 190);
                    }
                } catch (\Throwable $e) {
                }
            }
        }
        foreach (['label', 'title', 'name', 'id'] as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                return mb_substr($data[$field], 0, 190);
            }
        }
        return '';
    }

    /**
     * What is about to be written to one entity, kept as it is for now: while Doctrine
     * is still inside the flush, a row being created has no id yet and the entities it
     * points at may not either. The words are made later, in lines(), once everything
     * has been written and can say what it is.
     *
     * @param object $entity
     * @param string $action created | edited | deleted
     * @param array $changeSet Doctrine's [field => [before, after]] (empty when deleting)
     * @param array $data Doctrine's copy of the row as it was (used when deleting)
     * @return array|null null when there is nothing worth keeping
     */
    public static function entry($entity, string $action, array $changeSet, array $data, array $ignore = []): ?array
    {
        $class = self::realClass(get_class($entity));
        if (self::skip($class, $ignore)) {
            return null;
        }
        $title = self::title($entity, $data);
        // This module's own notes to itself are not news.
        if ($class === 'Omeka\\Entity\\Setting' && strpos($title, 'revision_history_') === 0) {
            return null;
        }
        $raw = [];
        if ($action === 'deleted') {
            // Doctrine's copy of a row carries both the number of what it points at and
            // the thing itself — site_id and site, user_id and user. The thing says more
            // than the number, so the number is left out where both are there; and the
            // row's own id is already the number on the entry.
            $twice = [];
            foreach (array_keys($data) as $field) {
                if (substr((string) $field, -3) === '_id' && array_key_exists(substr((string) $field, 0, -3), $data)) {
                    $twice[(string) $field] = true;
                }
            }
            foreach ($data as $field => $was) {
                if ($field === 'id' || isset($twice[(string) $field]) || self::secret((string) $field)) {
                    continue;
                }
                $raw[] = [(string) $field, $was, null];
            }
        } else {
            foreach ($changeSet as $field => $pair) {
                if (!self::secret((string) $field)) {
                    $raw[] = [(string) $field, $pair[0] ?? null, $pair[1] ?? null];
                }
            }
            if ($action === 'edited' && !$raw) {
                return null;
            }
        }
        $id = 0;
        if ($action !== 'created' && method_exists($entity, 'getId')) {
            try {
                $id = is_numeric($entity->getId()) ? (int) $entity->getId() : 0;
            } catch (\Throwable $e) {
            }
        }
        return ['action' => $action, 'kind' => self::kind($class), 'title' => $title, 'id' => $id, 'raw' => $raw];
    }

    /**
     * The lines of one entry, in words: what each field held before and after.
     *
     * @return array rows for revision_history_change
     */
    public static function lines(array $raw, string $action): array
    {
        $changes = [];
        foreach ($raw as [$field, $was, $now]) {
            $before = self::plain($was);
            $after = self::plain($now);
            if ($action === 'deleted') {
                if ($before !== null && $before !== '') {
                    $changes[] = ['op' => 'removed', 'field' => $field, 'property_id' => null, 'before' => self::value($before), 'after' => null];
                }
                continue;
            }
            if ($before === $after) {
                continue;
            }
            $op = $action === 'created' ? 'added' : ($before === null || $before === '' ? 'added' : ($after === null || $after === '' ? 'removed' : 'edited'));
            $changes[] = ['op' => $op, 'field' => $field, 'property_id' => null, 'before' => self::value($before), 'after' => self::value($after)];
        }
        return $changes;
    }
}
