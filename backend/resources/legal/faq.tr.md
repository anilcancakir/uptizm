Uptizm'in gerçekte neyi kontrol ettiği, nelere mal olduğu ve nerede sınırlarının bittiği
hakkında net yanıtlar. Aşağıdaki her sayı, ürünün kendisinin çalıştığı aynı yapılandırma
ve enum değerlerinden geliyor; bir plan ya da limit değiştiğinde bu sayfa da onunla
birlikte değişir.

## Ürün hakkında

<details>
<summary>Kontroller hangi bölgelerden çalışıyor?</summary>

Her monitör, desteklenen [[faq.region_count]] bölgeden herhangi birine sabitlenebilir:
[[faq.region_names]]. Seçtiğiniz tüm bölgeler aynı turda çalışır, bu yüzden yavaş bir
bölge sıradaki yerinden değil, o bölgenin kendisinden kaynaklanan bir durumdur.

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
bir kontrol edilen [[faq.free_monitors]] monitör. En fazla
[[faq.free_subscribers]] e-posta abonesi alabilen [[faq.free_status_pages]] durum sayfası. Aşağıdaki kanalların
tamamı üzerinden uyarı gönderebilen [[faq.free_responders]] nöbetçi. TLS sertifika süresi
uyarıları ve yanıt metriği sınırları. AI anomali gelen kutusu dahildir; bu özellik sizi
Pro'ya geçmeye yönlendirmeden önce [[faq.free_ai_trials]] ücretsiz AI monitör kurulumu
hakkı tanır.

</details>

<details>
<summary>Hangi protokolleri izleyebilirim?</summary>

İki tanesini: [[faq.monitor_types]]. Bir HTTP monitörü yapılandırılan adrese istek atar;
bir TCP monitörü ise yalnızca bir soket açıp bağlantı ve el sıkışma süresini ölçer. Bugün
ping kontrolü, DNS kontrolü ya da tarayıcı tabanlı bir kontrol yok.

</details>

<details>
<summary>Monitör kimlik doğrulaması ve yanıt doğrulama kuralları uygulanıyor mu?</summary>

Henüz değil. İkisi de bir monitör üzerinde girilip kaydedilebiliyor, ancak prob motoru şu anda
ikisini de yok sayıyor: kimlik doğrulaması isteyen bir uç nokta, istemiyormuş gibi kontrol
edilir ve bir doğrulama kuralı, kontrolün başarılı mı başarısız mı sayılacağını değiştirmez.
İkisine de güvenmeyin. [Kullanım Koşulları](/tr/terms) aynı açıklamayı taşıyor; bu satır, prob
motoru bu ayarları uygulayana kadar iki sayfada da kalacak.

</details>

<details>
<summary>Hangi uyarı kanallarını kullanabilirim?</summary>

[[faq.alert_channels]]. Kendi bildirimleriniz için kişisel SMS ve e-posta teslimi ayrı bir
tercihe bağlıdır; ancak bir monitörün çağırabileceği takım düzeyindeki uyarı hedefleri
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

Bir olayı incelerken yapay zeka, o olayın kendi zaman çizelgesini ve monitörlerine
kaydedilmiş kontrolleri okur: bölge, durum ve süre, ayrıca uç noktanızın döndürdüğü hata
mesajı, yanıt gövdesi ve yanıt başlıklarından en fazla [[faq.ai_char_limit]] karakter.
Dağıtımlarınızı, commit'lerinizi, CI sürecinizi, loglarınızı, trace'lerinizi, APM'inizi,
CDN'inizi ya da başka birinin durum sayfasını asla görmez: Uptizm'in çalıştırdığınız
hiçbir sisteme entegrasyonu yoktur, yalnızca kendi kontrollerinin ölçtüğü şeyden çıkarım
yapabilir.

</details>

## Hesabınız ve verileriniz hakkında

<details>
<summary>Kontrol geçmişi ne kadar süre tutuluyor?</summary>

Ham kontrollerin tamamı [[faq.retention_days]] gün boyunca, tam çözünürlükte tutulur. Bu
kayıtları bir uygulama görevi dolaşarak değil, veritabanının kendisi kendi takvimiyle
siler; bu takvim veritabanının çalıştırdığı zaman serisi eklentisinin bir özelliğidir ve
arkasında bekleyen ne saatlik ne de günlük bir özet katmanı vardır.

</details>

<details>
<summary>Nasıl iptal ederim?</summary>

E-posta ile; güvenilir biçimde işleyen yol budur: işletmecinin iletişim adresine bir mesaj
gönderin, bu adres [Kullanım Koşulları](/tr/terms) metninin 8. bölümünde yayımlanıyor. Bunun bir
bedeli ve bir ihbar süresi yoktur. İptal etmek sizi anında dışarıda da bırakmaz: planınız, zaten
ödediğiniz dönemin sonuna kadar aktif kalır; hesap sonrasında ücretsiz planda devam eder.
Uygulamadaki faturalandırma ekranı, ödeme sağlayıcısının müşteri portalını açar; faturalarınız ve
ödeme yönteminiz oradadır. O portalda nelerin yapılabileceğini bizim değil, ödeme sağlayıcısının
yapılandırması belirler; dolayısıyla orada bir iptal düğmesi bulacağınıza güvenmeyin. Hesabınızı
silmek de sözleşmeyi sona erdirir: ücretsiz planda olduğu gibi ücretli planda da.

</details>

<details>
<summary>Verilerimin silinmesini nasıl sağlarım?</summary>

Hesabınızı silmek API token'larınızı ve profil fotoğrafınızı kaldırır, ardından kullanıcı
kaydının kendisini siler; bu bir devre dışı bırakma değil, kalıcı bir silmedir. Bir
takımı silmek, ona bağlı monitörleri ve durum sayfası abonelerini veritabanı düzeyinde
birlikte kaldırır. Bir durum sayfasından aboneliğinizi iptal etmek, yalnızca o abone
kaydını kalıcı olarak siler. Ürünün henüz kendi kendine hizmet eden bir düğmesi
bulunmadığı bir silme talebiniz için [[faq.rights_email]] adresine yazabilirsiniz.

</details>
