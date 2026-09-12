<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Requests;

use App\Support\Settings\ShopSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saving «چاپ و هویت فروشگاه».
 *
 * ## `show_qr` is `required`, and that is the whole point of this class
 *
 * The reader defaults it ON — `$showQr !== false` — so that a shop which has never opened
 * this screen keeps the QR on its receipts. A rule of `['boolean']` alone would accept a
 * request that simply omitted the key, `$request->boolean('show_qr')` would answer
 * `false`, and the shop's QR would be switched off by a request that never mentioned it.
 * `required` turns that into a refusal the operator can see instead of a silent change to
 * what a customer receives.
 *
 * ## The logo is a URL, and the lengths are small on purpose
 *
 * Both of these ride along on every invoice's `settings_snapshot` — the reprint of a
 * year-old invoice has to carry the terms that were in force on the day — so a field that
 * is generous here is generous once per invoice, for ever.
 */
final class PrintSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', ShopSettings::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
            | An absolute http(s) address, because that is what the print layout puts in
            | an `<img src>`. Not a file upload: `PrintSettings` documents why the field is
            | a URL — the layout must not depend on the Files module being wired up — and
            | changing that is a different piece of work with an uploader in it.
            */
            'logo_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'footer_terms' => ['nullable', 'string', 'max:1000'],
            'show_qr' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo_url.url' => 'نشانی لوگو باید یک آدرس کامل باشد که با https:// یا http:// شروع شود.',
            'logo_url.max' => 'نشانی لوگو نباید بیش از ۵۰۰ نویسه باشد.',
            'footer_terms.max' => 'متن پایین فاکتور نباید بیش از ۱۰۰۰ نویسه باشد.',
            'show_qr.required' => 'وضعیت کد QR ارسال نشد. صفحه را دوباره باز کنید و ذخیره را بزنید.',
        ];
    }
}
