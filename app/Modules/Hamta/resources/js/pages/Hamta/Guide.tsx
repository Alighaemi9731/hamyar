import { Head, Link } from '@inertiajs/react';

import { AppShell } from '@/layouts/app-shell';

import { ApiNotice } from './Pending';

/**
 * The page a shop assistant reads before explaining همتا to a customer.
 *
 * Written for that person, not for a developer and not for a lawyer: what the code does,
 * what the SMS looks like, what happens if it is skipped, how long it takes. The spec asks
 * for exactly this, and it is the cheapest thing in the module — the alternative is every
 * shop inventing its own explanation at the counter.
 */
export default function HamtaGuide() {
  return (
    <AppShell title="راهنمای همتا">
      <Head title="راهنمای همتا" />

      <div className="max-w-3xl space-y-8">
        <header>
          <h1 className="text-2xl font-bold">همتا چیست و چرا مهم است؟</h1>
          <p className="mt-2 text-muted-foreground text-pretty">
            سامانهٔ همتا، رجیستری ملی گوشی‌های موبایل است. هر گوشی به نام یک نفر ثبت شده و
            وقتی دستگاه دست‌دوم جابه‌جا می‌شود، مالکیتش هم باید منتقل شود.
          </p>
        </header>

        <ApiNotice />

        <Section title="اگر انتقال انجام نشود چه می‌شود؟">
          <p>
            گوشی برای مدتی عادی کار می‌کند، اما در نهایت ممکن است روی شبکه محدود شود. آن
            موقع مشتری به فروشگاه شما برمی‌گردد — نه به فروشندهٔ قبلی. به همین دلیل ثبت
            انتقال، بیش از آنکه یک الزام اداری باشد، محافظ اعتبار فروشگاه است.
          </p>
          <p>
            نکتهٔ مهم: تا وقتی گوشی به نام فروشندهٔ قبلی است، از نظر سامانه آن شخص مالک
            است. برای همین قدم اول چک‌لیست، تأیید همین موضوع است.
          </p>
        </Section>

        <Section title="روش‌های انتقال">
          <ul className="list-inside list-disc space-y-2">
            <li>
              <strong>کد دستوری:</strong>{' '}
              <span className="font-mono" dir="ltr">
                *7777#
              </span>{' '}
              را روی گوشیِ خودِ مشتری بگیرید و منوی انتقال مالکیت را دنبال کنید.
            </li>
            <li>
              <strong>اپلیکیشن همتا</strong> روی گوشی مشتری.
            </li>
            <li>
              <strong>سایت</strong>{' '}
              <span dir="ltr">hamta.ntsw.ir</span>.
            </li>
          </ul>
          <p className="text-sm text-muted-foreground">
            هر سه روش یک کار را انجام می‌دهند. کد دستوری معمولاً سریع‌ترین است چون به
            اینترنت نیاز ندارد.
          </p>
        </Section>

        <Section title="پیامک تأیید">
          <p>
            بعد از ثبت درخواست، پیامکی از سامانهٔ همتا <strong>برای مشتری</strong> ارسال
            می‌شود — نه برای فروشگاه. داخل این پیامک یک <strong>شناسهٔ فعال‌سازی</strong>
            هست که باید در چک‌لیست ثبتش کنید.
          </p>
          <p>
            معمولاً چند دقیقه طول می‌کشد. اگر شلوغ باشد ممکن است تا چند ساعت هم برسد؛ در
            این حالت می‌توانید انتقال را «انجام شد» ثبت کنید و شناسه را بعداً وارد کنید.
          </p>
        </Section>

        <Section title="اگر مشتری نماند یا انتقال ممکن نشد">
          <p>
            فروش را متوقف نکنید. در چک‌لیست، همان مرحله را{' '}
            <strong>«انجام نشد»</strong> بزنید و دلیلش را بنویسید. دستگاه در فهرست{' '}
            <Link href="/hamta" className="text-primary hover:underline">
              انتقال‌های معوق
            </Link>{' '}
            می‌ماند تا بعداً پیگیری شود.
          </p>
          <p className="text-sm text-muted-foreground">
            همین یادداشت است که اگر ماه‌ها بعد اختلافی پیش بیاید، نشان می‌دهد فروشگاه کارش
            را انجام داده و چه چیزی مانع شده.
          </p>
        </Section>
      </div>
    </AppShell>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="space-y-3">
      <h2 className="text-lg font-semibold">{title}</h2>
      <div className="space-y-3 text-pretty leading-relaxed">{children}</div>
    </section>
  );
}
