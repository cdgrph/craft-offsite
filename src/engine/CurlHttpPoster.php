<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

final class CurlHttpPoster implements HttpPoster
{
    public function __construct(private readonly int $timeoutSeconds = 10)
    {
    }

    public function post(string $url, string $jsonBody): void
    {
        $this->request($url, $jsonBody);
    }

    public function get(string $url): void
    {
        $this->request($url, null);
    }

    private function request(string $url, ?string $jsonBody): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true, // TLS verification is mandatory (spec §5-5). No opt-out is provided
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        $result = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        // Slack webhook / heartbeat URLs carry a secret in the path, so exceptions
        // include the host only. This message is persisted to the DB via
        // sideEffects → summaryJson (Codex review B3)
        $host = parse_url($url, PHP_URL_HOST) ?: '(unknown host)';
        if ($result === false) {
            throw new \RuntimeException("HTTP transport error contacting {$host}: {$err}");
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("HTTP {$status} from {$host}");
        }
    }
}
