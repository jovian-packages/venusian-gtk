<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Video\GtkVideoDriver;
use Jovian\Toolkits\GTK\Video\GtkVideoPlayer;
use Jovian\Toolkits\GTK\Video\GtkVideoListener;
use Jovian\Toolkits\GTK\Video\GtkVideoManager;
use Jovian\Toolkits\GTK\Video\MediaFile\MediaFileVideoDriver;
use Jovian\Toolkits\GTK\Video\MediaFile\MediaFileVideoPlayer;
use Jovian\Toolkits\GTK\Video\Gstreamer\GstreamerVideoDriver;
use Jovian\Toolkits\GTK\Video\Gstreamer\GstreamerVideoPlayer;
use Voyager\Config\Repository;
use Voyager\Core\RenderedInstance;

/*
| The video manager: which driver a video's player comes from.
*/

/** A manager on a core whose gtk.video.driver is $driver. */
function gtkVideoManager(string $driver): GtkVideoManager
{
    $app = new RenderedInstance(sys_get_temp_dir());
    $app->registerInstance('config', new Repository(['gtk' => ['video' => ['driver' => $driver, 'position_interval' => 100]]]));
    $manager = new GtkVideoManager($app);
    toolkitApp();

    return $manager;
}

function gtkSilentListener(): GtkVideoListener
{
    return new class implements GtkVideoListener {
        public function videoPlaying(): void {}

        public function videoPaused(): void {}

        public function videoEnded(): void {}

        public function videoFailed(?string $reason): void {}

        public function videoMuted(bool $muted): void {}
    };
}

it('makes the driver gtk.video.driver names, once, and one manager for the toolkit', function (): void {
    $gtk = gtkVideoManager('gtk');
    $gstreamer = gtkVideoManager('gstreamer');

    expect($gtk->driver())->toBeInstanceOf(MediaFileVideoDriver::class)
        ->and($gtk->driver())->toBe($gtk->driver('gtk'))
        ->and($gstreamer->driver())->toBeInstanceOf(GstreamerVideoDriver::class)
        ->and(driver()->video())->toBe(driver()->video());
});

it('takes the default from gtk.video.driver', function (): void {
    $manager = gtkVideoManager('elsewhere');
    $driver = new class implements GtkVideoDriver {
        public function player(GtkVideoListener $listener): GtkVideoPlayer
        {
            throw new LogicException('not used');
        }

        public function supported(): bool
        {
            return true;
        }
    };
    $manager->extend('elsewhere', fn () => $driver);

    expect($manager->driver())->toBe($driver)
        ->and($manager->driver()->supported())->toBeTrue();
});

it('refuses a driver it does not know', function (): void {
    expect(fn () => gtkVideoManager('nothing')->driver())->toThrow(InvalidArgumentException::class, 'Driver [nothing] not supported.');
});

it('gives each built-in driver its player, and says whether it plays', function (): void {
    gtk_init();
    $gtk = gtkVideoManager('gtk')->driver();
    $gstreamer = gtkVideoManager('gstreamer')->driver();
    $none = $gtk->player(gtkSilentListener());
    $media = $gstreamer->player(gtkSilentListener());

    expect($none)->toBeInstanceOf(MediaFileVideoPlayer::class)
        ->and($none->widget())->toBeInstanceOf(GtkBox::class)
        ->and($gtk->supported())->toBeFalse()
        ->and($media)->toBeInstanceOf(GstreamerVideoPlayer::class)
        ->and($media->picture())->toBeInstanceOf(GtkPicture::class)
        ->and($media->controls()->getVisible())->toBeFalse()
        ->and($gstreamer->supported())->toBeTrue();
    $none->release();
    $media->release();
});

it('refuses the gstreamer driver without jovian/venusian-gstreamer, naming the install', function (): void {
    // Another PHP, whose autoloader no longer finds jovian/venusian-gstreamer.
    $code = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';'.<<<'PHP'
        foreach (spl_autoload_functions() as $loader) {
            if (is_array($loader) && $loader[0] instanceof Composer\Autoload\ClassLoader) {
                $loader[0]->setPsr4('Jovian\\Media\\Gstreamer\\', []);
            }
        }
        try {
            new Jovian\Toolkits\GTK\Video\Gstreamer\GstreamerVideoDriver(100);
            echo 'made';
        } catch (RuntimeException $e) {
            echo $e->getMessage();
        }
        PHP;
    $said = shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));

    expect($said)->toBe("The 'gstreamer' video driver needs jovian/venusian-gstreamer. Install it with 'composer require jovian/venusian-gstreamer'");
});
