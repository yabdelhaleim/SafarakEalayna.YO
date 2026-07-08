<?php

namespace App\Services\System;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Service for toggling the application's maintenance mode without SSH.
 *
 * Wraps the `php artisan down` / `php artisan up` commands so they can be
 * triggered from the Filament admin UI. Persists toggle-on/down data via
 * Laravel's MaintenanceModeRepository under the hood (file driver by default).
 */
class MaintenanceModeService
{
    public function __construct(
        protected Application $app,
    ) {}

    public function isDown(): bool
    {
        try {
            return $this->app->isDownForMaintenance();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{ok: bool, message: string}
     */
    public function enable(array $options = []): array
    {
        if ($this->isDown()) {
            return [
                'ok' => false,
                'message' => 'النظام بالفعل في وضع الصيانة.',
            ];
        }

        $args = $this->buildDownArgs($options);

        try {
            $exit = Artisan::call('down', $args);
            if ($exit !== 0) {
                return [
                    'ok' => false,
                    'message' => 'فشل تفعيل وضع الصيانة (exit code '.$exit.').',
                ];
            }

            return [
                'ok' => true,
                'message' => 'تم تفعيل وضع الصيانة بنجاح.',
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => 'حدث خطأ: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function disable(): array
    {
        if (! $this->isDown()) {
            return [
                'ok' => false,
                'message' => 'النظام ليس في وضع الصيانة بالفعل.',
            ];
        }

        try {
            $exit = Artisan::call('up');
            if ($exit !== 0) {
                return [
                    'ok' => false,
                    'message' => 'فشل إيقاف وضع الصيانة (exit code '.$exit.').',
                ];
            }

            return [
                'ok' => true,
                'message' => 'تم إيقاف وضع الصيانة بنجاح.',
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => 'حدث خطأ: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array{
     *   is_down: bool,
     *   down_at: ?string,
     *   secret: ?string,
     *   retry_after: ?int,
     *   redirect_url: ?string,
     *   bypass_url: ?string
     * }
     */
    public function status(): array
    {
        $isDown = $this->isDown();
        $payload = $isDown ? $this->readDownPayload() : null;

        $appUrl = rtrim((string) config('app.url'), '/');
        $bypassUrl = null;
        if ($isDown && ! empty($payload['secret'])) {
            $bypassUrl = $appUrl.'/'.ltrim((string) $payload['secret'], '/');
        }

        return [
            'is_down' => $isDown,
            'down_at' => $payload['time'] ?? null,
            'secret' => $payload['secret'] ?? null,
            'retry_after' => isset($payload['retry']) ? (int) $payload['retry'] : null,
            'redirect_url' => $payload['redirect'] ?? null,
            'bypass_url' => $bypassUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    protected function buildDownArgs(array $options): array
    {
        $args = [];

        $secret = trim((string) ($options['secret'] ?? ''));
        if ($secret !== '') {
            $args['--secret'] = $secret;
        }

        if (! empty($options['retry'])) {
            $args['--retry'] = (string) (int) $options['retry'];
        }

        if (! empty($options['redirect'])) {
            $args['--redirect'] = (string) $options['redirect'];
        }

        $render = trim((string) ($options['render'] ?? ''));
        if ($render !== '') {
            $args['--render'] = $render;
        }

        $status = (int) ($options['status'] ?? 503);
        $args['--status'] = (string) ($status >= 100 && $status <= 599 ? $status : 503);

        if (! empty($options['refresh'])) {
            $args['--refresh'] = (string) (int) $options['refresh'];
        }

        $allows = $options['allow'] ?? [];
        if (is_array($allows)) {
            foreach ($allows as $ip) {
                $ip = trim((string) $ip);
                if ($ip !== '') {
                    $args['--allow'][] = $ip;
                }
            }
        }

        return $args;
    }

    /**
     * @return array<string, mixed>
     */
    protected function readDownPayload(): array
    {
        $file = $this->downFilePath();
        if (! is_file($file)) {
            return [];
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    protected function downFilePath(): string
    {
        return $this->app->storagePath('framework/down');
    }
}