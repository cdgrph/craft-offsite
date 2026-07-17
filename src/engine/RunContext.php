<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

final class RunContext
{
    public function __construct(
        public readonly string $runId,
        public readonly string $startedAt,
        public readonly string $siteUid,
        public readonly string $craftVersion,
        public readonly string $schemaVersion,
        public readonly string $pluginVersion,
        public readonly string $dbDriver,
        public readonly string $workDir,
        /** Host of the primary site's base URL, falling back to the site display name — see SiteLabel::resolve(). */
        public readonly string $siteName = '',
        public readonly string $objectKeyPrefix = 'db/',
        public readonly bool $notifyOnSuccess = false,
    ) {
    }
}
