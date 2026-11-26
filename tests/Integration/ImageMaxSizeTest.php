<?php

declare(strict_types=1);

use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\ImageScaling;

/** A square PNG of $side pixels in the temp dir. */
function squareImage(int $side): string
{
    $file = sys_get_temp_dir()."/venusian-gtk-square-{$side}.png";
    imagepng(imagecreatetruecolor($side, $side), $file);

    return $file;
}

it('shows an image at its own size with no maximum', function (): void {
    $window = openWindow('main', 400, 300);
    $image = $window->column('m')->image('icon', squareImage(84))->align(Align::START, Align::START);
    pumpFor(0.3);

    expect($image->size())->toBe([84, 84]);
});

it('caps an image at its maximum size, and its row lays out round the cap', function (): void {
    $window = openWindow('main', 400, 300);
    $row = $window->column('m')->row('r')->setSpacing(10)->align(Align::START, Align::START);
    $image = $row->image('icon', squareImage(84))->setScaling(ImageScaling::FIT)->minSize(22, 22)->maxSize(22, 22);
    $label = $row->label('l', 'Time Zone');
    pumpFor(0.3);

    expect($image->size())->toBe([22, 22])
        ->and($row->size()[0])->toBe(22 + 10 + $label->size()[0])
        ->and($row->size()[1])->toBe(max(22, $label->size()[1]));
});

it('never enlarges an image to a maximum above its own size', function (): void {
    $window = openWindow('main', 400, 300);
    $image = $window->column('m')->image('icon', squareImage(84))->align(Align::START, Align::START)->maxSize(96, 96);
    pumpFor(0.3);

    expect($image->size())->toBe([84, 84]);
});

it('follows a new file under its maximum, and lets go of the maximum when cleared', function (): void {
    $window = openWindow('main', 400, 300);
    $image = $window->column('m')->image('icon', squareImage(84))->align(Align::START, Align::START)->maxSize(96, 96);
    pumpFor(0.3);

    $image->setFile(squareImage(256));
    pumpFor(0.3);
    expect($image->size())->toBe([96, 96]);

    $image->maxSize(0, 0);
    pumpFor(0.3);
    expect($image->size())->toBe([256, 256]);
});
