<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

interface Notifier
{
    public function name(): string;

    /** Throws on delivery failure; the runner records it as a side-effect status. */
    public function notify(RunSummary $summary): void;
}
