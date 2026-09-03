# همیار — voice and vocabulary

Owner's decision (2026-09-03): the product speaks in the **professional, confident register
of top-tier Iranian SaaS**. Clear, concrete, benefit-led, formal-friendly. No literary
metaphors, no slang, no hype — and every sentence a claim the product can keep.

This file governs every Persian string: landing, auth, product screens, errors, empty
states, SMS templates, emails, printed paper. `docs/design-system.md` §6 defers here.

## Register

| do | don't |
|---|---|
| «فروشگاه شما» — second person plural, imperative «کنید» | «تو»، «مغازه‌ت» |
| «هر دستگاه یک شناسنامه دارد» — a fact | «هرچه پشت پیشخوان اتفاق می‌افتد، یک سطر می‌شود» — a metaphor |
| «چیزی نصب نمی‌شود؛ در مرورگر کار می‌کند.» | «رُک بگوییم…»، «توی کشو گم نمی‌شود» — colloquial |
| «فروش با IMEI، تعمیرات، اقساط و چک در یک سامانه» | «راهکار جامع و هوشمند مدیریت کسب‌وکار» |
| «همتا API عمومی ندارد؛ همیار وضعیت را نگه می‌دارد و یادآوری می‌کند.» | «اتصال کامل به همتا» — a claim we cannot keep |

Rules:

1. **Verb-led and concrete.** Name the action and the object: «ثبت فاکتور», «چاپ قبض پذیرش».
   Never «تأیید», «OK», «ارسال» alone.
2. **Provable.** No adjective the reader cannot verify on the next screen. Banned unless
   quoting: هوشمند · جامع · پیشرفته · بی‌نظیر · قدرتمند · انقلابی · بهترین · حرفه‌ای‌ترین.
3. **No invented numbers.** No customer counts, logos, testimonials, uptime, benchmarks.
   Trust material is named pilot shops (with consent), true product facts, honest limits.
4. **Short.** Landing headline ≤ 8 words; sub-copy ≤ 25 words; a button ≤ 3 words. Say each
   idea once — if the heading explains the state, the paragraph adds something or goes.
5. **Calm.** No exclamation marks. Rhetorical questions only as FAQ questions.
6. **Honest where the product is limited** — HAMTA, Moadian, support hours, what a lapsed
   plan can and cannot do.

## Orthography

- Persian punctuation: «،» «؛» «؟» — no space before, one after. Quotes «…». Em dash «—»
  sparingly; prefer a full stop.
- **ZWNJ** (U+200C) inside compounds and prefixes: می‌شود · نمی‌کند · ثبت‌نام · شناسنامه‌ها ·
  پیش‌فاکتور · بین‌شعبه. Never a space, never joined.
- **Ezafe after final ه** with the hamza sign: «شناسنامهٔ IMEI», «سهمیهٔ ماهانه».
- Persian letters only: «ک» (U+06A9) and «ی» (U+06CC), never Arabic «ك» «ي».
- Digits: **prose → Persian** («۳ دستگاه», «۱۴۰۵/۰۶/۱۲»), thousands with «٬» (U+066C);
  **tables, invoices, amounts in columns → Latin tabular** with «,»; **IMEI, phone, serial,
  document numbers → Latin, LTR-isolated, ungrouped** (`<Num variant="ltr">`, `dir="ltr"`).
- Money: the amount then the unit, unit never inside a ladder rung: «۱۸٬۹۰۰٬۰۰۰ تومان».
  Rial/toman follows the tenant setting; the landing speaks in toman.
- Dates: Jalali, «۱۴۰۵/۰۶/۱۲» in tables, «۱۲ شهریور» in prose.
- Latin identifiers stay Latin: IMEI, QR, SMS, Excel, API, A4, HAMTA is «همتا».

## Glossary (fixed translations — do not vary for effect)

| term | fa | note |
|---|---|---|
| invoice | فاکتور | sales invoice; «صورتحساب» is reserved for the shop's own subscription bill |
| quote | پیش‌فاکتور | |
| transfer | حواله | between branches/stores |
| stock count | انبارگردانی | |
| repair intake receipt | قبض پذیرش | |
| abandoned device | رسوبی | |
| cheque | چک | |
| installment | قسط | plan = «طرح اقساط» |
| cash account | صندوق | |
| POS terminal account | کارتخوان | |
| party | طرف حساب | the CRM entity; «مشتری» when the role is buyer, «تأمین‌کننده» when supplier, «همکار» for the reseller price level |
| trade-in | معاوضه | |
| HAMTA | همتا | never «هَمتا» styling; state the no-API fact when relevant |
| Moadian | مودیان | «سامانهٔ مودیان» |
| serialized unit | دستگاه | a handset row; «کالا» is the catalogue product |
| IMEI passport | شناسنامهٔ IMEI | the product's centre |
| quota | سهمیه | «سهمیهٔ ماهانه»; plan = «پلن» |
| shop | فروشگاه | «مغازه» only in quoted speech |
| branch | شعبه | |
| register (sign up) | ثبت‌نام | with ZWNJ, everywhere |
| log in | ورود | |
| dashboard | داشبورد | |
| SMS | پیامک | |

## Patterns

- **Button** = verb + object: «ثبت فاکتور», «چاپ قبض», «رایگان شروع کنید». Destructive =
  name the object and the consequence: «ابطال فاکتور INV-000012».
- **Error** = what failed + how to recover: «مبلغ نمی‌تواند از ماندهٔ فاکتور بیشتر باشد.
  مبلغ را کم کنید یا روش پرداخت دیگری اضافه کنید.» Never «خطا» alone; never an internal code
  as the message.
- **Empty state** = the state + the next action: «هنوز فاکتوری ثبت نشده است.» + «فروش جدید».
  Distinguish first use / no results for this filter / no permission.
- **Loading** names the operation; **success** is brief and only mentions the next step when
  it changes what to do.
- **Landing section** = one claim (heading), one proof (a real screen or a fact), one
  action. A section that cannot name its proof is cut.
