<?php

use Jovian\Venusian\GTK\Views\GtkCss;
use Surface\NativeWindows\Enums\FontWeight;

it('renders declarations as one sorted block', function () {
    $block = GtkCss::block(['color' => 'rgba(255,85,0,1.000)', 'background-color' => 'rgba(16,20,24,1.000)']);

    expect($block)->toBe('background-color: rgba(16,20,24,1.000); color: rgba(255,85,0,1.000);');
});

it('renders nothing for no declarations', function () {
    expect(GtkCss::block([]))->toBe('');
});

it('maps surface font weights onto css numeric weights', function (FontWeight $weight, int $css) {
    expect(GtkCss::fontWeight($weight))->toBe($css);
})->with([
    'ultra light' => [FontWeight::ULTRA_LIGHT, 100],
    'regular' => [FontWeight::REGULAR, 400],
    'bold' => [FontWeight::BOLD, 700],
    'black' => [FontWeight::BLACK, 900],
]);
