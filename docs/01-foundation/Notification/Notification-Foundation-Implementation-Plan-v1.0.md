# IVORQ Notification Foundation v1.0 Blueprint

## SECTION 1: Domain Boundaries

### Inside Notification Foundation
The Notification Foundation is a centralized, shared enterprise service. It is strictly responsible for:
- **Routing**: Determining where and how a notification should be delivered based on channels and preferences.
- **Delivery**: Executing the actual dispatch across multiple channels (In-App, Push, Email, SMS, WhatsApp).
- **Preferences**: Managing user, department, and property-level notification settings.
- **Escalation**: Managing notification delivery escalations when SLAs are breached.
- **Tracking & Audit**: Storing immutable read receipts, delivery statuses, and audit trails.

### Outside Notification Foundation
The Notification Foundation **DOES NOT** own business logic or business state.
- It does not decide *when* a task is assigned, only handles the "Task Assigned" alert.
- It does not manage the lifecycle of an Incident or Work Order.
- The Business Event is owned entirely by the source module (e.g., Housekeeping, Logbook, Engineering).

**Core Rule**: The Notification Foundation acts as a dumb courier with a smart routing engine. It receives a standardized payload and guarantees delivery according to defined rules.

---

## SECTION 2: Core Notification Architecture

### Notification Registry
A central registry where all IVORQ modules must register their notification events (e.g., `housekeeping.task_assigned`).

### Channel Registry
A registry of all active delivery mechanisms (e.g., `EmailChannel`, `PushChannel`, `WhatsAppChannel`). Channels can be toggled per property.

### Delivery Pipeline
`Source Module Event` → `Event Bus` → `Notification Router` → `Preference Check` → `Channel Dispatcher` → `Queue Worker` → `Delivery`

### Notification Lifecycle
- **Pending**: In queue.
- **Sent**: Dispatched to the provider.
- **Delivered**: Confirmed delivery by provider or device.
- **Failed**: Hard bounce or provider error.
- **Read**: Explicitly read by the user in-app.
- **Escalated**: Triggered the escalation engine.

---

## SECTION 3: Notification Types

To standardize presentation and routing, all notifications must fall into a specific type:

- **Information**: General updates without required actions.
- **Reminder**: Time-based prompts for pending tasks.
- **Assignment**: Direct task delegation requiring acknowledgment.
- **Approval**: Workflow requests requiring a 'Yes/No' action.
- **Escalation**: System-generated alerts indicating a breached SLA.
- **Critical Alert**: High-priority operational issues (e.g., VIP complaint).
- **Executive Alert**: High-level summaries specifically targeting executives.
- **System Alert**: Infrastructure or platform-level notifications.
- **Digest**: Batched summaries (e.g., Daily Shift Handover).
- **Announcement**: Global property or corporate broadcasts.
- **Emergency**: Highest priority, overrides all quiet hours.

---

## SECTION 4: Channel Architecture

The foundation supports omni-channel delivery.

- **In-App**: WebSocket-driven real-time database notifications within the IVORQ React UI.
- **Push Notification**: Web Push (PWA) and Native Mobile Push (APNs/FCM).
- **Email**: Transactional HTML emails with property branding.
- **WhatsApp Ready**: API architecture prepared for WhatsApp Business API templates.
- **SMS Ready**: Fallback channel for critical offline alerts.
- **Webhook Ready**: Ability to push notifications to external enterprise systems.

---

## SECTION 5: In-App Notification Engine

The primary interaction point for active staff.

- **Notification Center**: A dedicated UI panel aggregating all alerts.
- **Unread Counter**: Real-time badge count powered by WebSockets/Redis.
- **Mark Read**: Ability to mark individual or all notifications as read.
- **Archive**: Moving older, read notifications out of the primary feed.
- **Priority Feed**: A segmented view showing only Critical, Emergency, and Escalation alerts.

---

## SECTION 6: Push Notification Architecture

Designed for mobile and offline workforces.

- **PWA Push**: Utilizing Service Workers and VAPID keys for browser-based push.
- **Mobile Push Ready**: Architecture supports routing to Firebase Cloud Messaging (FCM) or Apple Push Notification service (APNs).
- **Device Registration**: Tracking multiple devices per user (`user_devices` table).
- **Token Management**: Automated purging of expired or invalid push tokens.
- **Offline Delivery**: Queuing push payloads until the device reconnects.

---

## SECTION 7: Email Notification Architecture

Enterprise-grade email dispatch.

- **Template Engine**: Reusable Blade/Markdown components.
- **Branding**: Dynamic injection of the Property's logo, colors, and legal footer.
- **Attachments**: Support for PDF, Excel, and ZIP inclusions directly from the Reporting Foundation.
- **Delivery Tracking**: Webhook ingestion from the ESP (e.g., Postmark/SendGrid) for 'Delivered' events.
- **Open Tracking**: Pixel tracking for critical compliance emails.
- **Bounce Tracking**: Automated suppression list management to protect domain reputation.

---

## SECTION 8: WhatsApp Integration Readiness

Preparing for the dominant mobile messaging platform.

- **Template Strategy**: Strict adherence to WhatsApp Business pre-approved template structures.
- **Approval Strategy**: Internal workflow mapping to WhatsApp's template approval process.
- **Queue Strategy**: Dedicated, rate-limited queue to prevent API bans.
- **Rate Limit Strategy**: Dynamic throttling based on the property's WhatsApp API tier.
- **Media Support**: Support for sending images and documents via WhatsApp.

---

## SECTION 9: Notification Preference Center

A granular matrix defining *how* and *when* a user is notified.

- **User Preference**: Individual opt-ins/opt-outs per channel (excluding mandatory alerts).
- **Department Preference**: Default settings for all users in a department.
- **Role Preference**: Defaults based on Spatie Roles.
- **Property Preference**: Global overrides (e.g., "Disable SMS for all staff at Property A").
- **Global Preference**: Corporate-level master switches.

---

## SECTION 10: Quiet Hours Engine

Respecting work-life balance and shift schedules.

- **Do Not Disturb**: User-defined suppression windows (e.g., 22:00 - 06:00).
- **Night Shift Override**: Adjusting quiet hours based on the user's active shift schedule.
- **Emergency Bypass**: Absolute override. Emergencies and Critical Alerts ignore all Quiet Hours settings.
- **Executive Override**: Specific critical executive digests can bypass DND by policy.

---

## SECTION 11: Escalation Engine

Automated hierarchical routing when SLAs are breached.

- **15 Minutes**: Tier 1 SLA breach (e.g., VIP luggage delay).
- **30 Minutes**: Tier 2 SLA breach.
- **1 Hour**: Tier 3 SLA breach.
- **4 Hours**: Shift-level breach.
- **24 Hours**: Daily management breach.
- **Supervisor**: First escalation target.
- **Manager**: Second escalation target.
- **Executive**: Final escalation target.

---

## SECTION 12: Critical Alert Framework

Handling highest-priority operational events.

- **Red Alert**: Immediate operational shutdown or severe issue.
- **Safety Alert**: Physical safety hazards requiring immediate broadcast.
- **Security Alert**: Active security threats.
- **Incident Alert**: Rapid deployment of incident response teams.
- **Guest Emergency**: Medical or critical guest issues.
- **System Failure**: IT infrastructure downtime alerts.

---

## SECTION 13: Digest Engine

Reducing notification fatigue through batching.

- **Daily Digest**: A morning summary of the previous day's unresolved alerts.
- **Weekly Digest**: High-level statistical summary.
- **Monthly Digest**: Long-term operational summary.
- **Executive Digest**: Specific roll-ups for GMs and Corporate.
- **Department Digest**: Shift-end summaries for department heads.

---

## SECTION 14: Notification Templates

Standardization of all outgoing messages.

- **Versioning**: Templates are version-controlled to ensure backward compatibility.
- **Localization**: Multi-language support driven by the User's preferred language.
- **Property Branding**: Tenant-aware template compilation.
- **Template Registry**: A central UI for admins to modify text without touching code.

---

## SECTION 15: Queue Architecture

Mandatory asynchronous processing.

- **Notification Queue**: Standard background processing.
- **Priority Queue**: Dedicated workers for Critical and Emergency alerts.
- **Retry Queue**: Isolated processing for failed dispatches.
- **Dead Letter Queue**: Holding area for permanently failed messages requiring admin review.
- **Worker Strategy**: Horizontally scaled Redis-backed worker pools.

---

## SECTION 16: Retry Strategy

Robust failure recovery.

- **Retry 1**: 60 seconds (transient network errors).
- **Retry 2**: 5 minutes (temporary API outages).
- **Retry 3**: 15 minutes (extended outages).
- **Escalate**: If Retry 3 fails, the system logs a system error and alerts IT/Admin.

---

## SECTION 17: Audit Trail

Strict immutability for compliance.

- **Sent**: Timestamp of dispatch to the channel provider.
- **Delivered**: Verified receipt by the provider.
- **Opened**: Verified open (Email/In-App).
- **Clicked**: Verified interaction with a call-to-action.
- **Failed**: Timestamp and exact error payload.
- **Retried**: Log of every retry attempt.
- **Escalated**: Record of SLA breach routing.

---

## SECTION 18: Read Receipt Engine

Accountability tracking for operational tasks.

- **Read Tracking**: Passive tracking when the notification payload is loaded on screen.
- **Acknowledgement Tracking**: Explicit user action clicking "Acknowledge".
- **Acceptance Tracking**: Explicit user action clicking "Accept Task".

---

## SECTION 19: Notification Inbox

UI architecture for notification consumption.

- **User Inbox**: Personal notifications and assignments.
- **Department Inbox**: Shared queue for alerts assigned to a department role (e.g., "To: Engineering Team").
- **Executive Inbox**: Filtered view for high-level escalations.
- **Corporate Inbox**: Multi-property alerts aggregated for regional managers.

---

## SECTION 20: Multi Property Architecture

Scaling the foundation across the enterprise.

- **Property Scope**: Notifications restricted to a single hotel.
- **Group Scope**: Alerts broadcasted to a cluster (e.g., "All Bali Resorts").
- **Regional Scope**: Broadcasts to a geographic region.
- **Corporate Scope**: Global portfolio broadcasts.

---

## SECTION 21: Permission Architecture

Governance over notification generation and consumption.

- **Spatie Permission**: Integration with the core RBAC system.
- **Visibility Rules**: Users can only see notifications bound to their Property, Department, or explicitly to their User ID.
- **Ownership Rules**: Only system administrators or specific roles can send manual "Announcements".

---

## SECTION 22: Integration Contract

The strict contract between business modules and the Notification Foundation.

- **Housekeeping**: e.g., Room Ready, Task Assigned.
- **Logbook**: e.g., Handover Complete, Escalation.
- **Engineering**: e.g., Work Order Created, Asset Offline.
- **Inventory**: e.g., Low Stock Alert.
- **Procurement**: e.g., PO Approval Required.
- **Incident**: e.g., Incident Logged.
- **PTW**: e.g., Permit Expiring.
- **Guest Request**: e.g., VIP Request SLA Warning.
- **PMS**: e.g., VIP Check-in.
- **HRIS**: e.g., Shift Change.
- **Accounting**: e.g., Night Audit Complete.

---

## SECTION 23: Event Driven Architecture

The decoupling mechanism.

- **Events**: Standardized Laravel Events containing DTO payloads.
- **Listeners**: Notification Foundation listeners that catch specific module events.
- **Subscribers**: Grouped listeners for complex domains.
- **Event Bus**: The underlying infrastructure (Redis/RabbitMQ readiness) for cross-service communication.

---

## SECTION 24: API Readiness

Headless support for external and decoupled interfaces.

- **REST API**: Standard JSON endpoints for mobile and PWA consumption.
- **Webhook API**: Endpoints for ESPs (Email Service Providers) to report delivery status back to IVORQ.
- **External Integration**: Ability to accept trigger payloads from legacy on-premise systems.

---

## SECTION 25: Dashboard Widget Framework

UI components provided by the Foundation.

- **Recent Notifications**: Quick dropdown feed.
- **Failed Deliveries**: Admin widget showing system health.
- **Escalations**: Manager widget showing breached SLAs.
- **Critical Alerts**: Red-alert banner for emergencies.
- **Digests**: Executive widget summarizing the day.

---

## SECTION 26: Search Architecture

Enterprise searchability within the notification history.

- **Keyword**: Full-text search on payload content.
- **Date Range**: Time-bound filtering.
- **User**: Search by sender or recipient.
- **Department**: Search by target department.
- **Priority**: Filter by Critical/Emergency.
- **Status**: Filter by Unread, Failed, Escalated.

---

## SECTION 27: Reporting Readiness

Integration with the Reporting Foundation.

- **Notification Report**: Volume of notifications sent per channel.
- **Delivery Report**: Success/Failure rates to monitor ESP health.
- **Escalation Report**: Tracking which departments breach SLAs most often.
- **Executive Report**: High-level summary of critical broadcasts.

---

## SECTION 28: Compliance Readiness

Supporting legal and regulatory requirements.

- **ISO 9001**: Audit trails for task communication.
- **ISO 45001**: Immediate broadcast records for safety incidents.
- **Corporate Audit**: Immutable history of who was told what, and when.
- **Legal Audit**: Tamper-proof read receipts.
- **Insurance Investigation**: Evidence packages of emergency broadcasts.

---

## SECTION 29: Data Retention Policy

Managing database scale.

- **Hot Storage**: 30 Days (Fast querying in active UI).
- **Warm Storage**: 90 Days (Available via search/archive).
- **Cold Archive**: 1-7 Years (Exported to S3/Glacier).
- **Purge Policy**: Automated cron deletions for routine Information-type alerts.
- **Legal Hold**: Explicit flag preventing an alert and its read-receipts from ever being purged.

---

## SECTION 30: Performance Architecture

Scaling the dispatch engine.

- **High Volume Delivery**: Optimized chunking for global broadcasts (e.g., 5,000 staff).
- **Queue Scaling**: Dynamic worker scaling during shift changes.
- **Rate Limiting**: Protecting external APIs (WhatsApp/Email) from being hammered.
- **Concurrency**: Preventing race conditions in Read Receipt tracking.

---

## SECTION 31: Disaster Recovery

Ensuring reliability.

- **Failed Delivery Recovery**: UI for admins to bulk-retry failed batches.
- **Queue Recovery**: Graceful handling of Redis crashes.
- **Replay Strategy**: Ability to replay missed events from the Event Bus if the Notification worker dies.

---

## SECTION 32: AI Readiness

Preparing the foundation for future intelligence.

- **Notification Summary**: AI condensing 50+ unread alerts into a 3-sentence morning briefing.
- **Smart Prioritization**: AI learning which alerts a specific user clicks first and sorting the feed accordingly.
- **Trend Detection**: AI detecting a sudden spike in "Engineering" alerts and notifying the GM.
- **Alert Correlation**: Grouping 10 similar alerts into one master incident alert.

---

## SECTION 33: Executive Communication Center

Specialized views for leadership.

- **Executive Inbox**: Noise-free feed of only highest-priority items.
- **Executive Digest**: Curated daily summaries.
- **Executive Escalation Feed**: Real-time view of failing operational SLAs.
- **Executive Watchlist**: Alerts strictly tied to VIPs or critical assets the executive follows.

---

## SECTION 34: Platform Standards

Mandatory standards enforced by the Notification Foundation.

- **Mandatory Standards**: All modules MUST fire standardized DTO-backed Events. No module is allowed to implement its own direct push or email logic. All outgoing communication must route through the shared Notification Foundation.

---

## SECTION 35: Open CTO Questions

1. **Email Service Provider (ESP)**: Which ESP will IVORQ standardize on (e.g., Postmark, SendGrid, AWS SES) for optimal deliverability and webhook reliability?
2. **Push Provider Infrastructure**: Should we build directly against Firebase Cloud Messaging (FCM) or use an abstraction service like Pusher/OneSignal?
3. **Queue Infrastructure**: Will Redis be sufficient for the required throughput, or should we introduce RabbitMQ/Kafka for the Event Bus?
4. **WebSocket Provider**: Will we use Laravel Reverb (native) or a hosted solution (e.g., Soketi, Pusher) for real-time In-App notifications?
5. **Localization Scope**: Should notification templates be translated dynamically based on the receiving user's preference, or the sending property's default language?

---

## CTO DECISIONS & STRATEGIC RECOMMENDATIONS

### 1. WhatsApp Provider Strategy
**Recommendation**: Standardize on **Twilio** or **Meta Official Cloud API**. Twilio provides a more robust abstraction for failover (falling back to SMS if WhatsApp fails), while Meta Official provides lower latency and direct integration cost benefits.

### 2. Push Notification Strategy
**Recommendation**: Standardize on **Firebase Cloud Messaging (FCM)**. FCM provides a unified API for both Android and iOS, is highly reliable, and integrates seamlessly with Laravel via established open-source packages, avoiding the vendor lock-in and pricing tiers of third-party wrappers like OneSignal.

### 3. Notification Retention Policy
**Recommendation**:
- **Information/Routine Alerts**: Purge after 30 days.
- **Approvals/Escalations**: Archive after 90 days, retain for 1 year.
- **Critical/Emergency**: Retain for 7 years (Cold Storage) for compliance and liability protection.

### 4. Escalation Hierarchy
**Recommendation**: Implement a dynamic fallback hierarchy. If a target is off-shift (checked against the HRIS/Roster integration), the escalation engine should automatically bypass them and route to the active 'Duty Manager' or the next available person in the chain, rather than waiting for the timeout.

### 5. Emergency Broadcast Strategy
**Recommendation**: Implement a **"Break Glass"** multi-channel redundancy protocol. Emergency broadcasts must bypass the standard Queue and hit a dedicated High-Priority Queue. They must be dispatched simultaneously via In-App, Push, SMS, and WhatsApp to guarantee delivery regardless of the user's data connectivity.

---

**Status**: READY FOR CTO REVIEW
