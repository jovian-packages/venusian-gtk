<?php

declare(strict_types=1);

/** A 16 x 16 file. */
function gtkDropdownIconFile(): string
{
    $path = sys_get_temp_dir().'/gtk-dropdown-icon.png';
    imagepng(imagecreatetruecolor(16, 16), $path);

    return $path;
}

function gtkKindDropdown(): array
{
    $window = openWindow('dropdown', 400, 300);
    $dropdown = $window->column('root')->dropdown('kind', ['All', 'Images', 'Videos', 'Audio'], 1);
    pumpFor(0.1);

    return [$window, $dropdown];
}

/**
 * What a widget holds, depth first: each shown image's icon name (or 'file') and each label's text.
 *
 * @return list<string>
 */
function shownUnder(GtkWidget $widget): array
{
    $found = [];
    for ($child = $widget->getFirstChild(); ! is_null($child); $child = $child->getNextSibling()) {
        if (! $child->getVisible()) {
            continue;
        }
        if ($child instanceof GtkImage) {
            $found[] = 'image:'.($child->getIconName() ?? 'file');
        } elseif ($child instanceof GtkLabel) {
            $found[] = 'label:'.$child->getText();
        }
        array_push($found, ...shownUnder($child));
    }

    return $found;
}

it('draws the selected option on the button with its icon and its label', function (): void {
    [$window, $dropdown] = gtkKindDropdown();
    $dropdown->setIcons([['symbol' => 'system-search-symbolic'], ['symbol' => 'image-x-generic-symbolic']]);
    pumpFor(0.1);
    $button = $dropdown->native()->getFirstChild();

    expect(shownUnder($button))->toContain('image:image-x-generic-symbolic')
        ->and(shownUnder($button))->toContain('label:Images')
        ->and($button->hasCssClass('flat'))->toBeFalse();
    $window->close();
});

it('draws a file icon where there is one, and no image for an option without one', function (): void {
    [$window, $dropdown] = gtkKindDropdown();
    $dropdown->setIcons([null, ['file' => gtkDropdownIconFile(), 'symbol' => 'image-x-generic-symbolic']]);
    pumpFor(0.1);
    $withFile = shownUnder($dropdown->native()->getFirstChild());
    $dropdown->select(0);
    pumpFor(0.1);

    expect($withFile)->toContain('image:file')
        ->and(shownUnder($dropdown->native()->getFirstChild()))->not->toContain('image:file')
        ->and(shownUnder($dropdown->native()->getFirstChild()))->toContain('label:All');
    $window->close();
});

it('shows the selected option\'s icon alone on a flat button once icon-only, and its label again after', function (): void {
    [$window, $dropdown] = gtkKindDropdown();
    $dropdown->setIcons([['symbol' => 'system-search-symbolic'], ['symbol' => 'image-x-generic-symbolic']]);
    $dropdown->setIconOnly();
    pumpFor(0.1);
    $button = $dropdown->native()->getFirstChild();
    $iconOnly = shownUnder($button);
    $flat = $button->hasCssClass('flat');

    $dropdown->setIconOnly(false);
    pumpFor(0.1);

    expect($iconOnly)->toContain('image:image-x-generic-symbolic')
        ->and($iconOnly)->not->toContain('label:Images')
        ->and($flat)->toBeTrue()
        ->and($button->hasCssClass('flat'))->toBeFalse()
        ->and(shownUnder($button))->toContain('label:Images');
    $window->close();
});

it('keeps drawing its labels once its icons are taken away', function (): void {
    [$window, $dropdown] = gtkKindDropdown();
    $dropdown->setIcons([['symbol' => 'system-search-symbolic'], ['symbol' => 'image-x-generic-symbolic']]);
    pumpFor(0.1);
    $dropdown->setIcons([]);
    pumpFor(0.1);
    $shown = shownUnder($dropdown->native()->getFirstChild());

    expect(array_filter($shown, fn (string $s): bool => str_starts_with($s, 'image:image-x')))->toBe([])
        ->and($shown)->toContain('label:Images');
    $window->close();
});

it('marks the selected row in the list with GTK\'s check, as GTK\'s own items do, and none on the button', function (): void {
    [$window, $dropdown] = gtkKindDropdown();
    $dropdown->setIcons([['symbol' => 'system-search-symbolic'], ['symbol' => 'image-x-generic-symbolic']]);
    pumpFor(0.1);
    g_signal_emit_by_name($dropdown->native(), 'activate');
    pumpFor(0.3);
    $checks = (fn (): array => array_map(fn (GtkImage $c): float => $c->getOpacity(), $this->checks))->call($dropdown);
    ksort($checks);
    $dropdown->select(3);
    pumpFor(0.1);
    $moved = (fn (): array => array_map(fn (GtkImage $c): float => $c->getOpacity(), $this->checks))->call($dropdown);
    ksort($moved);

    expect($checks)->toBe([0 => 0.0, 1 => 1.0, 2 => 0.0, 3 => 0.0])
        ->and($moved)->toBe([0 => 0.0, 1 => 0.0, 2 => 0.0, 3 => 1.0])
        ->and(shownUnder($dropdown->native()->getFirstChild()))->not->toContain('image:object-select-symbolic');
    $window->close();
});
