import { Head, Link } from '@inertiajs/react';
import { HomeIcon, LockIcon, RefreshCcwIcon, SearchXIcon, ServerCrashIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';

interface ErrorPageProps {
  status: number;
  /** The server's own sentence, when it wrote one worth showing. */
  message?: string | null;
}

/**
 * The four errors a shopkeeper can actually meet, in Persian and the right way round.
 *
 * ## Why this page exists at all
 *
 * `resources/views/errors/` never existed, so every error in this product rendered
 * Laravel's stock `<html lang="en">` page: left-to-right, unstyled, and — with
 * `APP_DEBUG=false` — without even the message. Two of those messages had been carefully
 * written in Persian by `ResolveTenant` («فروشگاهی با این نشانی پیدا نشد.» and «دسترسی به
 * این فروشگاه موقتاً غیرفعال است.») and **nobody had ever seen either of them**.
 *
 * 419 is the one that costs most. A session that expired while a shop was mid-invoice
 * produced an English page with no explanation and no way back; the operator's next move
 * was to retype the basket, or to call somebody.
 *
 * ## Why each status gets its own words
 *
 * "Something went wrong" is true of all four and useful for none. What a person needs is
 * which of the four happened to them — did I mistype, am I not allowed, has my session
 * gone, or is it us — because the next action is different every time.
 */
export default function ErrorPage({ status, message }: ErrorPageProps) {
  const { title, body, icon: Icon, action } = describe(status);

  return (
    <>
      <Head title={title} />

      <main className="flex min-h-svh items-center justify-center bg-canvas px-6 py-16">
        <div className="w-full max-w-md text-center">
          <div className="mx-auto mb-6 flex size-14 items-center justify-center rounded-full bg-muted">
            <Icon className="size-6 text-muted-foreground" aria-hidden />
          </div>

          <h1 className="text-xl font-bold text-foreground">{title}</h1>

          <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
            {/* The server's sentence wins when it wrote one: it knows which shop, which
                module and why, and the generic line below only knows the number. */}
            {message && message.trim() !== '' ? message : body}
          </p>

          <div className="mt-8 flex flex-wrap justify-center gap-3">{action}</div>

          <p className="mt-8 text-xs text-muted-foreground tabular">کد خطا: {toFa(status)}</p>
        </div>
      </main>
    </>
  );
}

function describe(status: number) {
  switch (status) {
    case 403:
      return {
        title: 'به این بخش دسترسی ندارید',
        body: 'حساب شما اجازهٔ دیدن این صفحه را ندارد. اگر باید داشته باشید، از مدیر فروشگاه بخواهید دسترسی‌تان را تغییر دهد.',
        icon: LockIcon,
        action: <BackHome />,
      };

    case 404:
      return {
        title: 'این صفحه پیدا نشد',
        body: 'نشانی را اشتباه تایپ کرده‌اید، یا چیزی که دنبالش بودید حذف شده است.',
        icon: SearchXIcon,
        action: <BackHome />,
      };

    case 419:
      return {
        title: 'نشست شما منقضی شد',
        body: 'برای امنیت حساب، بعد از مدتی بی‌کاری از سیستم خارج می‌شوید. یک بار دیگر وارد شوید و ادامه دهید — چیزی پاک نشده است.',
        icon: RefreshCcwIcon,
        // Reload rather than a link: 419 usually happens on a POST, and the page the
        // person was on is the page they want back.
        action: (
          <>
            <Button onClick={() => window.location.reload()}>تلاش دوباره</Button>
            <BackHome variant="ghost" />
          </>
        ),
      };

    default:
      return {
        title: 'مشکلی از سمت ما پیش آمد',
        body: 'این خطا ثبت شد و ما آن را می‌بینیم. چند لحظه بعد دوباره تلاش کنید؛ اگر ادامه داشت، با پشتیبانی تماس بگیرید.',
        icon: ServerCrashIcon,
        action: (
          <>
            <Button onClick={() => window.location.reload()}>تلاش دوباره</Button>
            <BackHome variant="ghost" />
          </>
        ),
      };
  }
}

function BackHome({ variant = 'default' }: { variant?: 'default' | 'ghost' }) {
  return (
    <Button asChild variant={variant === 'ghost' ? 'ghost' : 'default'}>
      <Link href="/dashboard">
        <HomeIcon className="size-4" aria-hidden />
        بازگشت به داشبورد
      </Link>
    </Button>
  );
}

function toFa(value: number): string {
  return String(value).replace(/\d/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)] ?? digit);
}
