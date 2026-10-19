<?php

declare(strict_types=1);

namespace Vusys\Runabout\Support;

use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A few lines describing an actor response, for the failure message of the
 * step that made it: most failed step assertions in an HTTP journey mean
 * "the request was rejected for a reason I didn't expect".
 *
 * @internal
 */
final class ResponseSummary
{
    private const int BODY_LIMIT = 300;

    /** @param TestResponse<Response> $response */
    public static function of(TestResponse $response): string
    {
        $request = $response->baseRequest instanceof Request
            ? sprintf(' (%s %s)', $response->baseRequest->getMethod(), $response->baseRequest->getRequestUri())
            : '';

        $line = sprintf('Last response%s: %d', $request, $response->getStatusCode());

        if ($response->isRedirect()) {
            $line .= ' → '.$response->headers->get('Location');
        }

        $errors = self::sessionErrors();

        if ($errors !== []) {
            return $line."\n  session errors: ".self::encode($errors);
        }

        if ($response->isRedirect()) {
            return $line;
        }

        $body = self::body($response);

        return $body === '' ? $line : $line."\n  body: ".$body;
    }

    /** @return array<string, mixed> */
    private static function sessionErrors(): array
    {
        if (! app()->bound('session.store')) {
            return [];
        }

        $errors = resolve('session.store')->get('errors');

        return $errors instanceof ViewErrorBag ? $errors->getBag('default')->toArray() : [];
    }

    /** @param TestResponse<Response> $response */
    private static function body(TestResponse $response): string
    {
        $content = trim((string) $response->getContent());
        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            $content = self::encode($decoded);
        }

        return mb_strlen($content) > self::BODY_LIMIT
            ? mb_substr($content, 0, self::BODY_LIMIT).'…'
            : $content;
    }

    /** @param array<mixed> $value */
    private static function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
