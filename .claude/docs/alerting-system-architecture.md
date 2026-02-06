# Alerting System Architecture

> **Status:** Design Complete
> **Date:** 2026-02-05
> **Author:** Architecture Discussion

## Overview

Uptizm alerting sistemi, monitor durumu ve metrik değerlerine göre kullanıcıları uyaran kapsamlı bir bildirim altyapısıdır. Sistem üç farklı alert türünü destekler ve state-based yaklaşımla çalışır.

## Alert Türleri

### 1. Status-Based Alerts

Monitor'ün up/down durumuna göre tetiklenen alertler.

| Durum | Varsayılan Severity |
|-------|---------------------|
| Monitor Down | Critical |
| Monitor Up (Recovery) | Info |

**Otomatik Oluşturma:** Her yeni monitor için status alert rule otomatik oluşturulur.

### 2. Threshold-Based Alerts

Metrik değerlerinin belirli bir eşiği aşması/altına düşmesi durumunda tetiklenen alertler.

#### Desteklenen Operatörler

| Operatör | Açıklama | Örnek |
|----------|----------|-------|
| `>` | Büyüktür | `cpu_usage > 80` |
| `>=` | Büyük eşit | `connections >= 100` |
| `<` | Küçüktür | `free_memory < 500` |
| `<=` | Küçük eşit | `response_time <= 200` |
| `==` | Eşittir | `error_count == 0` |
| `!=` | Eşit değil | `status_code != 200` |
| `between` | Aralık içinde | `latency between 100-500` |
| `outside` | Aralık dışında | `orders outside 800-1200` |

**Örnek Kullanım:**
```
Kural: response_time > 5000ms
Severity: Warning
Consecutive Checks: 2
```

### 3. Anomaly-Based Alerts

Metrik değerlerindeki anormal değişimleri tespit eden alertler. İstatistiksel analiz kullanır.

#### Algoritma: Z-Score + Percentage Change

Anomali tespiti için iki koşulun **birlikte** sağlanması gerekir:

```
Anomali = (|Z-Score| > 2) AND (|% Change| > 20%)
```

##### Z-Score Hesaplama

```
Z-Score = (Güncel Değer - Ortalama) / Standart Sapma
```

- **Baseline Period:** Son 7 gün
- **Minimum Data:** 3 data point (7 günden az veri varsa mevcut veriyle çalışır)
- **Threshold:** |Z-Score| > 2

##### Percentage Change Hesaplama

```
% Change = ((Güncel Değer - Önceki Değer) / Önceki Değer) × 100
```

- **Karşılaştırma:** Güncel değer vs. bir önceki periyot
- **Threshold:** |% Change| > 20%

#### Örnek: E-Ticaret Sipariş Anomalisi

```
Son 7 günlük "24 saatlik sipariş sayısı":
- Pazartesi: 1000
- Salı: 1050
- Çarşamba: 980
- Perşembe: 1020
- Cuma: 1100
- Cumartesi: 1080
- Bugün: 650 ← Kontrol edilen değer

Hesaplama:
- Ortalama: 1038
- Standart Sapma: 50
- Z-Score: (650 - 1038) / 50 = -7.76 ✓ (|7.76| > 2)
- % Change: (650 - 1080) / 1080 = -39.8% ✓ (|-39.8%| > 20%)

Sonuç: ANOMALY ALERT!
```

---

## Severity Seviyeleri

| Seviye | Renk | Kullanım |
|--------|------|----------|
| **Critical** | Kırmızı | Acil müdahale gerektiren durumlar (monitor down, kritik eşik aşımı) |
| **Warning** | Sarı | Dikkat gerektiren durumlar (yüksek response time, yaklaşan limit) |
| **Info** | Mavi | Bilgilendirme amaçlı (recovery, minor değişimler) |

---

## Scope & Inheritance (Kapsam ve Kalıtım)

### İki Seviyeli Yapı

```
┌─────────────────────────────────────────┐
│              TEAM LEVEL                 │
│  (Tüm team monitor'lerine uygulanır)    │
├─────────────────────────────────────────┤
│           MONITOR LEVEL                 │
│    (Spesifik monitor için override)     │
└─────────────────────────────────────────┘
```

### Inheritance Kuralları

1. **Team-level rule'lar** tüm team monitor'lerine otomatik uygulanır
2. **Monitor-level rule'lar** team rule'larını override eder
3. Aynı metrik için hem team hem monitor rule varsa, **monitor rule geçerli**

### Örnek Senaryo

```
Team Default:
  - response_time > 5000ms → Warning

Payment Monitor Override:
  - response_time > 2000ms → Critical

Sonuç:
  - Payment Monitor: 2000ms kuralı (Critical)
  - Diğer Monitor'ler: 5000ms kuralı (Warning)
```

---

## State Machine

Alert'ler state-based yaklaşımla yönetilir. Aynı koşul için tekrar tekrar alert üretilmez.

```
                    Koşul sağlandı
                    (consecutive checks karşılandı)
┌─────────┐         ─────────────────────────────►         ┌──────────┐
│         │                                                │          │
│   OK    │                                                │ ALERTING │
│         │                                                │          │
└─────────┘         ◄─────────────────────────────         └──────────┘
                    Koşul normale döndü
                    (auto-resolve + notification)
```

### State Transitions

| Başlangıç | Bitiş | Tetikleyici | Aksiyon |
|-----------|-------|-------------|---------|
| OK | ALERTING | Koşul sağlandı (N consecutive) | Alert oluştur, notification gönder |
| ALERTING | OK | Koşul normale döndü | Alert resolve et, recovery notification gönder |

### Consecutive Checks (Flapping Önleme)

Alert tetiklenmeden önce kaç ardışık check'in başarısız olması gerektiği **her rule için ayrı ayarlanabilir**.

```
Örnek:
  - consecutive_checks: 3
  - Check interval: 1 dakika

  Senaryo:
    Check 1: response_time = 6000ms (fail) → counter: 1
    Check 2: response_time = 5500ms (fail) → counter: 2
    Check 3: response_time = 5200ms (fail) → counter: 3 → ALERT!

  Eğer Check 2'de normale dönseydi:
    Check 2: response_time = 4000ms (pass) → counter: 0 (reset)
```

---

## Default Alert Rules

Her yeni monitor oluşturulduğunda otomatik olarak eklenen rule'lar:

| Rule | Koşul | Severity | Consecutive |
|------|-------|----------|-------------|
| Monitor Status | `status == down` | Critical | 1 |
| Response Time | `response_time > 5000ms` | Warning | 2 |

Bu default'lar team settings'den customize edilebilir.

---

## Data Model

### AlertRule Tablosu

```sql
CREATE TABLE alert_rules (
    id                  BIGINT PRIMARY KEY AUTO_INCREMENT,
    team_id             BIGINT NOT NULL,           -- FK to teams
    monitor_id          BIGINT NULL,               -- FK to monitors (NULL = team-level)

    -- Rule Definition
    name                VARCHAR(255) NOT NULL,
    type                ENUM('status', 'threshold', 'anomaly') NOT NULL,
    enabled             BOOLEAN DEFAULT TRUE,

    -- Condition (for threshold/anomaly)
    metric_key          VARCHAR(255) NULL,         -- e.g., 'response_time', 'db_connections'
    operator            VARCHAR(20) NULL,          -- '>', '<', 'between', etc.
    threshold_value     DECIMAL(20,4) NULL,        -- Single value for >, <, etc.
    threshold_min       DECIMAL(20,4) NULL,        -- For 'between' / 'outside'
    threshold_max       DECIMAL(20,4) NULL,        -- For 'between' / 'outside'

    -- Alert Settings
    severity            ENUM('critical', 'warning', 'info') NOT NULL DEFAULT 'warning',
    consecutive_checks  INT NOT NULL DEFAULT 1,

    -- Timestamps
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_team_id (team_id),
    INDEX idx_monitor_id (monitor_id),
    INDEX idx_type (type),
    INDEX idx_enabled (enabled)
);
```

### Alert Tablosu

```sql
CREATE TABLE alerts (
    id                  BIGINT PRIMARY KEY AUTO_INCREMENT,
    alert_rule_id       BIGINT NOT NULL,           -- FK to alert_rules
    monitor_id          BIGINT NOT NULL,           -- FK to monitors

    -- Alert State
    status              ENUM('alerting', 'resolved') NOT NULL DEFAULT 'alerting',

    -- Trigger Info
    triggered_at        TIMESTAMP NOT NULL,
    resolved_at         TIMESTAMP NULL,
    trigger_value       DECIMAL(20,4) NULL,        -- Değer alert anında
    trigger_message     TEXT NULL,                 -- İnsan okunabilir mesaj

    -- Timestamps
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_alert_rule_id (alert_rule_id),
    INDEX idx_monitor_id (monitor_id),
    INDEX idx_status (status),
    INDEX idx_triggered_at (triggered_at)
);
```

### AlertRuleState Tablosu (Runtime State)

```sql
CREATE TABLE alert_rule_states (
    id                  BIGINT PRIMARY KEY AUTO_INCREMENT,
    alert_rule_id       BIGINT NOT NULL,           -- FK to alert_rules
    monitor_id          BIGINT NOT NULL,           -- FK to monitors

    -- Current State
    current_status      ENUM('ok', 'alerting') NOT NULL DEFAULT 'ok',
    consecutive_failures INT NOT NULL DEFAULT 0,
    last_check_at       TIMESTAMP NULL,
    last_value          DECIMAL(20,4) NULL,

    -- Active Alert Reference
    active_alert_id     BIGINT NULL,               -- FK to alerts (current alerting alert)

    -- Timestamps
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_rule_monitor (alert_rule_id, monitor_id)
);
```

---

## Flutter Models

### AlertRuleType Enum

```dart
enum AlertRuleType {
  status('status'),
  threshold('threshold'),
  anomaly('anomaly');

  final String value;
  const AlertRuleType(this.value);

  static AlertRuleType fromValue(String value) {
    return AlertRuleType.values.firstWhere(
      (e) => e.value == value,
      orElse: () => AlertRuleType.threshold,
    );
  }

  static List<SelectOption> get selectOptions => [
    SelectOption(label: 'Status', value: status.value),
    SelectOption(label: 'Threshold', value: threshold.value),
    SelectOption(label: 'Anomaly', value: anomaly.value),
  ];
}
```

### AlertSeverity Enum

```dart
enum AlertSeverity {
  critical('critical'),
  warning('warning'),
  info('info');

  final String value;
  const AlertSeverity(this.value);

  static AlertSeverity fromValue(String value) {
    return AlertSeverity.values.firstWhere(
      (e) => e.value == value,
      orElse: () => AlertSeverity.warning,
    );
  }

  Color get color => switch (this) {
    AlertSeverity.critical => const Color(0xFFDC2626),
    AlertSeverity.warning => const Color(0xFFF59E0B),
    AlertSeverity.info => const Color(0xFF3B82F6),
  };

  String get label => switch (this) {
    AlertSeverity.critical => 'Critical',
    AlertSeverity.warning => 'Warning',
    AlertSeverity.info => 'Info',
  };

  static List<SelectOption> get selectOptions => [
    SelectOption(label: 'Critical', value: critical.value),
    SelectOption(label: 'Warning', value: warning.value),
    SelectOption(label: 'Info', value: info.value),
  ];
}
```

### AlertOperator Enum

```dart
enum AlertOperator {
  greaterThan('>'),
  greaterThanOrEqual('>='),
  lessThan('<'),
  lessThanOrEqual('<='),
  equal('=='),
  notEqual('!='),
  between('between'),
  outside('outside');

  final String value;
  const AlertOperator(this.value);

  static AlertOperator fromValue(String value) {
    return AlertOperator.values.firstWhere(
      (e) => e.value == value,
      orElse: () => AlertOperator.greaterThan,
    );
  }

  String get label => switch (this) {
    AlertOperator.greaterThan => 'Greater than (>)',
    AlertOperator.greaterThanOrEqual => 'Greater than or equal (>=)',
    AlertOperator.lessThan => 'Less than (<)',
    AlertOperator.lessThanOrEqual => 'Less than or equal (<=)',
    AlertOperator.equal => 'Equal (==)',
    AlertOperator.notEqual => 'Not equal (!=)',
    AlertOperator.between => 'Between',
    AlertOperator.outside => 'Outside range',
  };

  bool get requiresRange => this == between || this == outside;
}
```

### AlertStatus Enum

```dart
enum AlertStatus {
  alerting('alerting'),
  resolved('resolved');

  final String value;
  const AlertStatus(this.value);

  static AlertStatus fromValue(String value) {
    return AlertStatus.values.firstWhere(
      (e) => e.value == value,
      orElse: () => AlertStatus.alerting,
    );
  }
}
```

### AlertRule Model

```dart
class AlertRule extends Model with HasTimestamps, InteractsWithPersistence {
  @override
  String get table => 'alert_rules';

  @override
  String get resource => 'alert-rules';

  @override
  List<String> get fillable => [
    'team_id',
    'monitor_id',
    'name',
    'type',
    'enabled',
    'metric_key',
    'operator',
    'threshold_value',
    'threshold_min',
    'threshold_max',
    'severity',
    'consecutive_checks',
  ];

  // Getters
  int? get teamId => (getAttribute('team_id') as num?)?.toInt();
  int? get monitorId => (getAttribute('monitor_id') as num?)?.toInt();
  String? get name => getAttribute('name') as String?;

  AlertRuleType get type => AlertRuleType.fromValue(
    getAttribute('type') as String? ?? 'threshold',
  );

  bool get enabled => getAttribute('enabled') == true || getAttribute('enabled') == 1;
  String? get metricKey => getAttribute('metric_key') as String?;

  AlertOperator? get operator => getAttribute('operator') != null
      ? AlertOperator.fromValue(getAttribute('operator') as String)
      : null;

  double? get thresholdValue => (getAttribute('threshold_value') as num?)?.toDouble();
  double? get thresholdMin => (getAttribute('threshold_min') as num?)?.toDouble();
  double? get thresholdMax => (getAttribute('threshold_max') as num?)?.toDouble();

  AlertSeverity get severity => AlertSeverity.fromValue(
    getAttribute('severity') as String? ?? 'warning',
  );

  int get consecutiveChecks => (getAttribute('consecutive_checks') as num?)?.toInt() ?? 1;

  // Setters
  set teamId(int? value) => setAttribute('team_id', value);
  set monitorId(int? value) => setAttribute('monitor_id', value);
  set name(String? value) => setAttribute('name', value);
  set type(AlertRuleType value) => setAttribute('type', value.value);
  set enabled(bool value) => setAttribute('enabled', value);
  set metricKey(String? value) => setAttribute('metric_key', value);
  set operator(AlertOperator? value) => setAttribute('operator', value?.value);
  set thresholdValue(double? value) => setAttribute('threshold_value', value);
  set thresholdMin(double? value) => setAttribute('threshold_min', value);
  set thresholdMax(double? value) => setAttribute('threshold_max', value);
  set severity(AlertSeverity value) => setAttribute('severity', value.value);
  set consecutiveChecks(int value) => setAttribute('consecutive_checks', value);

  // Computed
  bool get isTeamLevel => monitorId == null;
  bool get isMonitorLevel => monitorId != null;

  // Static methods
  static Future<AlertRule?> find(dynamic id) =>
      InteractsWithPersistence.findById<AlertRule>(id, AlertRule.new);

  static AlertRule fromMap(Map<String, dynamic> map) => AlertRule()
    ..setRawAttributes(map, sync: true)
    ..exists = map.containsKey('id');
}
```

### Alert Model

```dart
class Alert extends Model with HasTimestamps, InteractsWithPersistence {
  @override
  String get table => 'alerts';

  @override
  String get resource => 'alerts';

  @override
  List<String> get fillable => [
    'alert_rule_id',
    'monitor_id',
    'status',
    'triggered_at',
    'resolved_at',
    'trigger_value',
    'trigger_message',
  ];

  // Getters
  int? get alertRuleId => (getAttribute('alert_rule_id') as num?)?.toInt();
  int? get monitorId => (getAttribute('monitor_id') as num?)?.toInt();

  AlertStatus get status => AlertStatus.fromValue(
    getAttribute('status') as String? ?? 'alerting',
  );

  DateTime? get triggeredAt => getAttribute('triggered_at') != null
      ? DateTime.tryParse(getAttribute('triggered_at') as String)
      : null;

  DateTime? get resolvedAt => getAttribute('resolved_at') != null
      ? DateTime.tryParse(getAttribute('resolved_at') as String)
      : null;

  double? get triggerValue => (getAttribute('trigger_value') as num?)?.toDouble();
  String? get triggerMessage => getAttribute('trigger_message') as String?;

  // Computed
  bool get isAlerting => status == AlertStatus.alerting;
  bool get isResolved => status == AlertStatus.resolved;

  Duration? get duration {
    if (triggeredAt == null) return null;
    final end = resolvedAt ?? DateTime.now();
    return end.difference(triggeredAt!);
  }

  // Setters
  set alertRuleId(int? value) => setAttribute('alert_rule_id', value);
  set monitorId(int? value) => setAttribute('monitor_id', value);
  set status(AlertStatus value) => setAttribute('status', value.value);
  set triggeredAt(DateTime? value) => setAttribute('triggered_at', value?.toIso8601String());
  set resolvedAt(DateTime? value) => setAttribute('resolved_at', value?.toIso8601String());
  set triggerValue(double? value) => setAttribute('trigger_value', value);
  set triggerMessage(String? value) => setAttribute('trigger_message', value);

  // Static methods
  static Future<Alert?> find(dynamic id) =>
      InteractsWithPersistence.findById<Alert>(id, Alert.new);

  static Alert fromMap(Map<String, dynamic> map) => Alert()
    ..setRawAttributes(map, sync: true)
    ..exists = map.containsKey('id');
}
```

---

## Backend Evaluation Flow

Alert evaluation tamamen Laravel backend'de gerçekleşir. Flutter sadece görüntüleme ve yönetim yapar.

### Evaluation Akışı

```
┌─────────────────────────────────────────────────────────────────┐
│                      MONITOR CHECK JOB                          │
│                    (Her dakika çalışır)                         │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    1. FETCH ALERT RULES                         │
│                                                                 │
│   - Team-level rules (monitor_id = NULL)                        │
│   - Monitor-specific rules                                       │
│   - Merge & resolve conflicts (monitor > team)                  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                  2. EVALUATE EACH RULE                          │
│                                                                 │
│   STATUS:    check monitor.status == 'down'                     │
│   THRESHOLD: compare metric_value [operator] threshold          │
│   ANOMALY:   calculate Z-Score + % Change                       │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                3. UPDATE RULE STATE                             │
│                                                                 │
│   Koşul sağlandı:                                               │
│     - consecutive_failures++                                    │
│     - if >= consecutive_checks → trigger alert                  │
│                                                                 │
│   Koşul sağlanmadı:                                             │
│     - consecutive_failures = 0                                  │
│     - if was alerting → resolve alert                           │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│               4. CREATE/RESOLVE ALERTS                          │
│                                                                 │
│   New Alert:                                                    │
│     - Create alert record (status: alerting)                    │
│     - Queue notification job                                    │
│                                                                 │
│   Resolve Alert:                                                │
│     - Update alert (status: resolved, resolved_at)              │
│     - Queue recovery notification job                           │
└─────────────────────────────────────────────────────────────────┘
```

### Anomaly Calculation Service

```php
class AnomalyDetectionService
{
    private const MIN_DATA_POINTS = 3;
    private const BASELINE_DAYS = 7;
    private const Z_SCORE_THRESHOLD = 2.0;
    private const PERCENT_CHANGE_THRESHOLD = 0.20; // 20%

    public function isAnomaly(Monitor $monitor, string $metricKey): AnomalyResult
    {
        // 1. Fetch historical data
        $data = $this->getHistoricalData($monitor, $metricKey, self::BASELINE_DAYS);

        if (count($data) < self::MIN_DATA_POINTS) {
            return AnomalyResult::insufficientData();
        }

        $currentValue = $data[0]; // En son değer
        $historicalValues = array_slice($data, 1);

        // 2. Calculate statistics
        $mean = array_sum($historicalValues) / count($historicalValues);
        $stdDev = $this->calculateStdDev($historicalValues, $mean);

        // 3. Z-Score
        $zScore = $stdDev > 0 ? ($currentValue - $mean) / $stdDev : 0;

        // 4. Percentage Change (vs previous value)
        $previousValue = $data[1];
        $percentChange = $previousValue > 0
            ? ($currentValue - $previousValue) / $previousValue
            : 0;

        // 5. Evaluate
        $isZScoreAnomaly = abs($zScore) > self::Z_SCORE_THRESHOLD;
        $isPercentAnomaly = abs($percentChange) > self::PERCENT_CHANGE_THRESHOLD;

        $isAnomaly = $isZScoreAnomaly && $isPercentAnomaly;

        return new AnomalyResult(
            isAnomaly: $isAnomaly,
            zScore: $zScore,
            percentChange: $percentChange,
            mean: $mean,
            stdDev: $stdDev,
            currentValue: $currentValue,
            dataPoints: count($data),
        );
    }

    private function calculateStdDev(array $values, float $mean): float
    {
        $variance = array_reduce($values, function ($carry, $value) use ($mean) {
            return $carry + pow($value - $mean, 2);
        }, 0) / count($values);

        return sqrt($variance);
    }
}
```

---

## API Endpoints

### Alert Rules

| Method | Endpoint | Açıklama |
|--------|----------|----------|
| GET | `/api/v1/teams/{team}/alert-rules` | Team'in tüm alert rule'larını listele |
| POST | `/api/v1/teams/{team}/alert-rules` | Yeni alert rule oluştur |
| GET | `/api/v1/alert-rules/{rule}` | Alert rule detayı |
| PUT | `/api/v1/alert-rules/{rule}` | Alert rule güncelle |
| DELETE | `/api/v1/alert-rules/{rule}` | Alert rule sil |
| GET | `/api/v1/monitors/{monitor}/alert-rules` | Monitor'ün alert rule'ları |
| POST | `/api/v1/monitors/{monitor}/alert-rules` | Monitor'e özel alert rule ekle |

### Alerts

| Method | Endpoint | Açıklama |
|--------|----------|----------|
| GET | `/api/v1/teams/{team}/alerts` | Team'in alertlerini listele (filtrelenebilir) |
| GET | `/api/v1/monitors/{monitor}/alerts` | Monitor'ün alertlerini listele |
| GET | `/api/v1/alerts/{alert}` | Alert detayı |
| POST | `/api/v1/alerts/{alert}/acknowledge` | Alert'i acknowledge et (opsiyonel) |

### Query Parameters

```
GET /api/v1/teams/{team}/alerts
  ?status=alerting|resolved
  ?severity=critical|warning|info
  ?monitor_id=123
  ?from=2026-01-01
  ?to=2026-02-01
  &per_page=20
  &page=1
```

---

## UI Components

### 1. Alert Rules List (Team Settings)

```
┌─────────────────────────────────────────────────────────────────┐
│  Alert Rules                                           [+ Add]  │
├─────────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ 🔴 Monitor Down Alert                      Team Default │    │
│  │    Type: Status | Severity: Critical                    │    │
│  │    Consecutive: 1                          [Edit] [Del] │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ 🟡 High Response Time                      Team Default │    │
│  │    Type: Threshold | response_time > 5000ms             │    │
│  │    Severity: Warning | Consecutive: 2      [Edit] [Del] │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ 🟡 Order Anomaly Detection                 Team Default │    │
│  │    Type: Anomaly | order_count                          │    │
│  │    Severity: Warning                       [Edit] [Del] │    │
│  └─────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
```

### 2. Alert Rule Form

```
┌─────────────────────────────────────────────────────────────────┐
│  Create Alert Rule                                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Name                                                           │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ High Response Time Alert                                │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                 │
│  Type                                                           │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ ○ Status   ● Threshold   ○ Anomaly                      │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                 │
│  Metric                             Operator                    │
│  ┌──────────────────────────┐      ┌────────────────────┐       │
│  │ response_time         ▼ │      │ Greater than (>) ▼ │       │
│  └──────────────────────────┘      └────────────────────┘       │
│                                                                 │
│  Threshold Value                                                │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ 5000                                                 ms │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                 │
│  Severity                                                       │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ ○ Critical   ● Warning   ○ Info                         │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                 │
│  Consecutive Checks                                             │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ 2                                                       │    │
│  └─────────────────────────────────────────────────────────┘    │
│  Alert only after this many consecutive failures               │
│                                                                 │
│                                        [Cancel]  [Save Rule]    │
└─────────────────────────────────────────────────────────────────┘
```

### 3. Active Alerts Panel (Dashboard)

```
┌─────────────────────────────────────────────────────────────────┐
│  🚨 Active Alerts (3)                              [View All]   │
├─────────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ 🔴 CRITICAL | api.example.com                           │    │
│  │    Monitor is DOWN                                      │    │
│  │    Started 5 minutes ago                                │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ 🟡 WARNING | payment.example.com                        │    │
│  │    response_time: 6.2s (threshold: 5s)                  │    │
│  │    Started 12 minutes ago                               │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ 🟡 WARNING | shop.example.com                           │    │
│  │    order_count anomaly detected (-42% change)           │    │
│  │    Started 1 hour ago                                   │    │
│  └─────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
```

### 4. Monitor Detail - Alerts Tab

```
┌─────────────────────────────────────────────────────────────────┐
│  Overview   Checks   Metrics   [Alerts]   Settings              │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Alert Rules for this Monitor                          [+ Add]  │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ 🔴 Monitor Down (from Team)                   Enabled ✓ │    │
│  │ 🟡 Response Time > 2000ms (Override)          Enabled ✓ │    │
│  │ 🟡 DB Connections > 80 (Custom)               Enabled ✓ │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                 │
│  Recent Alerts                                                  │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ 🟢 RESOLVED | Response Time                             │    │
│  │    Feb 5, 10:30 - Feb 5, 10:45 (15 min)                 │    │
│  │    Peak: 7.2s                                           │    │
│  ├─────────────────────────────────────────────────────────┤    │
│  │ 🔴 ALERTING | Monitor Down                              │    │
│  │    Started Feb 5, 11:00 (ongoing)                       │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Data Retention

| Veri Türü | Retention |
|-----------|-----------|
| Alert Rules | Kalıcı (silinene kadar) |
| Alerts | 90 gün |
| Alert Rule States | Kalıcı (rule silinince silinir) |

### Cleanup Job

```php
// Daily cleanup job
Alert::where('resolved_at', '<', now()->subDays(90))->delete();
```

---

## Future Considerations (Sonraki Adımlar)

### Notification Integration

Bu dokümantasyon sadece alerting altyapısını kapsar. Notification sistemi ayrı bir adım olarak entegre edilecek:

1. **Notification Channels:** Email, Slack, SMS, Push, Webhook
2. **Channel Routing:** Severity'ye göre farklı kanallar
3. **Escalation Policies:** Alert X dakika çözülmezse escalate
4. **On-call Schedules:** Kimin ne zaman notification alacağı

### Advanced Features

1. **Maintenance Windows:** Belirli zamanlarda alert'leri sustur
2. **Alert Grouping:** İlişkili alert'leri tek notification'da birleştir
3. **Dependencies:** Monitor A down ise Monitor B alert'lerini sustur
4. **Custom Webhooks:** Alert tetiklendiğinde custom endpoint'e POST

---

## Summary

Uptizm Alerting System, üç tür alert (Status, Threshold, Anomaly) destekleyen, state-based çalışan, team ve monitor seviyesinde konfigüre edilebilen kapsamlı bir uyarı sistemidir. Tüm evaluation backend'de yapılır, Flutter client sadece yönetim ve görüntüleme için kullanılır.

**Key Decisions:**
- 3 Severity Level: Critical, Warning, Info
- State-based: OK ↔ Alerting (no duplicate alerts)
- Auto-resolve with recovery notification
- Z-Score + % Change for anomaly detection
- 7-day baseline, minimum 3 data points
- Team rules auto-apply, monitor rules override
- 90-day alert retention
