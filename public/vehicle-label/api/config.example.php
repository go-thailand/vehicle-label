<?php

declare(strict_types=1);

return [
    'db' => [
        'dsn' => envValue('NT_TAG_ID_DB_DSN', 'mysql:host=gothdb-staging.czeug8m2orgn.ap-southeast-1.rds.amazonaws.com;port=3306;dbname=nt_tag_id_db;charset=utf8mb4'),
        'username' => envValue('NT_TAG_ID_DB_USER', 'pm_kwang'),
        'password' => envValue('NT_TAG_ID_DB_PASS', ''),
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
    ],
    's3' => [
        'bucket' => envValue('VEHICLE_LABEL_S3_BUCKET', 'vehicle-reid-dataset'),
        'region' => envValue('VEHICLE_LABEL_S3_REGION', 'ap-southeast-7'),
        'access_key' => envValue('VEHICLE_LABEL_S3_ACCESS_KEY', ''),
        'secret_key' => envValue('VEHICLE_LABEL_S3_SECRET_KEY', ''),
        'base_prefix' => envValue('VEHICLE_LABEL_S3_BASE_PREFIX', 'unlabeled/'),
        'manifest_key' => envValue('VEHICLE_LABEL_S3_MANIFEST_KEY', 'manifest.csv'),
        'url_ttl' => (int) envValue('VEHICLE_LABEL_S3_URL_TTL', '300'),
    ],
    'labels' => [
        'table' => envValue('VEHICLE_LABEL_TABLE', 'qms_vehicle_labels'),
    ],
    'runtime' => [
        'dir' => __DIR__ . '/runtime',
        'cache_ttl' => (int) envValue('VEHICLE_LABEL_CACHE_TTL', '300'),
    ],
];

