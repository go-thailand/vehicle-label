<?php

declare(strict_types=1);

final class VehicleLabelS3Client
{
    public function __construct(
        private readonly string $bucket,
        private readonly string $region,
        private readonly string $accessKey,
        private readonly string $secretKey
    ) {
    }

    public function listBatchPrefixes(string $basePrefix): array
    {
        $result = $this->listObjects([
            'prefix' => trim($basePrefix, '/') . '/',
            'delimiter' => '/',
        ]);

        $prefixes = $result['CommonPrefixes'] ?? [];
        if (isset($prefixes['Prefix'])) {
            $prefixes = [$prefixes];
        }

        $items = [];
        foreach ($prefixes as $prefix) {
            if (!empty($prefix['Prefix'])) {
                $items[] = (string) $prefix['Prefix'];
            }
        }

        sort($items);
        return $items;
    }

    public function listImages(string $prefix, int $maxKeys = 1000): array
    {
        $token = null;
        $items = [];

        do {
            $query = [
                'prefix' => trim($prefix, '/') . '/',
                'max-keys' => (string) min($maxKeys, 1000),
            ];
            if ($token) {
                $query['continuation-token'] = $token;
            }

            $result = $this->listObjects($query);
            $contents = $result['Contents'] ?? [];
            if (isset($contents['Key'])) {
                $contents = [$contents];
            }

            foreach ($contents as $row) {
                $key = (string) ($row['Key'] ?? '');
                if ($key !== '' && !str_ends_with($key, '/')) {
                    $items[] = $row;
                }
            }

            $token = (string) ($result['NextContinuationToken'] ?? '');
        } while ($token !== '' && count($items) < $maxKeys);

        return array_slice($items, 0, $maxKeys);
    }

    public function getPresignedUrl(string $key, int $expires = 900): string
    {
        $expires = max(60, min($expires, 604800));
        $host = sprintf('%s.s3.%s.amazonaws.com', $this->bucket, $this->region);
        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $scope = sprintf('%s/%s/s3/aws4_request', $date, $this->region);
        $canonicalUri = '/' . str_replace('%2F', '/', rawurlencode(ltrim($key, '/')));

        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->accessKey . '/' . $scope,
            'X-Amz-Date' => $timestamp,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($query);
        $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $canonicalRequest = implode("\n", [
            'GET',
            $canonicalUri,
            $canonicalQuery,
            'host:' . $host . "\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $timestamp,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $query['X-Amz-Signature'] = hash_hmac('sha256', $stringToSign, $kSigning);

        return sprintf('https://%s%s?%s', $host, $canonicalUri, http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function getObject(string $key): string
    {
        $host = sprintf('%s.s3.%s.amazonaws.com', $this->bucket, $this->region);
        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $scope = sprintf('%s/%s/s3/aws4_request', $date, $this->region);
        $canonicalUri = '/' . str_replace('%2F', '/', rawurlencode(ltrim($key, '/')));

        $canonicalRequest = implode("\n", [
            'GET',
            $canonicalUri,
            '',
            'host:' . $host . "\n" . 'x-amz-content-sha256:UNSIGNED-PAYLOAD' . "\n" . 'x-amz-date:' . $timestamp . "\n",
            'host;x-amz-content-sha256;x-amz-date',
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $timestamp,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=%s',
            $this->accessKey,
            $scope,
            $signature
        );

        $ch = curl_init(sprintf('https://%s%s', $host, $canonicalUri));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $authorization,
                'Host: ' . $host,
                'x-amz-content-sha256: UNSIGNED-PAYLOAD',
                'x-amz-date: ' . $timestamp,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new RuntimeException('Unable to connect to S3: ' . $error);
        }
        if ($status >= 400) {
            throw new RuntimeException(sprintf('S3 object request failed with HTTP %d', $status));
        }

        return (string) $body;
    }

    private function listObjects(array $query): array
    {
        $query['list-type'] = '2';
        ksort($query);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $host = sprintf('%s.s3.%s.amazonaws.com', $this->bucket, $this->region);
        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $scope = sprintf('%s/%s/s3/aws4_request', $date, $this->region);

        $canonicalRequest = implode("\n", [
            'GET',
            '/',
            $queryString,
            'host:' . $host . "\n" . 'x-amz-content-sha256:UNSIGNED-PAYLOAD' . "\n" . 'x-amz-date:' . $timestamp . "\n",
            'host;x-amz-content-sha256;x-amz-date',
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $timestamp,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=%s',
            $this->accessKey,
            $scope,
            $signature
        );

        $ch = curl_init(sprintf('https://%s/?%s', $host, $queryString));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $authorization,
                'Host: ' . $host,
                'x-amz-content-sha256: UNSIGNED-PAYLOAD',
                'x-amz-date: ' . $timestamp,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new RuntimeException('Unable to connect to S3: ' . $error);
        }
        if ($status >= 400) {
            throw new RuntimeException(sprintf('S3 request failed with HTTP %d', $status));
        }

        $xml = simplexml_load_string((string) $body);
        if ($xml === false) {
            throw new RuntimeException('Unable to parse S3 response.');
        }

        $decoded = json_decode(json_encode($xml), true);
        return is_array($decoded) ? $decoded : [];
    }
}
