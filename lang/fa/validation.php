<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| پیام‌های اعتبارسنجی
|--------------------------------------------------------------------------
|
| This file did not exist until 0.18.0, and its absence was a product-wide bug.
|
| `config('app.locale')` is `fa` and the fallback is `en`, and with no `lang/`
| directory at all, Laravel resolved every validation message from its own English
| file inside `vendor/`. So a shopkeeper who left a field blank read
| «The identifier field is required.» — left-to-right English, naming a database
| column, on a right-to-left Persian page. That is the single most common error
| interaction in any application, and it was in the wrong language everywhere.
|
| Twenty-one of the twenty-four FormRequests hid this by hand-writing Persian for the
| specific rules somebody remembered — 121 keys in all. Everything they did not think
| of, plus all 40 inline `$request->validate()` calls in controllers, fell through to
| English. This inverts that: Persian is the default, and a `messages()` entry is now a
| refinement for a rule that deserves a better sentence than the generic one, rather
| than the only thing standing between a shopkeeper and a foreign language.
|
| ## Two rules for writing entries here
|
| **Say what to do, not what is wrong with the data.** «تعداد اقساط را وارد کنید.»
| beats «فیلد تعداد اقساط الزامی است.» The person reading this is mid-task with a
| customer in front of them and needs the next action, not a diagnosis.
|
| **Never let a schema name reach the screen.** `:attribute` is replaced from the
| `attributes` array at the bottom; a field missing from it renders its own column
| name, which is how «فیلد owner_mobile الزامی است.» happens. When you add a validated
| field anywhere in the application, add its label there too.
|
*/

return [
    'regex' => ':attribute را در قالب درست وارد کنید.',
    'required' => ':attribute را وارد کنید.',
    'required_array_keys' => 'در :attribute این موارد را هم کامل کنید: :values.',
    'required_if' => 'وقتی :other برابر :value است، :attribute را هم وارد کنید.',
    'required_if_accepted' => 'وقتی :other را تأیید می‌کنید، :attribute را هم وارد کنید.',
    'required_if_declined' => 'وقتی :other را تأیید نمی‌کنید، :attribute را هم وارد کنید.',
    'required_unless' => 'اگر :other یکی از این موارد نیست، :attribute را وارد کنید: :values.',
    'required_without' => 'اگر :values را وارد نمی‌کنید، :attribute را وارد کنید.',
    'required_without_all' => 'دست‌کم یکی از :values یا خود :attribute را وارد کنید.',
    'required_with' => 'حالا که :values را وارد کرده‌اید، :attribute را هم وارد کنید.',
    'required_with_all' => 'حالا که همهٔ :values را وارد کرده‌اید، :attribute را هم وارد کنید.',
    'same' => ':attribute باید با :other یکسان باشد.',
    'size' => [
        'array' => ':attribute باید دقیقاً :size مورد باشد.',
        'file' => 'حجم :attribute باید دقیقاً :size کیلوبایت باشد.',
        'numeric' => ':attribute باید برابر :size باشد.',
        'string' => ':attribute باید دقیقاً :size حرف باشد.',
    ],
    'starts_with' => ':attribute باید با یکی از این‌ها شروع شود: :values.',
    'string' => ':attribute را به‌صورت متن وارد کنید.',
    'timezone' => ':attribute را از میان منطقه‌های زمانی معتبر انتخاب کنید.',
    'ulid' => 'شناسهٔ :attribute معتبر نیست؛ دوباره بررسی کنید.',
    'unique' => 'این :attribute قبلاً ثبت شده است؛ یکی دیگر وارد کنید.',
    'uploaded' => 'بارگذاری :attribute انجام نشد؛ دوباره تلاش کنید.',
    'uppercase' => ':attribute را با حروف بزرگ وارد کنید.',
    'url' => ':attribute را به‌صورت نشانی اینترنتی معتبر وارد کنید.',
    'uuid' => 'شناسهٔ :attribute معتبر نیست؛ دوباره بررسی کنید.',
    'missing' => ':attribute را در این فرم وارد نکنید.',
    'missing_if' => 'وقتی :other برابر :value است، :attribute را وارد نکنید.',
    'missing_unless' => ':attribute را وارد نکنید، مگر آنکه :other برابر :value باشد.',
    'missing_with' => 'وقتی :values را وارد کرده‌اید، :attribute را وارد نکنید.',
    'missing_with_all' => 'وقتی :values را وارد کرده‌اید، :attribute را وارد نکنید.',
    'multiple_of' => ':attribute باید مضربی از :value باشد.',
    'not_in' => 'این گزینه برای :attribute مجاز نیست؛ گزینهٔ دیگری انتخاب کنید.',
    'not_regex' => ':attribute شکل درستی ندارد؛ آن را اصلاح کنید.',
    'numeric' => ':attribute را به عدد وارد کنید.',
    'password' => [
        'letters' => ':attribute باید دست‌کم یک حرف داشته باشد.',
        'mixed' => ':attribute باید دست‌کم یک حرف بزرگ و یک حرف کوچک داشته باشد.',
        'numbers' => ':attribute باید دست‌کم یک رقم داشته باشد.',
        'symbols' => ':attribute باید دست‌کم یک نشانه مانند ! یا @ داشته باشد.',
        'uncompromised' => 'این :attribute پیش‌تر در نشت اطلاعات دیده شده است؛ :attribute دیگری انتخاب کنید.',
    ],
    'present' => ':attribute باید در فرم باشد، حتی اگر خالی بماند.',
    'present_if' => 'وقتی :other برابر :value است، :attribute باید در فرم باشد، حتی اگر خالی بماند.',
    'present_unless' => ':attribute باید در فرم باشد، مگر آنکه :other برابر :value باشد.',
    'present_with' => 'وقتی :values را وارد کرده‌اید، :attribute هم باید در فرم باشد، حتی اگر خالی بماند.',
    'present_with_all' => 'وقتی :values را وارد کرده‌اید، :attribute هم باید در فرم باشد، حتی اگر خالی بماند.',
    'prohibited' => ':attribute در این فرم جایی ندارد؛ آن را خالی بگذارید.',
    'prohibited_if' => 'وقتی :other برابر :value است، :attribute را خالی بگذارید.',
    'prohibited_if_accepted' => 'وقتی :other را تأیید کرده‌اید، :attribute را خالی بگذارید.',
    'prohibited_if_declined' => 'وقتی :other را رد کرده‌اید، :attribute را خالی بگذارید.',
    'prohibited_unless' => ':attribute را خالی بگذارید، مگر آنکه :other یکی از :values باشد.',
    'prohibits' => 'وقتی :attribute را وارد کرده‌اید، :other نباید پر شود؛ یکی از این دو را خالی بگذارید.',
    'doesnt_end_with' => ':attribute نباید با یکی از این‌ها تمام شود: :values.',
    'doesnt_start_with' => ':attribute نباید با یکی از این‌ها شروع شود: :values.',
    'email' => ':attribute را به شکل یک ایمیل درست وارد کنید؛ مثل shop@example.com.',
    'ends_with' => ':attribute باید با یکی از این‌ها تمام شود: :values.',
    'enum' => ':attribute انتخاب‌شده درست نیست؛ یکی از گزینه‌های فهرست را انتخاب کنید.',
    'exists' => ':attribute انتخاب‌شده پیدا نشد؛ از فهرست یکی را انتخاب کنید.',
    'extensions' => 'پسوند فایل :attribute باید یکی از این‌ها باشد: :values.',
    'file' => ':attribute را به صورت یک فایل بارگذاری کنید.',
    'filled' => ':attribute را خالی نگذارید.',
    'gt' => [
        'array' => ':attribute باید بیشتر از :value مورد داشته باشد.',
        'file' => 'حجم :attribute باید بیشتر از :value کیلوبایت باشد.',
        'numeric' => ':attribute باید بیشتر از :value باشد.',
        'string' => ':attribute باید بیشتر از :value حرف باشد.',
    ],
    'gte' => [
        'array' => ':attribute باید دست‌کم :value مورد داشته باشد.',
        'file' => 'حجم :attribute باید دست‌کم :value کیلوبایت باشد.',
        'numeric' => ':attribute باید :value یا بیشتر باشد.',
        'string' => ':attribute باید دست‌کم :value حرف باشد.',
    ],
    'hex_color' => 'کد رنگ :attribute درست نیست؛ مثل #1A2B3C وارد کنید.',
    'image' => 'برای :attribute یک عکس انتخاب کنید.',
    'in' => ':attribute انتخاب‌شده درست نیست؛ یکی از گزینه‌های فهرست را انتخاب کنید.',
    'in_array' => ':attribute باید یکی از موارد :other باشد.',
    'in_array_keys' => ':attribute باید دست‌کم یکی از این موارد را داشته باشد: :values.',
    'integer' => ':attribute را به صورت عدد درست و بدون اعشار وارد کنید.',
    'ip' => ':attribute را به صورت یک نشانی IP درست وارد کنید.',
    'ipv4' => ':attribute را به صورت یک نشانی IPv4 درست وارد کنید.',
    'ipv6' => ':attribute را به صورت یک نشانی IPv6 درست وارد کنید.',
    'json' => ':attribute باید یک متن JSON درست باشد.',
    'list' => ':attribute باید یک فهرست ساده باشد.',
    'lowercase' => ':attribute را با حروف کوچک وارد کنید.',
    'lt' => [
        'array' => ':attribute باید کمتر از :value مورد داشته باشد.',
        'file' => 'حجم :attribute باید کمتر از :value کیلوبایت باشد.',
        'numeric' => ':attribute باید کمتر از :value باشد.',
        'string' => ':attribute باید کمتر از :value حرف باشد.',
    ],
    'lte' => [
        'array' => ':attribute نباید بیشتر از :value مورد داشته باشد.',
        'file' => 'حجم :attribute نباید بیشتر از :value کیلوبایت باشد.',
        'numeric' => ':attribute نباید بیشتر از :value باشد.',
        'string' => ':attribute نباید بیشتر از :value حرف باشد.',
    ],
    'mac_address' => ':attribute را به صورت یک نشانی MAC درست وارد کنید.',
    'max' => [
        'array' => ':attribute نباید بیشتر از :max مورد داشته باشد.',
        'file' => 'حجم :attribute نباید بیشتر از :max کیلوبایت باشد.',
        'numeric' => ':attribute نباید بیشتر از :max باشد.',
        'string' => ':attribute نباید بیشتر از :max حرف باشد.',
    ],
    'max_digits' => ':attribute نباید بیشتر از :max رقم باشد.',
    'mimes' => ':attribute باید فایلی از این نوع‌ها باشد: :values.',
    'mimetypes' => ':attribute باید فایلی از این نوع‌ها باشد: :values.',
    'min' => [
        'array' => ':attribute باید دست‌کم :min مورد داشته باشد.',
        'file' => 'حجم :attribute باید دست‌کم :min کیلوبایت باشد.',
        'numeric' => ':attribute نباید کمتر از :min باشد.',
        'string' => ':attribute باید دست‌کم :min حرف باشد.',
    ],
    'min_digits' => ':attribute باید دست‌کم :min رقم باشد.',
    'accepted' => ':attribute را بپذیرید.',
    'accepted_if' => 'چون :other برابر :value است، :attribute را بپذیرید.',
    'active_url' => ':attribute را به‌صورت یک نشانی اینترنتی درست وارد کنید.',
    'after' => 'برای :attribute تاریخی بعد از :date انتخاب کنید.',
    'after_or_equal' => 'برای :attribute تاریخ :date یا بعد از آن را انتخاب کنید.',
    'alpha' => ':attribute را فقط با حروف بنویسید.',
    'alpha_dash' => ':attribute را فقط با حروف، رقم، خط تیره و زیرخط بنویسید.',
    'alpha_num' => ':attribute را فقط با حروف و رقم بنویسید.',
    'array' => ':attribute را از فهرست انتخاب کنید.',
    'ascii' => ':attribute را فقط با حروف و رقم انگلیسی بنویسید.',
    'before' => 'برای :attribute تاریخی پیش از :date انتخاب کنید.',
    'before_or_equal' => 'برای :attribute تاریخ :date یا پیش از آن را انتخاب کنید.',
    'between' => [
        'array' => 'برای :attribute بین :min تا :max مورد انتخاب کنید.',
        'file' => 'حجم :attribute باید بین :min تا :max کیلوبایت باشد.',
        'numeric' => ':attribute را عددی بین :min تا :max وارد کنید.',
        'string' => ':attribute باید بین :min تا :max حرف باشد.',
    ],
    'boolean' => 'برای :attribute فقط بله یا خیر را انتخاب کنید.',
    'can' => 'اجازهٔ انتخاب این :attribute را ندارید.',
    'confirmed' => ':attribute با تکرار آن یکی نیست؛ دوباره وارد کنید.',
    'contains' => 'در :attribute گزینه‌های لازم را انتخاب کنید.',
    'current_password' => 'رمز عبور فعلی درست نیست؛ دوباره وارد کنید.',
    'date' => ':attribute درست نیست؛ تاریخ را مثل ۱۴۰۵/۰۶/۱۵ وارد کنید.',
    'date_equals' => 'برای :attribute دقیقاً تاریخ :date را انتخاب کنید.',
    'date_format' => ':attribute را با قالب :format وارد کنید.',
    'decimal' => ':attribute را با :decimal رقم اعشار وارد کنید.',
    'declined' => 'برای ادامه، تیک :attribute را بردارید.',
    'declined_if' => 'چون :other برابر :value است، تیک :attribute را بردارید.',
    'different' => ':attribute را متفاوت از :other وارد کنید.',
    'digits' => ':attribute را با :digits رقم وارد کنید.',
    'digits_between' => ':attribute را با :min تا :max رقم وارد کنید.',
    'dimensions' => 'اندازهٔ این تصویر مناسب نیست؛ تصویر دیگری برای :attribute انتخاب کنید.',
    'distinct' => ':attribute تکراری است؛ هر مورد را فقط یک‌بار وارد کنید.',
    'any_of' => ':attribute با هیچ‌کدام از حالت‌های مجاز نمی‌خواند؛ آن را اصلاح کنید.',
    'doesnt_contain' => ':attribute نباید شامل این‌ها باشد: :values.',
    'encoding' => ':attribute باید با کدگذاری :encoding نوشته شود.',
    'ipv4' => ':attribute را به صورت یک نشانی IPv4 درست وارد کنید.',
    'ipv6' => ':attribute را به صورت یک نشانی IPv6 درست وارد کنید.',

    /*
    |--------------------------------------------------------------------------
    | پیام‌های سفارشی هر فیلد
    |--------------------------------------------------------------------------
    |
    | Deliberately empty. A rule that needs a better sentence than the generic one gets
    | it in that FormRequest's own `messages()`, next to the rule it belongs to — which
    | is where somebody changing the rule will actually see it. Collecting them here
    | instead would put the rule and its message in two files that drift apart.
    |
    */

    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | نام فیلدها
    |--------------------------------------------------------------------------
    |
    | What `:attribute` becomes. This is the half that decides whether a message reads
    | as Persian or as a leak of the database schema, so a missing entry is worse than a
    | clumsy one: the fallback is the raw column name, in English, mid-sentence.
    |
    | Nested keys need their own rows and must read naturally alone, because they are
    | substituted into the same sentences: 'lines.*.quantity' => 'تعداد این ردیف' gives
    | «تعداد این ردیف را وارد کنید.», while inheriting 'lines' would give «قلم‌های فاکتور
    | را وارد کنید.» for one row's quantity — which names the wrong thing entirely.
    |
    */

    'attributes' => [
        'party_id' => 'طرف حساب',
        'salesperson_id' => 'فروشنده',
        'action' => 'عملیات',
        'vat_applied' => 'مالیات بر ارزش افزوده',
        'discount_amount' => 'مبلغ تخفیف فاکتور',
        'shipping_amount' => 'هزینهٔ ارسال',
        'lines' => 'ردیف‌های فاکتور',
        'lines.*.unit_id' => 'دستگاه این ردیف',
        'lines.*.variant_id' => 'کالای این ردیف',
        'lines.*.quantity' => 'تعداد این ردیف',
        'lines.*.unit_price' => 'قیمت واحد این ردیف',
        'lines.*.discount_amount' => 'تخفیف این ردیف',
        'payments' => 'پرداخت‌ها',
        'payments.*.method' => 'روش پرداخت',
        'payments.*.amount' => 'مبلغ پرداخت',
        'payments.*.reference' => 'شمارهٔ پیگیری پرداخت',
        'reason' => 'علت',
        'amount' => 'مبلغ',
        'quantity' => 'تعداد',
        'unit' => 'واحد مبلغ',
        'allocation' => 'روش سرشکن‌کردن هزینه',
        'supplier_id' => 'تأمین‌کننده',
        'invoice_id' => 'فاکتور',
        'coupon' => 'کد تخفیف',
        'name' => 'نام',
        'subdomain' => 'نشانی فروشگاه',
        'owner_name' => 'نام مالک',
        'owner_mobile' => 'شمارهٔ موبایل مالک',
        'owner_email' => 'ایمیل مالک',
        'accept_terms' => 'پذیرش قوانین',
        'mobile' => 'شمارهٔ موبایل',
        'password' => 'رمز عبور',
        'identifier' => 'شمارهٔ موبایل',
        'email' => 'ایمیل',
        'code' => 'کد',
        'recovery_code' => 'کد بازیابی',
        'role' => 'نقش',
        'roles' => 'نقش‌ها',
        'user' => 'کاربر',
        'user_id' => 'کاربر',
        'users' => 'کاربران',
        'actor' => 'کاربر',
        'plan' => 'پلن',
        'subscription_id' => 'اشتراک',
        'metric' => 'سهمیه',
        'module' => 'ماژول',
        'expires_at' => 'تاریخ انقضا',
        'return_to' => 'صفحهٔ بازگشت',
        // کالا، دسته‌بندی و برند
        'sku' => 'کد کالا',
        'barcode' => 'بارکد',
        'description' => 'توضیحات',
        'category_id' => 'دسته‌بندی',
        'brand_id' => 'برند',
        'parent_id' => 'دستهٔ والد',
        'position' => 'ترتیب نمایش',
        'low_stock_threshold' => 'حد هشدار موجودی',
        'is_active' => 'فعال بودن',

        // تنوع کالا (ماتریس ویژگی‌ها)
        'axes' => 'ویژگی‌های کالا',
        'axes.*.name' => 'نام ویژگی',
        'axes.*.values' => 'مقدارهای ویژگی',
        'axes.*.values.*' => 'مقدار ویژگی',
        'variant_id' => 'کالا',
        'product_variant_id' => 'کالا',

        // قیمت‌ها
        'price_level_id' => 'سطح قیمت',
        'value' => 'مقدار تغییر',
        'mode' => 'نوع تغییر قیمت',
        'effective_from' => 'تاریخ اعمال قیمت',
        'rows' => 'ردیف‌های قیمت',
        'rows.*.variant_id' => 'کالای ردیف',
        'rows.*.name' => 'نام کالای ردیف',
        'rows.*.from' => 'قیمت فعلی',
        'rows.*.to' => 'قیمت جدید',

        // شعبه و انبار
        'branch_id' => 'شعبه',
        'phone' => 'شمارهٔ تماس',
        'is_default' => 'شعبهٔ پیش‌فرض',
        'warehouse_id' => 'انبار',
        'from_warehouse_id' => 'انبار مبدأ',
        'to_warehouse_id' => 'انبار مقصد',

        // موجودی، حواله و انبارگردانی
        'counted' => 'تعداد شمارش‌شده',
        'counted.*' => 'تعداد شمارش‌شده',
        'is_blind' => 'انبارگردانی کور',
        'notes' => 'یادداشت',

        // دستگاه‌های سریال‌دار
        'unit_id' => 'دستگاه',
        'product_unit_id' => 'دستگاه',
        'imei1' => 'کد IMEI اول',
        'imei2' => 'کد IMEI دوم',
        'imeis' => 'کدهای IMEI',
        'serial' => 'شمارهٔ سریال',
        'condition' => 'وضعیت دستگاه',
        'grade' => 'رتبهٔ ظاهری دستگاه',
        'cost' => 'بهای خرید',
        // ---- Repairs: پذیرش، تعمیر، تحویل ----
        'device_brand' => 'برند دستگاه',
        'device_model' => 'مدل دستگاه',
        'device_colour' => 'رنگ دستگاه',
        'device_imei' => 'کد IMEI دستگاه',
        'device_passcode' => 'رمز دستگاه',
        'reported_issue' => 'ایرادی که مشتری گفته',
        'issue' => 'ایراد دستگاه',
        'estimate' => 'برآورد هزینه',
        'estimate_amount' => 'مبلغ برآورد',
        'quoted_amount' => 'مبلغ اعلام‌شده به مشتری',
        'prepaid_amount' => 'بیعانه',
        'promised_at' => 'زمان وعدهٔ تحویل',
        'technician_id' => 'تعمیرکار',
        'assignee_id' => 'مسئول',
        'priority' => 'اولویت',
        'accessories' => 'لوازم همراه دستگاه',
        'accessories.*' => 'لوازم همراه دستگاه',
        'checklist' => 'چک‌لیست پذیرش',
        'checklist.*.item_key' => 'بند چک‌لیست',
        'checklist.*.label' => 'عنوان بند چک‌لیست',
        'checklist.*.answer' => 'پاسخ بند چک‌لیست',
        'checklist.*.note' => 'توضیح بند چک‌لیست',
        'answers' => 'پاسخ‌های چک‌لیست',
        'answers.*.answer' => 'پاسخ',
        'answers.*.note' => 'توضیح',
        'labour' => 'اجرت‌ها',
        'labour.*.description' => 'شرح اجرت',
        'labour.*.amount' => 'مبلغ اجرت',
        'labour.*.quantity' => 'تعداد اجرت',
        'warranty_days' => 'مدت گارانتی به روز',
        'signature' => 'امضای مشتری',
        'reopen' => 'بازکردن دوبارهٔ قبض',
        'recovered' => 'دستگاه تحویل گرفته شد',
        'undo' => 'برگرداندن',

        // ---- CRM: طرف حساب و پیگیری ----
        'contacts' => 'راه‌های تماس',
        'contacts.*.type' => 'نوع راه تماس',
        'contacts.*.value' => 'شمارهٔ تماس',
        'contacts.*.label' => 'برچسب راه تماس',
        'national_id' => 'کد ملی',
        'economic_code' => 'کد اقتصادی',
        'company_name' => 'نام شرکت',
        'display_name' => 'نام نمایشی',
        'birthday' => 'تاریخ تولد',
        'address' => 'نشانی',
        'whatsapp' => 'واتساپ',
        'about' => 'دربارهٔ طرف حساب',
        'tags' => 'برچسب‌ها',
        'kind' => 'نوع طرف حساب',
        'credit_limit' => 'سقف اعتبار',
        'opening_balance' => 'مانده اول دوره',
        'points' => 'امتیاز',
        'due_at' => 'موعد پیگیری',
        'subject' => 'موضوع',
        'note' => 'یادداشت',
        'body' => 'متن',
        'title' => 'عنوان',

        // ---- Sales: فاکتور، پرداخت، معاوضه، مرجوعی ----
        'method' => 'روش پرداخت',
        'payments.*.account_id' => 'صندوق یا حساب پرداخت',
        'payments.*.tendered_amount' => 'مبلغ دریافتی از مشتری',
        'lines.*.description' => 'شرح قلم کالا',
        'lines.*.warranty_months' => 'مدت گارانتی قلم کالا به ماه',
        'lines.*.item_id' => 'قلم فاکتور',
        'lines.*.refund_amount' => 'مبلغ بازگشتی قلم',
        'lines.*.regrade' => 'تغییر درجهٔ کالای برگشتی',
        'lines.*.restock' => 'بازگشت به انبار',
        'lines.*.unit_cost' => 'بهای تمام‌شدهٔ قلم',
        'unit_price' => 'قیمت واحد',
        'trade_in' => 'معاوضه',
        'trade_in.device_name' => 'نام دستگاه معاوضه',
        'trade_in.imei1' => 'کد IMEI دستگاه معاوضه',
        'trade_in.grade' => 'درجهٔ دستگاه معاوضه',
        'trade_in.agreed_price' => 'قیمت توافقی معاوضه',
        'trade_in.product_variant_id' => 'کالای معاوضه',
        'trade_in.hamta_ack' => 'تأیید انتقال مالکیت همتا',
        'price' => 'قیمت',
        'number' => 'شماره',
        'status' => 'وضعیت',
        'type' => 'نوع',
        'label' => 'برچسب',
        'reference' => 'شمارهٔ پیگیری',

        // ---- Treasury: صندوق، انتقال، هزینه ----
        'account_id' => 'صندوق یا حساب',
        'from_account_id' => 'حساب مبدأ',
        'to_account_id' => 'حساب مقصد',
        'fee' => 'کارمزد',
        'occurred_on' => 'تاریخ',
        'entry_ids' => 'ردیف‌های انتخاب‌شده',
        'entry_ids.*' => 'ردیف انتخاب‌شده',
        'record' => 'ثبت',

        // ---- Installments: قسط ----
        'first_due' => 'سررسید اولین قسط',
        'principal' => 'مبلغ اصل',
        'profit_percent' => 'درصد سود',
        'interval_months' => 'فاصلهٔ اقساط به ماه',
        'count' => 'تعداد اقساط',
        'guarantor_party_id' => 'ضامن',
        'due_date' => 'تاریخ سررسید',

        // ---- Import: ورود گروهی ----
        'file' => 'فایل',
        'mapping' => 'تطبیق ستون‌ها',
        'mapping.*' => 'ستون',
        'skip_rejected' => 'رد کردن سطرهای نامعتبر',

        // ---- Identity, Platform, Storefront, Messaging ----
        'token' => 'کد',
        'remember' => 'مرا به خاطر بسپار',
        'roles.*' => 'نقش',
        'user_ids' => 'کاربران',
        'user_ids.*' => 'کاربر',
        'is_enabled' => 'فعال',
        'hostname' => 'نشانی دامنه',
        'slug' => 'نشانی یکتا',
        'activation_id' => 'کد فعال‌سازی',
        'template_id' => 'الگوی پیامک',
        'tracking_code' => 'کد رهگیری',
        'customer_name' => 'نام مشتری',
        'working_hours' => 'ساعت کاری',
        'shows_out_of_stock' => 'نمایش کالای ناموجود',
        'currency_display' => 'واحد نمایش مبلغ',
        'photos' => 'عکس‌ها',
        'photos.*' => 'عکس',
        'days' => 'تعداد روز',
        'report_key' => 'گزارش',
        'filters' => 'فیلترها',
        'filters.*' => 'فیلتر',

        // ---- Listing and filtering, shared by every index screen ----
        'q' => 'عبارت جست‌وجو',
        'search' => 'عبارت جست‌وجو',
        'from' => 'از تاریخ',
        'to' => 'تا تاریخ',
        'period' => 'دوره',
        'sort' => 'ترتیب',
        'direction' => 'جهت ترتیب',
        'per_page' => 'تعداد در هر صفحه',
        'page' => 'صفحه',
    ],
];
