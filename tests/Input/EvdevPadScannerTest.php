<?php

use Jovian\Venusian\GTK\Input\EvdevPadScanner;
use Microscrap\Bindings\Evdev\Enums\KeyCode;
use Microscrap\Bindings\Evdev\EvdevDevice;
use Microscrap\ScrapyardEvdev\EvdevGamepad;
use Microscrap\ScrapyardEvdev\EvdevScanner;
use Microscrap\ScrapyardEvdev\Tests\Support\ScriptedEvdevDevice;
use Surface\Contracts\HumanInput\GamepadAxis;
use Venusian\Surface\Tests\Support\Fakes\FakeControllerPad;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/gtk-evdev-'.uniqid();
    mkdir($this->dir);

    foreach (['event1', 'event2', 'event5'] as $node) {
        touch("{$this->dir}/{$node}");
    }

    /** @var array<string, ScriptedEvdevDevice> $this->devices */
    $this->devices = [];
    $this->scanner = new EvdevPadScanner(new EvdevScanner($this->dir, function (string $path): ?EvdevDevice {
        $device = match (basename($path)) {
            'event2' => new ScriptedEvdevDevice(keys: [KeyCode::BTN_SOUTH], name: 'Pad', path: $path),
            'event5' => new ScriptedEvdevDevice(path: $path),
            default => new ScriptedEvdevDevice(name: 'Keyboard', path: $path),
        };

        return $this->devices[] = $device;
    }));
});

afterEach(function (): void {
    array_map('unlink', glob("{$this->dir}/*"));
    rmdir($this->dir);
});

it('lists evdev gamepad nodes', function (): void {
    expect($this->scanner->gamepads())->toBe(["{$this->dir}/event2"]);
});

it('opens a node as an evdev gamepad and closes it', function (): void {
    $pad = $this->scanner->open("{$this->dir}/event2");

    expect($pad)->toBeInstanceOf(EvdevGamepad::class)
        ->and($pad->name())->toBe('Pad');

    $this->scanner->close($pad);

    expect($pad->connected())->toBeFalse()
        ->and(end($this->devices)->closed)->toBeTrue();
});

it('treats a node that answers no probe as no pad', function (): void {
    expect($this->scanner->open("{$this->dir}/event5"))->toBeNull()
        ->and(end($this->devices)->closed)->toBeTrue();
});

it('leaves a circuit it did not open alone', function (): void {
    $pad = new FakeControllerPad([], [GamepadAxis::LEFT_X]);

    $this->scanner->close($pad);

    expect($pad->connected)->toBeTrue();
});
