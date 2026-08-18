<?php

declare(strict_types=1);

/**
 * stress_curl_multi.php
 *
 * Phase 25 — curl_multi concurrency driver.
 *
 * Fires N parallel HTTP requests against a target endpoint on the
 * dedicated stress server (http://127.0.0.1:18000 by default) and
 * captures per-request status, body, latency. Aggregate metrics:
 *   - requested concurrency
 *   - successful (HTTP 2xx) / rejected (4xx/5xx) counts
 *   - status code histogram
 *   - latency P50/P95/P99/max (seconds)
 *   - duplicate financial effects (post-run ledger check)
 *
 * Designed to be invoked by stress_hot_account.php / hot_debt.php /
 * hot_booking.php / concurrent_*.php. Not meant to be run directly
 * for production-like loads — those scripts compose payloads and
 * call into this driver.
 *
 * Usage:
 *   require_once 'stress_curl_multi.php';
 *   $metrics = StressCurlMulti::fire(
 *       'POST', '/api/v1/finance/transactions',
 *       $payloads, $token, 'http://127.0.0.1:18000'
 *   );
 */

final class StressCurlMulti
{
    /**
     * Fire N parallel HTTP requests via curl_multi.
     *
     * @param  string  $method    POST|PUT|PATCH|DELETE
     * @param  string  $path      e.g. /api/v1/finance/transactions
     * @param  array   $payloads  array of request body arrays
     * @param  string  $token     bearer token (sanctum)
     * @param  string  $baseUrl   e.g. http://127.0.0.1:18000
     * @param  int     $concurrency  max parallel connections
     * @return array{
     *   requested: int,
     *   successful: int,
     *   rejected: int,
     *   status_histogram: array<string,int>,
     *   latencies_ms: array<int>,
     *   p50: float, p95: float, p99: float, max: float,
     *   responses: array<int, array{status:int, body:string, latency_ms:float}>
     * }
     */
    public static function fire(
        string $method,
        string $path,
        array $payloads,
        string $token,
        string $baseUrl = 'http://127.0.0.1:18000',
        int $concurrency = 50
    ): array {
        $mh = curl_multi_init();
        $handles = [];
        $startTimes = [];

        foreach ($payloads as $i => $payload) {
            $ch = curl_init($baseUrl . $path);
            $headers = [
                "Authorization: Bearer {$token}",
                'Accept: application/json',
                'Content-Type: application/json',
            ];
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER        => $headers,
                CURLOPT_CUSTOMREQUEST     => strtoupper($method),
                CURLOPT_POSTFIELDS        => json_encode($payload),
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_TIMEOUT           => 60,
                CURLOPT_CONNECTTIMEOUT    => 10,
                CURLOPT_HEADER            => false,
                CURLOPT_NOSIGNAL          => 1,
            ]);
            $handles[$i] = $ch;
            $startTimes[$i] = microtime(true);
            curl_multi_add_handle($mh, $ch);
        }
        curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, $concurrency);
        curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, $concurrency);

        $active = null;
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 1.0);
            }
        } while ($active && $status === CURLM_OK);

        $latencies = [];
        $responses = [];
        $histogram = [];
        $successful = 0;
        $rejected = 0;
        foreach ($handles as $i => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $latency = (microtime(true) - $startTimes[$i]) * 1000.0;
            $latencies[] = $latency;
            $responses[$i] = ['status' => $code, 'body' => (string)$body, 'latency_ms' => round($latency, 2)];
            $histogram[(string) $code] = ($histogram[(string) $code] ?? 0) + 1;
            if ($code >= 200 && $code < 300) {
                $successful++;
            } else {
                $rejected++;
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        sort($latencies);
        $count = count($latencies);
        $p = function (float $q) use ($latencies, $count) {
            if ($count === 0) return 0.0;
            $idx = (int) min(max(0, floor($q * $count)), $count - 1);
            return $latencies[$idx];
        };

        return [
            'requested'        => count($payloads),
            'successful'       => $successful,
            'rejected'         => $rejected,
            'status_histogram' => $histogram,
            'latencies_ms'     => $latencies,
            'p50'              => round($p(0.50), 2),
            'p95'              => round($p(0.95), 2),
            'p99'              => round($p(0.99), 2),
            'max'              => round($latencies ? max($latencies) : 0.0, 2),
            'avg'              => round($count > 0 ? array_sum($latencies) / $count : 0.0, 2),
            'responses'        => $responses,
        ];
    }

    /**
     * Write a metrics report JSON to storage/app/stress/.
     */
    public static function writeArtifact(string $phase, string $name, array $metrics): string
    {
        $dir = storage_path('app/stress');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir.'/'.$phase.'-'.$name.'.json';
        // Strip the bulky per-response bodies for the artifact — keep summaries.
        $artifact = $metrics;
        unset($artifact['responses']);
        $artifact['responses_sample'] = array_slice($metrics['responses'] ?? [], 0, 5);
        file_put_contents($file, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $file;
    }
}
