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
        /*
        | The tab, and nothing else. It was three clauses until the owner read it in a
        | browser tab («خیلی طولانیه… همین عادی بنویسه سامانه همیار کافیه») — a tab strip
        | shows about twenty characters, so every keyword after the dash was paying rent
        | in a search result while making the tab unreadable. The keywords moved into
        | `description`, which is the line under the title in a result and is read; the
        | unfurl titles below are not tabs and keep the category beside the name.
        */
        'title' => 'سامانه همیار',
        'description' => 'نرم‌افزار فروشگاه موبایل: فروش با IMEI، قبض پذیرش تعمیر، اقساط و چک، پیامک و گزارش سود. در مرورگر، با تقویم شمسی و تومان. پلن پایه رایگان و بدون کارت بانکی.',
        'og_title' => 'سامانه همیار — نرم‌افزار فروشگاه موبایل',
        'og_description' => 'هر گوشی پروندهٔ خودش را دارد: خرید، تعمیر، حواله و فروش، زیر یک شناسه.',
        'twitter_title' => 'سامانه همیار — نرم‌افزار فروشگاه موبایل',
        'twitter_description' => 'فاکتور فروش، قبض پذیرش، قسط و چک — همه در یک حساب، با تقویم شمسی.',
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
            'faq' => 'سؤالات',
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

        /*
        | Not the module list again — the head already names the category and the trust
        | bar below names the four trades. This says what the shopkeeper ends the day
        | holding: the four documents the work actually produces, and the one number a
        | ruled ledger never gives back.
        */
        'lede' => 'فاکتور فروش، قبض پذیرش، سررسید قسط و چک — همه در یک حساب. سودِ هر دستگاه هم پای همان فاکتور معلوم است.',

        'cta_primary' => 'رایگان شروع کنید',
        'cta_secondary' => 'دیدن نرم‌افزار',

        /*
        | The offer, not the product: three lines about what starting costs. The product
        | facts (Persian and Jalali, the browser, Excel) are the trust bar's three, one
        | screen below — the two lists said «در مرورگر» and «تقویم شمسی» twice between
        | them, which is how a page starts sounding like it is padding.
        */
        'ticks' => [
            'پلن پایه رایگان',
            'بدون کارت بانکی',
            'هر ماه، بدون قرارداد سالانه',
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
            // Not «پیامک برای مشتری رفت»: the SMS automations ship off and no screen
            // enables them (see `problems.items.sms`). The QR on the قبض پذیرش needs no
            // setting at all — `/t/{token}` is public and the receipt prints it.
            'repair' => [
                'title' => 'تعمیر آمادهٔ تحویل',
                'value' => 'مشتری با QR پیگیری می‌کند',
            ],
            'instalment' => [
                'title' => 'قسط وصول شد',
                'value' => '۴٬۲۰۰٬۰۰۰ تومان',
            ],
        ],
    ],

    /* ------------------------------------------------------------ 2. trust -- */

    'trust' => [
        // «مناسبِ» is what a brochure says about a product it is not sure of. The shop
        // does all four in one day; the line says that in the way it would be said aloud.
        'claim_html' => 'هم <b>فروش</b>، هم <b>تعمیرات</b>، هم <b>اقساط</b> و <b>چک</b>',

        /*
        | Where a SaaS page would carry customer logos or a shop count. This product has
        | no paying customers yet, so any number or logo on this line would be invented.
        | These three are true today and a shopkeeper can check every one of them on the
        | free plan before paying anybody — checkable beats impressive.
        |
        | The third one says «گزارش‌ها» and not «همه‌چیز» on purpose: the seven report
        | screens each carry an Excel button, and the product, invoice and party LISTS
        | do not. The wider claim was on this bar and in the FAQ, and neither was true.
        */
        'proofs' => [
            'فارسی، با تقویم شمسی و تومان',
            'در مرورگر باز می‌شود؛ چیزی نصب نمی‌شود',
            'گزارش‌ها، با خروجی اکسل',
        ],
    ],

    /* --------------------------------------------------------- 3. problems -- */

    'problems' => [
        /*
        | «مسئله» named a category and said nothing. What these six have in common is
        | what the kicker now says — each one costs the shop either an hour or a sum —
        | and the lede no longer spends twenty-five words explaining that the page is
        | not a feature list, which is a sentence about the page rather than about the
        | shop.
        */
        'eyebrow' => 'وقت و پولِ فروشگاه',
        'title_html' => 'شش گرفتاری که هر فروشندهٔ موبایل <em>می‌شناسد</em>',
        'lede' => 'زیر هرکدام نوشته‌ایم همیار چه می‌کند.',

        /*
        | Six independent faults, not a sequence — which is why they carry no ordinals.
        |
        | **The heading is a moment, not a category.** «ردیابی IMEI و شمارهٔ سریال» is a
        | feature-list row; «مشتری با همان گوشی برمی‌گردد» is a Tuesday afternoon, and a
        | shopkeeper recognises the second before reading the body. The body then answers
        | that moment with what the software does — in the shopkeeper's own nouns (قبض
        | پذیرش، همکار، سررسید، حواله، بهای تمام‌شده), never in how it feels.
        |
        | Statements, not questions: `docs/brand/voice.md` rule 5 keeps rhetorical
        | questions to the FAQ, where a question is the actual form of the content.
        |
        | The order they render in, and the icon each card carries, are in
        | `resources/views/landing/sections/problems.blade.php`.
        */
        'items' => [
            'imei' => [
                'title' => 'مشتری با همان گوشی برمی‌گردد',
                'body' => 'شمارهٔ سریال را می‌زنید و همان‌جا معلوم است دستگاه از کدام همکار آمده، کِی و به چه قیمتی فروخته شده و قبلاً چه تعمیری داشته. گشتن در فاکتورهای پارسال لازم نیست.',
            ],
            'intake' => [
                'title' => 'تلفن‌های پیاپی برای خبر گرفتن از تعمیر',
                'body' => 'قبض پذیرش با بارکد QR چاپ می‌شود؛ مشتری همان را اسکن می‌کند و وضعیت دستگاهش را خودش می‌بیند. یک قبض کاغذیِ گم‌شده هم دیگر پروندهٔ تعمیر را گم نمی‌کند.',
            ],
            'dues' => [
                'title' => 'چکی که سررسیدش را کسی یادش نبود',
                'body' => 'چک‌ها و اقساط یک میز وصول دارند: امروز چه کسی باید بیاید، چه کسی عقب افتاده و چقدر هنوز وصول نشده است.',
            ],
            'profit' => [
                'title' => 'فروش خوب بوده، سود معلوم نیست',
                'body' => 'بهای خرید هر دستگاه سرِ فروش روی همان فاکتور می‌نشیند، پس سود آخر ماه تفاوت واقعی خرید و فروش است، نه تخمینی از روی قیمت امروز.',
            ],
            'branches' => [
                'title' => 'مشتری چیزی می‌خواهد که شعبهٔ دیگر دارد',
                'body' => 'موجودی هر دو شعبه را از همین‌جا می‌بینید و جابه‌جایی دستگاه را با حواله ثبت می‌کنید: همان شناسه، انبار دیگر، بدون یک ردیف تازه.',
            ],
            /*
            | The only card that states a limit, because the alternative was to state
            | something untrue. The listeners are wired and the send is queued —
            | `SendRepairStatusSms` and `SendInvoiceIssuedSms` — but every automation
            | ships off and **no screen turns one on**: `/messaging` is a read-only log
            | and no route writes the shop's settings. Until one does, «پیامک خودکار»
            | with no caveat would be selling a switch that is not on any screen.
            */
            'sms' => [
                'title' => 'خبر دادن به مشتری، یکی‌یکی و با تلفن',
                'body' => 'پیامکِ «دستگاه آماده است» و «فاکتور ثبت شد» را خود سامانه از روی رویدادهای فروشگاه می‌فرستد. روشن‌کردنشان فعلاً دست ماست، نه یک کلید در تنظیمات.',
            ],
        ],
    ],

    /* ------------------------------------------------------------- 4. imei -- */

    'imei' => [
        'title_html' => 'این شناسه را بزنید،<br>بقیه‌اش پیداست.',
        // «هر دستگاه یک سطر با شناسهٔ خودش است» is the data model; a shopkeeper buys the
        // four answers, not the row. The four are the timeline's own questions below.
        'lede' => 'هر گوشی که وارد فروشگاه می‌شود پروندهٔ خودش را باز می‌کند: از که خریدید، چه تعمیری شد، کجا رفت و به که فروختید. دو سال بعد هم همان‌جاست.',

        'field_label' => 'شناسهٔ دستگاه را وارد کنید',
        'hint' => 'سه پروندهٔ نمونه از یک فروشگاه آزمایشی. یکی را انتخاب کنید یا شناسه را تایپ کنید.',

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
        // «یادآوری می‌کند» was not true: the module keeps a status per unit and a list
        // of the unregistered ones, and nothing sends a reminder about either.
        'honesty' => 'همتا API عمومی ندارد، پس ثبت نهایی را خودتان انجام می‌دهید — همیار وضعیت همتای هر دستگاه را کنارش نگه می‌دارد و ثبت‌نشده‌ها را جدا نشان می‌دهد.',

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
        'eyebrow' => 'شش صفحه از یک روز کاری',
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
                'body' => 'فاکتور را با اسکن می‌بندید: بارکد، IMEI یا سریال. معاوضه، تخفیف و چند روش پرداخت هم روی همان فاکتور جا می‌شود.',
                'alt' => 'صفحهٔ صندوق فروش همیار: سبد فاکتور با یک گوشی سریال‌دار، جعبهٔ اسکن بارکد و روش‌های پرداخت.',
            ],
            'repairs' => [
                'name' => 'بورد تعمیرات',
                'body' => 'تا لحظهٔ تحویل معلوم است هر دستگاه دست کیست و در چه مرحله‌ای مانده — پذیرش، در دست تعمیر، آمادهٔ تحویل، رسوبی.',
                'alt' => 'بورد تعمیرات همیار: کارت‌های قبض پذیرش در ستون‌های پذیرش، در دست تعمیر و آمادهٔ تحویل.',
            ],
            'installments' => [
                'name' => 'جدول اقساط',
                'body' => 'صبح که می‌رسید، می‌دانید امروز سراغ چه کسی بروید و چقدر از قسط‌ها عقب افتاده است.',
                'alt' => 'جدول اقساط همیار: سررسیدها، مبلغ هر قسط و وضعیت وصول برای چند مشتری.',
            ],
            'profit' => [
                'name' => 'گزارش سود',
                'body' => 'سر ماه به‌جای تخمین، تفاوت واقعی خرید و فروش را می‌بینید — به تفکیک کالا، برند، یا خودِ آن دستگاه.',
                'alt' => 'گزارش سود همیار: فروش، بهای تمام‌شده و سود به تفکیک کالا در یک بازهٔ شمسی.',
            ],
            'sms' => [
                'name' => 'پیامک',
                'body' => 'خبر آماده‌شدن دستگاه و ثبت فاکتور، از روی رویدادهای خود سامانه — نه از روی فهرستی که باید یادتان بماند.',
                'alt' => 'صفحهٔ پیامک همیار: قالب‌های آماده و سیاههٔ پیامک‌های ارسال‌شده به مشتریان.',
            ],
            'imei' => [
                'name' => 'پروندهٔ دستگاه',
                'body' => 'همان پرونده‌ای که بالاتر ورق زدید، این بار در خود نرم‌افزار.',
                'alt' => 'پروندهٔ یک دستگاه در همیار: شناسهٔ IMEI و سابقهٔ خرید، تعمیر و فروش همان گوشی.',
            ],
        ],
    ],

    /* ---------------------------------------------------------- 6. pricing -- */

    'pricing' => [
        // Not the word «تعرفه» over a table of tariffs: the kicker states the one fact
        // that decides whether the table below is worth reading (ADR 0018, GATE 6).
        'eyebrow' => 'همهٔ ماژول‌ها، در هر پلن',
        'title_html' => 'قیمت همینی است که <em>می‌بینید</em>',
        'lede' => 'هیچ ماژولی پشت پلن گران‌تر قفل نیست؛ پلن‌ها فقط در سهمیهٔ ماهانه فرق دارند. پلن پایه رایگان است و هر ماه می‌توانید پلن را عوض کنید یا قطع کنید.',

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
        // The lede used to restate the heading and then announce its own honesty. It
        // now says the one thing the six answers below prove.
        'eyebrow' => 'پیش از خرید',
        'title_html' => 'قبل از اینکه <em>بپرسید</em>',
        'lede' => 'هر جا جواب «نه» بوده، همان را نوشته‌ایم.',

        /*
        | Purchase objections, not trivia, in the order the owner listed them. Four of
        | the six now print something a marketing page would rather not: همتا has no
        | public API, the مودیان connection is not live, there is no support screen or
        | phone line, and the product and invoice lists have no Excel button yet. Each
        | was checked against the code before it was written, and each replaced a
        | sentence that claimed the opposite. Answering these plainly is worth more than
        | a sixth feature claim — and the lapse answer was simply wrong: ADR 0018 says a
        | lapsed shop falls back to the free plan and is never locked out, while the
        | page said «ورود به حساب بسته است».
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
                'a' => 'همتا API عمومی ندارد، پس هیچ نرم‌افزاری — از جمله ما — نمی‌تواند مستقیم در آن ثبت کند و هرکس خلافش را بگوید دارد چیزی می‌فروشد که ندارد. کاری که همیار می‌کند این است: وضعیت همتای هر IMEI را کنار خود دستگاه نگه می‌دارد، دستگاه‌های ثبت‌نشده را در فهرست جدا نشان می‌دهد و مرحله‌های انتقال را قدم‌به‌قدم جلوتان می‌گذارد. ثبت نهایی را خودتان در سامانهٔ همتا انجام می‌دهید.',
            ],
            [
                'q' => 'سامانهٔ مودیان چطور؟',
                'a' => 'ماژول مودیان صورتحساب را با همان قالبی که سامانه می‌خواهد می‌سازد و در صف ارسال می‌گذارد. ولی اتصال به شرکت معتمد هنوز زنده نیست و چیزی واقعاً ارسال نمی‌شود؛ اگر امروز به ارسال مودیان نیاز دارید، روی همیار حساب نکنید.',
            ],
            [
                'q' => 'از نرم‌افزار قبلی‌ام می‌توانم بیایم؟',
                'a' => 'بله، با فایل اکسل: فهرست کالاها، مشتری‌ها و مانده‌حساب اولیه‌شان. فایل خودتان را می‌دهید، ستون‌ها را تطبیق می‌دهید و پیش از ثبت نهایی همان سطرها را در یک پیش‌نمایش می‌بینید. موجودی اولیهٔ انبار از این فایل وارد نمی‌شود؛ آن را با فاکتور خرید یا انبارگردانی ثبت می‌کنید تا بهای تمام‌شده درست دربیاید.',
            ],
            [
                'q' => 'داده‌های من مال کیست؟',
                'a' => 'مال شما. گزارش‌های فروش، سود، موجودی، مالی — با چک و قسط — و مالیات هرکدام دکمهٔ خروجی اکسل دارند و برای بردن‌شان از کسی اجازه نمی‌گیرید. فهرست خام کالا و فاکتور هنوز دکمهٔ خروجی ندارد. دادهٔ هر فروشگاه هم از بقیه جداست و این جداسازی در خودِ پایگاه داده اعمال می‌شود، نه فقط در نرم‌افزار.',
            ],
            [
                'q' => 'پشتیبانی چطور است؟',
                'a' => 'با ایمیل. صفحهٔ پشتیبانی داخل نرم‌افزار و شمارهٔ تلفن پشتیبانی نداریم. نشانی پایین همین صفحه به ما می‌رسد و کسی جواب می‌دهد که خود نرم‌افزار را نوشته است.',
            ],
            [
                'q' => 'اگر اشتراکم تمام شود چه می‌شود؟',
                'a' => 'حسابتان بسته نمی‌شود. داده‌ها سر جای خودشان می‌مانند، ورود و دیدن و خروجی گرفتن باز است، و فروشگاه به همان پلن پایهٔ رایگان برمی‌گردد: فقط سهمیهٔ ثبت ماهانه به اندازهٔ آن پلن می‌شود. هر وقت تمدید کنید، سهمیه از همان ماه برمی‌گردد.',
            ],
        ],

        // The address itself is `'info@'.config('app.domain')` and stays in the template
        // — golden rule 1b, and `bin/check-apex-domain` scans this directory too.
        'help' => 'سؤالتان اینجا نبود؟ بنویسید',
    ],

    /* ---------------------------------------------------------- 8. closing -- */

    'closing' => [
        'title' => 'اولین فاکتورتان را همین امروز ثبت کنید',
        // «راه‌اندازی کار یک بعدازظهر است» went: it is a promise about the visitor's own
        // afternoon that nothing in the product can keep. What is left is three steps
        // that are each true and each takes one screen.
        'lede' => 'حساب فروشگاه را می‌سازید، فهرست کالا را از اکسل وارد می‌کنید و پشت پیشخوان شروع می‌کنید. چیزی نصب نمی‌شود.',
        'cta_primary' => 'رایگان شروع کنید',
        'cta_secondary' => 'دیدن تعرفه‌ها',
        'note' => 'پلن پایه رایگان · بدون کارت بانکی · خروجی اکسل از گزارش‌ها',

        'footer' => [
            'brand_label' => 'همیار',
            // The module list had already been read four times by the time a visitor
            // reaches the footer. This says the one thing the list does not: what a
            // record in همیار is.
            'about' => 'هر دستگاه در همیار پروندهٔ خودش را دارد: خرید، تعمیر، حواله و فروش، همه زیر یک شناسه. اقساط، چک، پیامک و گزارش سود هم در همان حساب است.',
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
