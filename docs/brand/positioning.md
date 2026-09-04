# همیار — positioning and the landing's message hierarchy

Written for Gate 16.2 (Redesign v2). Copy follows `docs/brand/voice.md`: professional and
confident, concrete, every sentence a claim the product keeps. Nothing here is a number
the product cannot show. Persian is the deliverable; English is the reasoning.

## 1. Who, where, and what they are deciding

A phone-shop owner in Iran — one or two people behind a glass counter in a پاساژ, a
customer waiting, a mid-range Android in hand. They record the day in a ruled ledger, a
cheque booklet and a drawer of thermal receipts, and they have been sold software before:
a desktop package from 2012, a "جامع" system that needed a technician to install, a Telegram
bot. What they are deciding on the landing is not "is this pretty" but **«این برای مغازهٔ من
است؟»** — is this built for a phone shop, will it cope with what my day actually contains
(IMEI, repairs, instalments, cheques, HAMTA), and can I start without paying anyone.

## 2. Positioning statement

> **همیار سامانهٔ ابری فروشگاه موبایل است.** هر گوشی با IMEI خودش ثبت می‌شود و از خرید تا
> فروش و تعمیر یک پرونده دارد؛ فروش، تعمیرات، اقساط و چک، پیامک و گزارش سود در همان یک
> سامانه است. در مرورگر کار می‌کند، با تقویم شمسی، و پلن اول رایگان است.

The mechanism a neighbour cannot truthfully copy: **serialized units → true per-device
profit**. Cost is captured at the sale, so the profit report is a margin, not
"today's price minus what I think I paid".

## 3. Message hierarchy

| level | says | proved by |
|---|---|---|
| **Category** (first 3 seconds) | This is software for a phone shop. | the words «فروشگاه موبایل» in the headline; a real product frame beside it |
| **Mechanism** (first scroll) | Every handset is a row with a history; profit is real. | the IMEI passport demo, with real seeded devices |
| **Coverage** (the tour) | It contains the whole day: till, workshop, instalments, cheques, SMS, reports. | six real captures, one sentence each |
| **Honesty** (FAQ, HAMTA) | It says what it does not do. | the HAMTA line; the lapsed-plan answer |
| **Trust** (proof strip) | Real shops use it; the people behind it are reachable. | named pilot shops with consent; a real contact channel; the legal entity |
| **Offer** (pricing) | Everything is open; the first rung is free; no card. | plan rows from the database |
| **Action** (every section) | Start now, in the browser. | «رایگان شروع کنید» |

One claim per section, one proof per claim, one action. A section that cannot name its
proof is cut.

## 4. The copy, v1 — `lang/fa/landing.php` shape

### Nav
- امکانات · شناسنامهٔ IMEI · تعرفه‌ها · سؤالات · **ورود** · **ثبت‌نام**

### Hero
- Kicker (only if the direction has one; the heading must stand without it): نرم‌افزار ابری فروشگاه موبایل
- **H1:** همهٔ کارِ فروشگاه موبایل، در یک سامانه
- Lede: فروش با IMEI، تعمیرات، اقساط و چک، پیامک و گزارش سود — هر گوشی با شناسهٔ خودش ثبت می‌شود و سود هر فروش همان لحظه معلوم است.
- Primary: رایگان شروع کنید · Secondary: دیدن نرم‌افزار (scrolls to the tour)
- Fine print: بدون کارت بانکی · در مرورگر · تقویم شمسی

Alternates for the gate (the owner may prefer one):
- H1-b: نرم‌افزار فروشگاه موبایل، ساخته‌شده برای بازار ایران
- H1-c: از فروش گوشی تا تعمیر و قسط، یک سامانه
- H1-d: فروشگاه موبایل شما، مرتب و قابل‌اتکا

### Proof strip
- ساخته‌شده برای بازار ایران: فارسی، تقویم شمسی، تومان و ریال
- بدون نصب — روی هر مرورگر، روی هر گوشی
- [pilot shops, with consent]: «در فروشگاه‌های … در حال استفاده است» — count or names only, never a superlative
- [contact]: پشتیبانی از داخل نرم‌افزار و {channel}

### The mechanism — «شناسنامهٔ IMEI»
- H2: این شناسه را وارد کنید؛ بقیه‌اش پیداست.
- Lede: در همیار گوشی «تعداد» نیست. هر دستگاه یک سطر با شناسهٔ خودش است و هر خرید، تعمیر، حواله و فروش زیر همان شناسه می‌ماند — دو سال بعد هم.
- Timeline labels: از چه کسی خریده شد · به چه کسی فروخته شد · کِی تعمیر شد · الان کجاست
- Result label: سود این دستگاه
- Honest line: همتا API عمومی ندارد؛ ثبت نهایی را خودتان انجام می‌دهید. همیار وضعیت هر دستگاه را نگه می‌دارد و یادآوری می‌کند.

### The tour — six screens, one sentence each
- صندوق فروش — بارکد یا IMEI را اسکن کنید؛ دستگاه با همان شناسه روی فاکتور می‌نشیند. معاوضه، تخفیف و چند روش پرداخت، همین‌جا.
- تختهٔ تعمیرات — هر قبض پذیرش یک کارت است و بین وضعیت‌های واقعی کارگاه جابه‌جا می‌شود؛ مشتری وضعیت را با یک لینک می‌بیند.
- میز وصول — امروز چه کسی باید بیاید، چه کسی عقب افتاده و چقدر هنوز وصول نشده.
- گزارش سود — بهای تمام‌شده در لحظهٔ فروش ثبت می‌شود؛ سود آخر ماه سود واقعی است.
- پیامک — «دستگاه آماده است» و یادآوری قسط از روی رویدادهای خود سیستم فرستاده می‌شود.
- پروندهٔ دستگاه — خرید، تعمیر، حواله و فروش، همه زیر یک شناسه.

### Modules — the coverage list (short)
فروش و صندوق · انبار سریال‌دار · تعمیرات · اقساط و چک · خزانه و بانک · پیامک · گزارش‌ها · چند شعبه · همتا · مودیان

### Pricing
- H2: تعرفه‌ای که همان است که می‌بینید
- Lede: همهٔ امکانات در همهٔ پلن‌ها باز است؛ پلن‌ها در سهمیهٔ ماهانه فرق می‌کنند. پلن پایه رایگان است و کارت بانکی نمی‌خواهد. هر ماه می‌توانید پلن را تغییر دهید یا قطع کنید.
- Toggle: ماهانه / سالانه · «۱۲ ماه به قیمت ۱۰ ماه»
- Row CTA: رایگان شروع کنید / انتخاب این پلن
- The basic plan's «۰ پیامک» is stated as a limit, not listed as a feature.

### FAQ (six; the honest answers stay)
1. با سامانهٔ همتا چه می‌کند؟ — همتا API عمومی ندارد و هیچ نرم‌افزاری نمی‌تواند مستقیم در آن ثبت کند. همیار وضعیت همتای هر IMEI را کنار دستگاه نگه می‌دارد، دستگاه‌های ثبت‌نشده را یادآوری می‌کند و مراحل را نشان می‌دهد؛ ثبت نهایی با خود شماست.
2. سامانهٔ مودیان چطور؟ — ماژول مودیان صورتحساب‌ها را با قالب سامانه آماده می‌کند و صف ارسال دارد؛ ارسال از طریق شرکت معتمدی انجام می‌شود که با آن قرارداد دارید.
3. از نرم‌افزار قبلی‌ام می‌توانم بیایم؟ — بله، با فایل اکسل: کالاها، مشتری‌ها و مانده‌حساب‌ها. پیش از ثبت نهایی، پیش‌نمایش می‌بینید و ستون‌ها را خودتان تطبیق می‌دهید.
4. داده‌های من مال کیست؟ — مال شما. هر زمان از همه‌چیز خروجی اکسل می‌گیرید. اطلاعات هر فروشگاه در خودِ پایگاه داده از بقیه جداست، نه فقط در نرم‌افزار.
5. پشتیبانی چطور است؟ — از داخل نرم‌افزار و {channel}؛ کسی پاسخ می‌دهد که نرم‌افزار را می‌شناسد.
6. اگر اشتراکم تمام شود؟ — داده‌ها پاک نمی‌شود و حساب به پلن رایگان برمی‌گردد؛ خواندن همیشه باز است و فقط سهمیهٔ ثبت ماهانه به پلن پایه محدود می‌شود.

### Closing
- H2: اولین فاکتور را همین امروز ثبت کنید
- Lede: چیزی نصب نمی‌شود. فروشگاه را می‌سازید، فهرست کالا را از اکسل وارد می‌کنید و شروع می‌کنید.
- CTA: رایگان شروع کنید · دیدن تعرفه‌ها

### Footer
- Blurb: سامانهٔ ابری فروشگاه موبایل: فروش سریال‌دار، تعمیرات، اقساط و چک، پیامک و گزارش سود. فارسی، تقویم شمسی، برای بازار ایران.
- Columns: محصول · ماژول‌ها · شروع · قوانین · [legal entity] · {contact}
- Base: © {year} همیار

## 5. What changed from the previous copy, and why

- The headline names the category. The previous one («هرچه پشت پیشخوان اتفاق می‌افتد، یک
  سطر می‌شود») was a metaphor; the category lived in a 14px eyebrow.
- The register moved from colloquial («توی کشو», «رُک بگوییم») to professional. Same
  facts, fewer words, no slang.
- The "trust bar" becomes a proof strip with real material (pilot shops, a channel, the
  entity) — the owner supplies it; nothing is invented.
- The lapsed-plan answer was wrong: ADR 0018 says a lapsed shop falls back to the free
  plan and is never locked out. The copy said «ورود به حساب بسته است». Corrected.
- «دموی ۳ دقیقه‌ای» pointed at a screenshot mosaic; it is now «دیدن نرم‌افزار».

## 6. Open for the owner

- Which H1 (a–d), or a different one.
- Pilot shops: names or a count, city, consent, an optional sentence each.
- The support channel and the legal entity.
- Whether the free plan's SMS limit should be shown on the plan row or only in the FAQ.
