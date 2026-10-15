<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Primitives\GTKLink;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\TextAlignment;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

it('builds a link button over the URL with the text, and updates both', function (): void {
    $column = driver()->open('main', 300, 200)->column('m');
    $link = $column->link('docs', 'Read the docs', 'https://example.com/docs');
    $empty = $column->link('empty', '', 'https://example.com/');

    expect($link)->toBeInstanceOf(GTKLink::class)
        ->and($link->native())->toBeInstanceOf(\GtkLinkButton::class)
        ->and($link->native()->getUri())->toBe('https://example.com/docs')
        ->and($link->native()->getLabel())->toBe('Read the docs')
        ->and($empty->native()->getLabel())->toBe('');

    $link->setText('Docs')->setUrl('mailto:help@example.com')->setAlignment(TextAlignment::RIGHT)->setFont(new FontSpec(14.0));

    expect($link->native()->getUri())->toBe('mailto:help@example.com')
        ->and($link->native()->getLabel())->toBe('Docs')
        ->and($link->native()->getFirstChild()->getXalign())->toBe(1.0)
        ->and($link->stylesheet())->toContain('font-size: 14px;')
        ->and(takeMail(session()))->toBe([]);
});

it('makes a link in a disabled group insensitive', function (): void {
    $column = driver()->open('main', 300, 200)->column('m');
    $link = $column->link('docs', 'Docs', 'https://example.com/');
    $column->disable();

    expect($link->native()->isSensitive())->toBeFalse();
});
