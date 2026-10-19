<?php
namespace RevisionHistory\Capture;

/**
 * Where a request came from, as an address.
 *
 * Behind a reverse proxy (Cloudflare, or a proxy on the same private network) the web
 * server only sees the proxy, so REMOTE_ADDR is a private address. The visitor's own
 * address then arrives in CF-Connecting-IP or X-Forwarded-For, and those headers are
 * believed only when the request reached the server from a private address — that is,
 * through such a proxy. A request from a public address could have written the headers
 * itself, so its own address is kept. See README, "Which address is recorded".
 */
class Where
{
    public static function ip(): string
    {
        if (PHP_SAPI === 'cli') {
            return '';
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $said = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        $public = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($said !== '' && $remote !== '' && $public === false && filter_var($said, FILTER_VALIDATE_IP)) {
            return mb_substr($said, 0, 45);
        }
        /* A request that reached the proxy without passing Cloudflare carries no
           CF-Connecting-IP; the proxy says who it was talking to in X-Forwarded-For, and
           that is believed on the same terms: only when the request came from a private
           address. */
        if ($remote !== '' && $public === false) {
            foreach (explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) as $one) {
                $one = trim($one);
                if ($one !== '' && filter_var($one, FILTER_VALIDATE_IP)) {
                    return mb_substr($one, 0, 45);
                }
            }
        }
        return mb_substr($remote, 0, 45);
    }
}
