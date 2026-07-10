<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds RFC 9457 `application/problem+json` responses — the single place that
 * knows the error wire format.
 */
final class ProblemDetails
{
    public const string CONTENT_TYPE = 'application/problem+json';

    /**
     * @param list<array{field: string, message: string}> $errors
     */
    public static function response(int $status, string $detail, array $errors = []): JsonResponse
    {
        $body = [
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
        ];
        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return new JsonResponse($body, $status, ['Content-Type' => self::CONTENT_TYPE]);
    }
}
