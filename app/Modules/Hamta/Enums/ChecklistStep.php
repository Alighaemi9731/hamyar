<?php

declare(strict_types=1);

namespace App\Modules\Hamta\Enums;

/**
 * The six steps of a HAMTA ownership transfer, as `docs/specs/hamta.md` lists them.
 *
 * ## They are a specification, not shop data
 *
 * A shop cannot add, remove or reorder one — which is why this is an enum rather than a
 * table. The steps describe what the registry requires; a shop that could edit them would
 * produce a record that proves nothing, because nobody reading it later would know which
 * version of the list was on screen at the time.
 *
 * ## Each one is answerable with "couldn't"
 *
 * `skipped` is a first-class answer (see the migration). A seller who is not the registered
 * owner, a customer who never forwards the SMS — these happen constantly, and a checklist
 * that only records success forces the salesperson to either lie or abandon the record. The
 * shop's protection in a dispute is the honest version: *we asked, and here is what happened*.
 */
enum ChecklistStep: string
{
    case OwnerConfirmed = 'owner_confirmed';

    case IdCaptured = 'id_captured';

    case TransferWalked = 'transfer_walked';

    case SmsAwaited = 'sms_awaited';

    case ActivationRecorded = 'activation_recorded';

    case BothAcknowledged = 'both_acknowledged';

    public function labelFa(): string
    {
        return match ($this) {
            self::OwnerConfirmed => 'تأیید شد که فروشنده، مالک ثبت‌شدهٔ دستگاه است',
            self::IdCaptured => 'مدرک شناسایی فروشنده ثبت شد',
            self::TransferWalked => 'مراحل انتقال با مشتری طی شد (#7777*، اپلیکیشن همتا یا hamta.ir)',
            self::SmsAwaited => 'پیامک تأیید همتا برای مشتری آمد',
            self::ActivationRecorded => 'شناسهٔ فعال‌سازی ثبت شد',
            self::BothAcknowledged => 'هر دو طرف انجام انتقال را تأیید کردند',
        };
    }

    /**
     * A sentence for the salesperson explaining what this step is actually for.
     *
     * The checklist is worked through with a customer standing at the counter by staff who
     * did not write the spec. A step nobody understands is a step everybody ticks.
     */
    public function hintFa(): string
    {
        return match ($this) {
            self::OwnerConfirmed => 'اگر فروشنده مالک ثبت‌شده نیست، انتقال انجام نمی‌شود — حتی اگر گوشی دستش باشد.',
            self::IdCaptured => 'تصویر کارت ملی یا شناسنامه. همین مدرک است که در اختلاف بعدی از فروشگاه دفاع می‌کند.',
            self::TransferWalked => 'کد #7777* را روی گوشی خودِ مشتری بگیرید؛ راهنمای کامل در صفحهٔ آموزش همتا هست.',
            self::SmsAwaited => 'پیامک از سامانهٔ همتا برای شمارهٔ مشتری می‌آید، نه برای فروشگاه.',
            self::ActivationRecorded => 'شناسه را از روی همان پیامک وارد کنید. ما آن را فقط ثبت می‌کنیم و صحتش را نمی‌سنجیم.',
            self::BothAcknowledged => 'اگر مشتری بدون تکمیل انتقال می‌رود، این را «انجام نشد» بزنید و دلیلش را بنویسید.',
        };
    }

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::OwnerConfirmed,
            self::IdCaptured,
            self::TransferWalked,
            self::SmsAwaited,
            self::ActivationRecorded,
            self::BothAcknowledged,
        ];
    }
}
