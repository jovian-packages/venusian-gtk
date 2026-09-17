<?php

use Jovian\Venusian\GTK\Input\GTKInputEngine;
use Jovian\Venusian\GTK\Input\NativeGtkWindowSpace;
use Venusian\GTK\Tests\Support\FakeApp;
use Venusian\GTK\Tests\Support\FakeControllerFactory;
use Venusian\GTK\Tests\Support\FakeGtkWindowSpace;
use Venusian\GTK\Tests\Support\FakeLog;
use Venusian\GTK\Tests\Support\FakePadScanner;

require_once dirname(__DIR__).'/Support/app.php';

beforeEach(function () {
    if ((new ReflectionFunction('app'))->getFileName() !== realpath(dirname(__DIR__).'/Support/app.php')) {
        $this->markTestSkipped('a real app() is loaded; these tests drive the stand-in');
    }
});

afterEach(function () {
    unset($GLOBALS['venusian_gtk_app']);
});

function brokenScanEngine(): GTKInputEngine
{
    $scanner = new FakePadScanner;
    $scanner->fail_scan = true;

    return new GTKInputEngine(new FakeControllerFactory, new FakeGtkWindowSpace, $scanner);
}

it('logs each recorded message as a warning when the container binds log', function () {
    $log = new FakeLog;
    $GLOBALS['venusian_gtk_app'] = new FakeApp(['log' => fn () => $log]);

    $engine = brokenScanEngine()->connect();

    expect($log->warnings)->toBe(['input.gtk: gamepad scan failed: scan failed'])
        ->and($engine->errors())->toBe($log->warnings);
});

it('keeps recording when the log throws', function () {
    $GLOBALS['venusian_gtk_app'] = new FakeApp(['log' => fn () => new FakeLog(broken: true)]);

    error_clear_last();
    $engine = brokenScanEngine()->connect();

    expect(error_get_last())->toBeNull()
        ->and($engine->errors())->toHaveCount(1);
});

it('records without logging when no log is bound', function () {
    $GLOBALS['venusian_gtk_app'] = new FakeApp;

    expect(brokenScanEngine()->connect()->errors())->toHaveCount(1);
});

it('answers no windows when native-window is not bound', function () {
    $GLOBALS['venusian_gtk_app'] = new FakeApp;

    expect((new NativeGtkWindowSpace)->windows())->toBe([]);
});

it('answers only gtk delegates from the native-window driver', function () {
    $driver = new class {
        /** @return list<object> */
        public function all(): array
        {
            return [new stdClass];
        }
    };
    $manager = new class($driver) {
        public function __construct(private readonly object $driver) {}

        public function driver(): object
        {
            return $this->driver;
        }
    };
    $GLOBALS['venusian_gtk_app'] = new FakeApp(['native-window' => fn () => $manager]);

    expect((new NativeGtkWindowSpace)->windows())->toBe([]);
});
