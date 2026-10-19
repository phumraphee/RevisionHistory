<?php
namespace RevisionHistory\Job;

use Omeka\Job\AbstractJob;
use RevisionHistory\Copy\Copier;

class CopyJob extends AbstractJob
{
    public function perform()
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $copier = new Copier(
            $services->get('Omeka\Connection'),
            $services->get('Omeka\Settings'),
            function ($msg) use ($logger) {
                $logger->info($msg);
            }
        );
        $copier->run();
    }
}
