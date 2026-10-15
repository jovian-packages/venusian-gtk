<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Windows\GTKMacTextKeys;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
});

it('binds the Mac text keys to GtkText\'s own signals', function (int $keyval, int $state, ?array $bound): void {
    expect(GTKMacTextKeys::binding($keyval, $state))->toBe($bound);
})->with([
    'cmd left' => [GTKMacTextKeys::LEFT, GDK_META_MASK, ['move-cursor', [4, -1, false]]],
    'cmd shift right' => [GTKMacTextKeys::RIGHT, GDK_META_MASK | GDK_SHIFT_MASK, ['move-cursor', [4, 1, true]]],
    'super left' => [GTKMacTextKeys::LEFT, GDK_SUPER_MASK, ['move-cursor', [4, -1, false]]],
    'cmd up' => [GTKMacTextKeys::UP, GDK_META_MASK, ['move-cursor', [8, -1, false]]],
    'cmd down' => [GTKMacTextKeys::DOWN, GDK_META_MASK, ['move-cursor', [8, 1, false]]],
    'option left' => [GTKMacTextKeys::LEFT, GDK_ALT_MASK, ['move-cursor', [2, -1, false]]],
    'option shift right' => [GTKMacTextKeys::RIGHT, GDK_ALT_MASK | GDK_SHIFT_MASK, ['move-cursor', [2, 1, true]]],
    'cmd backspace' => [GTKMacTextKeys::BACKSPACE, GDK_META_MASK, ['delete-from-cursor', [5, -1]]],
    'option backspace' => [GTKMacTextKeys::BACKSPACE, GDK_ALT_MASK, ['delete-from-cursor', [1, -1]]],
    'option delete' => [GTKMacTextKeys::DELETE, GDK_ALT_MASK, ['delete-from-cursor', [1, 1]]],
    'caps lock held' => [GTKMacTextKeys::LEFT, GDK_META_MASK | GDK_LOCK_MASK, ['move-cursor', [4, -1, false]]],
    'plain left' => [GTKMacTextKeys::LEFT, 0, null],
    'control left' => [GTKMacTextKeys::LEFT, GDK_CONTROL_MASK, null],
    'cmd option left' => [GTKMacTextKeys::LEFT, GDK_META_MASK | GDK_ALT_MASK, null],
    'cmd a' => [0x61, GDK_META_MASK, null],
]);

it('moves and deletes in an entry by line ends and words', function (): void {
    $window = driver()->open('main', 360, 160);
    $input = $window->column('c')->textInput('name', 'hello big world');
    $window->present();
    pumpFor(0.2);
    $input->native()->grabFocus();
    $text = $window->native()->getFocus();
    $read = fn (): string => $input->native()->getBuffer()->getText();

    GTKMacTextKeys::press($text, GTKMacTextKeys::LEFT, GDK_META_MASK);
    GTKMacTextKeys::press($text, GTKMacTextKeys::DELETE, GDK_ALT_MASK);
    $word_forward = $read();
    GTKMacTextKeys::press($text, GTKMacTextKeys::RIGHT, GDK_META_MASK);
    GTKMacTextKeys::press($text, GTKMacTextKeys::BACKSPACE, GDK_ALT_MASK);
    $word_back = $read();
    GTKMacTextKeys::press($text, GTKMacTextKeys::BACKSPACE, GDK_META_MASK);

    expect([$word_forward, $word_back, $read()])->toBe([' big world', ' big ', '']);
});

it('moves and deletes in a text area by document ends', function (): void {
    $window = driver()->open('main', 360, 200);
    $area = $window->column('c')->textArea('notes', "one\ntwo");
    $window->present();
    pumpFor(0.2);
    $view = $area->textView();
    $read = fn (): string => $view->getBuffer()->getText(0, -1);

    GTKMacTextKeys::press($view, GTKMacTextKeys::DOWN, GDK_META_MASK);
    GTKMacTextKeys::press($view, GTKMacTextKeys::BACKSPACE, GDK_META_MASK);
    $line = $read();
    GTKMacTextKeys::press($view, GTKMacTextKeys::UP, GDK_META_MASK);
    GTKMacTextKeys::press($view, GTKMacTextKeys::DELETE, GDK_ALT_MASK);

    expect([$line, $read()])->toBe(["one\n", "\n"]);
});

it('lets the window\'s capture controllers see the key first: the controller sits on the text widget, and only on macOS', function (): void {
    $window = driver()->open('main', 360, 200);
    $column = $window->column('c');
    $input = $column->textInput('name', 'x');
    $area = $column->textArea('notes', 'y');

    if (PHP_OS_FAMILY !== 'Darwin') {
        expect([$input->macKeys(), $area->macKeys()])->toBe([null, null]);

        return;
    }

    expect($input->macKeys()->getPropagationPhase())->toBe(GtkPropagationPhase::CAPTURE)
        ->and($input->macKeys()->getWidget())->toBe($input->native())
        ->and($area->macKeys()->getWidget())->toBe($area->textView());
});
