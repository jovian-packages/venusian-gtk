<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Input\GTKInputEngine;
use Jovian\Toolkits\GTK\Providers\VenusianGTKServiceProvider;
use Surface\Bridge\ToolkitManager;
use Surface\Contracts\HumanInput\Key;
use Surface\HumanInput\HumanInputManager;

afterEach(function (): void {
    driver()->closeAll();
    pumpUntil(fn (): bool => driver()->all() === []);
});

it('registers the gtk input engine: HumanInput follows the GTK session and reads its keys', function (): void {
    $toolkits = new class extends ToolkitManager {
        public function __construct()
        {
            $this->drivers = ['gtk' => driver()];
        }

        public function getDefaultDriver(): string
        {
            return 'gtk';
        }
    };
    $input = new HumanInputManager($toolkits, inputFrame(), device_os_family(), null, fn (object $mail) => null);
    VenusianGTKServiceProvider::input($input, $toolkits);
    session();
    driver()->open('provided', 200, 100);

    try {
        $input->poll();
        $engine = $input->engines()['gtk'];
        g_signal_emit_by_name(controllerOf($engine, 'provided', GtkEventControllerKey::class), 'key-pressed', 0x71, keycodeOf('q'), 0);

        expect($engine)->toBeInstanceOf(GTKInputEngine::class)
            ->and(array_keys($input->engines()))->toBe(['gtk'])
            ->and($input->keyboard()->isDown(Key::Q))->toBeTrue();
    } finally {
        $input->destroy();
    }
});
