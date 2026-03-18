<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/s3-client.php';

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    jsonError('Missing config.php. Copy config.example.php to config.php or use env vars.', 500);
}

$config = require $configFile;

function getPDO(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = $config['db'] ?? [];
    $pdo = new PDO(
        (string) ($db['dsn'] ?? ''),
        (string) ($db['username'] ?? ''),
        (string) ($db['password'] ?? ''),
        $db['options'] ?? []
    );

    return $pdo;
}

function getS3(array $config): VehicleLabelS3Client
{
    static $client = null;
    if ($client instanceof VehicleLabelS3Client) {
        return $client;
    }

    $s3 = $config['s3'] ?? [];
    $client = new VehicleLabelS3Client(
        (string) ($s3['bucket'] ?? ''),
        (string) ($s3['region'] ?? ''),
        (string) ($s3['access_key'] ?? ''),
        (string) ($s3['secret_key'] ?? '')
    );

    return $client;
}

function labelTable(array $config): string
{
    return (string) ($config['labels']['table'] ?? 'qms_vehicle_labels');
}

function normalizedReviewRow(array $row): array
{
    $row['vehicle_type'] = normalizeTokenLabel($row['vehicle_type'] ?? null);
    $row['dominant_color'] = normalizeTokenLabel($row['dominant_color'] ?? null);
    $row['vehicle_make'] = normalizeTitleLabel($row['vehicle_make'] ?? null);
    $row['vehicle_model'] = normalizeTitleLabel($row['vehicle_model'] ?? null);
    $row['ai_vehicle_type'] = normalizeTokenLabel($row['ai_vehicle_type'] ?? null);
    $row['ai_color'] = normalizeTokenLabel($row['ai_color'] ?? null);
    $row['ai_make'] = normalizeTitleLabel($row['ai_make'] ?? null);
    return $row;
}

function getLabelLookup(array $config, array $filenames = []): array
{
    if ($filenames === []) {
        return [];
    }

    $pdo = getPDO($config);
    $table = labelTable($config);
    $lookup = [];
    $filenames = array_values(array_unique(array_filter(array_map('strval', $filenames))));

    foreach (array_chunk($filenames, 500) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
        $stmt = $pdo->prepare(sprintf(
            'SELECT filename, segment_id, camera_index, batch_id, vehicle_type, dominant_color, vehicle_make, vehicle_model, quality, flagged, labeled_by, labeled_at, ai_vehicle_type, ai_color, ai_make, ai_confidence, updated_at
             FROM %s
             WHERE filename IN (%s)',
            $table,
            $placeholders
        ));
        $stmt->execute($chunk);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $row = normalizedReviewRow($row);
            $filename = (string) $row['filename'];
            $trackId = trackIdFromFilename($filename);
            $row['track_id'] = $trackId;
            $row['batch_prefix'] = batchPrefix((string) $row['batch_id']);
            $row['image_key'] = $row['batch_prefix'] . $filename;
            $lookup[$filename] = $row;
        }
    }

    return $lookup;
}

function getBatchCatalog(array $config): array
{
    $ttl = (int) ($config['runtime']['cache_ttl'] ?? 300);
    $basePrefix = (string) ($config['s3']['base_prefix'] ?? 'unlabeled/');

    return cacheRemember($config, 'batch-catalog', $ttl, static function () use ($config, $basePrefix): array {
        $prefixes = getS3($config)->listBatchPrefixes($basePrefix);
        $catalog = [];

        foreach ($prefixes as $prefix) {
            $batchId = basename(trim($prefix, '/'));
            $images = getS3($config)->listImages($prefix, 5000);
            $catalog[] = [
                'id' => $batchId,
                'batch_id' => $batchId,
                'prefix' => $prefix,
                'object_count' => count($images),
                'image_count' => count($images),
            ];
        }

        usort($catalog, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        return $catalog;
    });
}

function getBatchStats(array $config): array
{
    $pdo = getPDO($config);
    $table = labelTable($config);
    $rows = $pdo->query(sprintf(
        'SELECT batch_id,
                SUM(CASE WHEN labeled_by IS NOT NULL AND labeled_by <> "" THEN 1 ELSE 0 END) AS labeled_count,
                SUM(CASE WHEN flagged = 1 THEN 1 ELSE 0 END) AS flagged_count
         FROM %s
         GROUP BY batch_id',
        $table
    ))->fetchAll();

    $stats = [];
    foreach ($rows as $row) {
        $stats[(string) $row['batch_id']] = [
            'labeled_count' => (int) ($row['labeled_count'] ?? 0),
            'flagged_count' => (int) ($row['flagged_count'] ?? 0),
        ];
    }

    return $stats;
}

function attachBatchStats(array $catalog, array $stats): array
{
    return array_map(static function (array $batch) use ($stats): array {
        $current = $stats[$batch['id']] ?? ['labeled_count' => 0, 'flagged_count' => 0];
        return array_merge($batch, $current, [
            'batch_id' => $batch['id'],
            'image_count' => $batch['object_count'],
        ]);
    }, $catalog);
}

function getManifestLookup(array $config, array $filenames = []): array
{
    if ($filenames === []) {
        return [];
    }

    $manifestKey = (string) ($config['s3']['manifest_key'] ?? 'manifest.csv');
    $targets = array_fill_keys(array_values(array_unique(array_filter(array_map('strval', $filenames)))), true);
    $lookup = [];

    $csv = getS3($config)->getObject($manifestKey);
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        throw new RuntimeException('Unable to open manifest stream.');
    }

    fwrite($stream, $csv);
    rewind($stream);

    $header = fgetcsv($stream, 0, ",", "\"", "");
    if (!is_array($header)) {
        fclose($stream);
        return [];
    }

    while (($row = fgetcsv($stream, 0, ",", "\"", "")) !== false) {
        $assoc = [];
        foreach ($header as $index => $column) {
            $assoc[(string) $column] = $row[$index] ?? null;
        }

        $filename = trim((string) ($assoc['filename'] ?? ''));
        if ($filename === '' || !isset($targets[$filename])) {
            continue;
        }

        $lookup[$filename] = [
            'segment_id' => isset($assoc['segment_id']) && $assoc['segment_id'] !== '' ? (int) $assoc['segment_id'] : null,
            'camera_index' => isset($assoc['camera_index']) && $assoc['camera_index'] !== '' ? (int) $assoc['camera_index'] : null,
            'ai_vehicle_type' => normalizeTokenLabel($assoc['vehicle_type'] ?? null),
            'ai_color' => normalizeTokenLabel($assoc['dominant_color'] ?? null),
            'ai_make' => normalizeTitleLabel($assoc['vehicle_make'] ?? null),
            'ai_confidence' => $assoc['vehicle_type_confidence'] ?? null,
        ];

        if (count($lookup) === count($targets)) {
            break;
        }
    }

    fclose($stream);
    return $lookup;
}

function normalizeImage(array $s3Row, array $reviewLookup, array $manifestLookup, array $config): array
{
    $key = (string) $s3Row['Key'];
    $filename = filenameFromKey($key);
    $trackId = trackIdFromFilename($filename);
    $batchId = basename(dirname($key));
    $review = $reviewLookup[$filename] ?? [];
    $manifest = $manifestLookup[$filename] ?? [];
    $review = array_merge($manifest, $review);
    $review = normalizedReviewRow($review);
    $isLabeled = trim((string) ($review['labeled_by'] ?? '')) !== '';
    $isFlagged = !empty($review['flagged']);

    return [
        'track_id' => $trackId,
        'filename' => $filename,
        'segment_id' => $review['segment_id'] ?? segmentIdFromFilename($filename),
        'camera_index' => $review['camera_index'] ?? null,
        'batch_id' => $batchId,
        'batch_prefix' => batchPrefix($batchId),
        'image_key' => $key,
        'size_bytes' => (int) ($s3Row['Size'] ?? 0),
        'last_modified' => (string) ($s3Row['LastModified'] ?? ''),
        'image_url' => getS3($config)->getPresignedUrl($key, (int) ($config['s3']['url_ttl'] ?? 300)),
        's3_url' => getS3($config)->getPresignedUrl($key, (int) ($config['s3']['url_ttl'] ?? 300)),
        'status' => $isFlagged ? 'flagged' : ($isLabeled ? 'labeled' : 'unlabeled'),
        'review' => $review,
        'type' => $review['vehicle_type'] ?? null,
    ];
}

function applyImageFilters(array $images, ?string $statusFilter, ?string $typeFilter, ?string $aiTypeFilter = null, ?string $search = null): array
{
    $normalizedSearch = strtolower(trim((string) $search));

    return array_values(array_filter($images, static function (array $image) use ($statusFilter, $typeFilter, $aiTypeFilter, $normalizedSearch): bool {
        if ($statusFilter !== null && $statusFilter !== '' && $statusFilter !== 'all' && $image['status'] !== $statusFilter) {
            return false;
        }

        if ($typeFilter !== null && $typeFilter !== '' && $typeFilter !== 'all') {
            $imageType = normalizeTokenLabel($image['review']['vehicle_type'] ?? $image['type'] ?? null);
            if ($imageType !== normalizeTokenLabel($typeFilter)) {
                return false;
            }
        }

        if ($aiTypeFilter !== null && $aiTypeFilter !== '' && $aiTypeFilter !== 'all') {
            $imageAiType = normalizeTokenLabel($image['review']['ai_vehicle_type'] ?? null);
            if ($imageAiType !== normalizeTokenLabel($aiTypeFilter)) {
                return false;
            }
        }

        if ($normalizedSearch !== '') {
            $haystack = strtolower(trim(sprintf(
                '%s %s %s',
                (string) ($image['filename'] ?? ''),
                (string) ($image['track_id'] ?? ''),
                (string) ($image['review']['vehicle_model'] ?? '')
            )));
            if (!str_contains($haystack, $normalizedSearch)) {
                return false;
            }
        }

        return true;
    }));
}

function paginateItems(array $items, int $page, int $perPage): array
{
    $total = count($items);
    $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;

    return [
        'items' => array_slice($items, $offset, $perPage),
        'meta' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ],
    ];
}

function findLabelByFilename(PDO $pdo, string $table, string $filename): array
{
    $lookup = $pdo->prepare(sprintf('SELECT id, filename, flagged, labeled_by, labeled_at FROM %s WHERE filename = :filename LIMIT 1', $table));
    $lookup->execute(['filename' => $filename]);
    $row = $lookup->fetch();
    if (!$row) {
        jsonError('Label row not found for filename.', 404, ['filename' => $filename]);
    }

    return $row;
}

function saveLabel(array $config, array $payload): array
{
    $pdo = getPDO($config);
    $table = labelTable($config);
    $filename = (string) ($payload['filename'] ?? filenameFromKey((string) ($payload['image_key'] ?? '')));

    $record = [
        'filename' => $filename,
        'vehicle_type' => normalizeTokenLabel($payload['vehicle_type'] ?? null),
        'dominant_color' => normalizeTokenLabel($payload['dominant_color'] ?? null),
        'vehicle_make' => normalizeTitleLabel($payload['vehicle_make'] ?? null),
        'vehicle_model' => normalizeTitleLabel($payload['vehicle_model'] ?? null),
        'quality' => normalizeTokenLabel($payload['quality'] ?? 'good') ?? 'good',
        'flagged' => !empty($payload['flagged']) ? 1 : 0,
        'labeled_by' => normalizeWhitespace($payload['labeled_by'] ?? null),
        'labeled_at' => nowUtc(),
        'ai_vehicle_type' => normalizeTokenLabel($payload['ai_vehicle_type'] ?? null),
        'ai_color' => normalizeTokenLabel($payload['ai_color'] ?? null),
        'ai_make' => normalizeTitleLabel($payload['ai_make'] ?? null),
        'ai_confidence' => $payload['ai_confidence'] ?? null,
    ];

    $sql = sprintf(
        'UPDATE %s SET
            vehicle_type = :vehicle_type,
            dominant_color = :dominant_color,
            vehicle_make = :vehicle_make,
            vehicle_model = :vehicle_model,
            quality = :quality,
            flagged = :flagged,
            labeled_by = :labeled_by,
            labeled_at = :labeled_at,
            ai_vehicle_type = COALESCE(:ai_vehicle_type, ai_vehicle_type),
            ai_color = COALESCE(:ai_color, ai_color),
            ai_make = COALESCE(:ai_make, ai_make),
            ai_confidence = COALESCE(:ai_confidence, ai_confidence),
            updated_at = CURRENT_TIMESTAMP
         WHERE filename = :filename',
        $table
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute($record);

    return findLabelByFilename($pdo, $table, $filename);
}

function flagImage(array $config, array $payload): array
{
    $pdo = getPDO($config);
    $table = labelTable($config);
    $filename = (string) ($payload['filename'] ?? filenameFromKey((string) ($payload['image_key'] ?? '')));
    if ($filename === '') {
        jsonError('Missing required parameter: filename', 422);
    }
    $flagged = !empty($payload['flagged']) ? 1 : 0;

    $stmt = $pdo->prepare(sprintf(
        'UPDATE %s SET flagged = :flagged, updated_at = CURRENT_TIMESTAMP WHERE filename = :filename',
        $table
    ));
    $stmt->execute([
        'flagged' => $flagged,
        'filename' => $filename,
    ]);

    return findLabelByFilename($pdo, $table, $filename);
}

function normalizedDistribution(array $rows, bool $titleCase = false): array
{
    $counts = [];
    foreach ($rows as $row) {
        $label = $titleCase
            ? normalizeTitleLabel($row['label'] ?? null)
            : normalizeTokenLabel($row['label'] ?? null);
        if ($label === null) {
            continue;
        }
        $counts[$label] = ($counts[$label] ?? 0) + (int) ($row['count'] ?? 0);
    }

    arsort($counts);
    $result = [];
    foreach ($counts as $label => $count) {
        $result[] = ['label' => $label, 'count' => $count];
    }
    return $result;
}

function estimatedSecondsPerImage(PDO $pdo, string $table, int $fallbackSeconds = 5): int
{
    $row = $pdo->query(sprintf(
        'SELECT COUNT(*) AS total,
                MIN(labeled_at) AS first_labeled_at,
                MAX(labeled_at) AS last_labeled_at
         FROM %s
         WHERE labeled_by IS NOT NULL AND labeled_by <> "" AND labeled_at IS NOT NULL',
        $table
    ))->fetch() ?: [];

    $total = (int) ($row['total'] ?? 0);
    $first = $row['first_labeled_at'] ?? null;
    $last = $row['last_labeled_at'] ?? null;
    if ($total < 2 || !$first || !$last) {
        return $fallbackSeconds;
    }

    $elapsed = strtotime((string) $last) - strtotime((string) $first);
    if ($elapsed <= 0) {
        return $fallbackSeconds;
    }

    return max(1, min(60, (int) round($elapsed / max(1, $total - 1))));
}

function statsDistribution(PDO $pdo, string $table): array
{
    $types = $pdo->query(sprintf(
        'SELECT vehicle_type AS label, COUNT(*) AS count
         FROM %s
         WHERE labeled_by IS NOT NULL AND labeled_by <> "" AND vehicle_type IS NOT NULL AND vehicle_type <> ""
         GROUP BY vehicle_type
         ORDER BY count DESC',
        $table
    ))->fetchAll() ?: [];

    $colors = $pdo->query(sprintf(
        'SELECT dominant_color AS label, COUNT(*) AS count
         FROM %s
         WHERE labeled_by IS NOT NULL AND labeled_by <> "" AND dominant_color IS NOT NULL AND dominant_color <> ""
         GROUP BY dominant_color
         ORDER BY count DESC',
        $table
    ))->fetchAll() ?: [];

    $qualities = $pdo->query(sprintf(
        'SELECT quality AS label, COUNT(*) AS count
         FROM %s
         WHERE labeled_by IS NOT NULL AND labeled_by <> "" AND quality IS NOT NULL AND quality <> ""
         GROUP BY quality
         ORDER BY count DESC',
        $table
    ))->fetchAll() ?: [];

    $makes = $pdo->query(sprintf(
        'SELECT vehicle_make AS label, COUNT(*) AS count
         FROM %s
         WHERE labeled_by IS NOT NULL AND labeled_by <> "" AND vehicle_make IS NOT NULL AND vehicle_make <> ""
         GROUP BY vehicle_make
         ORDER BY count DESC, vehicle_make ASC
         LIMIT 50',
        $table
    ))->fetchAll() ?: [];

    $models = $pdo->query(sprintf(
        'SELECT vehicle_model AS label, COUNT(*) AS count
         FROM %s
         WHERE labeled_by IS NOT NULL AND labeled_by <> "" AND vehicle_model IS NOT NULL AND vehicle_model <> ""
         GROUP BY vehicle_model
         ORDER BY count DESC, vehicle_model ASC
         LIMIT 50',
        $table
    ))->fetchAll() ?: [];

    return [
        'type_distribution' => normalizedDistribution($types, false),
        'color_distribution' => normalizedDistribution($colors, false),
        'quality_distribution' => normalizedDistribution($qualities, false),
        'make_distribution' => normalizedDistribution($makes, true),
        'model_distribution' => normalizedDistribution($models, true),
    ];
}

function statsLeaderboard(PDO $pdo, string $table): array
{
    $allTime = $pdo->query(sprintf(
        'SELECT labeled_by, COUNT(*) AS total
         FROM %s
         WHERE labeled_by IS NOT NULL AND labeled_by <> ""
         GROUP BY labeled_by
         ORDER BY total DESC, labeled_by ASC
         LIMIT 20',
        $table
    ))->fetchAll() ?: [];

    $today = $pdo->query(sprintf(
        'SELECT labeled_by, COUNT(*) AS total
         FROM %s
         WHERE labeled_by IS NOT NULL AND labeled_by <> ""
           AND labeled_at IS NOT NULL
           AND DATE(CONVERT_TZ(labeled_at, "+00:00", "+07:00")) = DATE(CONVERT_TZ(UTC_TIMESTAMP(), "+00:00", "+07:00"))
         GROUP BY labeled_by
         ORDER BY total DESC, labeled_by ASC
         LIMIT 20',
        $table
    ))->fetchAll() ?: [];

    return [
        'today' => $today,
        'all_time' => $allTime,
    ];
}

function statsSummary(PDO $pdo, string $table, ?array $catalog = null): array
{
    $summary = $pdo->query(sprintf(
        'SELECT
            COUNT(*) AS total_images,
            SUM(CASE WHEN labeled_by IS NOT NULL AND labeled_by <> "" THEN 1 ELSE 0 END) AS labeled_count,
            SUM(CASE WHEN flagged = 1 THEN 1 ELSE 0 END) AS flagged_count
         FROM %s',
        $table
    ))->fetch() ?: [];

    $totalImages = $catalog !== null
        ? array_sum(array_column($catalog, 'object_count'))
        : (int) ($summary['total_images'] ?? 0);
    $labeledCount = (int) ($summary['labeled_count'] ?? 0);
    $remainingCount = max(0, $totalImages - $labeledCount);
    $avgSecondsPerImage = estimatedSecondsPerImage($pdo, $table, 5);
    $estimatedRemainingSeconds = $remainingCount * $avgSecondsPerImage;

    return [
        'storage' => 'database',
        'total_images' => $totalImages,
        'labeled_count' => $labeledCount,
        'remaining_count' => $remainingCount,
        'flagged_count' => (int) ($summary['flagged_count'] ?? 0),
        'percentage_complete' => $totalImages > 0 ? round(($labeledCount / $totalImages) * 100, 2) : 0.0,
        'avg_seconds_per_image' => $avgSecondsPerImage,
        'estimated_time_remaining_seconds' => $estimatedRemainingSeconds,
        'estimated_time_remaining_hours' => round($estimatedRemainingSeconds / 3600, 2),
        'labeled' => $labeledCount,
        'remaining' => $remainingCount,
        'flagged' => (int) ($summary['flagged_count'] ?? 0),
    ];
}

function statsPayload(array $config): array
{
    $pdo = getPDO($config);
    $table = labelTable($config);
    $catalog = attachBatchStats(getBatchCatalog($config), getBatchStats($config));
    $summary = statsSummary($pdo, $table, $catalog);
    $distribution = statsDistribution($pdo, $table);
    $leaderboard = statsLeaderboard($pdo, $table);

    return array_merge($summary, $distribution, [
        'labelers' => $leaderboard['all_time'],
        'leaderboard' => $leaderboard,
        'batches' => $catalog,
    ]);
}

function exportLabelsRows(PDO $pdo, string $table): array
{
    return $pdo->query(sprintf(
        'SELECT id, filename, segment_id, camera_index, batch_id, vehicle_type, dominant_color, vehicle_make, vehicle_model, quality, flagged, labeled_by, labeled_at, ai_vehicle_type, ai_color, ai_make, ai_confidence, created_at, updated_at
         FROM %s
         WHERE labeled_by IS NOT NULL AND labeled_by <> ""
         ORDER BY labeled_at DESC, id DESC',
        $table
    ))->fetchAll() ?: [];
}

function exportStatsData(array $config): array
{
    $pdo = getPDO($config);
    $table = labelTable($config);

    return [
        'generated_at' => nowUtc(),
        'summary' => statsSummary($pdo, $table),
        'distribution' => statsDistribution($pdo, $table),
        'leaderboard' => statsLeaderboard($pdo, $table),
    ];
}

function exportStatsCsvRows(array $stats): array
{
    $rows = [];

    foreach (($stats['summary'] ?? []) as $metric => $value) {
        if (is_array($value)) {
            continue;
        }
        $rows[] = ['summary', (string) $metric, '', (string) $value];
    }

    foreach ((($stats['leaderboard'] ?? [])['today'] ?? []) as $entry) {
        $rows[] = ['leaderboard_today', 'labeled_images', (string) ($entry['labeled_by'] ?? ''), (string) ($entry['total'] ?? 0)];
    }

    foreach ((($stats['leaderboard'] ?? [])['all_time'] ?? []) as $entry) {
        $rows[] = ['leaderboard_all_time', 'labeled_images', (string) ($entry['labeled_by'] ?? ''), (string) ($entry['total'] ?? 0)];
    }

    foreach (($stats['distribution'] ?? []) as $section => $items) {
        if (!is_array($items)) {
            continue;
        }
        foreach ($items as $item) {
            $rows[] = [$section, 'count', (string) ($item['label'] ?? ''), (string) ($item['count'] ?? 0)];
        }
    }

    return $rows;
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
if ($action === '') {
    jsonError('Missing action parameter.', 400, ['supported_actions' => ['list-batches', 'list-images', 'save-label', 'flag-image', 'stats', 'get-image', 'export-labels', 'export-stats']]);
}

try {
    switch ($action) {
        case 'list-batches':
            $page = max(1, (int) requestValue('page', 1));
            $perPage = max(1, min(100, (int) requestValue('per_page', 50)));
            $allBatches = attachBatchStats(getBatchCatalog($config), getBatchStats($config));
            $pagination = paginateItems($allBatches, $page, $perPage);
            jsonResponse([
                'ok' => true,
                'batches' => $pagination['items'],
                'page' => $pagination['meta']['page'],
                'per_page' => $pagination['meta']['per_page'],
                'total' => $pagination['meta']['total'],
                'total_pages' => $pagination['meta']['total_pages'],
            ]);

        case 'list-images':
            $batchId = (string) requiredRequestValue('batch');
            $page = max(1, (int) requestValue('page', 1));
            $perPage = max(1, min(200, (int) requestValue('per_page', 60)));
            $status = normalizeTokenLabel((string) requestValue('status', ''));
            $type = normalizeTokenLabel((string) requestValue('type', ''));
            $aiType = normalizeTokenLabel((string) requestValue('ai_type', ''));
            $search = normalizeWhitespace((string) requestValue('search', ''));
            $prefix = batchPrefix($batchId);
            $s3Images = getS3($config)->listImages($prefix, 5000);
            $filenames = array_map(static fn (array $row): string => filenameFromKey((string) ($row['Key'] ?? '')), $s3Images);
            $reviewLookup = getLabelLookup($config, $filenames);
            $manifestLookup = getManifestLookup($config, $filenames);
            $allImages = array_map(
                static fn (array $row): array => normalizeImage($row, $reviewLookup, $manifestLookup, $config),
                $s3Images
            );
            $filteredImages = applyImageFilters($allImages, $status, $type, $aiType, $search);
            $pagination = paginateItems($filteredImages, $page, $perPage);
            jsonResponse([
                'ok' => true,
                'batch' => $batchId,
                'batch_id' => $batchId,
                'prefix' => $prefix,
                'filters' => [
                    'status' => $status ?: 'all',
                    'type' => $type ?: 'all',
                    'ai_type' => $aiType ?: 'all',
                    'search' => $search ?? '',
                ],
                'page' => $pagination['meta']['page'],
                'per_page' => $pagination['meta']['per_page'],
                'total' => $pagination['meta']['total'],
                'total_pages' => $pagination['meta']['total_pages'],
                'images' => $pagination['items'],
            ]);

        case 'get-image':
            $key = (string) requestValue('key', '');
            if ($key === '') {
                $batchId = (string) requiredRequestValue('batch');
                $filename = (string) requiredRequestValue('filename');
                $key = batchPrefix($batchId) . $filename;
            }
            jsonResponse([
                'ok' => true,
                'key' => $key,
                'ttl_seconds' => (int) ($config['s3']['url_ttl'] ?? 300),
                'url' => getS3($config)->getPresignedUrl($key, (int) ($config['s3']['url_ttl'] ?? 300)),
            ]);

        case 'save-label':
            $payload = getJsonInput();
            if ($payload === []) {
                $payload = $_POST;
            }
            $saved = saveLabel($config, $payload);
            jsonResponse([
                'ok' => true,
                'storage' => 'database',
                'id' => isset($saved['id']) ? (int) $saved['id'] : null,
                'filename' => $saved['filename'] ?? ($payload['filename'] ?? filenameFromKey((string) ($payload['image_key'] ?? ''))),
                'flagged' => isset($saved['flagged']) ? (int) $saved['flagged'] : null,
                'labeled_by' => $saved['labeled_by'] ?? null,
                'labeled_at' => $saved['labeled_at'] ?? null,
            ]);

        case 'flag-image':
            $payload = getJsonInput();
            if ($payload === []) {
                $payload = $_POST;
            }
            $flagged = flagImage($config, $payload);
            jsonResponse([
                'ok' => true,
                'storage' => 'database',
                'id' => isset($flagged['id']) ? (int) $flagged['id'] : null,
                'filename' => $flagged['filename'] ?? null,
                'flagged' => isset($flagged['flagged']) ? (int) $flagged['flagged'] : null,
                'labeled_by' => $flagged['labeled_by'] ?? null,
                'labeled_at' => $flagged['labeled_at'] ?? null,
            ]);

        case 'stats':
            $statsType = normalizeTokenLabel((string) requestValue('type', 'full')) ?: 'full';
            $pdo = getPDO($config);
            $table = labelTable($config);

            if ($statsType === 'distribution') {
                jsonResponse([
                    'ok' => true,
                    'type' => 'distribution',
                    'stats' => statsDistribution($pdo, $table),
                ]);
            }

            if ($statsType === 'leaderboard') {
                jsonResponse([
                    'ok' => true,
                    'type' => 'leaderboard',
                    'stats' => statsLeaderboard($pdo, $table),
                ]);
            }

            if ($statsType === 'summary') {
                jsonResponse([
                    'ok' => true,
                    'type' => 'summary',
                    'stats' => statsSummary($pdo, $table),
                ]);
            }

            jsonResponse([
                'ok' => true,
                'type' => 'full',
                'stats' => statsPayload($config),
            ]);

        case 'export-labels':
            $pdo = getPDO($config);
            $table = labelTable($config);
            $rows = array_map(static fn (array $row): array => normalizedReviewRow($row), exportLabelsRows($pdo, $table));
            csvResponse(
                'vehicle-labels-' . gmdate('Ymd-His') . '.csv',
                ['id', 'filename', 'segment_id', 'camera_index', 'batch_id', 'vehicle_type', 'dominant_color', 'vehicle_make', 'vehicle_model', 'quality', 'flagged', 'labeled_by', 'labeled_at', 'ai_vehicle_type', 'ai_color', 'ai_make', 'ai_confidence', 'created_at', 'updated_at'],
                array_map(static fn (array $row): array => [
                    $row['id'] ?? '',
                    $row['filename'] ?? '',
                    $row['segment_id'] ?? '',
                    $row['camera_index'] ?? '',
                    $row['batch_id'] ?? '',
                    $row['vehicle_type'] ?? '',
                    $row['dominant_color'] ?? '',
                    $row['vehicle_make'] ?? '',
                    $row['vehicle_model'] ?? '',
                    $row['quality'] ?? '',
                    (int) ($row['flagged'] ?? 0),
                    $row['labeled_by'] ?? '',
                    $row['labeled_at'] ?? '',
                    $row['ai_vehicle_type'] ?? '',
                    $row['ai_color'] ?? '',
                    $row['ai_make'] ?? '',
                    $row['ai_confidence'] ?? '',
                    $row['created_at'] ?? '',
                    $row['updated_at'] ?? '',
                ], $rows)
            );

        case 'export-stats':
            $format = normalizeTokenLabel((string) requestValue('format', 'json')) ?: 'json';
            $stats = exportStatsData($config);
            if ($format === 'csv') {
                csvResponse(
                    'vehicle-label-stats-' . gmdate('Ymd-His') . '.csv',
                    ['section', 'metric', 'label', 'value'],
                    exportStatsCsvRows($stats)
                );
            }
            jsonResponse([
                'ok' => true,
                'type' => 'export-stats',
                'stats' => $stats,
            ]);

        default:
            jsonError('Unsupported action.', 404, ['supported_actions' => ['list-batches', 'list-images', 'save-label', 'flag-image', 'stats', 'get-image', 'export-labels', 'export-stats']]);
    }
} catch (Throwable $exception) {
    jsonError($exception->getMessage(), 500, ['action' => $action]);
}