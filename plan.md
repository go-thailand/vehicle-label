# Plan: Bbox Size Filter for Vehicle Label System

## Approach

Add `bbox_width` and `bbox_height` columns to `qms_vehicle_labels`, populate from `qms_vehicle_track_facts.bbox_best`, update the sync pipeline, and filter tiny images in frontend.

## Phase A: DB Migration + Populate

### A1. ALTER TABLE

```sql
ALTER TABLE qms_vehicle_labels
  ADD COLUMN bbox_width SMALLINT UNSIGNED NULL AFTER ai_confidence,
  ADD COLUMN bbox_height SMALLINT UNSIGNED NULL AFTER bbox_width,
  ADD INDEX idx_bbox_area (bbox_width, bbox_height);
```

SMALLINT UNSIGNED (0-65535) is sufficient — max video resolution is 1920x1080.

### A2. Populate existing rows (164K)

Chunked UPDATE to avoid lock timeout. Parse JSON bbox_best:

```sql
UPDATE qms_vehicle_labels l
JOIN qms_vehicle_track_facts f
  ON f.video_segment_id = l.segment_id
  AND CONCAT('seg', f.video_segment_id, '-track-', f.track_id, '.jpg') COLLATE utf8mb4_unicode_ci = l.filename
SET l.bbox_width = JSON_EXTRACT(f.bbox_best, '$.x2') - JSON_EXTRACT(f.bbox_best, '$.x1'),
    l.bbox_height = JSON_EXTRACT(f.bbox_best, '$.y2') - JSON_EXTRACT(f.bbox_best, '$.y1')
WHERE l.bbox_width IS NULL
LIMIT 10000;
```

Run in loop until 0 rows affected. ~17 iterations for 164K rows.

## Phase B: Update Sync Pipeline

### B1. Manifest generation (upload-new-crops.sh)

Add `bbox_width` and `bbox_height` to the PHP SELECT query that generates manifest.csv:

```php
// Current:
SELECT video_segment_id, camera_index, track_id, vehicle_type, ...

// New:
SELECT video_segment_id, camera_index, track_id, vehicle_type, ...,
  JSON_EXTRACT(bbox_best, '$.x2') - JSON_EXTRACT(bbox_best, '$.x1') AS bbox_width,
  JSON_EXTRACT(bbox_best, '$.y2') - JSON_EXTRACT(bbox_best, '$.y1') AS bbox_height
```

manifest.csv header adds: `...,classifier_status,labeled_by,bbox_width,bbox_height`

### B2. generate-labels-sync-php.py

Add `bbox_width` and `bbox_height` to INSERT IGNORE columns:

```python
# Current INSERT columns:
(filename, segment_id, camera_index, batch_id, ai_vehicle_type, ai_color, ai_make, ai_confidence)

# New:
(filename, segment_id, camera_index, batch_id, ai_vehicle_type, ai_color, ai_make, ai_confidence, bbox_width, bbox_height)
```

Read from manifest: `r.get('bbox_width', '')`, `r.get('bbox_height', '')`.

## Phase C: Backend API

### C1. getLabelLookup response

Already returns all columns via `SELECT *`-style query. New columns `bbox_width`, `bbox_height` will automatically appear in the response. Verify `normalizeImage()` passes them through in the `review` object.

### C2. getBatchStats enhancement

Add bbox-based stats to batch listing:

```sql
-- Add to existing getBatchStats query:
ROUND(AVG(bbox_width * bbox_height), 0) AS avg_bbox_area,
SUM(CASE WHEN bbox_width * bbox_height < 5000 THEN 1 ELSE 0 END) AS tiny_count
```

## Phase D: Frontend Filter

### D1. list.html — Browse page

After image load, filter and annotate:

```javascript
// Current filter chain:
images = images.filter(img => (img.review?.ai_confidence ?? 1) >= 0.3);
images.sort((a, b) => (b.review?.ai_confidence ?? 0) - (a.review?.ai_confidence ?? 0));

// New: add bbox area filter + composite sort
const bboxArea = (img) => (img.review?.bbox_width ?? 999) * (img.review?.bbox_height ?? 999);
images = images.filter(img => bboxArea(img) >= 5000);  // hide tiny
images.sort((a, b) => bboxArea(b) - bboxArea(a));       // large first
```

Badge on thumbnail: show WxH and size indicator (green/yellow/red):

```javascript
// In renderGrid card:
const w = review.bbox_width ?? 0;
const h = review.bbox_height ?? 0;
const area = w * h;
const sizeBg = area >= 100000 ? 'bg-emerald-500' : area >= 20000 ? 'bg-amber-500' : 'bg-orange-500';
// Badge: "${w}x${h}" with color
```

### D2. label.html — Label workspace

Show warning banner when image is small:

```javascript
// After loading image data:
const area = (image.review?.bbox_width ?? 999) * (image.review?.bbox_height ?? 999);
if (area < 20000) {
  // Show warning: "Small image (WxH) — type/color only, make/model may be unreliable"
}
```

### D3. index.html — Dashboard

Add `tiny_count` to batch row display. Already has `avg_confidence`/`high_conf_count`/`low_conf_count` — add `tiny_count` alongside.

## Trade-offs

| Choice | Why |
|--------|-----|
| SMALLINT vs INT | Max 65535 enough for 1920px video. Saves 2 bytes/row x 164K = 328KB |
| Filter threshold 5000 px | 51x47 avg for tiny tier. 5000 = ~70x70 minimum. Conservative — won't hide medium images |
| Sort by bbox area vs confidence | Use bbox area as primary sort — bigger images = better labels. Confidence as tiebreaker |
| Index on (bbox_width, bbox_height) | Enables efficient batch stats query, WHERE clause filtering |

## Files to Modify

| File | Change |
|------|--------|
| DB (qms_vehicle_labels) | ALTER TABLE + UPDATE JOIN |
| `qms-core/scripts/upload-new-crops.sh` | Manifest SELECT add bbox_width, bbox_height |
| `qms-core/scripts/generate-labels-sync-php.py` | INSERT add bbox_width, bbox_height |
| `vehicle-label/public/vehicle-label/api/index.php` | getBatchStats add tiny_count + avg_bbox_area |
| `vehicle-label/list.html` | Filter tiny + sort by area + size badge |
| `vehicle-label/label.html` | Small image warning |
| `vehicle-label/index.html` | Show tiny_count in batch row |

## Todo

### Phase A: DB Migration
- [ ] ALTER TABLE add bbox_width, bbox_height, idx_bbox_area
- [ ] Chunked UPDATE JOIN to populate 164K rows

### Phase B: Sync Pipeline
- [ ] upload-new-crops.sh: add bbox to manifest SELECT
- [ ] generate-labels-sync-php.py: add bbox to INSERT columns

### Phase C: Backend API
- [ ] getBatchStats: add avg_bbox_area, tiny_count
- [ ] Verify normalizeImage passes bbox through

### Phase D: Frontend
- [ ] list.html: filter tiny (<5K), sort by area, size badge (WxH)
- [ ] label.html: small image warning banner
- [ ] index.html: show tiny_count per batch

### Phase E: Deploy + Verify
- [ ] Deploy to server
- [ ] Test browse page — tiny images hidden, size badges visible
- [ ] Test label page — warning shows for small images
- [ ] Commit + push to go-thailand/vehicle-label
