<?php

declare(strict_types=1);

/**
 * Assert exact bytes, reporting the difference in hex. A failure that says
 * "0434373131 !== 0434373132" is readable; raw binary in a terminal is not.
 */
expect()->extend('toBeBytes', function (string $expected) {
    expect(bin2hex($this->value))->toBe(bin2hex($expected));

    return $this;
});
