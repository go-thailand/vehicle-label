# Research: Bbox Size Filter for Vehicle Label System

## Problem

25% of vehicle crop images (41,575 out of ~167K) are "tiny" — average 51x47 pixels (area < 5,000 px). These images:
- **Cannot be labeled by humans** — too small to identify vehicle type, color, make, or model
- **Cannot be used for classifier training** — ResNet-18 requires 224x224 input, upscaling 51x47 introduces pure noise
- **Waste labeler time** — team Songkhla (starting 23 Mar) will encounter ~1 in 4 images being unusable
- **Degrade AI quality filter** — current confidence badge shows YOLO detection confidence, not image usability

## Current System Architecture

### Data Flow
```
qms_vehicle_track_facts (source — has bbox_best JSON)
  → upload-new-crops.sh generates manifest.csv
    → manifest.csv columns: filename, segment_id, camera_index, vehicle_type,
      vehicle_type_confidence, dominant_color, vehicle_subtype, vehicle_make,
      classifier_status, labeled_by
    → generate-labels-sync-php.py → INSERT IGNORE into qms_vehicle_labels

qms_vehicle_labels (label UI reads/writes)
  → columns: filename, segment_id, camera_index, batch_id, vehicle_type,
    dominant_color, vehicle_make, vehicle_model, quality, flagged, labeled_by,
    labeled_at, ai_vehicle_type, ai_color, ai_make, ai_confidence
  → NO bbox dimensions currently
```

### Key Files

| File | Location | Role |
|------|----------|------|
| `upload-new-crops.sh` | `qms-core/scripts/` (local) | Manifest generation + S3 upload + DB sync |
| `generate-labels-sync-php.py` | `qms-core/scripts/` (local) | Generates PHP INSERT IGNORE from manifest.csv |
| `index.php` | `vehicle-label/public/vehicle-label/api/` (server) | API — list-batches, list-images, save-label |
| `list.html` | `vehicle-label/` (server) | Browse page — image grid with quality badges |
| `label.html` | `vehicle-label/` (server) | Label workspace |
| `index.html` | `vehicle-label/` (server) | Dashboard — batch list with confidence stats |

### Available Data in qms_vehicle_track_facts

| Column | Type | Value | Populated |
|--------|------|-------|-----------|
| `bbox_best` | JSON | `{"x1":1130,"x2":1244,"y1":0,"y2":106}` | 100% — all rows |
| `crop_quality_score` | DECIMAL(6,4) | Quality metric | Only 7% (12,009 of 167,627) |

`bbox_best` is the reliable source — available for all rows. `crop_quality_score` is mostly NULL (93%).

### Size Distribution (from bbox_best)

| Tier | Area Range | Count | % | Avg WxH | Usability |
|------|-----------|-------|---|---------|-----------|
| Tiny | < 5,000 px | 41,575 | 25% | 51x47 | Unusable — hide |
| Small | 5K-20K px | 48,665 | 29% | 118x94 | Type/color only |
| Medium | 20K-100K px | 42,171 | 25% | 241x188 | Good for all labels |
| Large | > 100K px | 34,919 | 21% | 610x426 | Excellent |

### Filename → track_facts Mapping

Filename format: `seg{segment_id}-track-{track_id}.jpg`
JOIN: `qms_vehicle_track_facts.video_segment_id = segment_id AND track_id`

**Collation issue**: `qms_vehicle_labels` uses `utf8mb4_unicode_ci`, `qms_vehicle_track_facts` uses `utf8mb4_0900_ai_ci`. JOIN requires explicit `COLLATE` clause.

### How normalizeImage Works (API response)

`normalizeImage()` merges S3 object data + DB review lookup + manifest lookup into a single image object. The `review` sub-object contains all label data. Frontend accesses `image.review.ai_confidence` etc.

Currently no bbox data is passed to frontend.

### How Frontend Filters Work

- `list.html`: After API returns images, JS sorts by `review.ai_confidence` DESC and filters `< 0.3`
- `index.html`: Batches sorted by `avg_confidence` DESC, shows confidence badge per batch
- Filtering is client-side (post-fetch), not server-side query params

## Approach Options

### Option 1: Add columns to qms_vehicle_labels + populate via UPDATE JOIN
- ALTER TABLE ADD `bbox_width` INT, `bbox_height` INT
- One-time UPDATE JOIN from `qms_vehicle_track_facts.bbox_best`
- Update `upload-new-crops.sh` manifest to include bbox columns
- Update `generate-labels-sync-php.py` to INSERT bbox data
- API returns bbox in normalizeImage → frontend filters

**Pros**: Clean, data in same table, fast queries
**Cons**: One-time migration + manifest format change + script updates

### Option 2: API JOINs track_facts on the fly
- No schema change on vehicle_labels
- API does `LEFT JOIN qms_vehicle_track_facts` when building image list
- Frontend filters on returned bbox data

**Pros**: No migration
**Cons**: Collation issues, slow JOIN (167K rows), adds coupling between tables

### Option 3: Frontend-only (image.naturalWidth/naturalHeight)
- No backend changes
- JS checks actual loaded image dimensions
- Filter after render

**Pros**: Zero backend work
**Cons**: Must load image first (wasted bandwidth), async timing issues, can't filter before showing

## Recommendation: Option 1

Option 1 is cleanest. The columns live in the same table the API already queries. Migration is one-time. The manifest pipeline already runs every 30 minutes — just add bbox columns.

## Pitfalls

1. **Collation mismatch** on JOIN — must use `COLLATE utf8mb4_unicode_ci`
2. **164K rows UPDATE** — do in chunks to avoid lock timeout
3. **manifest.csv format change** — `generate-labels-sync-php.py` must handle new columns
4. **Existing rows vs new rows** — migration handles existing, script handles future
5. **batch stats** — `getBatchStats()` should include avg bbox area for smart ordering
