# Draft: Incidents & Announcements System

## Requirements (confirmed)
- Incidents sistemi: Monitoring'den otomatik + manual incident yönetimi
- Announcements sistemi: Basit time-based bilgilendirme paylaşımı
- Referans UI: status.claude.com

## Research Findings

### Backend (Mevcut)
- **Monitor** model: UUID, team-scoped, `last_status` tracking
- **MonitorCheck**: Her check'in sonucu, `status` (up/down/degraded)
- **ProcessCheckResult** job: Status belirleme ve alert tetikleme
- **StatusPage** model: Many-to-many monitor ilişkisi, `is_published` flag
- **Incidents/Announcements**: Backend'de HİÇ yok - sıfırdan yapılacak

### Frontend (Mevcut)
- Models: `Model` + `InteractsWithPersistence` + `HasTimestamps` pattern
- Controllers: Singleton pattern, `ValueNotifier`, CRUD actions
- Routes: `/incidents` zaten "Coming Soon" olarak placeholder var
- Enums: `value`, `label`, `fromValue()`, `selectOptions` pattern
- `ActivityType.incident` ve `SearchResultType.incident` zaten var
- en.json'da incident translation key'leri mevcut
- **Announcements**: Hiçbir referans yok

### UI Pattern (status.claude.com referans)
- Incident: Date header → Title (color-coded) → Timeline updates (Resolved → Identified → Investigating)
- Her update: Status label + timestamp + description
- Impact levels: major_outage, partial_outage, degraded_performance
- Status flow: Investigating → Identified → Monitoring → Resolved

## Technical Decisions
- **Incident-Monitor ilişkisi**: Many-to-Many (bir incident birden fazla monitörü etkileyebilir)
- **Otomatik incident eşiği**: Monitor ayarlarından yapılandırılabilir (her monitör için ayrı eşik)
- **Impact seviyeleri**: 4 seviye - Major Outage, Partial Outage, Degraded Performance, Under Maintenance
- **Incident status flow**: Investigating → Identified → Monitoring → Resolved

## Open Questions
1. ~~Auto-incident: Kaç ardışık down check'ten sonra otomatik incident?~~ → ANSWERED: Configurable per monitor
2. ~~Impact levels: Hangi seviyeleri destekleyeceğiz?~~ → ANSWERED: 4 levels (Claude-style)
3. ~~Incident-Monitor ilişkisi?~~ → ANSWERED: Many-to-Many
4. Status page public view'da incidents nasıl gösterilecek?
5. Announcements: Status page'e mi bağlı, team'e mi?
6. Notification: Incident oluştuğunda/güncellendiğinde bildirim gidecek mi?
7. Incident başlangıç: Auto-incident açıldığında default durumu ne olacak?
8. Announcements: Scheduled announcements desteklenecek mi?

## Scope Boundaries
- INCLUDE: (pending)
- EXCLUDE: (pending)
