<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

final class RunSummary
{
    public function __construct(
        public readonly string $runId,
        public readonly bool $ok,
        public readonly string $message,
        /** Notification detail key-values; the 'site' value must follow the SiteLabel::resolve() contract: host of the primary site's base URL, display name only as fallback. */
        public readonly array $details = [],
    ) {
    }

    /** Site label for display: the 'site' detail when non-empty, otherwise 'unknown'. */
    public function site(): string
    {
        $site = (string)($this->details['site'] ?? '');
        return $site !== '' ? $site : 'unknown';
    }
}
