# VerifyBlind for WordPress

**[🇹🇷 Türkçe](#türkçe) · [🇬🇧 English](#english)**

WordPress ve WooCommerce için VerifyBlind eklentisi: çipli T.C. kimlik kartıyla yaş ve "tek kişi, tek hesap" doğrulaması. Site yalnız **uygun / uygun değil** yanıtını alır.

---

## Türkçe

### Ne yapar?
Ziyaretçi VerifyBlind uygulamasıyla çipli kimlik kartını telefonunda okutur ve canlı yüz kontrolünden geçer. Doğrulama kapalı bir ortamda (AWS Nitro Enclave) yapılır; ad, T.C. kimlik numarası, doğum tarihi ve fotoğraf ne siteye ne de VerifyBlind'a ulaşır. Site yalnız sorduğu koşulun cevabını (ör. 18+ → uygun) ve tek kişi kontrolünde yalnız bu site için üretilen bir **kişiye özel kod** (takma ad) alır.

Doğrulamanın yapıldığı enclave'in ve mobil uygulamaların kaynak kodu herkese açıktır (yalnızca inceleme amaçlı lisans); bu eklenti ise GPL-2.0-or-later lisanslıdır.

**Kurallar** neyin nerede sorulacağını belirler:
- **Nerede:** üye kaydı (WordPress + WooCommerce), sayfa / yazı / kategori kilidi ("VerifyBlind kilidi" bloğu ve `[verifyblind_gate]` kısa kodu), yorumlar, WooCommerce ödeme, ürün sayfası, mağaza girişi, kupon, ürün değerlendirmesi ya da yalnız rol ver.
- **Ne:** yaş koşulu (en az N, N'den küçük, aralık) ve/veya tek kişi kontrolü.
- **Aynı kişi başka hesapla gelirse:** doğrulamayı reddet · işlemi engelle · kabul et ve bildir · yeni hesaba taşı.
- **Geçenlere:** isteğe bağlı rol ve geçerlilik süresi.

Her kapı sunucuda uygulanır: kilitli metin sayfa gönderilmeden çıkarılır; sipariş, kupon, üye kaydı ve yorum doğrulama yoksa sunucuda reddedilir.

Ayrıca: kurulum sihirbazı ve hazır kurallar, "VerifyBlind ile doğrulandı" rozeti, doğrulanan üyeler ekranı, WordPress kişisel veri dışa aktarma/silme araçları, gizlilik politikası için hazır paragraf, iptal bildirimi adresi (kişi iznini VerifyBlind uygulamasında geri çekince sonuç sitede de silinir), Türkçe ve İngilizce arayüz.

### Gereksinimler
WordPress 6.0+, PHP 7.4+, WooCommerce 8.0+ (isteğe bağlı) ve ücretsiz bir VerifyBlind partner hesabı ([partner.verifyblind.com](https://partner.verifyblind.com) — ayda 2.000 doğrulama ücretsiz).

### Kurulum
1. [Releases](https://github.com/VerifyBlind/verifyblind-plugin-wordpress/releases) sayfasından `verifyblind-<sürüm>.zip` dosyasını indirin.
2. WordPress panelinde **Eklentiler → Yeni ekle → Eklenti yükle** ile zip'i yükleyip etkinleştirin.
3. Açılan **kurulum sihirbazında** API anahtarınızı girin, site türünüzü seçin, kuralları gözden geçirin.
4. Sihirbazın gösterdiği **iptal bildirimi adresini** partner portalı → Ayarlar → Revoke URL alanına yapıştırın.

Sihirbazı sonra **VerifyBlind → Kurulum sihirbazı** menüsünden yeniden açabilirsiniz.

### Dış servisler
Eklentinin VerifyBlind'a neyi ne zaman gönderdiği [`readme.txt`](readme.txt) dosyasının **External services** bölümünde tek tek yazılıdır.

### Geliştirme
```bash
composer install
vendor/bin/phpunit -c phpunit.xml.dist               # birim testleri (WordPress'siz, PHP 7.4+)
VB_WP_LOAD=/yol/wp-load.php vendor/bin/phpunit -c phpunit-integration.xml.dist   # eklentinin etkin olduğu bir WordPress'te
bash bin/build-zip.sh                                # dist/verifyblind-<sürüm>.zip (commit'li ağaçtan)
```

🌐 [verifyblind.com](https://verifyblind.com) · 🧩 [PHP örneği](https://github.com/VerifyBlind/example-web-php) · 🧩 [Next.js örneği](https://github.com/VerifyBlind/example-web-nextjs)

---

## English

### What it does
Visitors scan their chipped Turkish ID card with the VerifyBlind app on their phone and pass a live face check. The verification runs in a sealed environment (an AWS Nitro Enclave); names, ID numbers, birth dates and photos reach neither the site nor VerifyBlind. The site receives only the answer to the condition it asked (for example 18+ → eligible) and, for the one-person check, a **pseudonymous code** issued for that site only.

The enclave that does the verification and the mobile apps are public source code (for review only); this plugin is licensed GPL-2.0-or-later.

**Rules** decide where a verification is asked and what is asked:
- **Where:** sign-up (WordPress + WooCommerce), page / post / category lock (the "VerifyBlind lock" block and the `[verifyblind_gate]` shortcode), comments, WooCommerce checkout, product page, shop entrance, coupons, product reviews, or only a role.
- **What:** an age condition (at least N, younger than N, a range) and/or the one-person check.
- **Same person on another account:** reject the verification · block the action · accept and notify · move to the new account.
- **For those who pass:** an optional role and a validity period.

Every gate is enforced on the server: locked text is removed before the page is sent; orders, coupons, sign-ups and comments are refused on the server when the check is missing.

Also: a setup wizard with ready-made rules, a "Verified with VerifyBlind" badge, a verified members screen, the WordPress personal data export and erase tools, a suggested privacy policy paragraph, a revoke address (when a person withdraws consent in the VerifyBlind app, the result is deleted on the site too), Turkish and English.

### Requirements
WordPress 6.0+, PHP 7.4+, WooCommerce 8.0+ (optional) and a free VerifyBlind partner account ([partner.verifyblind.com](https://partner.verifyblind.com) — 2,000 verifications a month free).

### Installation
1. Download `verifyblind-<version>.zip` from [Releases](https://github.com/VerifyBlind/verifyblind-plugin-wordpress/releases).
2. In WordPress, **Plugins → Add New → Upload Plugin**, upload the zip and activate it.
3. In the **setup wizard** that opens, enter your API key, choose your kind of site and review the rules.
4. Paste the **revoke address** the wizard shows into the partner portal → Settings → Revoke URL.

You can open the wizard again under **VerifyBlind → Setup wizard**.

### External services
What the plugin sends to VerifyBlind, and when, is listed in the **External services** section of [`readme.txt`](readme.txt).

### Development
```bash
composer install
vendor/bin/phpunit -c phpunit.xml.dist               # unit tests (no WordPress, PHP 7.4+)
VB_WP_LOAD=/path/wp-load.php vendor/bin/phpunit -c phpunit-integration.xml.dist   # on a WordPress with the plugin active
bash bin/build-zip.sh                                # dist/verifyblind-<version>.zip (from the committed tree)
```

🌐 [verifyblind.com](https://verifyblind.com) · 🧩 [PHP example](https://github.com/VerifyBlind/example-web-php) · 🧩 [Next.js example](https://github.com/VerifyBlind/example-web-nextjs)

---

## Lisans · License

GPL-2.0-or-later — bkz. / see [LICENSE](LICENSE).
