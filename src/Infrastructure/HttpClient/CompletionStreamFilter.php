<?php

declare(strict_types=1);

namespace Msstc4Symfony\ProfilingBundle\Infrastructure\HttpClient;

use Closure;
use Override;
use php_user_filter;

/**
 * Pass-through read filter that reports the end of the body and the closing of the stream, so toStream() can keep
 * the inner, buffered and rewindable stream.
 *
 * @internal attached by ProfiledResponse::toStream() only
 */
final class CompletionStreamFilter extends php_user_filter
{
    public const NAME = 'msstc4symfony.profiling.completion';

    /**
     * @param resource $stream
     * @param Closure(): void $onEnd the whole body was read
     * @param Closure(): void $onClose the stream was closed, read to its end or not
     */
    public static function attach($stream, Closure $onEnd, Closure $onClose): void
    {
        if (!in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }

        stream_filter_append($stream, self::NAME, STREAM_FILTER_READ, [$onEnd, $onClose]);
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     */
    #[Override]
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        // Before PHP 8.4 the bucket is a plain object, not a StreamBucket: no instanceof here.
        while (is_object($bucket = stream_bucket_make_writeable($in))) {
            $length = $bucket->datalen;
            $consumed += is_int($length) ? $length : 0;
            stream_bucket_append($out, $bucket);
        }

        // Only EOF: fclose() does not reach filter() with $closing, it calls onClose().
        if ($closing) {
            $this->notify(0);
        }

        return PSFS_PASS_ON;
    }

    #[Override]
    public function onClose(): void
    {
        $this->notify(1);
    }

    /**
     * @param 0|1 $index 0 for the end of the body, 1 for the closing
     */
    private function notify(int $index): void
    {
        $callback = is_array($this->params) ? $this->params[$index] ?? null : null;
        if ($callback instanceof Closure) {
            $callback();
        }
    }
}
