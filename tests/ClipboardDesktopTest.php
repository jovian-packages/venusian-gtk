<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\Services\ClipboardChanged;
use Surface\Contracts\Windows\Mail\Services\ClipboardText;

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

/** The clipboard mail the session holds after $seconds of pumping, in order. */
function clipboardMail(float $seconds = 0.3): array
{
    pumpFor($seconds);

    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof ClipboardText || $mail instanceof ClipboardChanged));
}

describe('the clipboard\'s text and changes', function (): void {
    // The tests write the user's clipboard: they keep the text there and put it back. Pest runs a
    // skipped describe's hooks too, so they check the platform themselves.
    beforeEach(function (): void {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return;
        }
        $this->kept = (string) shell_exec('pbpaste');
        clipboardMail(0.05);
    });

    afterEach(function (): void {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return;
        }
        driver()->clipboard()->setText($this->kept);
        readWhilePumping('pbpaste');
    });

    it('answers two text requests made before a pump, each once', function (): void {
        driver()->clipboard()->setText('c14 round');
        clipboardMail();
        $first = driver()->clipboard()->requestText();
        $second = driver()->clipboard()->requestText();
        $mail = clipboardMail();

        expect($mail)->toHaveCount(2)
            ->and($mail)->toContainEqual(new ClipboardText($first, 'c14 round'))
            ->and($mail)->toContainEqual(new ClipboardText($second, 'c14 round'))
            ->and(driver()->clipboard()->hasText())->toBeTrue();
    });

    it('posts ClipboardChanged once for its own text', function (): void {
        driver()->clipboard()->setText('c14 changed');

        expect(clipboardMail())->toEqual([new ClipboardChanged()]);
    });
})->skip(PHP_OS_FAMILY !== 'Darwin', 'the user\'s clipboard is read back, to keep it, on the Mac only');

it('posts ClipboardChanged when another X11 client copies', function (): void {
    // The test writes the user's clipboard: it keeps the text there and puts it back. The read
    // pumps: when this process owns the clipboard, xclip asks it for the text.
    $kept = readWhilePumping('xclip -selection clipboard -o 2>/dev/null');
    clipboardMail(0.05);
    shell_exec('printf c14-other | xclip -selection clipboard >/dev/null 2>&1');
    $mail = clipboardMail(1.0);
    shell_exec('printf %s ' . escapeshellarg($kept) . ' | xclip -selection clipboard >/dev/null 2>&1');

    // GDK's X11 backend emits changed when the owner changes and again when its formats arrive:
    // latest-wins folds the two only when they land in one pump.
    expect($mail)->not->toBeEmpty()
        ->each->toBeInstanceOf(ClipboardChanged::class);
})->skip(PHP_OS_FAMILY !== 'Linux' || is_null(shell_exec('command -v xclip')), 'X11 with xclip only: GDK on macOS notices another app\'s copy when the app becomes active (checked by eye)');

it('refuses to open a missing path', function (string $path): void {
    expect(fn () => driver()->desktop()->open($path))->toThrow(InvalidArgumentException::class, $path);
})->with(['/nonexistent-c7', 'nonexistent-c7']);
