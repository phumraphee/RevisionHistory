<?php
namespace RevisionHistory\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use RevisionHistory\View\Helper\RevisionHistoryShow;

class RevisionHistoryShowFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new RevisionHistoryShow(
            $services->get('Omeka\Connection'),
            (string) ($services->get('Omeka\Settings')->get('time_zone') ?: 'UTC'),
            function (string $path) use ($services) {
                return self::menuLabel($services, $path);
            },
            (string) $services->get('Omeka\Settings')->get('revision_history_title_term', 'dcterms:title'),
            (string) $services->get('Omeka\Settings')->get('revision_history_accession_term', 'dcterms:identifier')
        );
    }

    /**
     * The name a module gives its own admin page, read from that module's own
     * navigation config — never guessed from the address.
     *
     * The saved address is matched against Omeka's router. The page belongs to a
     * module's menu entry when the entry's route is the matched route (or a parent
     * of it), or when the entry's controller is in the same module as the page's.
     * No match: null, and the page shows the address.
     */
    public static function menuLabel($services, string $path): ?string
    {
        // A module's name (for its background jobs): the label of its own menu entry.
        if ($path !== '' && $path[0] !== '/') {
            return self::walk($services, '', $path, false);
        }
        try {
            $request = new \Laminas\Http\Request();
            $request->setUri($path);
            $match = $services->get('Router')->match($request);
            if (!$match) {
                return null;
            }
            $route = (string) $match->getMatchedRouteName();
            $controller = (string) $match->getParam('controller');
            if ($controller !== '' && strpos($controller, '\\') === false && $match->getParam('__NAMESPACE__')) {
                $controller = $match->getParam('__NAMESPACE__') . '\\' . ucfirst($controller);
            }
            $module = strstr($controller, '\\', true) ?: '';
            return self::walk($services, $route, $module, true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The menus of Omeka's admin: the Modules section first, then the others, where a
     * module may also put its page (Batch Values is under RESOURCES). In those others
     * only a module's own entries count, never Omeka's own Items or Sites.
     */
    private static function walk($services, string $route, string $module, bool $byRoute): ?string
    {
        try {
            $nav = $services->get('Config')['navigation'] ?? [];
            $sections = ['AdminModule', 'AdminResource', 'AdminSite', 'AdminGlobal'];
            foreach ($sections as $section) {
                $found = self::walkSection($nav[$section] ?? [], $route, $module, $byRoute, $section !== 'AdminModule');
                if ($found !== null) {
                    return $found;
                }
            }
            // Nothing a module put there answers to this address. Omeka's own pages do:
            // its menu says "Sites", "Users", "Vocabularies" — its words, not ours, for
            // the part of the admin this save was made in.
            foreach ($sections as $section) {
                $found = self::walkSection($nav[$section] ?? [], $route, $module, $byRoute, false);
                if ($found !== null) {
                    return $found;
                }
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function walkSection(array $pages, string $route, string $module, bool $byRoute, bool $modulesOnly): ?string
    {
        $byModule = null;
        $walk = function (array $pages) use (&$walk, $route, $module, $byRoute, $modulesOnly, &$byModule) {
            foreach ($pages as $page) {
                if (!is_array($page) || empty($page['label'])) {
                    continue;
                }
                $res = (string) ($page['resource'] ?? '');
                $isModule = $res !== '' && strpos($res, 'Omeka\\') !== 0;
                if ($modulesOnly && !$isModule) {
                    continue;
                }
                $r = (string) ($page['route'] ?? '');
                if ($byRoute && $r !== '' && ($r === $route || strpos($route, $r . '/') === 0)) {
                    return (string) $page['label'];
                }
                if ($byModule === null && $module !== '' && $module !== 'Omeka' && strpos($res, $module . '\\') === 0) {
                    $byModule = (string) $page['label'];
                }
                if (!empty($page['pages']) && is_array($page['pages'])) {
                    $found = $walk($page['pages']);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
            return null;
        };
        return $walk($pages) ?? $byModule;
    }
}
