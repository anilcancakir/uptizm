Uptizm size iki tür otomatik istek gönderir ve bu sayfa ikisini de anlatır. Hangisi
erişim kayıtlarınızda göründüyse, ne olduğunu öğrenmek ya da durmasını istiyorsanız,
cevabın tamamı burada. Aşağıdaki değerler, elle güncellenen bir metinden değil, bu kurulumun
kendi yapılandırmasından gelir; dolayısıyla çalıştırdığımız şeyi yansıtır. Yakalamanız yerine
söylemeyi tercih ettiğimiz bir kayıt: erişilebilirlik kontrolünün sıklığı yapılandırılmış
varsayılandır ve ayarı değiştirilmiş tek bir kontrol bundan farklı olabilir.

## Nasıl tanınırlar

İkisi de her istekte aynı dizeyle kendini tanıtır:

```
[[bot.user_agent]]
```

Hiçbiri tarayıcı gibi davranmaz ve tarayıcı User-Agent değeri göndermez.

## 1. Erişilebilirlik kontrolü

İkisinin büyüğü bu olduğu için ilk sırada. Servisinizde **tek bir adresi**, genellikle ana
sayfanızı isteriz ve yanıt verip vermediğini, ne kadar hızlı yanıt verdiğini kaydederiz.
Yayımladığımız ölçüm budur ve servisinizle ilgili sayfanın var olmasının tek nedeni de
budur.

**[[bot.probe_regions]] bölgeden**, her biri yaklaşık **[[bot.probe_interval_seconds]]
saniyede bir** çalışır; bu da o tek adrese günde kabaca
**[[bot.probe_daily_requests]] istek** demektir. Düz bir GET isteğidir. Başka hiçbir
sayfayı okumaz, bağlantı izlemez, form göndermez ve yayımlamadığınız hiçbir şeyi aramaz.

## 2. Durum akışı okuması

Servisiniz makine tarafından okunabilir bir durum akışı yayımlıyorsa ve Uptizm'de bir kişi
şartlarınızı inceleyip bu incelemeyi kaydettiyse, kendiniz hakkında söylediklerinizi bizim
ölçtüğümüzün yanında gösterebilmek için o akışı da okuruz.

Aynı servis için iki okuma arasındaki en kısa aralık **[[bot.min_interval_seconds]]
saniyedir** ve bu alt sınır bir zamanlayıcıyla değil, son kaydedilen çekim üzerinden
uygulanır; böylece yeniden başlatma, mükerrer bir zamanlama tetiği veya bir yeniden deneme
bunu hızlandıramaz.

Son seferde verdiğiniz `ETag` değerini `If-None-Match` ile geri gönderir. `304 Not
Modified` yanıtladığınızda hiçbir şey yazmaz ve sonra tekrar sorar; yani değişmemiş bir
akış size yalnızca bir başlık alışverişine mal olur.

Yönlendirmeleri izlemez. Akışınız taşınırsa isteğimiz yönlendirmede durur ve adresi bir
kişinin güncellemesi gerekir, çünkü yeni alan adı henüz kimsenin incelemediği bir adrestir.

## Nasıl geri çekilirler

**Akış** okumasına `429 Too Many Requests` veya `403 Forbidden` yanıtlarsanız o akışı anında
devre dışı bırakır ve sormayı bırakır. Yeniden denemez ve hiçbir şey onu otomatik olarak
geri açmaz: bir kişinin neden reddedildiğine bakıp elle temizlemesi gerekir. `403`
eksiksiz ve kalıcı bir cevaptır; bizi engellemek zorunda kalmanız yerine onu göndermenizi
tercih ederiz.

**Erişilebilirlik kontrolü** kendini bu şekilde durdurmaz ve bunu keşfetmenize bırakmak
yerine söylemeyi tercih ederiz. Programına göre istemeye devam eder ve ne aldığını
kaydeder; çünkü bir reddetme de bir ölçümdür ve "isteklerimizi reddediyorlar" bilgisini
yayımlamak, hiçbir şey yayımlamamaktan daha dürüsttür. Onun da durmasını istiyorsanız, yol
aşağıdaki bölümde.

## Bir insana nasıl ulaşılır

Hiçbir şey istememizi tercih ediyorsanız, farklı bir sıklık istiyorsanız ya da yalnızca
neden orada olduğumuzu öğrenmek istiyorsanız, hangi alan adını sorduğunuzu belirterek
[[bot.contact_email]] adresine yazın. Servisinizi katalogdan kaldırırız; bu iki istemciyi
de durdurur. User-Agent değerini engellemeniz de işe yarar ve bunu etrafından dolaşmak
yerine olduğu gibi bir cevap olarak kabul ederiz.

Açıkça söylemekte fayda var: her iki kanal da bize ait tek bir adresten değil, dönüşümlü bir
havuzdaki üçüncü taraf çıkış adreslerinden gelir; bu yüzden tek bir adresi engellemek
gelecekteki her isteği kalıcı olarak dışarıda tutmaz. Bizi kalıcı olarak durdurmak
istiyorsanız güvenilir yol, yukarıdaki gibi servisinizi katalogdan kaldırmamızı istemek ya da
hangi adresten geldiğine bakmaksızın aynı şekilde kabul ettiğimiz User-Agent değerini
engellemektir.

## Ne için var

Uptizm, servis başına bağımsız bir sayfa yayımlar: herkese açık bir uç noktaya dair kendi
ölçümümüzü, aynı anda o servisin kendi durum akışının söylediğiyle birlikte, her biri
nereden geldiği etiketlenmiş halde gösterir. İkisini asla tek bir sayıda birleştirmeyiz ve
sizin yayımladığınız durumu asla kendimizinmiş gibi sunmayız. Akışınızı okumanın amacı,
ikisi çeliştiğinde sizin tarafınızı bizimkinin yanında gösterebilmektir.
