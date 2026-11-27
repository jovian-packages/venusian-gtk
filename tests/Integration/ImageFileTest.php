<?php

declare(strict_types=1);

use Surface\Toolkits\ToolkitException;

/*
| A picture file is decoded once, by the picture that shows it. One GTK reads no picture in
| is refused, and the picture shown before stays.
*/

/** A $width x $height PNG on disk. */
function pngFile(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    $path = tempnam(sys_get_temp_dir(), 'gtk-image-').'.png';
    imagepng($image, $path);

    return $path;
}

/** The size of the texture the picture shows, or null for none. */
function shownSize(object $image): ?array
{
    $paintable = (fn () => $this->picture)->call($image)->getPaintable();

    return $paintable instanceof GdkTexture ? [$paintable->getWidth(), $paintable->getHeight()] : null;
}

afterEach(fn () => closeAll());

it('shows a picture file, and keeps the one before when a file holds no picture', function (): void {
    $window = openWindow('main', 300, 200);
    $first = pngFile(40, 30);
    $image = $window->column('root')->image('picture', $first);
    pumpFor(0.2);
    expect(shownSize($image))->toBe([40, 30]);

    $image->setFile($second = pngFile(20, 10));
    expect(shownSize($image))->toBe([20, 10]);

    $text = tempnam(sys_get_temp_dir(), 'gtk-image-').'.png';
    file_put_contents($text, 'not a picture');

    expect(fn () => $image->setFile($text))->toThrow(ToolkitException::class, "'{$text}' is not an image GTK reads.")
        ->and(shownSize($image))->toBe([20, 10])
        ->and($image->file())->toBe($second);

    $image->setFile(null);
    expect(shownSize($image))->toBeNull();
});

it('refuses a file that holds no picture when the image is built', function (): void {
    $window = openWindow('main', 300, 200);
    $text = tempnam(sys_get_temp_dir(), 'gtk-image-').'.png';
    file_put_contents($text, 'not a picture');

    expect(fn () => $window->column('root')->image('picture', $text))->toThrow(ToolkitException::class, "'{$text}' is not an image GTK reads.");
});
