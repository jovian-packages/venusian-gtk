<?php

declare(strict_types=1);

/** Run a command while this process serves GDK's clipboard; its standard output. */
function readWhilePumping(string $command): string
{
    $proc = proc_open($command, [1 => ['pipe', 'w']], $pipes);
    stream_set_blocking($pipes[1], false);
    $out = '';
    $end = microtime(true) + 2.0;
    while (proc_get_status($proc)['running'] && microtime(true) < $end) {
        pumpFor(0.01);
        $out .= stream_get_contents($pipes[1]);
    }
    $out .= stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($proc);

    return $out;
}

it('puts text on the clipboard that pbpaste reads', function (): void {
    // The test writes the user's clipboard: it keeps the text there and puts it back.
    $kept = (string) shell_exec('pbpaste');
    driver()->clipboard()->setText('c7 path');
    $read = readWhilePumping('pbpaste');
    driver()->clipboard()->setText($kept);
    readWhilePumping('pbpaste');

    expect($read)->toBe('c7 path');
})->skip(PHP_OS_FAMILY !== 'Darwin', 'the clipboard is read back on the Mac only');

it('sets the clipboard first thing in a process, before any window', function (): void {
    // A fresh process: GTK starts only when the session connects, so the accessor must connect it.
    // The child sets the text already on the user's clipboard, so the clipboard keeps it.
    $kept = (string) shell_exec('pbpaste');
    $code = 'require getcwd() . "/vendor/autoload.php"; require getcwd() . "/tests/Pest.php";'
        . ' driver()->clipboard()->setText(' . var_export($kept, true) . '); driver()->desktop(); echo "ok";';
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=128M -r ' . escapeshellarg($code) . ' 2>&1');

    expect($out)->toEndWith('ok');
})->skip(PHP_OS_FAMILY !== 'Darwin', 'the user\'s clipboard is read back, to keep it, on the Mac only');

it('refuses to open a missing path', function (string $path): void {
    expect(fn () => driver()->desktop()->open($path))->toThrow(InvalidArgumentException::class, $path);
})->with(['/nonexistent-c7', 'nonexistent-c7']);
