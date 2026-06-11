# Media Foundation Implementation Plan (v2.1C)

**Document Type:** Master Architecture Blueprint
**Status:** Pending CTO Approval

---

## 1. Domain Analysis
The Media Foundation is the centralized digital asset management engine for the entire IVORQ platform. It must be implemented immediately because almost every subsequent operational module requires heavy file attachment capabilities:
- **Preventive Maintenance (PM) & Work Orders (WO):** Require "Before" and "After" photographic evidence.
- **Incident Management:** Relies on structural evidence (photos/videos) for liability and insurance audits.
- **Asset Management:** Demands warranty PDFs, CAD drawings, and compliance certificates.
- **Housekeeping & Visitors:** Requires lost-and-found imagery and digital signature capture.
- **HRIS & Finance:** Requires secure document storage (certifications, scanned invoices).

---

## 2. Architecture Design
The Foundation operates on the following core entities:
- **`Media`**: The master record linking a physical cloud file to an IVORQ entity (Polymorphic).
- **`MediaFolder`**: Virtual directory structures mapping directly to physical S3 paths.
- **`MediaVersion`**: Immutable historical copies of documents (e.g., SOP revisions, Blueprint updates).
- **`MediaTag`**: Taxonomy labels (e.g., `Leak`, `Critical_Evidence`, `Invoice`) for rapid search.
- **`MediaMetadata`**: A JSON column storing extracted EXIF data (GPS, Device, Timestamps).
- **`MediaAccess`**: Granular ACLs defining who can view/download specific files.
- **`MediaComment` & `MediaShare`**: Collaboration tools (e.g., highlighting a specific part of a Blueprint).

---

## 3. Storage Architecture & Cloud Strategy

### 3.1 Cloud Storage Strategy
- **Primary Strategy:** AWS S3 (or Cloudflare R2 for zero-egress cost optimization at high volume).
- **CDN Strategy:** Cloudflare CDN sits in front of the S3 bucket to instantly serve generated thumbnails and compressed images to mobile PWAs globally.

### 3.2 Hierarchical Folder Strategy (CTO Directive)
Flat buckets are strictly prohibited to ensure rapid disaster recovery and audit exports.
Physical storage paths will be strictly constructed as:
`{property_ulid}/{department_code}/{module_name}/{year}/{month}/{file_ulid}.{ext}`
*Example:* `PROP-1234/ENG/WORK_ORDERS/2026/06/FILE-999.jpg`

### 3.3 Isolation (Property, Department, Module)
- **Property Isolation:** The root path enforces absolute multi-tenant security. A cross-tenant breach is physically impossible without knowing the exact root ULID.
- **Department & Module Isolation:** Permits precise billing metrics (e.g., Finance consumes 5TB, Engineering consumes 20TB) and allows Department Managers to request specific audit exports of *only* their `Incidents` folder.

---

## 4. Media Types & Evidence Workflow

### 4.1 Supported Media
- **Formats:** JPEG, PNG, WEBP, MP4 (Video), PDF, DOCX, MP3 (Audio), DWG (CAD Blueprints).
- **Thumbnailing:** The system dynamically generates WEBP thumbnails for images and extracts the first frame of MP4s via background queues to ensure fast UI loading.

### 4.2 Photo Evidence Strategy
Mobile users execute physical tasks requiring structured proof.
- **Before Photos:** Mandatory state capture prior to wrench time.
- **Progress Photos:** Used for long-running Projects/CAPEX.
- **After/Completion Photos:** Required state capture to clear the WO/PM from the active queue.

---

## 5. Mobile PWA Strategy
The Media Foundation is built for the field, not the desk.
- **Camera Integration:** Natively invokes the device camera via HTML5 APIs.
- **Offline Upload & Background Sync:** Photos taken in basements are stored in local IndexedDB. The Service Worker automatically uploads them via Background Sync API once WiFi is restored.
- **Client-Side Compression:** The PWA forcibly compresses 12MB iPhone photos into 500KB WEBP images *before* network transmission, drastically saving bandwidth and storage.

---

## 6. Metadata, Versioning & Security

### 6.1 Metadata Capture
EXIF data is automatically stripped from the file and moved to the `MediaMetadata` DB column.
- **Captured Fields:** GPS Coordinates (Lat/Lng), Timestamp, Device ID, Uploader ID.
- **Audit Value:** Cryptographically verifies that a PM "Completion Photo" was actually taken inside the `Location` of the asset at the time the technician clicked "Complete", not uploaded from a camera roll at home.

### 6.2 Version Control
Documents (e.g., `Evacuation-Map.pdf`) change over time. Uploading a new file to the same logical `Media` record generates a new `MediaVersion`. Old versions are moved to an archive path, ensuring historical Work Orders still reference the exact Blueprint version active on the day of the work.

### 6.3 Security Model
- **Access Control:** Files are *not* public. S3 objects are private. The API returns short-lived Pre-Signed URLs (valid for 15 minutes) for viewing.
- **Watermarks:** Highly sensitive documents (HRIS, Finance) dynamically imprint user details across the PDF before download to deter leaks.

---

## 7. Search & Retention Strategy

### 7.1 Search Strategy
- **Meilisearch Integration:** The DB `media` table syncs to Meilisearch, indexing File Name, Tags, Uploader, and connected entity IDs (Asset, PM).
- **Performance:** Allows sub-millisecond searches like "Show me all photos tagged 'Fire Damage' in Tower A from 2024".

### 7.2 Retention Policy
Controlled by DB chron-jobs dictating S3 Lifecycle rules:
- **PM/WO Photos:** Moved to S3 Glacier (Cold Storage) after 2 years; deleted after 5 years.
- **Incident/Finance Documents:** Moved to Glacier after 3 years; deleted after 10 years for legal compliance.
- **Training/Blueprints:** Infinite retention.

---

## 8. Business Rules

- **BR-001:** Media cannot be deleted if explicitly linked to a closed Incident, Completed PM, or Locked Financial Record.
- **BR-002:** Client-side image compression is mandatory for mobile payloads to prevent storage bloat.
- **BR-003:** Any update to a Blueprint, SOP, or Manual must trigger a version increment, never an overwrite.
- **BR-004:** PWA uploads must extract and validate GPS metadata against the assigned Work Order's Location coordinates, flagging anomalies for supervisor review.

---

## 9. Scalability & Risk Analysis

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| **Storage Explosion** | Critical | 20M photos * 5MB = 100TB. By enforcing client-side WEBP compression (500KB), storage drops to 10TB. S3 Glacier lifecycle rules further reduce costs by 80%. |
| **Upload Failures** | High | Deep basements lack 4G. Implement strict IndexedDB offline caching and Background Sync queues to guarantee evidence is never lost. |
| **Unauthorized Access** | Critical | Root S3 bucket policy strictly denies all public access. All file access must route through the Laravel API to generate short-lived signed URLs. |

---

## 10. Implementation Plan

### Entities
- `Media`, `MediaFolder`, `MediaVersion`, `MediaTag`, `MediaMetadata`, `MediaAccess`, `MediaComment`.

### Services
- **`MediaUploadService`**: Handles pre-signed S3 POST requests directly from the client (bypassing server RAM limits).
- **`MediaMetadataService`**: Extracts EXIF data.
- **`MediaLifecycleService`**: Manages cold-storage transitions.

### API Strategy
- Provide REST endpoints to generate Signed Upload URLs and Signed Download URLs.

---

## 11. Testing Strategy
- **Upload Tests:** Mock S3 to verify direct-to-cloud upload workflows.
- **Security Tests:** Attempt to access an S3 URL without a valid signature; assert a 403 Forbidden response.
- **Retention Tests:** Time-travel the application clock 5 years forward and verify the `MediaLifecycleService` correctly transitions records to Glacier status.

---

## 12. Open Questions
1. **Malware Scanning:** Do we need to integrate AWS Macie or a Lambda-based ClamAV scanner to scrub uploaded PDFs and Word Docs from contractors before they enter IVORQ?
2. **Video Transcoding:** Will we require an AWS Elemental MediaConvert pipeline to re-encode 4K contractor videos down to streaming 720p HLS, or is raw MP4 playback sufficient for v2.1C?

---

## 13. CTO Recommendations
1. **Direct-to-S3 Uploads:** Do *not* allow the Laravel server to process 50MB video uploads. The API must generate a pre-signed S3 POST URL, allowing the mobile PWA to stream the file directly to AWS. This keeps the PHP fpm workers free and prevents memory crashes.
2. **Mandate Pre-Signed Downloads:** Never expose the raw S3 bucket URL to the frontend. Short-lived URLs ensure that if a technician leaves the company, their browser history cannot be used to download sensitive floor plans.
3. **Strict Folder Pathing:** The hierarchical folder directive is excellent. Implement it programmatically in the `MediaUploadService` so developers cannot accidentally save files to flat structures.
