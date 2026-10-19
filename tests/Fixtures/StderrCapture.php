<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures;

use Closure;

/** Swallows STDERR while a closure runs, and returns what was written. */
final class StderrCapture extends \php_user_filter
{
    private static string $buffer = '';

    private static bool $registered = false;

    /** @param Closure(): void $fn */
    public static function of(Closure $fn): string
    {
        if (! self::$registered) {
            stream_filter_register('runabout-stderr-capture', self::class);
            self::$registered = true;
        }

        self::$buffer = '';
        $handle = stream_filter_append(STDERR, 'runabout-stderr-capture');

        try {
            $fn();
        } finally {
            if ($handle !== false) {
                stream_filter_remove($handle);
            }
        }

        return self::$buffer;
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     */
    #[\Override]
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $before = strlen(self::$buffer);
            self::$buffer .= $bucket->data;
            $consumed += strlen(self::$buffer) - $before;
        }

        return PSFS_PASS_ON;
    }
}
