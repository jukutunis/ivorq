# IVORQ Media / Attachment Foundation v1.0 Blueprint

## SECTION 1 — DOMAIN BOUNDARIES

### Inside Media Foundation
The Media Foundation acts as the centralized file handling abstraction layer. It is responsible for:
- Upload and ingest.
- Cloud and local Storage allocation.
- Security (MIME validation, malware scanning).
- Processing (thumbnail generation, compression).
- Retention (hot, warm, cold archiving).
- Retrieval (pre-signed URLs, CDN delivery).

### Outside Media Foundation
The Media Foundation **stores files only**. It does not own the business logic or context of the file.
- **Business Ownership**: Modules (e.g., Housekeeping, Logbook, Engineering) own the context.
- **Work Orders / Incidents**: Link to media assets but do not manage the file's lifecycle directly.
- **Housekeeping tasks**: Store proof-of-cleaning photos as references to Media IDs.

---

## SECTION 2 — MEDIA ARCHITECTURE

### Media Asset
A central `MediaAsset` entity represents every uploaded file.

### Attributes
- `id`: ULID
- `property_id`: ULID (Strict Isolation)
- `owner_type`: Polymorphic string (e.g., `App\Modules\Operations\Incident\Models\Incident`)
- `owner_id`: ULID
- `media_type`: Enum (Image, Video, Document, Voice)
- `file_name`: Original file name
- `mime_type`: Validated MIME type
- `file_size`: Bytes
- `checksum`: SHA-256 for integrity and deduplication
- `uploaded_by`: ULID (User ID)
- `uploaded_at`: Timestamp

### Support
- **ULID Primary Keys**
- **Multi Property Isolation**: Strict tenant scoping on all queries.

---

## SECTION 3 — STORAGE STRATEGY

### Supported Providers
- Local Development (Local Disk)
- AWS S3
- Cloudflare R2
- MinIO
- Future: Azure Blob, Google Cloud Storage

### Abstraction Layer
The foundation wraps Laravel's native `Storage` facade via a dedicated `StorageService`. Business modules interact with the `MediaFoundationContract` and must never know the underlying storage provider or directly call `Storage::disk()`.

---

## SECTION 4 — UPLOAD ENGINE

### Supported Upload Types
- **Single Upload**: Standard multipart form-data.
- **Multi Upload**: Batch processing of multiple files.
- **Chunk Upload**: Resumable uploads for unstable connections.
- **Large File Upload**: Specialized queue-based processing.

### Supported Files
- `jpg`, `png`, `webp`
- `pdf`, `mp4`, `mov`
- `docx`, `xlsx`

### Validation Architecture
Strict server-side validation against a whitelist of MIME types and extensions. Files with mismatched MIME/extensions are immediately rejected.

---

## SECTION 5 — PRE-SIGNED URL ARCHITECTURE

### Design
- **Temporary Upload URL**: Clients can request a pre-signed URL to upload directly from the browser/PWA to S3/R2, bypassing the PHP server completely for large files.
- **Temporary Download URL**: All media retrieval utilizes short-lived, signed URLs to prevent unauthorized hotlinking.
- **Expiration Policy**: URLs expire after a configurable duration (e.g., 15 minutes).
- **Revocation Strategy**: Changing the underlying file's visibility or ownership immediately invalidates future URL generation.

---

## SECTION 6 — IMAGE PROCESSING ENGINE

### Supported Capabilities
- **Thumbnail Generation**: Automated creation of `thumb`, `medium`, and `large` variants.
- **Resize**: Capping maximum resolution to save storage (e.g., max 1920x1080).
- **Compression**: Automated lossy compression for rapid mobile loading.
- **WebP Conversion**: Real-time or queued conversion of JPEGs/PNGs to WebP formats.
- **Metadata Extraction**: Stripping EXIF data for privacy, but retaining critical metadata (e.g., timestamp, GPS location for operational verification if permitted).

### Future AI Readiness
- OCR (Optical Character Recognition)
- Object Detection

---

## SECTION 7 — VIDEO PROCESSING ENGINE

### Supported Capabilities
- **Thumbnail Extraction**: Generating a poster frame from the first second of the video.
- **Compression**: Standardizing bitrates via an external FFmpeg worker queue.
- **Resolution Profiles**: Generating 480p / 720p variants for mobile offline syncing.

### Future Readiness
- Transcription
- Video Analysis

---

## SECTION 8 — DOCUMENT ENGINE

### Supported Formats
- PDF
- DOCX
- XLSX

### Capabilities
- **Preview**: PDF rendering in browser.
- **Metadata**: Extracting page counts and authors.
- **Search Readiness**: Preparing text extraction pipelines for future Elasticsearch/Vector indexing.

---

## SECTION 9 — VOICE NOTE ENGINE

### Supported Capabilities
- **Mobile Voice Notes**: Ingesting raw audio from the PWA.
- **PWA Recording**: Web Audio API integration.
- **Compression**: Standardizing to lightweight MP3/AAC/Ogg formats.

### Future Readiness
- Speech To Text (Transcription)
- Sentiment Analysis

---

## SECTION 10 — ATTACHMENT LINKING

### Polymorphic Architecture
Media links to business entities using Laravel's Polymorphic relationships (`media_links` table).

### Supported Consumers
- Logbook
- Housekeeping
- Asset
- Engineering
- Incident
- PTW
- PMS
- HRIS
- Finance

**Core Rule**: Media Foundation owns the file and its physical lifecycle. The Business module owns the operational context of the link.

---

## SECTION 11 — ACCESS CONTROL

### Definition
- **Property Isolation**: A user belonging to Property A cannot access a media file uploaded to Property B, even with a direct link.
- **Department Visibility**: Optional restrictions binding a file to specific departments (e.g., HRIS files).
- **Role Visibility**: Enforced via Spatie Permission before issuing a signed URL.
- **Executive Visibility**: Read-only cross-property access for corporate executives.

---

## SECTION 12 — SECURITY ARCHITECTURE

### Supported Capabilities
- **Virus Scan Readiness**: Hook points to send uploads to ClamAV or external malware scanners.
- **Malware Detection**: Rejecting executable payloads.
- **MIME Validation**: Validating the actual file header (magic bytes), not just the `.ext`.
- **File Signature Validation**: Cryptographic hash matching.

### Quarantine Process
Suspicious files are routed to an isolated S3 bucket (`quarantine`) and await admin review or automated deletion.

---

## SECTION 13 — OFFLINE PWA ARCHITECTURE

### Supported Capabilities
- **Offline Capture**: Taking photos/videos when deep in the basement (no WiFi).
- **Offline Photos & Videos & Voice Notes**: Storing binary data locally.

### Implementation
- **IndexedDB**: Caching Blobs/Base64 data in the browser.
- **Sync Queue**: A Service Worker background sync process that uploads files sequentially once the connection is restored.
- **Conflict Resolution**: The client generates a ULID upon capture. The server respects this ULID. If the ULID exists, the server checks the checksum to prevent duplicate uploads.

---

## SECTION 14 — RETENTION POLICY

### Supported Tiers
- **Hot Storage**: Instant access (e.g., S3 Standard) for the first 90 days.
- **Warm Storage**: Cheaper, slight latency (e.g., S3 Infrequent Access) for 90 days to 1 year.
- **Cold Storage**: Deep archive (e.g., Glacier) for 1 to 7 years.

### Lifecycle Events
- **Archive**: Moving from Warm to Cold.
- **Purge**: Permanent hard delete.
- **Legal Hold**: A flag `is_legal_hold = true` that prevents automated purging or archiving, overriding all other policies.

### Retention Matrix
- **Housekeeping Images**: Purge after 90 days.
- **Incident Videos**: Retain for 7 years (Cold Storage).

---

## SECTION 15 — EVIDENCE PACKAGE ENGINE

### Supported Packages
- **Investigation Package**
- **Incident Package**
- **Audit Package**
- **Legal Package**

### Export Architecture
- **ZIP**: An asynchronous queue job zips the requested media files.
- **PDF Index**: A generated PDF detailing who uploaded what and when, serving as a cover page.
- **Attachment Manifest**: A JSON/CSV file mapping original filenames, checksums, and timestamps to prove chain of custody.

---

## SECTION 16 — SEARCH READINESS

### Capabilities
- **Filename Search**
- **Metadata Search**
- **Tag Search**
- **OCR Ready Search**: Database columns prepared to store extracted text.

### Future Readiness
Preparing the metadata schema to sync with AI vector databases.

---

## SECTION 17 — REPORTING FOUNDATION INTEGRATION

### Architecture
- The Media Foundation provides APIs for the Reporting Foundation to embed or attach files.
- **Support**: PDF Attachments, Excel Attachments, Report Archive.
- **Rule**: No duplicate reporting logic. The Media Foundation only supplies the secure file paths to the Reporting Engine.

---

## SECTION 18 — NOTIFICATION FOUNDATION INTEGRATION

### Architecture
- **Support**: Injecting secure Attachment Links into emails/push notifications.
- **Expiring Media Alerts**: Notifying owners before their files are permanently purged.
- **Failed Upload Alerts**: Notifying users if a background chunked upload fails.
- **Rule**: Notification owns delivery; Media owns the file lifecycle.

---

## SECTION 19 — AUDIT FOUNDATION READINESS

### Tracked Events
- **Upload**: File creation.
- **Download**: Generating a signed URL.
- **View**: Previewing the file.
- **Share**: Generating a public link.
- **Delete**: Soft deleting.
- **Restore**: Recovering from soft delete.

All events fire standard Laravel events to be caught by the Audit Foundation, ensuring an immutable audit trail.

---

## SECTION 20 — MULTI PROPERTY ARCHITECTURE

### Scope Support
- **Single Property**
- **Resort Group**
- **Hotel Group**
- **Corporate**

### Architecture
Strict tenant isolation. Every media asset is bound to a `property_id`. Group and Corporate scopes aggregate read access but maintain individual property ownership at the row level.

---

## SECTION 21 — API READINESS

### Headless Support
- **REST API**: `/api/v1/media/upload`, `/api/v1/media/{id}/download`.
- **Webhook Ready**: Incoming webhooks from external video transcoders (e.g., AWS Elemental MediaConvert).
- **Event Driven Ready**: Emitting `MediaUploaded`, `MediaProcessed`, `MediaDeleted` to the Event Bus.
- **Rule**: No direct DB access by external modules.

---

## SECTION 22 — PERFORMANCE STRATEGY

### Optimization
- **CDN**: Cloudflare or CloudFront sitting in front of the storage buckets.
- **Cache**: Redis caching of frequently accessed signed URLs.
- **Lazy Loading**: Client-side image lazy loading directives.
- **Streaming**: Range-request support for streaming large MP4s without downloading the entire file.

---

## SECTION 23 — COMPLIANCE READINESS

### Preparation
- **ISO 9001**: Quality management traceability.
- **ISO 45001**: Occupational health and safety records.
- **Insurance Investigation**: Immutable timestamps and checksums.
- **Legal Investigation**: Evidence packaging and chain of custody.

---

## SECTION 24 — AI FOUNDATION READINESS

### Future Capabilities
- OCR (Optical Character Recognition)
- Caption Generation
- Image Classification
- Asset Recognition
- Similar Image Search
- Vector Embedding

*(Architecture only, no implementation yet).*

---

## SECTION 25 — OPEN CTO QUESTIONS

1. **Storage Provider Standardization**: Will IVORQ standardize entirely on AWS (S3), or use a multi-cloud abstraction allowing properties to use Cloudflare R2 for cost savings?
2. **Direct-to-S3 Uploads**: For pre-signed uploads, should the PWA communicate directly with S3 (reducing server load but complicating CORS and error handling), or proxy through the IVORQ backend?
3. **Malware Scanning Strategy**: Should files be synchronously scanned upon upload (slower UX) or asynchronously scanned post-upload (requires a quarantine bucket)?
4. **Video Transcoding Costs**: FFmpeg requires heavy compute. Will we use an external service (AWS MediaConvert, Mux) or deploy dedicated GPU-enabled worker nodes within our Kubernetes cluster?
5. **Cold Storage Latency**: Glacier retrieval can take up to 12 hours. Does the UI need to implement a "Request Retrieval" workflow for archived incident videos?
6. **Data Sovereignty**: For multi-property hotel groups spanning regions (e.g., EU and Asia), must the Media Foundation support regional bucket allocation based on the `property_id` to comply with GDPR?
7. **Offline Sync Conflict**: If a user uploads an offline photo but the business entity (e.g., Work Order) was deleted by a manager while they were offline, should the photo be orphaned or hard-deleted?
8. **Deduplication Strategy**: If 10 users upload the exact same PDF manual, should the storage engine deduplicate via checksum, or maintain 10 separate physical files for strict isolation?
9. **Image Optimization Aggressiveness**: Should we force WebP conversion on all images and delete the original JPEGs to save space, or must originals be retained for legal integrity?
10. **Evidence Package Size Limits**: What is the maximum permitted size for an Evidence ZIP export before forcing the user to use a specialized download manager or split archives?

---

**STATUS: READY FOR FINAL CTO LOCK**
