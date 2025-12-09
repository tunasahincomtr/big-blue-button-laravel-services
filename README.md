# 📌 Laravel BigBlueButton Service

Laravel projeleri için geliştirilmiş, BigBlueButton (BBB) API ile güvenli ve kolay entegrasyon sağlayan bir servis sınıfıdır.  
Bu servis sayesinde toplantı oluşturabilir, katılım linkleri üretebilir, toplantı bilgilerini çekebilir, katılımcı listelerini görebilir ve toplantıları sonlandırabilirsiniz.

Tamamen Laravel mimarisine uygun şekilde hazırlanmış olup, SHA256 checksum doğrulaması ve XML işleme özellikleri ile BigBlueButton API gereksinimlerini eksiksiz karşılar.

---

## 🚀 Özellikler

- ✔ Laravel servis mimarisine tamamen uyumlu
- ✔ `create`, `join`, `getMeetingInfo`, `isMeetingRunning`, `end`, `getMeetings` gibi tüm temel API özellikleri hazır
- ✔ SHA256 checksum üretimi
- ✔ XML response parse işlemleri
- ✔ Ayrıntılı loglama sistemi
- ✔ Katılımcı listesi (isim, rol, video durumu, dinleme modu vb.)
- ✔ Hata yönetimi ve API iletişim kontrolü
- ✔ BigBlueButton API-Mate ile test edilebilir yapı

---

## 📁 Servis Dosyası Konumu

Aşağıdaki dosyayı kendi projenize ekleyin:

```
app/Services/BigBlueButtonService.php
```

Bu sınıf; checksum üretimi, URL oluşturma, API isteği gönderme ve tüm metodların uygulanmış hâlini içerir.

---

## ⚙️ Kurulum

### 1️⃣ `config/services.php` dosyasına ekleme yapın:

```php
'bigbluebutton' => [
    'server_url' => env('BIGBLUEBUTTON_SERVER_URL'),
    'secret'     => env('BIGBLUEBUTTON_SECRET'),
],
```

---

### 2️⃣ `.env` dosyanıza bağlantı bilgilerini ekleyin:

```env
BIGBLUEBUTTON_SERVER_URL=https://bbb.sunucu-adresiniz.com
BIGBLUEBUTTON_SECRET=BURAYA_SHARED_SECRET_GELECEK
```

---

## 🧪 API Test Aracı – Mutlaka Önerilir

BigBlueButton API'nizi test etmek için resmi test aracını kullanabilirsiniz:

🔗 https://bigbluebutton.org/api-mate/

Burada:
- Server URL
- Secret  
değerlerini girerek API çağrılarını doğrulayabilirsiniz.

---

## 💻 Kullanım Örnekleri

### ✔ Toplantı Oluşturma

```php
$result = $bbb->createMeeting([
    'meetingID' => 'ders_001',
    'name' => 'Özel Ders',
    'duration' => 60,
]);
```

### ✔ Katılım Linki Üretme

```php
$joinUrl = $bbb->getJoinUrl('ders_001', 'Öğrenci Adı', 'student_pw');
```

### ✔ Toplantı Bilgisi Alma

```php
$info = $bbb->getMeetingInfo('ders_001', 'teacher_pw');
```

### ✔ Toplantıyı Sonlandırma

```php
$bbb->endMeeting('ders_001', 'teacher_pw');
```

---

## 🧑‍💻 Geliştirici

Bu servis örneği, Laravel projelerinde BigBlueButton entegrasyonu geliştirmek isteyenler için  
**Tuna Şahin** tarafından hazırlanmıştır.

---

## 📄 Lisans

MIT Lisansı altında dağıtılabilir.
