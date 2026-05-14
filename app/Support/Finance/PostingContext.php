<?php

namespace App\Support\Finance;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * سياق طلب يُخزَّن تلقائياً على رأس القيد المحاسبي (Source / IP / المسار).
 */
final class PostingContext
{
    public function __construct(
        public readonly string $channel,
        public readonly ?string $httpMethod = null,
        public readonly ?string $requestPath = null,
        public readonly ?string $routeName = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $clientIp = null,
        public readonly ?string $userAgent = null,
    ) {}

    public static function fromHttpRequest(Request $request): self
    {
        $ua = (string) $request->userAgent();
        if (strlen($ua) > 2000) {
            $ua = substr($ua, 0, 2000);
        }

        return new self(
            channel: 'http_api',
            httpMethod: $request->method(),
            requestPath: '/'.$request->path(),
            routeName: $request->route()?->getName(),
            correlationId: $request->header('X-Request-Id') ?: (string) Str::uuid(),
            clientIp: $request->ip(),
            userAgent: $ua !== '' ? $ua : null,
        );
    }

    public static function console(string $label = 'artisan'): self
    {
        return new self(
            channel: $label,
            correlationId: (string) Str::uuid(),
        );
    }

    public static function job(string $jobClass): self
    {
        return new self(
            channel: 'queued_job',
            requestPath: $jobClass,
            correlationId: (string) Str::uuid(),
        );
    }
}
