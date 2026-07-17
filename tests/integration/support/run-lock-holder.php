<?php
declare(strict_types=1);

use cdgrph\offsite\engine\RunLock;
use cdgrph\offsite\engine\SystemClock;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[$script, $guardPath, $statePath, $mode, $seconds] = $argv;
$lock = new RunLock($guardPath, $statePath, new SystemClock());
$lock->acquire('holder:' . getmypid());

if ($mode === 'child') {
    $child = proc_open(['/bin/sleep', $seconds], [], $pipes);
    if (!is_resource($child)) {
        throw new RuntimeException('Cannot start child process.');
    }
    $status = proc_get_status($child);
    echo 'READY ' . getmypid() . ' ' . $status['pid'] . "\n";
} else {
    echo 'READY ' . getmypid() . "\n";
}
flush();

if ($mode === 'brief') {
    usleep((int)$seconds * 1000);
    $lock->release('holder:' . getmypid());
    exit(0);
}

while (true) {
    sleep(1);
}
