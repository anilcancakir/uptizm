## Neyi ölçüyoruz, neyi ölçmüyoruz

Uptizm `[[service.endpoints]]` adresini [[service.check_interval_seconds]] saniyede bir,
en fazla [[service.region_count]] ölçüm bölgesinden kontrol ediyor ve yukarıdaki
"Uptizm'in ölçtüğü" bölümü yalnızca bu kontrolden oluşuyor. Günlük şerit, son
[[service.strip_days]] gün boyunca o tek adrese erişebilmemizi gösteriyor; her hücre bir
gün, ve ölçüm yapmadığımız bir gün yeşile boyanmak yerine nötr kalıyor.

Bu iddia bilinçli olarak dar. [[service.name]] ürününün tek bir herkese açık adresine,
kendi ağlarının dışından erişiyoruz. İç servislerini, bölgesel kapasitesini, API hata
oranlarını ya da ölçmediğimiz parçalarını göremiyoruz; bu yüzden [[service.name]] için
**hiçbir çalışma süresi yüzdesi, erişilebilirlik oranı veya SLA sayısı
yayımlamıyoruz**. Böyle bir sayı, ürünün tamamını kapsadığımız anlamına gelirdi;
kapsamıyoruz. O sayıya ihtiyacınız varsa, onu yayımlamak bize değil onlara düşer.

## Bu sayfa "erişemedik" dediğinde

Yukarıdaki her okuma bize ait, hangi adresten geldiğini söylüyor ve alındığı zamanı
taşıyor. Ne söyleyebileceğimize iki kural karar veriyor:

- [[service.stale_after_seconds]] saniyeden eski bir okuma **bilinmiyor** sayılır.
  Elimizde kalan son değeri, güncelmiş gibi sayfada bırakmayız.
- Bir sorunu ancak [[service.incident_threshold]] kontrol üst üste başarısız olduğunda
  **ve** en az [[service.agreeing_regions]] bölge aynı şeyi söylediğinde bildiriyoruz.
  Tek bir bölgenin kötü bir dakikası, o bölge hakkında bir bilgidir.

İkinci kural, kendi müşterilerimize verdiğimiz uyarılardan bilerek daha katı: orada
hız önemlidir, müşteri bir dakika içinde haberdar olmak ister. [[service.name]]
kendi durum sayfasında başka bir şey söylerken ona itiraz eden herkese açık bir
sayfanın ise hızlı olmaktan çok doğru olması gerekir.

## [[service.name]] kendisi ne yayımlıyor

[[service.name]] makine tarafından okunabilir bir durum akışı yayımlıyorsa ve
koşullarını gözden geçirdiysek, yukarıdaki bölüm onu alıntılar: kendi genel durum
kelimeleri, bileşen adları, açık olayları ve bunları ne zaman aldığımız. Onların
sözleri, onların sözleri olarak gösterilir. Sözlüklerini bizimkine çevirmiyoruz,
genel durumlarını yeniden renklendirmiyoruz ve onların adına kendi olay kaydımızı
açmıyoruz.

İki bölüm birbiriyle çelişirse sayfa bunu söyler ve ikisini de olduğu gibi bırakır.
Burada üçüncü, harmanlanmış bir cevap yok: biz tek bir adresi dışarıdan izliyoruz,
onlar göremediğimiz sistemleri görüyor ve iki okuma aynı anda doğru olabilir. Kendi
sistemleri hakkında yetkili kaynak, kendi durum sayfalarıdır.

## Bu sayfa ne değildir

Bu sayfa Uptizm tarafından yayımlanır ve [[service.name]] şirketinin resmî durum
sayfası değildir. [[service.name]] ile bağlantılı değiliz, onlar tarafından
onaylanmadık veya desteklenmiyoruz ve [[service.name]] sahibinin ticari markasıdır.
Adı, hangi servisi ölçtüğümüzü açıkça belirtmek için kullanıyoruz.
