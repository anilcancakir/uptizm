Uptizm'in gerçekte neyi kontrol ettiği, nelere mal olduğu ve nerede sınırlarının bittiği
hakkında net yanıtlar. Aşağıdaki her sayı, ürünün kendisinin çalıştığı aynı yapılandırma
ve enum değerlerinden geliyor; bir plan ya da limit değiştiğinde bu sayfa da onunla
birlikte değişir.

## Ürün hakkında

<details>
<summary>Kontroller hangi bölgelerden çalışıyor?</summary>

Her izleyici, desteklenen [[faq.region_count]] bölgeden herhangi birine sabitlenebilir:
[[faq.region_names]]. Seçtiğiniz tüm bölgeler aynı turda çalışır, bu yüzden yavaş bir
bölge sıradaki yerinden değil, o bölgenin kendisinden kaynaklanan bir durumdur.

</details>

<details>
<summary>Hangi protokolleri izleyebilirim?</summary>

İki tanesini: [[faq.monitor_types]]. Bir HTTP izleyici yapılandırılan adrese istek atar;
bir TCP izleyici ise yalnızca bir soket açıp bağlantı ve el sıkışma süresini ölçer. Bugün
ping kontrolü, DNS kontrolü ya da tarayıcı tabanlı bir kontrol yok.

</details>

<details>
<summary>Bir kontrol ne sıklıkla çalışabilir?</summary>

Bu, planınıza bağlı. Ayarlayabileceğiniz en kısa aralık: Free'de
[[faq.free_interval_seconds]] saniye, Pro'da [[faq.pro_interval_seconds]] saniye,
Business'ta [[faq.business_interval_seconds]] saniye, Enterprise'da ise
[[faq.enterprise_interval_seconds]] saniye. Planınızın alt sınırından daha uzun bir
aralığı her zaman seçebilirsiniz.

</details>

<details>
<summary>Free plan neleri içeriyor?</summary>

[[faq.region_count]] bölgenin tamamından, en sık [[faq.free_interval_seconds]] saniyede
bir kontrol edilen [[faq.free_monitors]] izleyici. En fazla [[faq.free_subscribers]]
e-posta abonesi alabilen [[faq.free_status_pages]] durum sayfası. Aşağıdaki kanalların
tamamı üzerinden uyarı gönderebilen [[faq.free_responders]] nöbetçi. TLS sertifika süresi
uyarıları ve yanıt metriği sınırları. AI anomali gelen kutusu dahildir; bu özellik sizi
Pro'ya geçmeye yönlendirmeden önce [[faq.free_ai_trials]] ücretsiz AI izleyici kurulumu
hakkı tanır.

</details>

<details>
<summary>Hangi uyarı kanallarını kullanabilirim?</summary>

[[faq.alert_channels]]. Kendi bildirimleriniz için kişisel SMS ve e-posta teslimi ayrı bir
tercihe bağlıdır; ancak bir izleyicinin çağırabileceği takım düzeyindeki uyarı hedefleri
bu dördüdür.

</details>

<details>
<summary>Bir çalışma süresi taahhüdü, yani SLA var mı?</summary>

Hayır. Uptizm bugün hiçbir planda bir çalışma süresi yüzdesi ya da destek yanıt süresi
taahhüt etmiyor.

</details>

## Yapay zeka hakkında

<details>
<summary>Yapay zeka gerçekte neyi görüyor, neyi görmüyor?</summary>

Bir olayı incelerken yapay zeka, o olayın kendi zaman çizelgesini ve izleyicilerine
kaydedilmiş kontrolleri okur: bölge, durum ve süre, ayrıca uç noktanızın döndürdüğü hata
mesajı, yanıt gövdesi ve yanıt başlıklarından en fazla [[faq.ai_char_limit]] karakter.
Dağıtımlarınızı, commit'lerinizi, CI sürecinizi, loglarınızı, trace'lerinizi, APM'inizi,
CDN'inizi ya da başka birinin durum sayfasını asla görmez: Uptizm'in çalıştırdığınız
hiçbir sisteme entegrasyonu yoktur, yalnızca kendi kontrollerinin ölçtüğü şeyden çıkarım
yapabilir.

</details>

## Hesabınız ve verileriniz hakkında

<details>
<summary>Kontrol geçmişi ne kadar süre saklanıyor?</summary>

[[faq.retention_days]] gün boyunca ham kontrol verisi. Bu sürenin ötesindeki geçmiş, tam
çözünürlükte sonsuza dek tutulmak yerine saatlik ve günlük özetlere indirgenir.

</details>

<details>
<summary>Nasıl iptal ederim?</summary>

Faturalandırma ayarlarınızdan, istediğiniz an. İptal etmek sizi anında dışarıda bırakmaz:
planınız, zaten ödediğiniz dönemin sonuna kadar aktif kalır; aynı ekran, faturalarınız ve
ödeme yönteminiz için Stripe'ın faturalandırma portalını da açar.

</details>

<details>
<summary>Verilerimin silinmesini nasıl sağlarım?</summary>

Hesabınızı silmek API token'larınızı ve profil fotoğrafınızı kaldırır, ardından kullanıcı
kaydının kendisini siler; bu bir devre dışı bırakma değil, kalıcı bir silmedir. Bir
takımı silmek, ona bağlı izleyicileri ve durum sayfası abonelerini veritabanı düzeyinde
birlikte kaldırır. Bir durum sayfasından aboneliğinizi iptal etmek, yalnızca o abone
kaydını kalıcı olarak siler. Ürünün henüz kendi kendine hizmet eden bir düğmesi
bulunmadığı bir silme talebiniz için [[faq.rights_email]] adresine yazabilirsiniz.

</details>
