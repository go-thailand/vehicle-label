<?php

declare(strict_types=1);

function envValue(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string) $value;
}

function getJsonInput(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        $cached = [];
        return $cached;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        jsonError('Invalid JSON body.', 400);
    }

    $cached = $decoded;
    return $cached;
}

function requestValue(string $key, mixed $default = null): mixed
{
    $body = getJsonInput();
    if (array_key_exists($key, $body)) {
        return $body[$key];
    }

    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function requiredRequestValue(string $key): mixed
{
    $value = requestValue($key);
    if ($value === null || $value === '') {
        jsonError(sprintf('Missing required parameter: %s', $key), 422);
    }
    return $value;
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError(string $message, int $status = 400, array $extra = []): void
{
    jsonResponse(array_merge([
        'ok' => false,
        'error' => $message,
    ], $extra), $status);
}

function csvResponse(string $filename, array $headers, array $rows): void
{
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $stream = fopen('php://output', 'wb');
    if ($stream === false) {
        throw new RuntimeException('Unable to open CSV output stream.');
    }

    fputcsv($stream, $headers);
    foreach ($rows as $row) {
        fputcsv($stream, $row);
    }

    fclose($stream);
    exit;
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException(sprintf('Unable to create directory: %s', $path));
    }
}

function nowUtc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function batchPrefix(string $batchId): string
{
    return 'unlabeled/' . trim($batchId, '/') . '/';
}

function filenameFromKey(string $key): string
{
    return basename($key);
}

function trackIdFromFilename(string $filename): string
{
    return pathinfo($filename, PATHINFO_FILENAME);
}

function segmentIdFromFilename(string $filename): ?int
{
    if (preg_match('/seg(\d+)/i', $filename, $matches) === 1) {
        return (int) $matches[1];
    }

    return null;
}

function normalizeWhitespace(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    return $value === '' ? null : $value;
}

function normalizeTokenLabel(?string $value): ?string
{
    $value = normalizeWhitespace($value);
    if ($value === null) {
        return null;
    }

    return strtolower(str_replace([' ', '-'], '_', $value));
}

function normalizeTitleLabel(?string $value): ?string
{
    $value = normalizeWhitespace($value);
    if ($value === null) {
        return null;
    }

    $known = [
        'toyota' => 'Toyota',
        'isuzu' => 'Isuzu',
        'honda' => 'Honda',
        'mitsubishi' => 'Mitsubishi',
        'nissan' => 'Nissan',
        'ford' => 'Ford',
        'mazda' => 'Mazda',
        'suzuki' => 'Suzuki',
        'byd' => 'BYD',
        'mg' => 'MG',
        'gwm' => 'GWM',
        'bmw' => 'BMW',
        'benz' => 'Benz',
        'mercedes' => 'Mercedes',
        'mercedes-benz' => 'Mercedes-Benz',
        'mercedes benz' => 'Mercedes-Benz',
    ];

    $lookup = strtolower($value);
    if (isset($known[$lookup])) {
        return $known[$lookup];
    }

    return preg_replace_callback('/[A-Za-z0-9]+/', static function (array $matches): string {
        $word = $matches[0];
        return strtoupper(substr($word, 0, 1)) . strtolower(substr($word, 1));
    }, $value) ?? $value;
}

function runtimePath(array $config, string $name): string
{
    $dir = (string) ($config['runtime']['dir'] ?? (__DIR__ . '/runtime'));
    ensureDirectory($dir);
    return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
}

function readRuntimeJson(array $config, string $name, array $fallback = []): array
{
    $path = runtimePath($config, $name);
    if (!is_file($path)) {
        return $fallback;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : $fallback;
}

function writeRuntimeJson(array $config, string $name, array $data): void
{
    $path = runtimePath($config, $name);
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function cacheRemember(array $config, string $name, int $ttlSeconds, callable $resolver): array
{
    $path = runtimePath($config, 'cache-' . $name . '.json');
    if (is_file($path) && (time() - filemtime($path)) <= $ttlSeconds) {
        $cached = json_decode((string) file_get_contents($path), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $data = $resolver();
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $data;
}
