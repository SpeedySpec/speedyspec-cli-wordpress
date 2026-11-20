<?php

declare(strict_types=1);

use SpeedySpec\CliWordPress\Maintenance;

it('dates the maintenance file when it is written, so WordPress lets it lapse after ten minutes', function ()
{
    $before = time();
    $contents = Maintenance::contents();
    expect($contents)->toMatch('/^<\?php \$upgrading = \d+;$/');
    $upgrading = (int) preg_replace('/\D/', '', $contents);
    expect($upgrading)->toBeGreaterThanOrEqual($before)
        ->and($upgrading)->toBeLessThanOrEqual(time())
        ->and($contents)->not->toContain('time()');
});
