<?php

declare(strict_types=1);

namespace Deadcode\Report;

use RuntimeException;

final class CollectorClient
{
    public function __construct(
        private readonly string $collectorUrl,
        private readonly string $exportToken,
    ) {
    }

    public function export(string $app): Export
    {
        $curl = curl_init(rtrim($this->collectorUrl, '/') . '/export?' . http_build_query(['app' => $app]));
        if ($curl === false) {
            throw new RuntimeException('Could not initialise curl.');
        }

        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $this->exportToken]);
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

        return Export::fromJson($body);
    }
}
