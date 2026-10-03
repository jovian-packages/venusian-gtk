<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Primitives\GTKVideo;
use Surface\Contracts\Windows\Mail\View\VideoEnded;
use Surface\Contracts\Windows\Mail\View\VideoFailed;
use Surface\Contracts\Windows\Mail\View\VideoPaused;
use Surface\Contracts\Windows\Mail\View\VideoPlaying;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** Pump until mail of $class arrives (or $seconds pass), and return everything collected. */
function collectUntil(string $class, float $seconds): array
{
    $mail = [];
    pumpUntil(function () use (&$mail, $class): bool {
        $mail = [...$mail, ...viewMail(session())];

        return array_filter($mail, fn (object $each): bool => $each instanceof $class) !== [];
    }, $seconds);

    return $mail;
}

it('plays a clip to the end and reports a missing file', function (): void {
    $window = driver()->open('main', 200, 200);
    $video = $window->column('m')->video('v', __DIR__.'/../fixtures/clip.mp4');
    $video->fill();
    $window->present();
    $video->play();
    // Mid-play the position advances; after the end GStreamer's stream reports 0 again.
    $advanced = pumpUntil(fn (): bool => $video->position() > 0.3, 3.0);
    $mail = collectUntil(VideoEnded::class, 6.0);

    expect($video)->toBeInstanceOf(GTKVideo::class)
        ->and($video->native())->toBeInstanceOf(\GtkVideo::class)
        ->and($video->native()->getMediaStream())->toBe($video->stream())
        ->and(array_map(fn (object $each): string => $each::class, $mail))->toContain(VideoPlaying::class, VideoPaused::class, VideoEnded::class)
        ->and($video->isPlaying())->toBeFalse()
        ->and($advanced)->toBeTrue()
        ->and($video->duration())->toBeGreaterThan(0.9);

    $video->setMuted(true)->setLoop(true)->seek(0.5);
    expect($video->stream()->getMuted())->toBeTrue()
        ->and($video->stream()->getLoop())->toBeTrue();

    $video->setFile('/nope/clip.mp4');
    $video->play();
    $failed = array_values(array_filter(collectUntil(VideoFailed::class, 5.0), fn (object $each): bool => $each instanceof VideoFailed));
    expect($failed)->not->toBe([])
        ->and($failed[0]->reason)->not->toBe('')
        ->and($video->isPlaying())->toBeFalse()
        ->and($video->stream()->getMuted())->toBeTrue();

    $video->setFile(null);
    expect($video->native()->getMediaStream())->toBeNull()
        ->and($video->duration())->toBeNull()
        ->and($video->position())->toBe(0.0);
})->skip(function (): bool {
    session();

    return ! gtk_media_backend_available();
}, 'no GTK media backend on this machine');

it('refuses a video where GTK has no media backend', function (): void {
    $column = driver()->open('main', 200, 200)->column('m');

    expect(fn () => $column->video('v'))->toThrow(WindowException::class, 'libgtk-4-media-gstreamer')
        ->and($column->view('v'))->toBeNull();
})->skip(function (): bool {
    session();

    return gtk_media_backend_available();
}, 'this machine has a GTK media backend');
