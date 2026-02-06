# Draft: Status Page Custom Metrics

## Requirements (confirmed)
- **Metrik seçim modeli**: Kullanıcı, monitor'e ait metriklerden hangilerinin status page'de görüneceğini TEK TEK seçecek
- **Public sayfa görünümü**: Her monitor'ün altında badge/kartlar olarak gösterilecek (ör: CPU: 45%, Memory: 2.1GB, Status: Active)
- **UI akışı**: Monitor seçildiğinde, o monitor'ün metrik listesi hemen açılsın ve checkbox'larla seçilsin — tek adımda
- **Custom label**: HAYIR — orijinal metrik label'ı kullanılacak, status page'e özel isim yok
- **Automated tests**: TDD enforced (proje genelinde TDD var)

## Technical Decisions
- Pivot tablo genişletilecek: `status_page_monitor` tablosuna metric seçimleri eklenecek (muhtemelen JSON column veya ayrı pivot tablo)
- Backend: `attachMonitors` API'si metric bilgisini de kabul edecek
- Public controller: Seçili metriklerin son değerlerini `monitor_metric_values` tablosundan çekecek
- Flutter: Monitor seçim UI'ı genişletilecek — monitor seçince altında metric checkbox'ları gösterilecek

## Research Findings
- **Pivot tablo**: `status_page_monitor` → `display_order`, `custom_label` mevcut
- **Metric mappings**: Monitor model'de `metric_mappings` JSON field'ında saklanıyor
- **Metric values**: `monitor_metric_values` (TimescaleDB hypertable) — `metric_key`, `numeric_value`, `string_value`, `status_value`
- **Public page**: Şu an sadece uptime bar chart + response time gösteriyor, metrikleri çekmiyor
- **Flutter field isimleri vs DB**: Flutter `display_name`/`sort_order` → DB `custom_label`/`display_order` mapping var

## Open Questions
- ~~Metric seçimlerini nerede saklayalım?~~ → KARAR: Ayrı tablo `status_page_monitor_metrics`
- ~~Public sayfada metric tiplerine göre görünüm farklı mı olacak?~~ → KARAR: Evet, tip bazlı stiller
  - Numeric → değer+unit badge
  - Status → yeşil/kırmızı dot + text
  - String → plain text

## Confirmed Technical Decisions
- **Depolama**: Yeni `status_page_monitor_metrics` tablosu (normalize)
  - `status_page_id`, `monitor_id`, `metric_key` (JSON path), `display_order`
- **Görünüm**: Tip bazlı farklı stiller (numeric/status/string)
- **Custom metric label**: Yok — orijinal `metric_label` kullanılacak

## Scope Boundaries
- INCLUDE: Metric seçimi UI, backend depolama, public sayfa render
- INCLUDE: Tüm 3 metrik tipi (numeric, string, status)
- EXCLUDE: Custom label for metrics (orijinal yeterli)
- EXCLUDE: Chart/grafik (sadece son değer badge)
- EXCLUDE: Metric timeline/history görünümü
