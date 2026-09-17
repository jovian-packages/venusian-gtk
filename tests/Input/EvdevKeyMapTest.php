<?php

use Jovian\Venusian\GTK\Input\EvdevKeyMap;
use Surface\Contracts\HumanInput\Key;

it('maps linux evdev key codes to surface keys', function (int $code, Key $key) {
    expect(EvdevKeyMap::key($code))->toBe($key);
})->with([
    'KEY_A' => [30, Key::A],
    'KEY_1' => [2, Key::DIGIT_1],
    'KEY_9' => [10, Key::DIGIT_9],
    'KEY_0' => [11, Key::DIGIT_0],
    'KEY_ENTER' => [28, Key::ENTER],
    'KEY_SPACE' => [57, Key::SPACE],
    'KEY_F1' => [59, Key::F1],
    'KEY_F10' => [68, Key::F10],
    'KEY_F12' => [88, Key::F12],
    'KEY_KP0' => [82, Key::NUMPAD_0],
    'KEY_KP7' => [71, Key::NUMPAD_7],
    'KEY_UP' => [103, Key::UP],
    'KEY_LEFTMETA' => [125, Key::LEFT_META],
    'KEY_RIGHTCTRL' => [97, Key::RIGHT_CTRL],
    'KEY_MENU' => [127, Key::MENU],
    'unmapped' => [200, Key::UNKNOWN],
    'gap' => [84, Key::UNKNOWN],
    'negative' => [-1, Key::UNKNOWN],
]);

it('maps every code in the table to a known key', function () {
    $codes = [...range(1, 83), 87, 88, 96, 97, 98, 99, 100, ...range(102, 111), 117, 119, 125, 126, 127];

    foreach ($codes as $code) {
        expect(EvdevKeyMap::key($code))->not->toBe(Key::UNKNOWN, "code {$code}");
    }
});
