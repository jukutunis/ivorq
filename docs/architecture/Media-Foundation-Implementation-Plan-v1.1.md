# Media Foundation Implementation Plan (v1.1)

**Document Type:** Master Architecture Blueprint (Revision 1.1)
**Status:** Pending CTO Approval

---

## 1. Revised Architecture & New Entities
The Media Foundation serves as the enterprise's central nervous system for digital files, evidence, and compliance documents. Revision 1.1 aggressively enhances security, compliance, AI-automation, and malware protection.

**Core Entities:**
- **`Media`**: The root file record tracking cloud location, metadata, and lifecycle status.
- **`MediaCollection`**: A logical grouping mechanism (e.g., WO-0001 Collection containing `Before`, `Progress`, and `After` buckets).
- **`MediaRelation`**: A highly normalized polymorphic pivot. A single 50MB `Blueprint.pdf` can be linked to 15 different Asset records without duplicating physical storage.
- **`DigitalSignature`**: Secure, tamper-evident capture of user approvals (Technician, Guest, Contractor).
- **`MediaAuditTrail`**: An immutable log tracking *every* action (View, Download, Upload, Delete) against a file.
- **`MediaRetentionPolicy`**: Dynamic rules dictating S3 lifecycles, with Legal Hold overrides.
- **`MediaQuarantine`**: Tracks files failing Lambda Malware scans pending administrator review.

---

## 2. Storage Architecture

**Folder Hierarchy:**
`{Property}/{Department}/{Module}/{Year}/{Month}/{File_ULID}.{ext}`

**Cloud Vendor Strategy:**
At an estimated 20,000,000 photos over 10 years, traditional AWS S3 egress costs will scale unsustainably.
- **Primary Storage:** Cloudflare R2 (zero egress fees) paired tightly with Cloudflare CDN for lightning-fast thumbnail serving.
- **Cold Storage:** AWS S3 Glacier Deep Archive. 10-year retention policies actively migrate aged Financial/Incident files from R2 into Glacier to slash long-term storage costs by up to 90%.

---

## 3. Malware Scanning Architecture (CTO Mandatory Requirement)

Contractors and external vendors will upload PDFs and images to IVORQ. Accepting unverified files directly into production is a critical security vulnerability.
**Workflow:**
1. **Upload:** Client uploads directly to a constrained `s3://ivorq-temp-quarantine` bucket via a pre-signed URL.
2. **Lambda Trigger:** A Serverless Lambda function automatically executes a ClamAV scan.
3. **Outcome - Approved:** The file is securely moved to the production R2 bucket, and the `Media` record status updates to `Active`.
4. **Outcome - Quarantined:** The file is locked. An entry is created in `MediaQuarantine`, and an alert routes to the IT Director. Quarantined files are strictly blocked from being attached to WOs or PMs.

---

## 4. Security Architecture

- **Granular File Classifications:** Files are categorized as Standard, Sensitive, Restricted, Confidential, Legal, or HR.
- **Dynamic Watermarking:** Downloading a `Confidential` floor plan dynamically burns the user's Name, IP, and Timestamp across the PDF via a background queue before serving the download link.
- **View-Only Mode:** Mobile PWAs can display Restricted files (like vendor contracts) via secure preview layers that block native browser saving.
- **Emergency Revocation:** Administrators can instantly revoke all active pre-signed URLs globally if a data leak is suspected.

---

## 5. Audit & Compliance Architecture

### 5.1 Media Audit Trail
Compliance demands tracking the complete lifecycle of sensitive data. `MediaAuditTrail` logs:
`Upload`, `View`, `Download`, `Share`, `Delete Request`, `Restore`, `Retention Action`, `Malware Quarantine`.
*Impact:* If a guest claims injury, Legal can forensically prove exactly which technicians viewed the relevant CCTV or maintenance photos, complete with IPs and Timestamps.

### 5.2 Dynamic Retention Policies & Legal Holds
Retention is no longer hardcoded. `MediaRetentionPolicy` allows corporate configuration:
- PM Evidence = 5 Years, Incident Evidence = 10 Years, Training = Unlimited.
- **Legal Hold:** If a lawsuit occurs, Legal can flag an Incident. The Legal Hold absolutely overrides *all* deletion and lifecycle policies, preventing automated destruction of evidence.

### 5.3 Digital Signature Foundation
- **Support:** Technician, Supervisor, Contractor, and Guest Signatures.
- **Storage Model:** Captured as biometric vector data (SVG) and secured with a cryptographic hash mapping the signature directly to the underlying `MediaCollection` or Checklist state. Signatures are strictly immutable.

---

## 6. OCR Architecture
IVORQ must transition from a passive storage bin to an active data processor.
- **Engine Integration:** AWS Textract or Google Cloud Vision.
- **Workflow:** When a Contractor uploads a PDF `Invoice` or `Warranty`, the OCR queue extracts the `Invoice Number`, `Serial Number`, and `Vendor Name`, appending it directly to the `Media` search payload.
- **Impact:** Eliminates manual data entry and allows instantly searching "Find Invoice 9982-A" across millions of PDFs.

---

## 7. AI Metadata Architecture
Manual tagging fails at enterprise scale. AI handles classification automatically.
- **Workflow:** Uploading an image of an Air Handling Unit triggers the AI Vision queue.
- **Output:** The image is auto-tagged with `Mechanical`, `AHU`, `Maintenance`.
- **Governance:** The AI attaches a `Confidence Score`. Scores below 70% flag the photo for human `Human Override Process` review.
- **Impact:** Engineers can literally search "Leaking Pipe" and retrieve relevant WO photos globally, even if the technician never manually typed the word "Leak".

---

## 8. Mobile PWA Strategy & Large Video Handling
- **Offline Capture:** Photos map locally to IndexedDB when in basements.
- **Background Upload:** Leverages the Background Sync API to push payloads to the quarantine bucket silently when WiFi restores.
- **Large Video Handling:** 4K Mobile videos are capped and compressed client-side (via WebAssembly ffmpeg wrappers if feasible, or strict OS constraints) to prevent multi-gigabyte uploads crashing the PWA.
- **GPS / QR Context:** PWA natively binds the scanned Room's ULID and live GPS coordinates to the photo's metadata pre-upload.

---

## 9. Risk Analysis Update

| Risk | Severity | Mitigation |
| :--- | :--- | :--- |
| **Storage Explosion** | Critical | Utilize Cloudflare R2 for zero-egress, client-side WEBP compression, and aggressive transitions to S3 Glacier. |
| **Malware Risk** | Critical | ClamAV Lambda quarantine intercepts 100% of uploads before they touch production buckets. |
| **Signature Fraud** | High | Cryptographically hash the digital signature SVG against the exact timestamp and WO ID it approves. |
| **Unauthorized Downloads** | Critical | Enforce short-lived pre-signed URLs. Apply dynamic PDF watermarks for Confidential documents. |
| **AI / OCR Errors** | Medium | Maintain the raw file intact. Rely on AI tags only for *additive* search, not destructive logic. Force human review on low confidence scores. |

---

## 10. Updated Business Rules
- **BR-001:** Quarantined files are strictly isolated; attempting to link them via `MediaRelation` throws an exception.
- **BR-002:** Digital signatures are completely immutable. Re-opening a WO invalidates the prior signature.
- **BR-003:** A file linked to a closed `Incident` cannot be soft or hard deleted.
- **BR-004:** Blueprint revisions must generate a new `MediaVersion`; destructive overwrites are blocked.
- **BR-005:** `Legal Hold` completely overrides all `MediaRetentionPolicy` automated deletions.

---

## 11. Updated Implementation Plan

### Entities
`Media`, `MediaCollection`, `MediaRelation`, `MediaVersion`, `MediaTag`, `MediaMetadata`, `DigitalSignature`, `MediaAuditTrail`, `MediaRetentionPolicy`, `MediaQuarantine`.

### Services
- `MediaUploadService` (Manages Quarantine staging).
- `MalwareReviewService` (Handles ClamAV webhooks).
- `OCRProcessingService` & `AIVisionService` (Queue-driven metadata extraction).
- `MediaLifecycleService` (Retention & Glacier logic).

### Search & Security
- Meilisearch indexes the `MediaMetadata` (OCR Text + AI Tags).
- Laravel Policies enforce RBAC, evaluating Legal Hold statuses prior to deletion authorization.

---

## 12. Updated Testing Strategy
- **Malware Flow:** Upload an EICAR test string. Assert Lambda quarantines the file and triggers the IT Alert.
- **Legal Hold:** Apply Legal Hold to a file. Attempt deletion via API. Assert `403 Forbidden`.
- **Pre-Signed Security:** Wait 16 minutes on a 15-minute S3 link. Assert AWS returns `Access Denied`.
- **OCR/AI Mocks:** Mock AWS Textract responses and assert the database correctly saves the extracted `Invoice Number`.

---

## 13. Open Questions
1. **Video Transcoding Engine:** To support 100 properties, relying on raw 4K MP4 downloads will kill property bandwidth. Should we implement AWS Elemental MediaConvert to slice videos into 720p HLS streams instantly upon approval from Quarantine?
2. **AI Privacy Impact:** Using Google Vision or AWS Rekognition on internal photos requires careful legal review regarding data privacy. Will the CTO authorize cloud-based AI processing, or do we need to investigate self-hosted open-source vision models?

---

## 14. CTO Recommendations
1. **Mandate Cloudflare R2:** Do not launch this module on raw AWS S3 if 100 properties are rapidly downloading blueprints and training videos daily. AWS egress fees will destroy the operational budget. R2's zero-egress fee model is an absolute financial necessity for this scale.
2. **Prioritize the Quarantine Layer:** The Malware Scanning workflow cannot be a "Phase 2" item. Do not deploy the Media Foundation without the ClamAV Lambda interceptor active.
3. **Decouple the AI/OCR Queues:** OCR and AI Vision API calls are slow and expensive. They must be heavily decoupled onto low-priority background queues to ensure the PWA Technician experiences zero UI lag during uploads.
