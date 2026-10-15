<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\View\ViewScrolled;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\TextRangeStyle;
use Surface\NutsAndBolts\Color;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    viewMail(session());
});

/** Whether a tag at (line, offset) has $property set. */
function tagged(GtkTextBuffer $buffer, int $line, int $offset, string $property): bool
{
    foreach ($buffer->getIterAtLineOffset($line, $offset)->getTags() as $tag) {
        if (g_object_get_property($tag, "{$property}-set")) {
            return true;
        }
    }

    return false;
}

it('tags line backgrounds and range colours, replaces them on the next set, and clears them with a new value', function (): void {
    $window = driver()->open('main', 300, 200);
    $area = $window->column('m')->textArea('diff', "one\ntwo\nthrée😀x")->watchScroll();
    $buffer = $area->textView()->getBuffer();
    $area->setLineBackgrounds([1 => Color::hex('#ff0000')])
        ->setRangeStyles([new TextRangeStyle(2, 4, 2, foreground: Color::hex('#0000ff'), background: Color::hex('#ffff00'))]);

    expect(tagged($buffer, 1, 0, 'paragraph-background'))->toBeTrue()
        ->and(tagged($buffer, 0, 0, 'paragraph-background'))->toBeFalse()
        ->and(tagged($buffer, 2, 4, 'foreground'))->toBeTrue()
        ->and(tagged($buffer, 2, 5, 'background'))->toBeTrue()
        ->and(tagged($buffer, 2, 3, 'foreground'))->toBeFalse()
        ->and(tagged($buffer, 2, 6, 'background'))->toBeFalse();

    $area->setLineBackgrounds([0 => Color::hex('#ff0000')]);
    expect(tagged($buffer, 1, 0, 'paragraph-background'))->toBeFalse()
        ->and(tagged($buffer, 0, 0, 'paragraph-background'))->toBeTrue()
        ->and(tagged($buffer, 2, 4, 'foreground'))->toBeTrue();

    $area->setValue("x\ny");
    expect(tagged($buffer, 0, 0, 'paragraph-background'))->toBeFalse()
        ->and($buffer->getText(0, -1))->toBe("x\ny")
        ->and(viewMail(session()))->toBe([]);

    $window->present();
    expect(settled($area))->toBeTrue()
        ->and(viewMail(session()))->toBe([]);
});

it('tags again the styles it was asked for before, after a user edit moved or dropped them', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('diff', "one\ntwo\nthree");
    $buffer = $area->textView()->getBuffer();
    $red = Color::hex('#ff0000');
    $area->setLineBackgrounds([1 => $red]);

    // setText stands in for a user's replace-all paste; undo off keeps GTK's history from warning about it.
    g_object_set_property($buffer, 'enable-undo', false);
    $buffer->beginUserAction();
    $buffer->setText("one\ntwo\nthree");
    $buffer->endUserAction();
    $dropped = tagged($buffer, 1, 0, 'paragraph-background');
    $area->setLineBackgrounds([1 => $red]);

    expect($dropped)->toBeFalse()
        ->and(tagged($buffer, 1, 0, 'paragraph-background'))->toBeTrue();
});

it('counts the lines a user edit brings back with CRLF as GTK does', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('diff', 'x');
    $buffer = $area->textView()->getBuffer();

    // setText stands in for a user's replace-all paste; undo off keeps GTK's history from warning about it.
    g_object_set_property($buffer, 'enable-undo', false);
    $buffer->beginUserAction();
    $buffer->setText("one\r\ntwo");
    $buffer->endUserAction();
    $area->setRangeStyles([new TextRangeStyle(1, 0, 3, foreground: Color::hex('#0000ff'))]);

    expect($area->lineCount())->toBe(2)
        ->and(tagged($buffer, 1, 0, 'foreground'))->toBeTrue()
        ->and(tagged($buffer, 0, 0, 'foreground'))->toBeFalse();
});

it('selects lines and reads the selection back as lines', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('diff', "é😀\ntwo\nthree");
    $buffer = $area->textView()->getBuffer();

    $area->selectLines(1, 2);
    [$from, $to] = $buffer->getSelectionBounds();
    expect([$from->getOffset(), $to->getOffset()])->toBe([3, 12])
        ->and($area->selectedLines())->toBe([1, 2])
        ->and($area->caretLine())->toBe(1);

    $buffer->selectRange($buffer->getIterAtLine(1), $buffer->getIterAtLine(2));   // "two\n"
    expect($area->selectedLines())->toBe([1, 1]);
    $buffer->selectRange($buffer->getIterAtLineOffset(0, 1), $buffer->getIterAtLineOffset(0, 1));
    expect($area->selectedLines())->toBe([0, 0])
        ->and(viewMail(session()))->toBe([]);
});

it('stops wrapping, turns read-only on with a visible caret, and asks CSS for monospace', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('diff', 'text');
    $area->setWrap(false)->setReadOnly(true)->setFont(new FontSpec(12.0, monospace: true));

    expect($area->textView()->getEditable())->toBeFalse()
        ->and($area->textView()->getCursorVisible())->toBeTrue()
        ->and($area->stylesheet())->toContain('font-family: monospace;');

    $area->setWrap(true)->setReadOnly(false)->setFont(new FontSpec(12.0, family: 'Menlo', monospace: true));
    expect($area->textView()->getEditable())->toBeTrue()
        ->and($area->stylesheet())->toContain('font-family: "Menlo";')
        ->and($area->stylesheet())->not->toContain('monospace');
});

it('scrolls by content fractions and to a line, posting ViewScrolled only for scrolls it did not make', function (): void {
    $window = driver()->open('main', 300, 200);
    $area = $window->column('m')->textArea('diff', implode("\n", range(1, 400)))->fill()->setWrap(false)->watchScroll();
    $window->present();
    $vertical = $area->native()->getVadjustment();
    expect(settled($area))->toBeTrue()
        ->and($vertical->getUpper())->toBeGreaterThan($vertical->getPageSize());
    viewMail(session());

    $area->scrollTo(0.0, 0.5);
    expect(settled($area))->toBeTrue();
    $at = $area->scrollPosition();
    expect(viewMail(session()))->toBe([])
        ->and($at->y)->toEqualWithDelta(0.5, 0.01)
        ->and($at->height)->toBeLessThan(0.5);

    $vertical->setValue(100.0);   // as the scroll bar or the wheel does
    expect(settled($area))->toBeTrue();
    $mail = viewMail(session());
    expect($mail)->toHaveCount(1)
        ->and($mail[0])->toBeInstanceOf(ViewScrolled::class)
        ->and($mail[0]->position)->toEqual($area->scrollPosition());

    $area->scrollToLine(200);
    expect(settled($area))->toBeTrue()
        ->and($area->scrollPosition()->y)->toEqualWithDelta(0.5, 0.01)
        ->and(viewMail(session()))->toBe([]);

    $area->watchScroll(false);
    $vertical->setValue(0.0);
    expect(settled($area))->toBeTrue()
        ->and(viewMail(session()))->toBe([]);
});

it('leaves a pane where it is when told the position it already has', function (): void {
    $window = driver()->open('main', 300, 200);
    $area = $window->column('m')->textArea('diff', implode("\n", range(1, 400)))->fill()->setWrap(false)->watchScroll();
    $window->present();
    [$horizontal, $vertical] = [$area->native()->getHadjustment(), $area->native()->getVadjustment()];
    $area->scrollTo(0.0, 0.37);
    expect(settled($area))->toBeTrue();
    viewMail(session());
    $at = $area->scrollPosition();
    $values = [$horizontal->getValue(), $vertical->getValue()];

    $area->scrollTo($at->x, $at->y);
    expect(settled($area))->toBeTrue()
        ->and([$horizontal->getValue(), $vertical->getValue()])->toBe($values)
        ->and(viewMail(session()))->toBe([]);
});

it('lays out a new font without posting ViewScrolled, and still posts the next user scroll', function (): void {
    $window = driver()->open('main', 300, 200);
    $area = $window->column('m')->textArea('diff', implode("\n", range(1, 400)))->fill()->setWrap(false)->watchScroll();
    $window->present();
    $vertical = $area->native()->getVadjustment();
    $area->scrollTo(0.0, 0.5);
    expect(settled($area))->toBeTrue();
    viewMail(session());
    [$value, $upper] = [$vertical->getValue(), $vertical->getUpper()];

    $area->setFont(new FontSpec(28.0, monospace: true));
    expect(settled($area))->toBeTrue()
        ->and($vertical->getUpper())->toBeGreaterThan($upper)
        ->and($vertical->getValue())->not->toBe($value)
        ->and(viewMail(session()))->toBe([]);

    $vertical->setValue(50.0);
    expect(settled($area))->toBeTrue();
    $mail = viewMail(session());
    expect($mail)->toHaveCount(1)
        ->and($mail[0])->toBeInstanceOf(ViewScrolled::class);
});

it('keeps an unwrapped pane with line backgrounds as wide as its text, and scrolls sideways to its right edge', function (): void {
    $window = driver()->open('main', 300, 200);
    $area = $window->column('m')->textArea('diff', implode("\n", ['short', str_repeat('wide ', 80), 'short']))->fill()->setWrap(false);
    $window->present();
    $horizontal = $area->native()->getHadjustment();
    expect(settled($area))->toBeTrue();
    $bare = $horizontal->getUpper();

    $red = Color::hex('#ff0000');
    $area->setLineBackgrounds([0 => $red, 1 => $red, 2 => $red]);
    expect(settled($area))->toBeTrue()
        ->and($horizontal->getUpper())->toBeGreaterThan($horizontal->getPageSize())
        ->and($horizontal->getUpper())->toEqualWithDelta($bare, 1.0);

    $area->scrollTo(1.0, 0.0);
    expect(settled($area))->toBeTrue()
        ->and($horizontal->getValue())->toEqualWithDelta($horizontal->getUpper() - $horizontal->getPageSize(), 0.5);
    $at = $area->scrollPosition();
    expect($at->x + $at->width)->toEqualWithDelta(1.0, 0.001);
});

it('posts one ViewScrolled for a user scroll made while the area settles', function (): void {
    $window = driver()->open('main', 300, 200);
    $area = $window->column('m')->textArea('diff', implode("\n", range(1, 400)))->fill()->setWrap(false)->watchScroll();
    $window->present();
    $vertical = $area->native()->getVadjustment();
    expect(settled($area))->toBeTrue();
    viewMail(session());

    $area->setLineBackgrounds([10 => Color::hex('#ff0000')]);
    expect($area->isSettling())->toBeTrue();
    $vertical->setValue(120.0);   // as the wheel does, inside the settle
    expect($area->isSettling())->toBeTrue()
        ->and(settled($area))->toBeTrue();
    $mail = viewMail(session());
    expect($mail)->toHaveCount(1)
        ->and($mail[0])->toBeInstanceOf(ViewScrolled::class)
        ->and($mail[0]->position)->toEqual($area->scrollPosition())
        ->and($vertical->getValue())->toBe(120.0);
});

it('settles only for hooks that change something, and ends a settle that changes keep re-arming at its cap', function (): void {
    $window = driver()->open('main', 300, 200);
    $text = implode("\n", range(1, 400));
    $area = $window->column('m')->textArea('diff', $text)->fill()->setWrap(false)->watchScroll();
    $window->present();
    expect(settled($area))->toBeTrue();
    viewMail(session());

    // The same value again (which also clears no styles, there being none), the same wrap.
    expect(pumpUntil(function () use ($area, $text): bool {
        $area->setValue($text)->setWrap(false)->setLineBackgrounds([])->setRangeStyles([]);

        return $area->isSettling();
    }, 0.3))->toBeFalse();

    // The same line backgrounds again.
    $red = [10 => Color::hex('#ff0000')];
    $area->setLineBackgrounds($red);
    expect(settled($area))->toBeTrue();
    expect(pumpUntil(function () use ($area, $red): bool {
        $area->setLineBackgrounds($red)->setRangeStyles([])->setWrap(false);

        return $area->isSettling();
    }, 0.3))->toBeFalse();

    $toggle = false;
    $area->setLineBackgrounds([10 => Color::hex('#00ff00')])->scrollTo(0.0, 0.5);
    $began = microtime(true);
    $scrolled = pumpUntil(function () use ($area, &$toggle): bool {
        $toggle = ! $toggle;
        $area->setLineBackgrounds([10 => Color::hex($toggle ? '#0000ff' : '#00ff00')]);

        return abs($area->scrollPosition()->y - 0.5) < 0.01;
    }, 2.0);
    $took = microtime(true) - $began;
    expect($scrolled)->toBeTrue()
        ->and($took)->toBeGreaterThan(0.45)
        ->and($took)->toBeLessThan(1.0)
        ->and(settled($area))->toBeTrue()
        ->and(viewMail(session()))->toBe([]);
});
