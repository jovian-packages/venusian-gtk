<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\UI\GtkWebView;
use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\Insets;
use Surface\Toolkits\UI\TKWebView;

it('has no web engine: every load posts WebViewFailed with the reason', function (): void {
    $window = openWindow('main', 400, 300);
    $web = $window->column('m')->webView('web');
    takeMail();

    $web->load('https://example.org/')->loadHtml('<p>hi</p>', 'https://example.org/base/')->reload()->stop();
    $failed = array_values(array_filter(takeMail(), fn (object $m): bool => (new ReflectionClass($m))->getShortName() === 'WebViewFailed'));

    expect($web)->toBeInstanceOf(GtkWebView::class)
        ->and($web->supported())->toBeFalse()
        ->and($web->url())->toBeNull()
        ->and($web->title())->toBeNull()
        ->and($web->isLoading())->toBeFalse()
        ->and($failed)->toHaveCount(2)
        ->and($failed[0]->url)->toBe('https://example.org/')
        ->and($failed[1]->url)->toBe('https://example.org/base/')
        ->and($failed[0]->reason)->toBe(TKWebView::NO_ENGINE);
});

it('lays out as an empty area that fills', function (): void {
    $window = openWindow('main', 400, 300);
    $column = $window->column('m')->fill();
    $web = $column->webView('web')->fill();
    pumpFor(0.3);

    expect($web->size())->toBe([400, 300]);
});

it('takes a minimum size like any primitive', function (): void {
    $window = openWindow('main', 400, 300);
    $column = $window->column('m');
    $web = $column->webView('web')->minSize(220, 90)->align(Align::START, Align::START);
    pumpFor(0.3);

    expect($web->size())->toBe([220, 90]);
});

it('pads a button by CSS: more by the padding than without, back when cleared', function (): void {
    $window = openWindow('main', 400, 300);
    $column = $window->column('m')->setSpacing(10);
    $plain = $column->button('plain', 'Save')->align(Align::START, Align::START);
    $padded = $column->button('padded', 'Save')->align(Align::START, Align::START);
    pumpFor(0.3);
    [$width, $height] = $plain->size();
    expect($padded->size())->toBe([$width, $height]);

    $padded->setPadding([30, 40]);
    pumpFor(0.3);
    [$paddedWidth, $paddedHeight] = $padded->size();

    // the theme's own padding is replaced, not added to: the label's size plus the padding
    expect($paddedWidth)->toBeGreaterThan($width)
        ->and($paddedHeight)->toBeGreaterThan($height);

    $padded->setPadding(Insets::none());
    pumpFor(0.3);

    expect($padded->size())->toBe([$width, $height]);
});
