<?php
namespace RevisionHistory\Job;

use Omeka\Job\AbstractJob;
use RevisionHistory\Watch\Watchman;

class WatchJob extends AbstractJob
{
    public function perform()
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        (new Watchman(
            $services->get('Omeka\Connection'),
            $services->get('Omeka\Settings'),
            function ($msg) use ($logger) {
                $logger->info($msg);
            }
        ))->run();
    }
}
