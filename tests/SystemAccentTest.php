<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Services\GTKSystemAccent;

/**
 * What GTKSystemAccent reports in a child process whose only schemas are the ones written here: the default
 * of accent-color is $default; null = the schema lacks the key, false = no org.gnome.desktop.interface at all.
 */
function accentIn(string|false|null $default): string
{
    $dir = sys_get_temp_dir().'/gtk-accent-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $key = is_string($default) ? "<key name=\"accent-color\" type=\"s\"><default>'{$default}'</default></key>" : '';
    $id = $default === false ? 'org.example.other' : 'org.gnome.desktop.interface';
    file_put_contents("{$dir}/{$id}.gschema.xml", "<schemalist><schema id=\"{$id}\" path=\"/test/\">{$key}<key name=\"other\" type=\"b\"><default>true</default></key></schema></schemalist>");
    exec('glib-compile-schemas '.escapeshellarg($dir).' 2>&1', $out, $status);
    if ($status !== 0) {
        throw new RuntimeException('glib-compile-schemas failed: '.implode("\n", $out));
    }

    $code = 'require $argv[1]; try { echo (new '.GTKSystemAccent::class.')->color()?->toHex() ?? "null"; }'
        .' catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(); }';
    $proc = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__).'/vendor/autoload.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
        'PATH' => (string) getenv('PATH'),
        'GSETTINGS_SCHEMA_DIR' => $dir,
        'GSETTINGS_BACKEND' => 'memory',
        'XDG_DATA_DIRS' => $dir,
        'XDG_DATA_HOME' => $dir,
    ]);
    $result = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    array_map('unlink', glob("{$dir}/*"));
    rmdir($dir);

    return $result;
}

it('maps the names GNOME gives an accent to the colours libadwaita draws', function (string $name, string $hex): void {
    expect(accentIn($name))->toBe($hex);
})->with([
    ['blue', '#3584e4'], ['teal', '#2190a4'], ['green', '#3a944a'], ['yellow', '#c88800'], ['orange', '#ed5b00'],
    ['red', '#e62d42'], ['pink', '#d56199'], ['purple', '#9141ac'], ['slate', '#6f8396'], ['Purple', '#9141ac'],
]);

it('reports none on a desktop whose schema has no accent-color, or no schema', function (): void {
    expect(accentIn(null))->toBe('null')
        ->and(accentIn(false))->toBe('null');
});

it('refuses a name GNOME gives no accent, naming it', function (): void {
    expect(accentIn('chartreuse'))->toStartWith('Surface\Contracts\Windows\WindowException: ')
        ->toContain("accent-color is 'chartreuse'; GNOME names blue, teal, green");
});

it('reads the real desktop the way gsettings does', function (): void {
    $names = ['blue' => '#3584e4', 'teal' => '#2190a4', 'green' => '#3a944a', 'yellow' => '#c88800', 'orange' => '#ed5b00',
        'red' => '#e62d42', 'pink' => '#d56199', 'purple' => '#9141ac', 'slate' => '#6f8396'];
    $said = trim((string) shell_exec('gsettings get org.gnome.desktop.interface accent-color 2>/dev/null'), "' \n");

    expect((new GTKSystemAccent)->color()?->toHex())->toBe($names[$said] ?? null);
})->skip(shell_exec('command -v gsettings') === null, 'needs the gsettings tool');

it('is the driver\'s system accent', function (): void {
    expect(driver()->systemAccent())->toBeInstanceOf(GTKSystemAccent::class)
        ->and(driver()->systemAccent())->toBe(driver()->systemAccent());
});
