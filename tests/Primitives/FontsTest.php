<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Primitives\Concerns\GTKFonts;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\TextAlignment;
use Surface\Contracts\Windows\Styling\TextRangeStyle;
use Surface\NutsAndBolts\Color;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    viewMail(session());
});

/** The value of $property on the tag at (line, offset) that sets it, or null. */
function tagValue(GtkTextBuffer $buffer, int $line, int $offset, string $property): mixed
{
    foreach (lineOffsetIter($buffer, $line, $offset)->getTags() as $tag) {
        if (g_object_get_property($tag, "{$property}-set")) {
            return g_object_get_property($tag, $property);
        }
    }

    return null;
}

it('measures the monospace font, a family the system lacks and no family at all', function (): void {
    $mono = driver()->fontMetrics(new FontSpec(13.0, monospace: true));
    $missing = driver()->fontMetrics(new FontSpec(13.0, family: 'Zzqq Sans'));
    // Pango segfaults measuring a description with no family.
    $system = driver()->fontMetrics(new FontSpec(13.0));

    expect($mono->fixedPitch)->toBeTrue()
        ->and($mono->ascent)->toBeGreaterThan(0.0)
        ->and($mono->descent)->toBeGreaterThan(0.0)
        ->and($mono->averageCharWidth)->toBeGreaterThan(0.0)
        ->and($mono->lineHeight)->toBeGreaterThanOrEqual($mono->ascent + $mono->descent)
        ->and($missing->ascent)->toBeGreaterThan(0.0)
        ->and($system->fixedPitch)->toBeFalse();
});

it('asks for an italic face in the stylesheet', function (): void {
    $label = driver()->open('main', 300, 200)->column('m')->label('a', 'x')->setFont(new FontSpec(13.0, italic: true));

    expect($label->stylesheet())->toContain('font-style: italic;');
});

it('tags runs bold, italic, underlined and struck, a flag-only run sets no colour, and new runs clear old flags', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('t', "bold italic under strike");
    $area->setRangeStyles([
        new TextRangeStyle(0, 0, 4, bold: true, underline: true),
        new TextRangeStyle(0, 5, 6, italic: true),
        new TextRangeStyle(0, 18, 6, strikeout: true),
    ]);
    $buffer = $area->textView()->getBuffer();

    expect(tagValue($buffer, 0, 1, 'weight'))->toBe(700)
        ->and(tagValue($buffer, 0, 1, 'underline'))->toBe(1)
        ->and(tagValue($buffer, 0, 6, 'style'))->toBe(2)
        ->and(tagValue($buffer, 0, 19, 'strikethrough'))->toBeTrue()
        ->and(tagValue($buffer, 0, 6, 'foreground'))->toBeNull()
        ->and(tagValue($buffer, 0, 4, 'weight'))->toBeNull();

    $area->setRangeStyles([new TextRangeStyle(0, 0, 1, foreground: Color::hex('#ff0000'))]);
    expect(tagValue($buffer, 0, 1, 'weight'))->toBeNull()
        ->and(tagValue($buffer, 0, 19, 'strikethrough'))->toBeNull();
});

it('sets tab stops at columns × the character width and follows a font change', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('t', "a\tb");
    $view = $area->textView();
    $width = fn (float $size): float => GTKFonts::metrics($view->getPangoContext(), new FontSpec($size, monospace: true))->averageCharWidth;

    $area->setFont(new FontSpec(13.0, monospace: true))->setTabWidth(4);
    $view->getTabs()->getTab(0, $alignment, $location);
    expect($alignment)->toBe(PangoTabAlign::LEFT)
        ->and($location)->toEqualWithDelta(4 * $width(13.0), 1.0)
        ->and($view->getTabs()->getPositionsInPixels())->toBeTrue();

    $area->setFont(new FontSpec(20.0, monospace: true));
    $view->getTabs()->getTab(0, $alignment, $location);
    expect($location)->toEqualWithDelta(4 * $width(20.0), 1.0);
});

it('aligns a text area, an input and a button', function (): void {
    $column = driver()->open('main', 300, 200)->column('m');
    $area = $column->textArea('t', 'x')->setAlignment(TextAlignment::RIGHT);
    $input = $column->textInput('i', '12')->setAlignment(TextAlignment::RIGHT);
    $button = $column->button('b', 'Go')->setAlignment(TextAlignment::RIGHT);

    expect(g_object_get_property($area->textView(), 'justification'))->toBe(GtkJustification::RIGHT->value)
        ->and(g_object_get_property($input->native(), 'xalign'))->toBe(1.0)
        ->and($button->native()->getChild()->getHalign())->toBe(GtkAlign::END);

    $button->setLabel('Again');
    expect($button->native()->getChild()->getHalign())->toBe(GtkAlign::END);
});
