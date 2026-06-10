# Purchasing Approval Matrix Architecture

## 5. Multi-Level Dynamic Approval Matrix

Sistem persetujuan IVORQ didesain menggunakan **Rule-Engine** yang dinamis, tidak *hardcoded*. Ini memastikan IVORQ dapat menyesuaikan diri dengan SOP (Standard Operating Procedure) pengadaan dari berbagai skala hotel (Bintang 3 vs Bintang 5) dan grup manajemen.

### Approval Hierarchy Levels
Hierarki *Approver* mengikuti rantai komando operasional hotel standar:
1. **Requester** (Pembuat PR)
2. **Supervisor** (Opsional untuk departemen besar seperti F&B Kitchen)
3. **Department Head / Manager** (Wajib, filter operasional pertama)
4. **Financial Controller (FC)** (Wajib, filter budget dan cashflow)
5. **General Manager (GM)** (Wajib untuk nominal tertentu)
6. **Owner / Corporate Director** (Wajib untuk belanja modal/Capex yang sangat besar)

### Approval Rules Engine (Matrix Dimensions)

Matrix persetujuan (_Approval Workflows_ & _Approval Steps_) dievaluasi menggunakan kombinasi parameter berikut:

#### 1. Berdasarkan Nominal (Amount Thresholds)
Keputusan siapa yang berhak menyetujui bergantung pada Total Amount PR/PO.
*Contoh Rule PR:*
- Rp 0 - Rp 5.000.000: Dept Head → FC
- Rp 5.000.001 - Rp 50.000.000: Dept Head → FC → GM
- > Rp 50.000.000: Dept Head → FC → GM → Owner

#### 2. Berdasarkan Departemen (Departmental Routing)
Tidak semua departemen memiliki rute yang sama. 
*Contoh Rule:*
- PR dari departemen IT yang berhubungan dengan *software* mungkin harus melalui *Corporate IT Director* sebelum masuk ke GM.
- PR dari departemen *Housekeeping* cukup berputar di level internal properti.

#### 3. Berdasarkan Properti (Property Isolation)
Setiap properti dalam IVORQ (Multi-Property) memiliki `approval_workflows` miliknya sendiri. GM di Hotel A tidak bisa meng- *approve* PR milik Hotel B, kecuali ia menjabat sebagai *Cluster GM*.

#### 4. Berdasarkan Perusahaan (Company / Capital Expenditure)
Belanja modal (CAPEX) atau perombakan renovasi (Renovation) biasanya tunduk pada aturan *Company Level*, mengesampingkan rute normal *Property Level*.

### Database Mapping (High-Level)
Sistem ini menggunakan dua tabel utama untuk memetakan logika di atas:
- `approval_workflows`: Menyimpan *header* aturan (Misal: "Workflow PR Engineering > 10Jt"). Difilter dengan `document_type`, `department_id`, `min_amount`, `max_amount`.
- `approval_steps`: Mendefinisikan urutan (_Sequence_) siapa yang harus menyetujui (berdasarkan `role_id` atau spesifik `user_id`).

### Escalation & Delegation
- **Delegation**: Jika FC sedang cuti, hak *approval* bisa di-delegasikan sementara (dengan tanggal kedaluwarsa) ke *Assistant FC*.
- **Over-Budget Escalation**: PR yang nilainya melampaui sisa *Budget* departemen secara otomatis di-eskalasi satu level di atas matriks normalnya (Misal: Normalnya berhenti di FC, eskalasi memaksa harus GM).

---

## Architecture Refinement v2

### Immutable Approval Snapshots
Untuk menjaga integritas rekam jejak (*Audit Compliance*) berstandar Enterprise, IVORQ menerapkan desain `APPROVAL_SNAPSHOTS` setiap kali keputusan *Approve* atau *Reject* diambil. Sistem **tidak** sekadar mereferensikan relasi ke `user_id` atau `role_id` penyetuju karena data tersebut bisa berubah di masa depan (misal: *Role limit* direvisi atau *user* berhenti bekerja).

#### Snapshot Metadata
Setiap entri mem-_freeze_ status data pada mikrodetik keputusan diambil:
- `approver_name`: Nama presisi orang yang menyetujui.
- `role_name`: Jabatan spesifik pada saat itu (contoh: "Acting General Manager").
- `approval_limit`: Batasan nominal otorisasi orang tersebut di menit ia menyetujui.
- `approval_order`: Nomor urut hierarki penyetujuan.
- `approval_timestamp`: Jejak waktu absolut (tersertifikasi/immutable).

#### Legal Traceability & Anti-Workflow Changes
- Rekaman *Snapshot* ini bertindak sebagai alat perlindungan hukum yang mencegah penyangkalan (_Non-Repudiation_) jika ada audit forensik.
- Meskipun Administrator mengubah konfigurasi rute di tabel `approval_workflows`, seluruh PR atau PO historis tidak akan kehilangan jejak otentiknya. Hal ini vital untuk pelaporan *Historical Integrity* ke auditor *Cost Control* maupun *Corporate Owner*.
