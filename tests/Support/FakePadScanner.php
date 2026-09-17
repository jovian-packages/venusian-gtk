<?php

namespace Venusian\GTK\Tests\Support;

use Jovian\Venusian\GTK\Input\PadScanner;
use RuntimeException;
use Surface\Contracts\HumanInput\Circuits\GameController;

/** Nodes and the pads behind them are set by the test; counts scans, opens and closes. */
final class FakePadScanner implements PadScanner
{
    public int $scans = 0;

    /** @var list<string> */
    public array $opened = [];

    /** @var list<GameController> */
    public array $closed = [];

    /** @var list<string> node paths whose open throws */
    public array $refuse = [];

    public bool $fail_scan = false;

    /** @param array<string, GameController> $pads node path → circuit; the keys are what gamepads() lists */
    public function __construct(public array $pads = []) {}

    public function gamepads(): array
    {
        $this->scans++;

        if ($this->fail_scan) {
            throw new RuntimeException('scan failed');
        }

        return array_keys($this->pads);
    }

    public function open(string $path): ?GameController
    {
        $this->opened[] = $path;

        if (in_array($path, $this->refuse, true)) {
            throw new RuntimeException("cannot open {$path}");
        }

        return $this->pads[$path] ?? null;
    }

    public function close(GameController $pad): void
    {
        $this->closed[] = $pad;
    }
}
