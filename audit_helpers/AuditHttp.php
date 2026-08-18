<?php

namespace AuditHelpers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\Models\User;

/**
 * Thin wrapper around Laravel's HTTP test machinery for the audit.
 * Provides direct HTTP-call testing of routes that the audit MUST verify are
 * blocked (Phase 11 — Edit Lock) or rejected (Phase 14 — cross-module).
 *
 * Use as:
 *   $audit->http('admin')
 *         ->post('/api/v1/flight/bookings/'.$id, [...])
 *         ->assert(405);
 */
class AuditHttp
{
    protected ?string $bearer = null;
    protected ?User $user = null;
    protected array $cookies = [];
    protected array $server = [];

    public function __construct() {}

    public function asUser(User $user): self
    {
        $this->user = $user;
        // Generate a Sanctum token if Sanctum is in use; otherwise rely on
        // session-based auth via actingAs. We'll use actingAs to avoid Sanctum
        // token plumbing in the audit.
        return $this;
    }

    public function asAdmin(): self
    {
        $user = User::where('role', 'admin')->where('is_active', true)->first()
            ?? User::where('is_active', true)->first();
        if (!$user) {
            throw new \RuntimeException('No admin user found for HTTP audit; seed first');
        }
        return $this->asUser($user);
    }

    public function asAnonymous(): self
    {
        $this->user = null;
        return $this;
    }

    /**
     * Make an HTTP call via Laravel's test kernel. Returns ['status' => int,
     * 'body' => string, 'json' => array|null, 'headers' => array].
     */
    public function call(string $method, string $uri, array $payload = [], array $headers = []): array
    {
        $server = $this->server;
        $server['HTTP_ACCEPT'] = 'application/json';
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }

        $request = Request::create(
            uri: $uri,
            method: strtoupper($method),
            parameters: $payload,
            cookies: $this->cookies,
            server: $server,
            content: null,
        );

        // Bind the user into the request via Auth::setUser (Laravel's test pattern)
        if ($this->user) {
            $request->setUserResolver(fn () => $this->user);
        } else {
            $request->setUserResolver(fn () => null);
        }

        // Handle the request via Laravel's HTTP kernel
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);

        $body = $response->getContent();
        $json = null;
        try {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) $json = $decoded;
        } catch (\Throwable $e) {
            // ignore
        }

        // Recycle kernel for next call
        $kernel->terminate($request, $response);

        return [
            'status'  => $response->getStatusCode(),
            'body'    => $body,
            'json'    => $json,
            'headers' => $response->headers->all(),
        ];
    }

    public function get(string $uri, array $payload = []): array
    {
        return $this->call('GET', $uri, $payload);
    }

    public function post(string $uri, array $payload = []): array
    {
        return $this->call('POST', $uri, $payload);
    }

    public function put(string $uri, array $payload = []): array
    {
        return $this->call('PUT', $uri, $payload);
    }

    public function patch(string $uri, array $payload = []): array
    {
        return $this->call('PATCH', $uri, $payload);
    }

    public function delete(string $uri, array $payload = []): array
    {
        return $this->call('DELETE', $uri, $payload);
    }
}
