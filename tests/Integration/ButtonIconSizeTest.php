<?php

declare(strict_types=1);

use Surface\Contracts\Toolkits\UI\Align;

/** The width and height GTK gave the image inside a button that shows an icon. */
function buttonImageSize(object $button): array
{
    $image = (fn () => $this->image)->call($button);

    return [$image->getWidth(), $image->getHeight()];
}

/** A square PNG of $side pixels in the temp dir. */
function iconFile(int $side): string
{
    $file = sys_get_temp_dir()."/venusian-gtk-icon-{$side}.png";
    imagepng(imagecreatetruecolor($side, $side), $file);

    return $file;
}

it('shows a button\'s file icon at the size GTK gives a named icon, whatever the file\'s pixels', function (): void {
    $window = openWindow('main', 400, 300);
    $column = $window->column('m');
    $named = $column->button('named', '')->setSymbol('edit-find-symbolic')->align(Align::START, Align::START);
    $small = $column->button('small', '')->setIcon(iconFile(24))->align(Align::START, Align::START);
    $large = $column->button('large', '')->setIcon(iconFile(128))->align(Align::START, Align::START);
    pumpFor(0.3);

    $size = buttonImageSize($named);

    expect($size[0])->toBeGreaterThan(0)
        ->and(buttonImageSize($small))->toBe($size)
        ->and(buttonImageSize($large))->toBe($size);
});
