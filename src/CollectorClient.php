<?php

declare(strict_types=1);

namespace Deadcode\Report;

use RuntimeException;

final class CollectorClient
{
    public function __construct(
        private readonly string $collectorUrl,
        private readonly string $token,
    ) {
    }

    /**
     * @return array<string, array{hits: int, last_seen: string}> total hits and last-seen day by path
     */
    public function export(string $app): array
    {
        $curl = curl_init(rtrim($this->collectorUrl, '/') . '/export?' . http_build_query(['app' => $app]));
        if ($curl === false) {
            throw new RuntimeException('Could not initialise curl.');
        }

        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $this->token]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);

        $body = curl_exec($curl);
        if (!is_string($body)) {
            throw new RuntimeException('Could not reach the collector: ' . curl_error($curl));
        }

        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($status !== 200) {
            throw new RuntimeException(sprintf('The collector answered the export request with HTTP %d.', $status));
        }

        $export = json_decode($body, true);
        if (!is_array($export) || !is_array($export['files'] ?? null)) {
            throw new RuntimeException('The collector returned an export this report does not understand.');
        }

        $files = [];
        foreach ($export['files'] as $path => $file) {
            if (!is_array($file) || !is_int($file['hits'] ?? null) || !is_string($file['last_seen'] ?? null)) {
                throw new RuntimeException('The collector returned an export this report does not understand.');
            }
            $files[(string) $path] = ['hits' => $file['hits'], 'last_seen' => $file['last_seen']];
        }

        return $files;
    }
}
