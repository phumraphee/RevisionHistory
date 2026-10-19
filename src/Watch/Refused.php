<?php
namespace RevisionHistory\Watch;

use Laminas\Session\Container;
use Laminas\Validator\Csrf;

/**
 * Saves the admin pages refused before anything was recorded.
 *
 * Omeka puts a token in every admin form and checks it when the form comes back:
 * "Invalid or missing CSRF token" is what a person is shown when that check fails.
 * The save never reaches the tables, so nothing in this history says it happened —
 * and the person is left with a page that failed for no reason they can see. Pressing
 * Save again usually works, which is exactly what makes it hard to find: whatever went
 * wrong was gone by the time anybody looked.
 *
 * So this looks at every admin form as it arrives, with the same question Omeka is
 * about to ask, and when the answer is no it writes down the state of that moment:
 * whether the token came at all, how many tokens the session holds, whether this is
 * still the session the page was opened in and how old it is, how much of the form
 * this server actually received. Those few numbers tell the causes apart —
 *
 *   nothing received at all          the body was dropped (too large for this server)
 *   the form stops at the limit      this server stopped reading it partway
 *   session younger than the save    the session was replaced between page and save
 *   session holds fewer than it did  something wrote over the session
 *
 * and what the person was saving is kept beside it, so a refused save costs nobody
 * their work. Nothing here changes the request, and nothing it does can fail it.
 */
class Refused
{
    /** This module's own corner of the session: when it began, and what it has seen. */
    const OURS = 'RevisionHistory';

    /** Never written down, whatever a form calls it. */
    const SECRET = '/password|credential|hash|token|secret|salt/i';

    /** A form's own field is never kept either: it is the token itself. */
    const TOKEN = '/(^|_)csrf$/i';

    /** At most this much of a refused form is kept. */
    const KEEP = 4000000;

    /**
     * @param \Laminas\ServiceManager\ServiceLocatorInterface $services
     * @param \Laminas\Mvc\MvcEvent $event
     */
    public static function look($services, $event): void
    {
        try {
            $match = $event->getRouteMatch();
            if (!$match || strpos((string) $match->getMatchedRouteName(), 'admin') !== 0) {
                return;
            }
            // This module's own pages are left alone: the one the admin forms post to
            // before they save carries the page's token only to read it, and the pages
            // that ask for something check a token of their own and say so themselves.
            if (strpos((string) $match->getMatchedRouteName(), 'admin/revision-history') === 0) {
                return;
            }
            // Read first, write after: what a refused save is compared with is the most
            // this session had been seen to hold before this request, not including it.
            $seen = self::session();
            self::held(self::holding('csrf'));
            $request = $event->getRequest();
            if (!method_exists($request, 'isPost') || !$request->isPost()) {
                return;
            }
            // Only a form. A page that sends JSON to a module's own address is not one
            // of Omeka's forms: PHP reads nothing into the form at all, which would
            // look exactly like a form this server threw away. Those requests carry
            // their own protection and are none of this module's business.
            if (!self::isForm($request)) {
                return;
            }
            $post = $request->getPost()->toArray();
            $bytes = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            $vars = self::count($post);

            $tokens = self::tokens($post);
            if ($tokens === null) {
                // No form token in this request. Only worth writing down when this
                // server plainly did not hand PHP the whole form: an empty form that
                // was sent with a body, or one that stops exactly at the limit. A form
                // that simply has no token is somebody else's business.
                $limit = (int) ini_get('max_input_vars');
                $cut = ($vars === 0 && $bytes > 0) || ($limit > 0 && $vars >= $limit);
                // Or the form is plainly one of Omeka's own, which always carries a
                // token: then its absence is the thing to write down.
                if (!$cut && !self::omeka($post)) {
                    return;
                }
                self::write($services, $post, $seen, [
                    'token_sent' => 0, 'token_known' => 0, 'tokens' => self::holding('csrf'),
                ], $vars, $bytes);
                return;
            }
            if ($tokens['ok']) {
                return;
            }
            self::write($services, $post, $seen, [
                'token_sent' => 1, 'token_known' => 0, 'tokens' => $tokens['held'],
            ], $vars, $bytes);
        } catch (\Throwable $e) {
            // A request is never failed by this.
        }
    }

    /**
     * The tokens this form carries, and whether any of them is one the session knows.
     * The question is put to Omeka's own validator, which only reads.
     *
     * @return array{ok: bool, held: int}|null null when the form carries none
     */
    private static function tokens(array $post): ?array
    {
        $names = [];
        foreach (array_keys($post) as $key) {
            if (is_string($key) && preg_match(self::TOKEN, $key)) {
                $names[] = $key;
            }
        }
        if (!$names) {
            return null;
        }
        $held = 0;
        foreach ($names as $name) {
            $value = $post[$name];
            if (!is_string($value)) {
                continue;
            }
            $validator = new Csrf(['name' => $name]);
            try {
                $box = new Container($validator->getSessionName());
                $list = $box->tokenList;
                $held = max($held, is_array($list) ? count($list) : 0);
            } catch (\Throwable $e) {
            }
            if ($validator->isValid($value)) {
                return ['ok' => true, 'held' => $held];
            }
        }
        return ['ok' => false, 'held' => $held];
    }

    /**
     * This session as this module knows it: a name of its own that says nothing about
     * the real one, when this module first saw it, and the most tokens it ever held.
     * Written once per session, and once more only when the count goes up.
     *
     * @return array{ref: string, at: ?string, most: int}
     */
    private static function session(): array
    {
        $out = ['ref' => '', 'at' => null, 'most' => 0];
        try {
            $ours = new Container(self::OURS);
            if (!$ours->ref) {
                $id = function_exists('session_id') ? (string) @session_id() : '';
                $ours->ref = substr(sha1('rh' . $id . microtime()), 0, 12);
                $ours->at = gmdate('Y-m-d H:i:s');
            }
            $out['ref'] = (string) $ours->ref;
            $out['at'] = $ours->at ? (string) $ours->at : null;
            $out['most'] = (int) $ours->most;
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /** The most tokens this session has been seen to hold, kept up to date. */
    public static function held(int $now): void
    {
        try {
            $ours = new Container(self::OURS);
            if ($now > (int) $ours->most) {
                $ours->most = $now;
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Whether this request is a form at all — what a browser sends when somebody
     * presses a button on a page, and the only thing Omeka puts a token in.
     */
    private static function isForm($request): bool
    {
        $type = '';
        try {
            $header = $request->getHeaders()->get('Content-Type');
            if ($header) {
                $type = strtolower((string) $header->getFieldValue());
            }
        } catch (\Throwable $e) {
        }
        if ($type === '') {
            $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        }
        return strpos($type, 'application/x-www-form-urlencoded') === 0
            || strpos($type, 'multipart/form-data') === 0;
    }

    /** Whether this is one of Omeka's own resource forms, which always carry a token. */
    private static function omeka(array $post): bool
    {
        foreach (array_keys($post) as $key) {
            if ($key === 'values_json' || (is_string($key) && strpos($key, 'o:') === 0)) {
                return true;
            }
        }
        return false;
    }

    /** How many tokens the session holds under this name now. */
    private static function holding(string $name): int
    {
        try {
            $box = new Container((new Csrf(['name' => $name]))->getSessionName());
            return is_array($box->tokenList) ? count($box->tokenList) : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** How many things PHP read out of the form, counting a list as its parts. */
    public static function count($post): int
    {
        $n = 0;
        foreach ((array) $post as $value) {
            $n += is_array($value) ? self::count($value) : 1;
        }
        return $n;
    }

    /**
     * What the person was saving, kept so it is not lost with the save.
     *
     * The form's own token is not kept — it is the token itself — and neither is
     * anything whose name says it is a password, a key or a secret, whatever form it
     * came from.
     */
    public static function keep($post): array
    {
        $out = [];
        foreach ((array) $post as $key => $value) {
            if (is_string($key) && (preg_match(self::TOKEN, $key) || preg_match(self::SECRET, $key))) {
                continue;
            }
            $out[$key] = is_array($value) ? self::keep($value) : $value;
        }
        return $out;
    }

    /**
     * What this says happened, in one sentence.
     *
     * @param array $row a row of `revision_history_refused`
     */
    public static function reason(array $row): string
    {
        $sent = !empty($row['token_sent']);
        $vars = (int) ($row['vars'] ?? 0);
        $bytes = (int) ($row['bytes'] ?? 0);
        $tokens = (int) ($row['tokens'] ?? 0);
        $most = (int) ($row['tokens_most'] ?? 0);
        if (!$sent) {
            if ($vars === 0 && $bytes > 0) {
                $most = self::bytes((string) ($row['limits'] ?? ''));
                if ($most > 0 && $bytes >= $most) {
                    return 'The browser sent the form and this server received none of it: the form was larger than this server accepts.';
                }
                return 'The browser sent the form and this server read nothing out of it.';
            }
            $limit = 0;
            if (preg_match('/max_input_vars=(\d+)/', (string) ($row['limits'] ?? ''), $m)) {
                $limit = (int) $m[1];
            }
            if ($limit > 0 && $vars >= $limit) {
                return sprintf('This server reads at most %d fields of a form and the form had at least that many, so its last field — the token — never arrived.', $limit);
            }
            return 'The form arrived with no token in it at all, and it was not this server that dropped it.';
        }
        if ($tokens === 0) {
            return 'This session holds no token at all: it is not the session the page was opened in.';
        }
        $age = self::age($row);
        if ($age !== null && $age <= 120) {
            return sprintf('The session was made %d seconds before this save: the one the page was opened in was gone by then.', $age);
        }
        if ($most > $tokens) {
            return sprintf('This session holds %d tokens and once held %d: something wrote over the session.', $tokens, $most);
        }
        return 'The token sent was not one this session knows.';
    }

    /** The most a form may be on this server, in bytes, out of what was written down. */
    public static function bytes(string $limits): int
    {
        if (!preg_match('/post_max_size=(\d+)\s*([KMG]?)/i', $limits, $m)) {
            return 0;
        }
        $n = (int) $m[1];
        $unit = strtoupper($m[2]);
        if ($unit === 'K') {
            return $n * 1024;
        }
        if ($unit === 'M') {
            return $n * 1024 * 1024;
        }
        if ($unit === 'G') {
            return $n * 1024 * 1024 * 1024;
        }
        return $n;
    }

    /** How old the session was when the save was refused, in seconds. */
    public static function age(array $row): ?int
    {
        if (empty($row['session_at']) || empty($row['at'])) {
            return null;
        }
        try {
            $began = new \DateTime((string) $row['session_at'], new \DateTimeZone('UTC'));
            $when = new \DateTime(substr((string) $row['at'], 0, 19), new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return null;
        }
        return $when->getTimestamp() - $began->getTimestamp();
    }

    private static function write($services, array $post, array $seen, array $state, int $vars, int $bytes): void
    {
        $who = ['id' => null, 'name' => ''];
        try {
            $user = $services->get('Omeka\AuthenticationService')->getIdentity();
            if ($user) {
                $who = ['id' => (int) $user->getId(), 'name' => (string) $user->getName()];
            }
        } catch (\Throwable $e) {
        }
        $kept = json_encode(self::keep($post), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($kept) || strlen($kept) > self::KEEP) {
            $kept = null;
        }
        $conn = $services->get('Omeka\Connection');
        $conn->executeStatement(
            'INSERT INTO `revision_history_refused`
               (`at`,`user_id`,`user_name`,`path`,`came_from`,`token_sent`,`token_known`,`tokens`,
                `tokens_most`,`session_ref`,`session_at`,`vars`,`bytes`,`limits`,`agent`,`kept`)
             VALUES (UTC_TIMESTAMP(6),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $who['id'], $who['name'],
                substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 255),
                substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 255),
                $state['token_sent'], $state['token_known'], $state['tokens'],
                $seen['most'], $seen['ref'], $seen['at'],
                $vars, $bytes,
                substr('max_input_vars=' . ini_get('max_input_vars')
                    . ' post_max_size=' . ini_get('post_max_size')
                    . ' session.gc_maxlifetime=' . ini_get('session.gc_maxlifetime'), 0, 190),
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190),
                $kept,
            ]
        );
        try {
            (new \Omeka\Mvc\Controller\Plugin\Messenger())->addWarning(
                'This save was refused before it reached the archive, and nothing was recorded.'
                . ' What you were saving has been kept: Revision History → Saves that were refused.'
            );
        } catch (\Throwable $e) {
        }
    }
}
