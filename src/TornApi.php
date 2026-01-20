<?php
declare(strict_types=1);

final class TornApi
{
    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://api.torn.com/v2'
    ) {}

    public function get(string $path, array $query = []): array
    {
        $query['key'] = $this->apiKey;
        $url = $this->baseUrl . $path . '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FAILONERROR => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("cURL error: {$err}");
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Bad JSON (HTTP {$status}): " . substr($raw, 0, 200));
        }

        if ($status >= 400) {
            // Torn errors often come back as JSON; show useful bits if present
            $msg = $data['message'] ?? $data['error'] ?? ('HTTP ' . $status);
            throw new RuntimeException("API error: {$msg}");
        }

        return $data;
    }
}
