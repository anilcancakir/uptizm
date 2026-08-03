Uptizm, başka şirketlerin herkese açık durum akışlarını okuyan tek bir otomatik istemci
çalıştırır. Erişim kayıtlarınızda gördüyseniz ve ne olduğunu öğrenmek ya da durmasını
istiyorsanız, cevabın tamamı bu sayfadadır. Aşağıdaki her değer, istekleri gerçekten yapan
kodun kendisinden gelir; dolayısıyla istemcinin uymadığı bir sıklığı vaat edemez.

## Nasıl tanınır

Her istekte kendini tanıtır:

```
[[bot.user_agent]]
```

Hiçbir zaman tarayıcı gibi davranmaz ve tarayıcı User-Agent değeri göndermez.

## Neyi, ne sıklıkla ister

Servis başına tek bir belge çeker: zaten herkes için yayımladığınız genel durum akışını,
Uptizm'de bir kişinin elle inceleyip kaydettiği adresten. Sitenizde başka hiçbir şeyi
okumaz. Tarama yapmaz, bağlantıları izlemez ve yayımlamadığınız hiçbir şeyi aramaz.

Aynı servis için iki istek arasındaki en kısa aralık **[[bot.min_interval_seconds]]
saniyedir** ve bu alt sınır bir zamanlayıcıyla değil, son kaydedilen çekim üzerinden
uygulanır; böylece yeniden başlatma, mükerrer bir zamanlama tetiği veya bir yeniden deneme
bunu hızlandıramaz.

Son seferde verdiğiniz `ETag` değerini `If-None-Match` ile geri gönderir. `304 Not
Modified` yanıtladığınızda hiçbir şey yazmaz ve sonra tekrar sorar; yani değişmemiş bir
akış size yalnızca bir başlık alışverişine mal olur, gövdeye hiç gerek kalmaz.

Yönlendirmeleri izlemez. Akışınız taşınırsa isteğimiz yönlendirmede durur ve adresi bir
kişinin güncellemesi gerekir, çünkü yeni alan adı henüz kimsenin incelemediği bir adrestir.

## Nasıl geri çekilir

`429 Too Many Requests` veya `403 Forbidden` yanıtlarsanız o akışı anında devre dışı
bırakır ve sormayı bırakır. Yeniden denemez ve hiçbir şey onu otomatik olarak geri açmaz:
bir kişinin neden reddedildiğine bakıp elle temizlemesi gerekir.

Durdurmanın amaçlanan yolu budur. `403` eksiksiz ve kalıcı bir cevaptır; bizi ağ
seviyesinde engellemek zorunda kalmanız yerine onu göndermenizi tercih ederiz.

## Bir insana nasıl ulaşılır

Akışınızı hiç okumamamızı tercih ediyorsanız, farklı bir sıklık istiyorsanız ya da yalnızca
neden orada olduğumuzu öğrenmek istiyorsanız, hangi alan adını sorduğunuzu belirterek
[[bot.contact_email]] adresine yazın. Kaldıracağız.

## Ne için var

Uptizm, servis başına bağımsız bir sayfa yayımlar: herkese açık bir uç noktaya dair kendi
ölçümümüzü, aynı anda o servisin kendi durum akışının söylediğiyle birlikte, her biri
nereden geldiği etiketlenmiş halde gösterir. İkisini asla tek bir sayıda birleştirmeyiz ve
sizin yayımladığınız durumu asla kendimizinmiş gibi sunmayız. Akışınızı okumanın amacı,
ikisi çeliştiğinde sizin tarafınızı bizimkinin yanında gösterebilmektir.
