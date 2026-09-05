<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| متن‌های صفحهٔ نخست
|--------------------------------------------------------------------------
|
| Every sentence the landing page shows, in one file. It was typed into eight Blade
| templates until 16.3, against the convention CLAUDE.md states plainly — «Persian UI
| strings in `lang/fa/**`; never hardcode Farsi in components» — which meant the copy
| a shopkeeper reads was the only copy in the product a copy editor could not find.
|
| ## The shape
|
| Nested by section, in the order the sections render: the page shell, then hero,
| trust, problems, imei, tour, pricing, faq, closing. Editing the pricing copy means
| opening `pricing` and nothing else.
|
| **A key whose value carries markup ends in `_html`, and those are the only ones the
| templates render with `{!! !!}`.** Everything else goes through `{{ }}` and is
| escaped. There are exactly seven: five section headings lighting their last phrase in
| `<em>` for the accent colour (the hero's also holds «فروشگاه موبایل» together in a
| `.nowrap` span), the IMEI heading's `<br>`, and the trust bar's claim, whose four
| trades carry the line in `<b>` while the connective words step back. A new key
| carrying a tag takes the suffix, or it renders as visible angle brackets.
|
| ## What is deliberately NOT here
|
| **Anything computed.** Plan names, taglines and prices come from `plans` (roadmap
| 11.4: a price change is a panel edit, not a deploy), the quota labels come from the
| metric registry, the contact address is `'info@'.config('app.domain')` (golden rule
| 1b — a hostname literal is a bug, in `lang/` as much as anywhere), and the year in
| the copyright line is `jalali(now(), 'Y')`. Where such a value sits inside a
| sentence, the sentence is here with a `:placeholder` and the value is passed in.
|
| **The two structured-data blocks.** They are written for a machine, not a reader,
| and they stay beside the markup they describe — `landing.blade.php` for the product
| graph and `faq.blade.php` for the FAQ. The FAQ block reads `faq.items` from this
| file, so a seventh question added below appears in the rich result and on the page
| together; it cannot appear on one and be missing from the other.
|
| ## Voice
|
| `docs/brand/voice.md` governs every string here and `bin/check-copy-terms` scans this
| file: no unverifiable adjective, no Arabic ك or ي, no Arabic-Indic digits, no
| exclamation mark, a ZWNJ where a compound takes one, «فروشگاه» and never «مغازه».
| Persian digits in prose; IMEI, receipt and document numbers stay Latin because a
| serial read back to a supplier over the phone is a Latin string.
|
*/

return [

    /*
    | The document head. Search results and social unfurls are built from these four,
    | and they are the only copy on the page nobody on the team ever sees rendered.
    */
    'meta' => [
        'title' => 'سامانه همیار — نرم‌افزار فروشگاه موبایل: فروش، تعمیرات، اقساط',
        'description' => 'سامانه همیار کار روزانهٔ فروشگاه موبایل را می‌بندد: فروش سریال‌دار با IMEI، تعمیرات، اقساط و چک، پیامک خودکار و گزارش سود. پلن رایگان، بدون کارت بانکی.',
        'og_title' => 'سامانه همیار — نرم‌افزار فروشگاه موبایل',
        'og_description' => 'از پذیرش تعمیر تا تسویه، روی یک قبض.',
        'twitter_title' => 'سامانه همیار — نرم‌افزار فروشگاه موبایل',
        'twitter_description' => 'فروش با IMEI، تعمیرات، اقساط و چک، پیامک و گزارش سود — در یک سامانه.',
    ],

    // The first focusable thing on the page, and CSS-only since the 16.0 baseline.
    'skip' => 'پرش به محتوا',

    'nav' => [
        'brand_label' => 'همیار — صفحهٔ نخست',
        'aria' => 'پیمایش اصلی',
        'menu' => 'منو',
        'login' => 'ورود',
        'register' => 'ثبت‌نام',

        /*
        | The four in-page anchors. Their hrefs stay in the template — every one has to
        | match a section id that exists, which is a structural fact rather than copy.
        */
        'links' => [
            'problems' => 'امکانات',
            'imei' => 'شناسنامهٔ IMEI',
            'pricing' => 'تعرفه‌ها',
            'faq' => 'سوالات',
        ],
    ],

    /* ------------------------------------------------------------- 1. hero -- */

    'hero' => [
        /*
        | Not a customer count. The reference page this composition follows opens with
        | «Used by 500+ Phone Shops Worldwide»; this product has no such number, and
        | `docs/brand/voice.md` rule 3 forbids inventing one. It becomes a pilot-shop
        | line the day the owner supplies names and written consent.
        */
        'eyebrow' => 'نرم‌افزار ابری فروشگاه موبایل',

        /*
        | «فروشگاه موبایل» is one noun phrase and the category the headline exists to
        | name, so it is held together against `text-wrap: balance`, which was splitting
        | it across the two lines.
        */
        'title_html' => 'همهٔ کارِ <span class="nowrap">فروشگاه موبایل</span>، در <em>یک سامانه</em>',

        'lede' => 'فروش با IMEI، تعمیرات، اقساط و چک، پیامک و گزارش سود — هر گوشی با شناسهٔ خودش ثبت می‌شود و سود هر فروش همان لحظه معلوم است.',

        'cta_primary' => 'رایگان شروع کنید',
        'cta_secondary' => 'دیدن نرم‌افزار',

        'ticks' => [
            'بدون کارت بانکی',
            'در مرورگر، بدون نصب',
            'تقویم شمسی و تومان',
        ],

        /*
        | The capture is real: `bin/shots` takes it from a seeded shop, the manifest
        | records the commit, and `LandingShotsTest` fails if the two disagree. The alt
        | text describes what is actually in the frame for the same reason.
        */
        'shot_alt' => 'داشبورد همیار: فروش امروز، نمودار درآمد ۳۰ روز، چک‌ها و اقساط سررسیدشده.',

        /*
        | Three moments from one shop's day, floated over the frame. Persian digits in
        | prose and money; the IMEI stays Latin and is rendered `dir="ltr"`, because a
        | serial that reorders under bidi is a serial nobody can read back to a customer.
        */
        'cards' => [
            'imei' => [
                'title' => 'دستگاه ثبت شد',
                'value' => '356938035643809',
            ],
            'repair' => [
                'title' => 'تعمیر آمادهٔ تحویل',
                'value' => 'پیامک برای مشتری رفت',
            ],
            'instalment' => [
                'title' => 'قسط وصول شد',
                'value' => '۴٬۲۰۰٬۰۰۰ تومان',
            ],
        ],
    ],

    /* ------------------------------------------------------------ 2. trust -- */

    'trust' => [
        'claim_html' => 'مناسبِ <b>فروش</b>، <b>تعمیرات</b>، <b>اقساط</b> و <b>چک</b>',

        /*
        | Where a SaaS page would carry customer logos or a shop count. This product has
        | no paying customers yet, so any number or logo on this line would be invented.
        | These three are true today and a shopkeeper can check every one of them on the
        | free plan before paying anybody — checkable beats impressive.
        */
        'proofs' => [
            'فارسی، با تقویم شمسی',
            'روی مرورگر — چیزی نصب نمی‌شود',
            'خروجی اکسل، هر وقت خواستید',
        ],
    ],

    /* --------------------------------------------------------- 3. problems -- */

    'problems' => [
        'eyebrow' => 'مسئله',
        'title_html' => 'شش گرفتاری که هر فروشندهٔ موبایل <em>می‌شناسد</em>',
        'lede' => 'هیچ‌کدام از این‌ها از فهرست امکانات درنیامده؛ کارهایی است که یا وقت فروشگاه را می‌گیرد یا پولش را. جلوی هرکدام نوشته‌ایم همیار دقیقاً چه می‌کند.',

        /*
        | Six independent faults, not a sequence — which is why they carry no ordinals.
        | Each complaint is in the shopkeeper's own nouns (قبض پذیرش، همکار، سررسید،
        | حواله، بهای تمام‌شده) and each answer names what the software does, not how it
        | feels. The order they render in, and the icon each card carries, are in
        | `resources/views/landing/sections/problems.blade.php`.
        */
        'items' => [
            'imei' => [
                'title' => 'ردیابی IMEI و شمارهٔ سریال',
                'body' => 'گوشی‌ها در موجودی «تعداد» می‌شوند و معلوم نیست کدام دستگاه از کدام همکار آمده و به کدام مشتری رفته. در همیار هر دستگاه یک سطر با شناسهٔ خودش است.',
            ],
            'intake' => [
                'title' => 'قبض پذیرش دست‌نویس',
                'body' => 'قبض کاغذی گم می‌شود و مشتری برای خبر گرفتن زنگ می‌زند. قبض پذیرش با بارکد QR چاپ می‌شود و مشتری وضعیت دستگاهش را خودش می‌بیند.',
            ],
            'dues' => [
                'title' => 'اقساط و چک در دفترچه',
                'body' => 'سررسیدها در دفترچه و کشو می‌مانند تا روزی که دیر شده باشد. میز وصول هر روز می‌گوید چه کسی باید بیاید، چه کسی عقب افتاده و چقدر وصول نشده.',
            ],
            'profit' => [
                'title' => 'سود واقعی معلوم نیست',
                'body' => 'وقتی بهای خرید هر دستگاه جایی ثبت نشده، سود آخر ماه یک تخمین است. بهای تمام‌شده در لحظهٔ فروش ثبت می‌شود و سود هر فاکتور همان‌جا معلوم است.',
            ],
            'branches' => [
                'title' => 'چند شعبه، چند حساب',
                'body' => 'موجودی هر شعبه جدا شمرده می‌شود و حواله بین آن‌ها ثبتی ندارد. انبار هر شعبه و حواله‌های بین شعبه‌ها در یک جا و روی یک موجودی است.',
            ],
            'sms' => [
                'title' => 'خبردادن به مشتری، دستی',
                'body' => 'یادآوری قسط و خبر آماده‌شدن دستگاه یادتان می‌رود یا وقت می‌گیرد. پیامک از روی رویدادهای خود سیستم فرستاده می‌شود.',
            ],
        ],
    ],

    /* ------------------------------------------------------------- 4. imei -- */

    'imei' => [
        'title_html' => 'این شناسه را بزنید،<br>بقیه‌اش پیداست.',
        'lede' => 'گوشی در همیار «تعداد» نیست؛ هر دستگاه یک سطر با شناسهٔ خودش است. هر خرید، تعمیر، حواله و فروشی که رویش ثبت شود زیر همان شناسه می‌ماند — حتی اگر دو سال بعد سراغش را بگیرید.',

        'field_label' => 'شناسهٔ دستگاه را وارد کنید',
        'hint' => 'سه پروندهٔ نمونه از یک فروشگاه آزمایشی. یکی را انتخاب کنید، یا شناسه را رقم‌به‌رقم تایپ کنید.',

        // Typed digits that match no sample. A dead end is a bad answer, so this one
        // says what the visitor would actually get in their own account.
        'miss' => 'این شناسه بین سه نمونهٔ بالا نیست.',
        'miss_detail' => 'در حساب خودتان، هر دستگاهی که ثبت کرده باشید همین صفحه را می‌سازد.',

        /*
        | The caveat belongs beside the claim it qualifies: a shopkeeper who believes we
        | register in HAMTA and finds out later has been mis-sold. The FAQ answers the
        | same question at length — one line here, the full answer there, because the two
        | arrived as near-identical paragraphs 120 words apart on the page's most
        | negative message.
        */
        'honesty' => 'همتا API عمومی ندارد، پس ثبت نهایی را خودتان انجام می‌دهید — همیار وضعیت هر دستگاه را نگه می‌دارد و یادآوری می‌کند.',

        /*
        | Three sample records — seed-shaped fiction from a demo shop, never a customer's
        | data. They are deliberately three DIFFERENT stories: a phone bought and sold, a
        | trade-in repaired before resale, and one still sitting on a shelf in the second
        | branch, because a shopkeeper recognises the third case as fast as the first.
        |
        | The serial in the masthead is the first record's, not a fourth invented number.
        | `state` is the record's own word for where the unit is, not a colour.
        |
        | Which events each record carries, in which order, and the icon beside each, are
        | in `resources/views/landing/sections/imei.blade.php` — the copy is here, the
        | timeline's shape is there. A `null` label or amount renders no amount row.
        */
        'records' => [
            'iphone-13' => [
                'imei' => '354879116234901',
                'name' => 'اپل آیفون ۱۳ — ۱۲۸ گیگ',
                'state' => 'فروخته شده',
                'events' => [
                    'bought' => [
                        'ask' => 'از که خریدم؟',
                        'title' => 'خرید از پخش موبایل ایرانیان',
                        'date' => '۱۴۰۵/۰۲/۱۱',
                        'doc' => 'PUR-00924',
                        'note' => 'همان روز با اسکن IMEI وارد انبار شد.',
                        'label' => 'بهای تمام‌شده',
                        'amount' => '۴۱٬۲۰۰٬۰۰۰ تومان',
                    ],
                    'sold' => [
                        'ask' => 'به که فروختم؟',
                        'title' => 'فروش به سمیرا احمدی',
                        'date' => '۱۴۰۵/۰۳/۰۴',
                        'doc' => 'INV-001873',
                        'note' => 'شش قسط ماهانه، با دو چک ضمانت.',
                        'label' => 'مبلغ فاکتور',
                        'amount' => '۴۴٬۹۰۰٬۰۰۰ تومان',
                    ],
                    'repaired' => [
                        'ask' => 'کِی تعمیر شد؟',
                        'title' => 'تعمیر: تعویض گلس',
                        'date' => '۱۴۰۵/۰۵/۲۹',
                        'doc' => 'REP-000184',
                        'note' => 'شش ماه بعد از فروش، خارج از گارانتی؛ هزینه از مشتری گرفته شد.',
                        'label' => 'اجرت تعمیر',
                        'amount' => '۴۲۰٬۰۰۰ تومان',
                    ],
                ],
                'result' => [
                    'label' => 'سود این دستگاه',
                    'value' => '۳٬۷۰۰٬۰۰۰ تومان',
                ],
            ],

            'galaxy-a54' => [
                'imei' => '356938035643809',
                'name' => 'سامسونگ گلکسی A54',
                'state' => 'فروخته شده',
                'events' => [
                    'bought' => [
                        'ask' => 'از که خریدم؟',
                        'title' => 'معاوضه از مرتضی کاظمی',
                        'date' => '۱۴۰۵/۰۴/۱۸',
                        'doc' => 'INV-001902',
                        'note' => 'به‌عنوان معاوضه، پای فاکتور فروش یک گوشی دیگر تحویل گرفته شد.',
                        'label' => 'بهای تمام‌شده',
                        'amount' => '۱۴٬۳۰۰٬۰۰۰ تومان',
                    ],
                    'repaired' => [
                        'ask' => 'کِی تعمیر شد؟',
                        'title' => 'تعویض باتری، پیش از فروش',
                        'date' => '۱۴۰۵/۰۴/۲۱',
                        'doc' => 'REP-000171',
                        'note' => 'باتری از انبار کم شد و هزینه‌اش روی بهای تمام‌شدهٔ همین دستگاه نشست.',
                        'label' => 'هزینهٔ قطعه',
                        'amount' => '۹۸۰٬۰۰۰ تومان',
                    ],
                    'sold' => [
                        'ask' => 'به که فروختم؟',
                        'title' => 'فروش به فاطمه رستمی',
                        'date' => '۱۴۰۵/۰۵/۰۹',
                        'doc' => 'INV-001955',
                        'note' => 'نقدی، با کارتخوان فروشگاه.',
                        'label' => 'مبلغ فاکتور',
                        'amount' => '۱۷٬۵۰۰٬۰۰۰ تومان',
                    ],
                ],
                'result' => [
                    'label' => 'سود این دستگاه',
                    'value' => '۲٬۲۲۰٬۰۰۰ تومان',
                ],
            ],

            'redmi-note-12' => [
                'imei' => '861234037654321',
                'name' => 'شیائومی ردمی نوت ۱۲',
                'state' => 'موجود در انبار',
                'events' => [
                    'bought' => [
                        'ask' => 'از که خریدم؟',
                        'title' => 'خرید از پخش موبایل ایرانیان',
                        'date' => '۱۴۰۵/۰۵/۰۲',
                        'doc' => 'PUR-01037',
                        'note' => 'یکی از هفت دستگاه همان فاکتور؛ هرکدام سطر خودش را دارد.',
                        'label' => 'بهای تمام‌شده',
                        'amount' => '۹٬۶۵۰٬۰۰۰ تومان',
                    ],
                    'moved' => [
                        'ask' => 'الان کجاست؟',
                        'title' => 'حواله از انبار مرکزی به شعبهٔ ۲',
                        'date' => '۱۴۰۵/۰۵/۲۰',
                        'doc' => 'TRF-000318',
                        'note' => 'همان دستگاه، همان شناسه، انبار دیگر — نه یک ردیف جدید.',
                        'label' => null,
                        'amount' => null,
                    ],
                    'sold' => [
                        'ask' => 'به که فروختم؟',
                        'title' => 'هنوز فروخته نشده',
                        'date' => null,
                        'doc' => null,
                        'note' => 'روی رَف شعبهٔ ۲ است. تا وقتی نرود، این سطر خالی می‌ماند.',
                        'label' => null,
                        'amount' => null,
                    ],
                ],
                'result' => [
                    'label' => 'سود این دستگاه',
                    'value' => 'بعد از فروش',
                ],
            ],
        ],
    ],

    /* ------------------------------------------------------------- 5. tour -- */

    'tour' => [
        'eyebrow' => 'داخل نرم‌افزار',
        'title_html' => 'همان صفحه‌هایی که هر روز <em>باز می‌کنید</em>',

        /*
        | A claim `bin/shots` keeps true: the six captures come from a seeded shop, the
        | manifest records the commit each was taken at, `LandingShotsTest` fails if the
        | manifest and the files disagree, and the weekly workflow re-takes them. The
        | page said this once while showing a product that had changed twice since.
        */
        'lede' => 'تصویرها از خود نرم‌افزار گرفته شده‌اند — نه ماکت، نه طرح.',

        /*
        | Six screens. `name` is the caption's lead, `body` the sentence under it, `alt`
        | what a screen reader gets — and `alt` describes what is actually in the frame,
        | because a visitor who cannot see the capture is owed the same evidence.
        |
        | Which shots survive below 640px, how wide each tile is, and where a phone-sized
        | crop focuses are in `resources/views/landing/sections/tour.blade.php`: three of
        | these six are dropped on a phone, because a 1440×900 capture in a 358px shell
        | puts the interface inside it at about two pixels and asks to be believed while
        | showing nothing.
        */
        'screens' => [
            'pos' => [
                'name' => 'صندوق فروش',
                'body' => 'بارکد یا IMEI را می‌زنید و دستگاه با همان شناسه روی فاکتور می‌نشیند. معاوضه، تخفیف و چند روش پرداخت، همه روی همین یک صفحه.',
                'alt' => 'صفحهٔ صندوق فروش همیار: سبد فاکتور با یک گوشی سریال‌دار، جعبهٔ اسکن بارکد و روش‌های پرداخت.',
            ],
            'repairs' => [
                'name' => 'بورد تعمیرات',
                'body' => 'هر قبض پذیرش یک کارت است و بین وضعیت‌های واقعی کارگاه جابه‌جا می‌شود: پذیرش، در دست تعمیر، آمادهٔ تحویل، رسوبی.',
                'alt' => 'بورد تعمیرات همیار: کارت‌های قبض پذیرش در ستون‌های پذیرش، در دست تعمیر و آمادهٔ تحویل.',
            ],
            'installments' => [
                'name' => 'جدول اقساط',
                'body' => 'چه کسی امروز باید بیاید، چه کسی عقب افتاده و چقدر هنوز وصول نشده — به‌جای دفترچه‌ای که فقط خودتان می‌توانید بخوانید.',
                'alt' => 'جدول اقساط همیار: سررسیدها، مبلغ هر قسط و وضعیت وصول برای چند مشتری.',
            ],
            'profit' => [
                'name' => 'گزارش سود',
                'body' => 'بهای تمام‌شده در همان لحظهٔ فروش ثبت می‌شود، پس سود آخر ماه سود واقعی است، نه تفاضل قیمت امروز با قیمت خرید.',
                'alt' => 'گزارش سود همیار: فروش، بهای تمام‌شده و سود به تفکیک کالا در یک بازهٔ شمسی.',
            ],
            'sms' => [
                'name' => 'پیامک',
                'body' => 'پیامکِ «دستگاه آماده است» و یادآوری قسط از روی رویدادهای خود سیستم می‌رود، نه از روی فهرستی که باید یادتان بماند.',
                'alt' => 'صفحهٔ پیامک همیار: قالب‌های آماده و سیاههٔ پیامک‌های ارسال‌شده به مشتریان.',
            ],
            'imei' => [
                'name' => 'پروندهٔ دستگاه',
                'body' => 'همان پرونده‌ای که بالاتر ورق زدید، این بار داخل نرم‌افزار: خرید، تعمیر، حواله و فروش، همه زیر یک شناسه.',
                'alt' => 'پروندهٔ یک دستگاه در همیار: شناسهٔ IMEI و سابقهٔ خرید، تعمیر و فروش همان گوشی.',
            ],
        ],
    ],

    /* ---------------------------------------------------------- 6. pricing -- */

    'pricing' => [
        'eyebrow' => 'تعرفه',
        'title_html' => 'قیمت همینی است که <em>می‌بینید</em>',
        'lede' => 'همهٔ امکانات در همهٔ پلن‌ها باز است؛ فقط سهمیهٔ ماهانه فرق می‌کند. پلن پایه رایگان است و کارت بانکی نمی‌خواهد. هر ماه می‌توانید پلن را بالا و پایین ببرید یا قطع کنید — قرارداد سالانه و جریمهٔ فسخ نداریم.',

        'billing_aria' => 'دورهٔ پرداخت',
        'monthly' => 'ماهانه',
        'yearly' => 'سالانه',

        /*
        | Yearly is twelve months for the price of ten, and the deal is STATED rather than
        | implied by a smaller number appearing. `:months` is the free count, worked out
        | from the factor in the template so the two can never disagree.
        */
        'saving' => '۱۲ ماه به قیمت ۱۰ ماه — :months ماه رایگان.',
        'year_equivalent' => 'معادل سالانه :amount تومان · :months ماه رایگان',

        // The owner's own words for this mark, from the brief.
        'recommended' => 'پیشنهاد ما',

        // The free rung. No yearly figure: twelve times nothing is still nothing, and a
        // «۰ تومان» with a discount beside it reads as a trick rather than an offer.
        'free_price' => 'رایگان',
        'free_unit' => 'برای همیشه',
        'free_note' => 'بدون کارت بانکی — هر وقت خواستید ارتقا دهید',

        'unit_month' => 'تومان / ماه',
        'unit_year' => 'تومان / سال',

        'cta' => 'رایگان شروع کنید',

        /*
        | Since DECISION GATE 6 a plan sells how much work a shop may record in a Jalali
        | month, not which modules it may open. The numbers and the metric labels beside
        | them are read from `plans` and the metric registry; only these three fixed
        | strings are copy.
        */
        'included_label' => 'سهمیهٔ ماهانه:',
        'unlimited' => 'نامحدود',
        'all_modules' => 'و همهٔ ماژول‌های دیگر، بدون استثنا',
    ],

    /* -------------------------------------------------------------- 7. faq -- */

    'faq' => [
        'eyebrow' => 'سؤال‌های پیش از خرید',
        'title_html' => 'قبل از اینکه <em>بپرسید</em>',
        'lede' => 'شش سؤالی که هر فروشندهٔ موبایل پیش از خرید می‌پرسد — با جواب صریح، حتی آنجا که جوابش «نه» است.',

        /*
        | Purchase objections, not trivia, in the order the owner listed them. Two are
        | questions a marketing page would rather not print — what happens with همتا
        | (nothing automatic; it has no public API) and what happens when a subscription
        | lapses — and answering those plainly is worth more than a sixth feature claim.
        |
        | **This array is the single source for both the rendered list and the FAQPage
        | structured data.** `faq.blade.php` builds the JSON-LD from it rather than from
        | a second copy, so a seventh question appears in the rich result and on the page
        | together, and an unflattering answer cannot be quietly dropped from one of the
        | two. `tests/Feature/LandingSeoTest.php` asserts exactly that.
        */
        'items' => [
            [
                'q' => 'با سامانهٔ همتا چه می‌کند؟',
                'a' => 'همتا API عمومی ندارد، پس هیچ نرم‌افزاری — از جمله ما — نمی‌تواند مستقیم در آن ثبت کند و هر کس خلافش را بگوید دارد چیزی می‌فروشد که ندارد. کاری که همیار می‌کند این است: وضعیت همتای هر IMEI را کنار خود دستگاه نگه می‌دارد، دستگاه‌های ثبت‌نشده را یادآوری می‌کند و مرحله‌های کار را نشان می‌دهد. ثبت نهایی را خودتان در سامانه انجام می‌دهید.',
            ],
            [
                'q' => 'سامانهٔ مودیان چطور؟',
                'a' => 'ماژول مودیان صورتحساب‌ها را با همان قالبی که سامانه می‌خواهد آماده می‌کند و صف ارسال دارد. ارسال واقعی از راه همان شرکت معتمدی انجام می‌شود که خودتان با آن قرارداد دارید؛ شناسه و کلید حافظهٔ مالیاتی را یک بار در تنظیمات وارد می‌کنید و بعد از آن کاری ندارید.',
            ],
            [
                'q' => 'از نرم‌افزار قبلی‌ام می‌توانم بیایم؟',
                'a' => 'بله، با فایل اکسل: فهرست کالاها، مشتری‌ها و مانده‌حساب‌ها. قبل از ثبت نهایی یک پیش‌نمایش می‌بینید و ستون‌ها را خودتان تطبیق می‌دهید، پس هیچ چیز کورکورانه وارد نمی‌شود و یک فایل به‌هم‌ریخته، انبارتان را به هم نمی‌ریزد.',
            ],
            [
                'q' => 'داده‌های من مال کیست؟',
                'a' => 'مال شما. هر وقت بخواهید از همه‌چیز خروجی اکسل می‌گیرید — کالا، فاکتور، مشتری، چک و قسط — و برای بردن‌شان لازم نیست از کسی اجازه بگیرید. اطلاعات هر فروشگاه هم از بقیه جداست، و این جداسازی در خودِ پایگاه داده اعمال می‌شود، نه فقط در نرم‌افزار.',
            ],
            [
                'q' => 'پشتیبانی چطور است؟',
                'a' => 'وارد کردن فهرست کالاها از فایل اکسل خودتان است و راهنمای مرحله‌به‌مرحله دارد؛ اگر جایی گیر کردید کمک می‌کنیم. پشتیبانی از داخل خود نرم‌افزار و با ایمیل است و کسی جواب می‌دهد که نرم‌افزار را می‌شناسد.',
            ],
            [
                'q' => 'اگر اشتراکم تمام شود چه می‌شود؟',
                'a' => 'داده‌هایتان پاک نمی‌شود و سر جای خودش می‌ماند — ولی تا وقتی تمدید نکنید، ورود به حساب بسته است. پس قبل از سررسید خروجی اکسل بگیرید؛ ما هم از یک هفته قبل یادآوری می‌کنیم. هر وقت تمدید کنید همه‌چیز دقیقاً همان‌جاست که گذاشته بودید.',
            ],
        ],

        // The address itself is `'info@'.config('app.domain')` and stays in the template
        // — golden rule 1b, and `bin/check-apex-domain` scans this directory too.
        'help' => 'سؤالتان اینجا نبود؟ بنویسید',
    ],

    /* ---------------------------------------------------------- 8. closing -- */

    'closing' => [
        'title' => 'اولین فاکتورتان را همین امروز بزنید',
        'lede' => 'چیزی نصب نمی‌شود. فروشگاه را می‌سازید، فهرست کالا را از اکسل وارد می‌کنید و پشت پیشخوان شروع می‌کنید. راه‌اندازی کار یک بعدازظهر است، نه یک پروژه.',
        'cta_primary' => 'رایگان شروع کنید',
        'cta_secondary' => 'دیدن تعرفه‌ها',
        'note' => 'بدون کارت بانکی · بدون قرارداد سالانه · خروجی اکسل هر وقت خواستید',

        'footer' => [
            'brand_label' => 'همیار',
            'about' => 'نرم‌افزار ابری فروشگاه‌های موبایل: فروش سریال‌دار، تعمیرات، اقساط و چک، پیامک و گزارش سود. فارسی، تقویم شمسی، و ساخته‌شده برای بازار ایران.',
            'nav_aria' => 'پیوندهای فوتر',

            'product_heading' => 'محصول',

            // Each label's anchor stays in the template: every one has to match a section
            // id that exists on this page. `#features` sat in that list for three sections
            // that no longer carried the id — the quietest kind of broken link.
            'product' => [
                'problems' => 'امکانات',
                'imei' => 'شناسنامهٔ IMEI',
                'tour' => 'گشتی در نرم‌افزار',
                'pricing' => 'تعرفه‌ها',
                'faq' => 'سؤالات پرتکرار',
            ],

            'modules_heading' => 'ماژول‌ها',

            // Plain labels, not links: there is no per-module section to point them at,
            // and a footer of links that all land in the same place is worse than a list
            // that admits it is a list.
            'modules' => [
                'فروش و صندوق',
                'انبار سریال‌دار و IMEI',
                'تعمیرات',
                'اقساط و چک',
                'خزانه و بانک',
                'پیامک',
                'گزارش سود',
                'چندشعبه و حواله',
            ],

            'start_heading' => 'شروع کنید',
            'register' => 'ساخت فروشگاه',
            'login' => 'ورود به حساب',
            'contact' => 'تماس با ما',

            'legal_heading' => 'قوانین',
            'terms' => 'قوانین و شرایط',
            'privacy' => 'حریم خصوصی',
            'data' => 'مالکیت داده‌های شما',

            // `:year` is `jalali(now(), 'Y')`, rendered rather than typed: a hardcoded
            // «۱۴۰۵» is correct for four more months and then quietly wrong on the one
            // page every prospect reads.
            'copyright' => '© :year همیار — همهٔ حقوق محفوظ است.',
            'made_for' => 'ساخته‌شده برای فروشگاه‌های موبایل ایران.',
        ],
    ],

];
