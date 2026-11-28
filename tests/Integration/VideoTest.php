<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\UI\GtkVideo;
use Jovian\Toolkits\GTK\Video\MediaFile\MediaFileVideoPlayer;
use Jovian\Toolkits\GTK\Video\VideoDriverUnsupported;
use Jovian\Toolkits\GTK\Video\Gstreamer\GstreamerVideoPlayer;
use Surface\Toolkits\UI\TKVideo;
use Surface\Contracts\Toolkits\Mail\Views\VideoEnded;
use Surface\Contracts\Toolkits\Mail\Views\VideoFailed;
use Surface\Contracts\Toolkits\Mail\Views\VideoPlaying;
use Surface\Contracts\Toolkits\Mail\Views\VideoPaused;

/*
| A video on each driver. 'gtk' (GTK's own media) throws on macOS and the view has no player:
| every source fails and nothing plays (on Linux the driver is made, and has no player yet). 'gstreamer' (jovian/venusian-gstreamer's playbin drawing through
| gtk4paintablesink into a GtkPicture, with a bar of GTK's own icons while it has controls)
| plays. The fixtures are a 1.2 s 160 x 120 H.264 clip and a 1 s AAC tone, played from disk and
| from PHP's built-in server on 127.0.0.1 with byte ranges (fixtures/serve.php).
*/

afterEach(function (): void {
    closeAll();
    toolkitApp()->make('config')->set('gtk.video.driver', 'gtk');
});

/** A window with one video filling its content column, its player from $driver. */
function gtkVideo(string $driver, bool $controls = false): GtkVideo
{
    toolkitApp()->make('config')->set('gtk.video.driver', $driver);
    $window = openWindow('video', 400, 300);
    /** @var GtkVideo $video */
    $video = $window->column('root')->video('player');
    $video->setControls($controls)->fill(true, true);
    pumpFor(0.05);
    takeMail();

    return $video;
}

function gtkFixture(string $name): string
{
    return dirname(__DIR__).'/fixtures/'.$name;
}

/** The fixture's address on a server started once for the process. */
function gtkServed(string $name): string
{
    static $port = null;
    if (is_null($port)) {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $dir = dirname(__DIR__).'/fixtures';
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$dir}/serve.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir);
        register_shutdown_function(static fn () => proc_terminate($server));
        // Until it answers: a refused connection while it starts is expected, not a warning.
        set_error_handler(static fn (): bool => true);
        $until = microtime(true) + 5.0;
        while (! stream_socket_client("tcp://127.0.0.1:{$port}", $code, $text, 0.1) && microtime(true) < $until) {
            usleep(20_000);
        }
        restore_error_handler();
    }

    return "http://127.0.0.1:{$port}/{$name}";
}

/** @param list<object> $mail @return list<class-string> the video mail, in order */
function gtkVideoMail(array $mail): array
{
    return array_values(array_map(fn (object $m): string => $m::class, array_filter($mail, fn (object $m): bool => str_starts_with($m::class, 'Surface\Contracts\Toolkits\Mail\Views\Video'))));
}

/** What the playbin holds now: muted, loop, controls, paused, and the position in seconds. */
function gtkPlayerReads(GtkVideo $video, string $what): mixed
{
    /** @var GstreamerVideoPlayer $player */
    $player = $video->player();
    $gstreamer = $player->gstreamer();

    return match ($what) {
        'muted' => $gstreamer->isMuted(),
        'loop' => $gstreamer->isLooping(),
        'controls' => $player->controls()->getVisible(),
        'paused' => ! $gstreamer->isPlaying(),
        'position' => $gstreamer->position(),
    };
}

function gtkLoaded(GtkVideo $video): void
{
    expect(pumpUntil(fn (): bool => ! is_null($video->duration()), 10.0))->toBeTrue();
}

it('has no player on the gtk driver: every source posts VideoFailed with the reason, and nothing plays', function (): void {
    $video = gtkVideo('gtk');

    $video->setControls(true)->setMuted(true)->setSource('https://example.org/clip.mp4')->play()->seek(1.0);
    $video->setSource('/tmp/clip.mp4');
    $failed = array_values(array_filter(takeMail(), fn (object $m): bool => $m instanceof VideoFailed));

    if (device_os_family() === 'mac') {
        expect($video->player())->toBeNull();
    } else {
        expect($video->player())->toBeInstanceOf(MediaFileVideoPlayer::class);
    }
    expect($video->supported())->toBeFalse()
        ->and($video->isPlaying())->toBeFalse()
        ->and($video->position())->toBe(0.0)
        ->and($video->duration())->toBeNull()
        ->and($failed)->toHaveCount(2)
        ->and([$failed[0]->source, $failed[1]->source])->toBe(['https://example.org/clip.mp4', '/tmp/clip.mp4'])
        ->and($failed[0]->reason)->toBe(TKVideo::NO_PLAYER);
});

it('lays out as an empty area that fills on the gtk driver', function (): void {
    $video = gtkVideo('gtk');
    pumpFor(0.3);

    expect($video->size())->toBe([400, 300]);
});

it('has no player when its driver cannot be made on this system', function (): void {
    driver()->video()->extend('nowhere', fn () => throw new VideoDriverUnsupported('No player here.'));
    $video = gtkVideo('nowhere');
    $video->setSource('https://example.org/clip.mp4');
    $failed = array_values(array_filter(takeMail(), fn (object $m): bool => $m instanceof VideoFailed));
    pumpFor(0.3);

    expect($video->player())->toBeNull()
        ->and($video->supported())->toBeFalse()
        ->and($video->size())->toBe([400, 300])
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->reason)->toBe(TKVideo::NO_PLAYER);
});

it('throws on a driver name the manager does not know', function (): void {
    expect(fn () => gtkVideo('nothing'))->toThrow(InvalidArgumentException::class, 'Driver [nothing] not supported.');
});

it('holds the gstreamer player\'s widget in its own box', function (): void {
    $video = gtkVideo('gstreamer');
    $player = $video->player();

    expect($player)->toBeInstanceOf(GstreamerVideoPlayer::class)
        ->and($video->native())->toBeInstanceOf(GtkBox::class)
        ->and($video->native()->getFirstChild())->toBe($player->widget())
        ->and($video->supported())->toBeTrue()
        ->and($video->position())->toBe(0.0)
        ->and($video->duration())->toBeNull();
});

it('loads a file paused at its start, its picture the sink\'s paintable', function (): void {
    $video = gtkVideo('gstreamer');
    $video->setSource(gtkFixture('clip.mp4'));
    gtkLoaded($video);
    pumpFor(0.5);
    /** @var GstreamerVideoPlayer $player */
    $player = $video->player();
    $paintable = g_object_get_property($player->picture(), 'paintable');

    expect($video->duration())->toEqualWithDelta(1.2, 0.1)
        ->and($video->position())->toBe(0.0)
        ->and($video->isPlaying())->toBeFalse()
        ->and(gtkVideoMail(takeMail()))->toBe([])
        ->and(gtkPlayerReads($video, 'paused'))->toBeTrue()
        ->and($paintable?->typeName())->toBe('GstGtk4Paintable');
});

it('plays to the end: VideoPlaying, then VideoEnded', function (string $source, float $length): void {
    $video = gtkVideo('gstreamer');
    $video->setMuted(true)->setSource(str_starts_with($source, 'http:') ? gtkServed(substr($source, 5)) : gtkFixture($source));
    gtkLoaded($video);
    $video->play();
    $mail = mailUntil('VideoEnded', 10.0);

    expect(gtkVideoMail($mail))->toBe([VideoPlaying::class, VideoEnded::class])
        ->and($video->duration())->toEqualWithDelta($length, 0.1)
        ->and($video->isPlaying())->toBeFalse();
})->with([
    'a file' => ['clip.mp4', 1.2],
    'an audio file' => ['tone.m4a', 1.0],
    'a clip over HTTP' => ['http:clip.mp4', 1.2],
    'audio over HTTP' => ['http:tone.m4a', 1.0],
]);

it('posts VideoPaused on pause, and seeks to the time asked for', function (): void {
    $video = gtkVideo('gstreamer');
    $video->setSource(gtkFixture('tone.m4a'));
    gtkLoaded($video);
    $video->setMuted(true)->play();
    mailUntil('VideoPlaying', 5.0);
    $video->pause();
    $paused = mailUntil('VideoPaused', 5.0);
    $video->seek(0.5);
    pumpUntil(fn (): bool => abs($video->position() - 0.5) < 0.01, 5.0);

    expect(countMail($paused, 'VideoPaused', 'video'))->toBe(1)
        ->and($video->isPlaying())->toBeFalse()
        ->and($video->position())->toEqualWithDelta(0.5, 0.01)
        ->and(gtkPlayerReads($video, 'position'))->toEqualWithDelta(0.5, 0.01)
        ->and($video->isMuted())->toBeTrue();
});

it('hands muting, looping and controls to the player, set before the source and after it', function (): void {
    $video = gtkVideo('gstreamer');
    $video->setMuted(true)->setLoop(true)->setControls(true);
    $video->setSource(gtkFixture('clip.mp4'));
    gtkLoaded($video);
    $before = [gtkPlayerReads($video, 'muted'), gtkPlayerReads($video, 'loop'), gtkPlayerReads($video, 'controls')];
    $video->setMuted(false)->setLoop(false)->setControls(false);
    pumpFor(0.1);
    $after = [gtkPlayerReads($video, 'muted'), gtkPlayerReads($video, 'loop'), gtkPlayerReads($video, 'controls')];

    expect($before)->toBe([true, true, true])
        ->and($after)->toBe([false, false, false]);
});

it('loops with no VideoEnded', function (): void {
    $video = gtkVideo('gstreamer');
    $video->setMuted(true)->setLoop(true)->setSource(gtkFixture('clip.mp4'));
    gtkLoaded($video);
    $video->play();
    $mail = mailUntil('VideoEnded', 3.0);

    expect(gtkVideoMail($mail))->toBe([VideoPlaying::class])
        ->and($video->isPlaying())->toBeTrue();
});

it('posts VideoFailed with a reason', function (Closure $source): void {
    $video = gtkVideo('gstreamer');
    $video->setSource($source());
    $failed = array_values(array_filter(mailUntil('VideoFailed', 10.0), fn (object $m): bool => $m instanceof VideoFailed));
    pumpFor(0.5);
    $more = array_filter(takeMail(), fn (object $m): bool => $m instanceof VideoFailed);

    expect($failed)->toHaveCount(1)
        ->and($more)->toBe([])
        ->and($failed[0]->reason)->toBeString()->not->toBe('');
})->with([
    'a file that is not there' => fn () => '/nonexistent/gtk-video-test.mp4',
    'a file that is not media' => fn () => gtkFixture('serve.php'),
    'a server that refuses' => fn () => 'http://127.0.0.1:9/clip.mp4',
    'a page that is not there' => fn () => gtkServed('nothing.mp4'),
]);

it('posts nothing for a source it moved on from', function (): void {
    $video = gtkVideo('gstreamer');
    $video->setMuted(true)->setSource(gtkFixture('clip.mp4'));
    gtkLoaded($video);
    $video->play();
    mailUntil('VideoPlaying', 5.0);
    $video->setSource(gtkFixture('tone.m4a'));
    pumpFor(2.0);

    expect(gtkVideoMail(takeMail()))->toBe([])
        ->and($video->duration())->toEqualWithDelta(1.0, 0.1);
});

it('posts nothing from a removed video', function (): void {
    $video = gtkVideo('gstreamer');
    $video->setMuted(true)->setSource(gtkFixture('clip.mp4'));
    $video->play();
    $video->remove();
    pumpFor(1.5);

    expect(gtkVideoMail(takeMail()))->toBe([]);
});

it('shows the control bar only while it has controls, and its buttons play and mute', function (): void {
    $video = gtkVideo('gstreamer');
    /** @var GstreamerVideoPlayer $player */
    $player = $video->player();
    $bar = $player->controls();
    $hidden = $bar->getVisible();
    $video->setControls(true);
    pumpFor(0.05);
    $shown = $bar->getVisible();
    $video->setSource(gtkFixture('tone.m4a'));
    gtkLoaded($video);

    [$toggle, $sound] = (fn (): array => [$this->toggle->getParent(), $this->sound->getParent()])->call($player);
    g_signal_emit_by_name($sound, 'clicked');
    g_signal_emit_by_name($toggle, 'clicked');
    $mail = mailUntil('VideoPlaying', 5.0);

    expect([$hidden, $shown])->toBe([false, true])
        ->and($video->isMuted())->toBeTrue()
        ->and(countMail($mail, 'VideoPlaying', 'video'))->toBe(1);
});

it('takes the size the layout gives it, not the media\'s, and fits the picture inside', function (): void {
    toolkitApp()->make('config')->set('gtk.video.driver', 'gstreamer');
    $window = openWindow('video', 400, 300);
    /** @var GtkVideo $video */
    $video = $window->column('root')->video('player');
    $video->fill(true, false)->minSize(0, 120);
    pumpFor(0.05);
    $before = $video->size();
    // The clip is 160 x 120: at the column's 400 points wide its picture would be 300 high.
    $video->setSource(gtkFixture('clip.mp4'));
    gtkLoaded($video);
    pumpFor(0.3);

    expect($before)->toBe([400, 120])
        ->and($video->size())->toBe([400, 120]);
});

it('seeks where its slider is dragged, while it plays', function (): void {
    $video = gtkVideo('gstreamer', true);
    $video->setMuted(true)->setSource(gtkFixture('clip.mp4'));
    gtkLoaded($video);
    $video->play();
    mailUntil('VideoPlaying', 5.0);
    /** @var GstreamerVideoPlayer $player */
    $player = $video->player();
    $scrub = (fn (): GtkScale => $this->scrub)->call($player);
    $seen = [];
    foreach ([0.3, 0.5, 0.7, 0.9] as $seconds) {
        g_signal_emit_by_name($scrub, 'change-value', 1, $seconds);
        pumpFor(0.12);
        $seen[] = $scrub->getValue();
    }

    expect(min($seen))->toBeGreaterThanOrEqual(0.3)
        ->and($seen[3])->toBeGreaterThanOrEqual(0.9)
        ->and(gtkVideoMail(takeMail()))->not->toContain(VideoPaused::class);
});

it('keeps to the frame it is given when it does not fill: the player\'s own expand stays inside', function (): void {
    toolkitApp()->make('config')->set('gtk.video.driver', 'gstreamer');
    $window = openWindow('video', 400, 300);
    /** @var GtkVideo $video */
    $video = $window->column('root')->video('player');
    $video->minSize(200, 120)->maxSize(200, 120)->setControls(true);
    $video->setSource(gtkFixture('clip.mp4'));
    gtkLoaded($video);
    pumpFor(0.3);

    expect($video->size())->toBe([200, 120]);
});

it('takes the room up to its maximum when it fills, from its start, and lets go when cleared', function (): void {
    toolkitApp()->make('config')->set('gtk.video.driver', 'gstreamer');
    $window = openWindow('video', 400, 300);
    /** @var GtkVideo $video */
    $video = $window->column('root')->video('player');
    $video->fill(true, false)->minSize(0, 120)->maxSize(250, 0)->setControls(true);
    pumpFor(0.3);
    $capped = $video->size();
    $video->maxSize(0, 0);
    pumpFor(0.3);

    expect($capped)->toBe([250, 120])
        ->and($video->size())->toBe([400, 120]);
});

it('pauses when it stops being shown, as a container above it hides', function (): void {
    toolkitApp()->make('config')->set('gtk.video.driver', 'gstreamer');
    $window = openWindow('video', 400, 300);
    $drawer = $window->column('root')->column('drawer')->fill();
    /** @var GtkVideo $video */
    $video = $drawer->video('player')->fill();
    $video->setMuted(true)->setLoop(true)->setSource(gtkFixture('clip.mp4'));
    gtkLoaded($video);
    $video->play();
    mailUntil('VideoPlaying', 5.0);
    $drawer->hide();
    $mail = mailUntil('VideoPaused', 3.0);

    expect(gtkVideoMail($mail))->toBe([VideoPaused::class])
        ->and($video->isPlaying())->toBeFalse()
        ->and(gtkPlayerReads($video, 'paused'))->toBeTrue();
});
