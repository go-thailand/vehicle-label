# Vehicle Label System

Frontend + PHP API สำหรับ human labeling ภาพรถจาก CCTV โดยเชื่อมกับ S3 และ MySQL จริง

## Implemented

- Frontend เชื่อม mock template เข้ากับ real API แล้ว
- Dashboard ใช้ข้อมูลจริงจาก API
- Browse ใช้ `list-images` จริง พร้อม pagination, status/type/ai/search filter
- Label workspace ใช้ `save-label` และ `flag-image` จริง
- AI suggestion ดึงจาก `manifest.csv` บน S3 ผ่าน backend
- User identification แบบ simple dropdown (`Best`, `S`, `Jame`, `Opal`) เก็บใน `localStorage`
- Export สำหรับ ML:
  - `export-labels` เป็น CSV
  - `export-stats` เป็น JSON หรือ CSV

## Frontend Pages

- Dashboard: `index.html`
- Browse: `list.html`
- Label Workspace: `label.html`

Local URLs:

- `http://localhost/vehicle-label/index.html`
- `http://localhost/vehicle-label/list.html?batch=batch-001`
- `http://localhost/vehicle-label/label.html?batch=batch-001`

## API Routes

API base:

- `http://localhost/vehicle-label/public/vehicle-label/api/index.php`

Routes:

- `?action=list-batches`
- `?action=list-images&batch=batch-001&page=1&per_page=60`
- `?action=get-image&key=unlabeled/batch-001/seg6714906-track-1.jpg`
- `?action=save-label` `POST`
- `?action=flag-image` `POST`
- `?action=stats`
- `?action=stats&type=summary`
- `?action=stats&type=distribution`
- `?action=stats&type=leaderboard`
- `?action=export-labels`
- `?action=export-stats`
- `?action=export-stats&format=csv`

## Data Sources

- S3 bucket: `vehicle-reid-dataset`
- S3 image path: `unlabeled/batch-NNN/`
- S3 machine predictions: `manifest.csv`
- DB table: `qms_vehicle_labels (108,507 rows; 2 human-labeled so far)`

## Files Added

- [config.example.php](/c:/Mobile%20ai/vehicle-label/public/vehicle-label/api/config.example.php)
- [helpers.php](/c:/Mobile%20ai/vehicle-label/public/vehicle-label/api/helpers.php)
- [index.php](/c:/Mobile%20ai/vehicle-label/public/vehicle-label/api/index.php)
- [s3-client.php](/c:/Mobile%20ai/vehicle-label/public/vehicle-label/api/s3-client.php)

## Do Not Commit

ไฟล์พวกนี้ถูก ignore แล้วและไม่ควร commit:

- `public/vehicle-label/api/config.php`
- `public/vehicle-label/api/runtime/`

## Notes

- ระบบใช้ `qms_vehicle_labels` เป็น source of truth
- ไม่ต้อง sync `manifest.csv` กลับ S3
- ไม่ต้องย้ายรูปออกจาก `unlabeled/`
- export ถูกออกแบบไว้ให้ Opal ใช้ต่อกับ training pipeline
