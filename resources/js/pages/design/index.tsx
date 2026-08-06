import { Head } from '@inertiajs/react';
import { PlusIcon, SearchIcon, SmartphoneIcon, WrenchIcon } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { EmptyState } from '@/components/domain/empty-state';
import { JDatePicker } from '@/components/domain/jdate-picker';
import { Money } from '@/components/domain/money';
import { Num } from '@/components/domain/num';
import { STATUS_MAP, StatusBadge } from '@/components/domain/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { AppShell } from '@/layouts/app-shell';

/**
 * /design — the component gallery (dev-only route).
 *
 * The workflow rule from the mobishop-ui skill: a component appears HERE, with its
 * full state matrix, before it is used in a feature page. That makes visual review a
 * single page instead of a hunt through the app, and it is where you check RTL, dark
 * mode and the 390px/1280px breakpoints in one pass.
 *
 * When you add a component, add its states here too — default, hover, focus,
 * disabled, loading, error, and empty where the component has one.
 */
export default function DesignGallery() {
  return (
    <AppShell title="گالری دیزاین‌سیستم">
      <Head title="گالری دیزاین‌سیستم" />

      <p className="mb-14 max-w-2xl text-sm leading-relaxed text-muted-foreground">
        هر کامپوننت پیش از استفاده در صفحات محصول، اینجا با همه حالت‌هایش ثبت می‌شود.
        این صفحه فقط در محیط توسعه در دسترس است. برای بررسی: یک‌بار در حالت روشن و
        یک‌بار تیره، در عرض ۳۹۰ و ۱۲۸۰ پیکسل.
      </p>

      <div className="space-y-6">
        <TokensSection />
        <MoneySection alt />
        <NumSection />
        <DateSection alt />
        <StatusSection />
        <ButtonSection alt />
        <FormSection />
        <OverlaySection alt />
        <TableSection />
        <StateSection alt />
      </div>
    </AppShell>
  );
}

/* -------------------------------------------------------------------------- */

/**
 * Sections alternate ground rather than relying on borders — that alternation is the
 * primary separator in this visual language (ADR 0008). `alt` flips a section onto
 * `surface-muted`; the gallery alternates them so both grounds get reviewed.
 */
function Section({
  title,
  note,
  alt = false,
  children,
}: {
  title: string;
  note?: string;
  alt?: boolean;
  children: React.ReactNode;
}) {
  return (
    <section
      className={cn(
        'rounded-card border border-border px-6 py-10 sm:px-10 sm:py-14',
        alt ? 'bg-surface-muted' : 'bg-surface'
      )}
    >
      {/* <bdi> isolates the heading from the surrounding RTL paragraph direction.
          Without it, a Latin title like "<Money/>" has its angle brackets reordered by
          the bidi algorithm and displays as "</Money>". `dir="auto"` on <bdi> picks the
          direction from the first strong character, so Persian titles stay RTL. */}
      <h2 className="mb-2 text-xl font-bold">
        <bdi>{title}</bdi>
      </h2>
      {note && <p className="mb-10 max-w-2xl text-sm leading-relaxed text-muted-foreground">{note}</p>}
      <div className="space-y-8">{children}</div>
    </section>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="grid gap-3 sm:grid-cols-[11rem_1fr] sm:items-start">
      <span className="pt-2 text-xs text-muted-foreground">{label}</span>
      <div className="flex flex-wrap items-center gap-3">{children}</div>
    </div>
  );
}

/* -------------------------------------------------------------------------- */

function TokensSection({ alt }: { alt?: boolean }) {
  const grounds = [
    { name: 'canvas', value: '#FFFFFF', className: 'bg-canvas text-ink' },
    { name: 'canvas-alt', value: '#F5F5F7', className: 'bg-canvas-alt text-ink' },
    { name: 'canvas-invert', value: '#000000', className: 'bg-canvas-invert text-white' },
    { name: 'ink', value: '#1D1D1F', className: 'bg-ink text-white' },
    { name: 'ink-soft', value: '#6E6E73', className: 'bg-ink-soft text-white' },
  ];

  const semantics = [
    { name: 'brand', value: '#0066CC', className: 'bg-brand text-white' },
    { name: 'success', value: '#0F7B3F', className: 'bg-success text-white' },
    { name: 'warning', value: '#8A5A00', className: 'bg-warning text-white' },
    { name: 'danger', value: '#B3261E', className: 'bg-danger text-white' },
    { name: 'label', value: '#FFD84D', className: 'bg-label text-ink' },
  ];

  return (
    <Section
      alt={alt}
      title="توکن‌ها"
      note="رنگ فقط معنا را حمل می‌کند، نه تزئین را: آبی یعنی «می‌شود روی این کاری کرد»، و سه رنگ سمانتیک یعنی وضعیت مالی/کاری. بقیه رابط خنثی است. هیچ کد رنگ خامی در صفحات نوشته نمی‌شود."
    >
      <Row label="زمینه و متن">
        <div className="flex flex-wrap gap-2">
          {grounds.map((s) => (
            <div
              key={s.name}
              className={`flex h-20 w-28 flex-col justify-end rounded-control border border-border p-2.5 text-2xs ${s.className}`}
            >
              <span className="font-medium">{s.name}</span>
              <span className="ltr-value opacity-70" dir="ltr">
                {s.value}
              </span>
            </div>
          ))}
        </div>
      </Row>

      <Row label="یک آبی + سمانتیک">
        <div className="flex flex-wrap gap-2">
          {semantics.map((s) => (
            <div
              key={s.name}
              className={`flex h-20 w-28 flex-col justify-end rounded-control border border-border p-2.5 text-2xs ${s.className}`}
            >
              <span className="font-medium">{s.name}</span>
              <span className="ltr-value opacity-70" dir="ltr">
                {s.value}
              </span>
            </div>
          ))}
        </div>
      </Row>

      <Row label="تایپوگرافی">
        <div className="space-y-3">
          <p className="font-display text-3xl font-extrabold">استعداد ۸۰۰ — تیتر</p>
          <p className="font-display text-xl font-bold">استعداد ۷۰۰ — تیتر بخش</p>
          <p className="max-w-xl text-base leading-relaxed">
            وزیرمتن ۴۰۰ در اندازه ۱۷ پیکسل با ارتفاع خط ۱٫۶۵ — فارسی به فاصله سطر
            بیشتری از لاتین نیاز دارد و این قاعده دست‌نخورده مانده است.
          </p>
          <p className="tabular text-sm" dir="ltr">
            1,250,000 — tabular figures line up in a column
          </p>
        </div>
      </Row>

      <Row label="شکل و عمق">
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex h-16 w-28 items-center justify-center rounded-pill border border-border bg-surface text-2xs">
            pill
          </div>
          <div className="flex h-16 w-28 items-center justify-center rounded-card border border-border bg-surface text-2xs shadow-low">
            card 18px
          </div>
          <div className="flex h-16 w-28 items-center justify-center rounded-control border border-border bg-surface text-2xs shadow-high">
            control 12px
          </div>
        </div>
      </Row>

      <Row label="کروم شیشه‌ای">
        <div className="relative h-24 w-full max-w-md overflow-hidden rounded-card">
          {/* Something busy behind the panel, so the blur and saturate are visible.
              A flat fill would make the frosted effect impossible to review. */}
          <div className="absolute inset-0 bg-brand" />
          <div className="absolute inset-y-0 start-1/3 w-24 bg-warning" />
          <div className="absolute inset-y-0 end-8 w-16 bg-success" />
          <div className="glass absolute inset-x-0 top-0 flex h-12 items-center border-b px-4 text-2xs">
            .glass — نوار چسبان مات‌شیشه‌ای
          </div>
        </div>
      </Row>

      <Row label="حرکت">
        <div className="flex flex-wrap gap-3">
          {['reveal', 'delay-1', 'delay-2'].map((label, i) => (
            <div
              key={label}
              className={cn(
                'reveal flex h-16 w-28 items-center justify-center rounded-card border border-border bg-surface text-2xs',
                i === 1 && 'reveal-delay-1',
                i === 2 && 'reveal-delay-2'
              )}
            >
              {label}
            </div>
          ))}
          <span className="self-center text-2xs text-muted-foreground">
            تنها واژگان حرکتی: محو + بالا آمدن. با prefers-reduced-motion غیرفعال می‌شود.
          </span>
        </div>
      </Row>
    </Section>
  );
}

function MoneySection({ alt }: { alt?: boolean }) {
  return (
    <Section
      alt={alt}
      title="<Money/>"
      note="تنها راه نمایش پول. ورودی همیشه عدد صحیح ریال است؛ واحد نمایش (ریال/تومان) و شکل ارقام از تنظیمات فروشگاه می‌آید."
    >
      <Row label="پیش‌فرض فروشگاه">
        <Money rial={125_000_000} withUnit />
        <Money rial={0} withUnit />
        <Money rial={-4_500_000} withUnit signed />
      </Row>
      <Row label="ریال، ارقام لاتین">
        <Money rial={125_000_000} unit="rial" digits="latin" withUnit />
      </Row>
      <Row label="تومان، ارقام فارسی">
        <Money rial={125_000_000} unit="toman" digits="fa" withUnit />
      </Row>
      <Row label="ستون جدول (تراز)">
        <div className="w-40 space-y-0.5 text-end">
          <div><Money rial={9_500_000} digits="latin" /></div>
          <div><Money rial={125_000_000} digits="latin" /></div>
          <div><Money rial={1_250_000_000} digits="latin" /></div>
        </div>
      </Row>
    </Section>
  );
}

function NumSection({ alt }: { alt?: boolean }) {
  return (
    <Section
      alt
      title="<Num/>"
      note="سه حالت عمدی: متن (ارقام فارسی)، جدول (لاتینِ جدولی)، و شناسه‌های ذاتاً LTR مثل IMEI که هرگز فارسی نمی‌شوند — چون باید تلفنی خوانده و در همتا وارد شوند."
    >
      <Row label="prose">
        <span className="text-xs">
          <Num value={3} /> دستگاه در انتظار قطعه
        </span>
      </Row>
      <Row label="table">
        <Num value={1250} variant="table" />
        <Num value={98} variant="table" />
      </Row>
      <Row label="ltr (IMEI)">
        <Num value="356938035643809" variant="ltr" />
      </Row>
      <Row label="ltr (موبایل)">
        <Num value="09121234567" variant="ltr" />
      </Row>
    </Section>
  );
}

function DateSection({ alt }: { alt?: boolean }) {
  const [value, setValue] = useState<string | null>(new Date().toISOString());
  const [empty, setEmpty] = useState<string | null>(null);

  return (
    <Section
      alt={alt}
      title="<JDatePicker/>"
      note="ورودی و خروجی همیشه UTC است؛ جلالی فقط نمایش است. هفته از شنبه شروع می‌شود و جمعه رنگ متفاوت دارد."
    >
      <Row label="مقدار دارد">
        <div className="w-56">
          <JDatePicker value={value} onChange={setValue} />
        </div>
        <code className="ltr-value text-2xs text-muted-foreground" dir="ltr">
          {value ?? 'null'}
        </code>
      </Row>
      <Row label="خالی">
        <div className="w-56">
          <JDatePicker value={empty} onChange={setEmpty} placeholder="تاریخ سررسید" />
        </div>
      </Row>
      <Row label="خطا">
        <div className="w-56">
          <JDatePicker value={null} onChange={() => {}} invalid />
        </div>
        <span className="text-2xs text-destructive">تاریخ سررسید الزامی است.</span>
      </Row>
      <Row label="غیرفعال">
        <div className="w-56">
          <JDatePicker value={value} onChange={setValue} disabled />
        </div>
      </Row>
    </Section>
  );
}

function StatusSection({ alt }: { alt?: boolean }) {
  const groups: Array<{ label: string; keys: string[] }> = [
    { label: 'فاکتور', keys: ['draft', 'final', 'void', 'paid', 'partially_paid', 'unpaid'] },
    { label: 'واحد سریالی', keys: ['in_stock', 'reserved', 'sold', 'in_repair', 'returned', 'written_off'] },
    {
      label: 'تعمیر',
      keys: ['queued', 'diagnosing', 'awaiting_approval', 'awaiting_parts', 'repairing', 'ready', 'delivered', 'rejected', 'abandoned'],
    },
    { label: 'چک', keys: ['in_hand', 'deposited', 'cleared', 'bounced', 'spent_to_third_party'] },
    { label: 'اقساط', keys: ['due_soon', 'overdue', 'settled'] },
    { label: 'همتا', keys: ['transfer_pending', 'transfer_done'] },
  ];

  return (
    <Section
      alt
      title="<StatusBadge/>"
      note={`نگاشت وضعیت→رنگ فقط یک‌جا تعریف می‌شود (${Object.keys(STATUS_MAP).length} وضعیت). رنگ‌دهی دستی در صفحه = باگ.`}
    >
      {groups.map((group) => (
        <Row key={group.label} label={group.label}>
          {group.keys.map((key) => (
            <StatusBadge key={key} status={key} />
          ))}
        </Row>
      ))}
      <Row label="وضعیت ثبت‌نشده">
        <StatusBadge status="some_unregistered_key" />
        <span className="text-2xs text-muted-foreground">
          کلید خام نمایش داده می‌شود تا معلوم باشد چه چیزی باید به STATUS_MAP اضافه شود.
        </span>
      </Row>
    </Section>
  );
}

function ButtonSection({ alt }: { alt?: boolean }) {
  return (
    <Section alt={alt} title="Button" note="در هر صفحه فقط یک دکمه اصلی (brand) وجود دارد.">
      <Row label="variant">
        <Button>ثبت فاکتور</Button>
        <Button variant="secondary">ذخیره پیش‌نویس</Button>
        <Button variant="outline">انصراف</Button>
        <Button variant="ghost">بیشتر</Button>
        <Button variant="destructive">ابطال</Button>
        <Button variant="link">راهنما</Button>
      </Row>
      <Row label="size">
        <Button size="sm">کوچک</Button>
        <Button>معمولی</Button>
        <Button size="lg">بزرگ</Button>
        <Button size="icon" aria-label="افزودن">
          <PlusIcon className="size-4" />
        </Button>
      </Row>
      <Row label="با آیکون">
        <Button>
          <PlusIcon className="size-4" />
          افزودن گوشی
        </Button>
        <Button variant="outline">
          <SearchIcon className="size-4" />
          جستجو
        </Button>
      </Row>
      <Row label="disabled">
        <Button disabled>ثبت فاکتور</Button>
        <Button variant="outline" disabled>
          انصراف
        </Button>
      </Row>
      <Row label="loading">
        <Button disabled>
          <span className="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent" />
          در حال ثبت…
        </Button>
      </Row>
    </Section>
  );
}

function FormSection({ alt }: { alt?: boolean }) {
  return (
    <Section
      alt={alt}
      title="فرم‌ها"
      note="لیبل بالای فیلد، خطا زیر فیلد با متن قابل‌اقدام. مقادیر ذاتاً LTR (IMEI/موبایل/مبلغ لاتین) با dir=ltr داخلی."
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="g-name">نام مشتری</Label>
          <Input id="g-name" placeholder="مثلاً رضا محمدی" />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="g-imei">IMEI</Label>
          <Input
            id="g-imei"
            dir="ltr"
            inputMode="numeric"
            placeholder="356938035643809"
            className="ltr-value tabular"
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="g-error">مبلغ (تومان)</Label>
          <Input
            id="g-error"
            dir="ltr"
            aria-invalid
            defaultValue="12,500"
            className="ltr-value tabular border-destructive"
          />
          <p className="text-2xs text-destructive">مبلغ نمی‌تواند از مانده فاکتور بیشتر باشد.</p>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="g-select">سطح قیمت</Label>
          <Select>
            <SelectTrigger id="g-select">
              <SelectValue placeholder="انتخاب کنید" />
            </SelectTrigger>
            {/* Portal: dir must be passed explicitly (design-system rule 2). */}
            <SelectContent dir="rtl">
              <SelectItem value="consumer">مصرف‌کننده</SelectItem>
              <SelectItem value="reseller">همکار</SelectItem>
              <SelectItem value="vip">همکار ویژه</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-1.5 sm:col-span-2">
          <Label htmlFor="g-note">توضیحات</Label>
          <Textarea id="g-note" rows={3} placeholder="ایراد اظهاری مشتری…" />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="g-disabled">غیرفعال</Label>
          <Input id="g-disabled" disabled defaultValue="قابل ویرایش نیست" />
        </div>
      </div>
    </Section>
  );
}

function OverlaySection({ alt }: { alt?: boolean }) {
  return (
    <Section
      alt
      title="لایه‌های شناور"
      note="همه Portalها dir=rtl صریح می‌گیرند، وگرنه انیمیشن و ترازشان برعکس می‌شود."
    >
      <Row label="Dialog">
        <Dialog>
          <DialogTrigger asChild>
            <Button variant="outline">ابطال فاکتور</Button>
          </DialogTrigger>
          <DialogContent dir="rtl">
            <DialogHeader>
              <DialogTitle>ابطال فاکتور ۱۴۰۵-۰۰۱۲؟</DialogTitle>
              <DialogDescription>
                موجودی گوشی‌ها به انبار برمی‌گردد و اسناد مالی معکوس می‌شود. این کار
                قابل بازگشت نیست.
              </DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <Button variant="outline">انصراف</Button>
              <Button variant="destructive">ابطال کن</Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </Row>

      <Row label="Sheet">
        <Sheet>
          <SheetTrigger asChild>
            <Button variant="outline">جزئیات دستگاه</Button>
          </SheetTrigger>
          <SheetContent side="right" dir="rtl">
            <SheetHeader>
              <SheetTitle>شناسنامه IMEI</SheetTitle>
            </SheetHeader>
            <div className="px-4 text-xs text-muted-foreground">
              <Num value="356938035643809" variant="ltr" />
            </div>
          </SheetContent>
        </Sheet>
      </Row>

      <Row label="DropdownMenu">
        {/* Radix takes `dir` on the Root for menu-style primitives (it drives keyboard
            navigation as well as placement), and on the Content for plain popovers. */}
        <DropdownMenu dir="rtl">
          <DropdownMenuTrigger asChild>
            <Button variant="outline">عملیات</Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent>
            <DropdownMenuItem>ویرایش</DropdownMenuItem>
            <DropdownMenuItem>چاپ فاکتور</DropdownMenuItem>
            <DropdownMenuItem variant="destructive">ابطال</DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </Row>

      <Row label="Popover / Tooltip">
        <Popover>
          <PopoverTrigger asChild>
            <Button variant="outline">راهنما</Button>
          </PopoverTrigger>
          <PopoverContent dir="rtl" className="text-xs">
            سود این فاکتور بر پایه بهای خرید همان واحد سریالی محاسبه می‌شود.
          </PopoverContent>
        </Popover>

        <Tooltip>
          <TooltipTrigger asChild>
            <Button variant="ghost" size="icon" aria-label="اطلاعات">
              <SmartphoneIcon className="size-4" />
            </Button>
          </TooltipTrigger>
          <TooltipContent dir="rtl">دستگاه فروخته‌شده — قابل ویرایش نیست</TooltipContent>
        </Tooltip>
      </Row>

      <Row label="Toast">
        <Button variant="outline" onClick={() => toast.success('فاکتور با موفقیت ثبت شد.')}>
          موفق
        </Button>
        <Button variant="outline" onClick={() => toast.error('اتصال به درگاه پیامک برقرار نشد.')}>
          خطا
        </Button>
        <Button variant="outline" onClick={() => toast.warning('اعتبار پیامک رو به اتمام است.')}>
          هشدار
        </Button>
      </Row>

      <Row label="Tabs">
        <Tabs defaultValue="items" className="w-full max-w-md">
          <TabsList>
            <TabsTrigger value="items">اقلام</TabsTrigger>
            <TabsTrigger value="payments">پرداخت‌ها</TabsTrigger>
            <TabsTrigger value="history">تاریخچه</TabsTrigger>
          </TabsList>
          <TabsContent value="items" className="pt-3 text-xs text-muted-foreground">
            اقلام فاکتور
          </TabsContent>
          <TabsContent value="payments" className="pt-3 text-xs text-muted-foreground">
            پرداخت‌های ترکیبی
          </TabsContent>
          <TabsContent value="history" className="pt-3 text-xs text-muted-foreground">
            تغییرات
          </TabsContent>
        </Tabs>
      </Row>
    </Section>
  );
}

function TableSection({ alt }: { alt?: boolean }) {
  const rows = [
    { imei: '356938035643809', model: 'iPhone 13 128GB', status: 'in_stock', cost: 425_000_000 },
    { imei: '351756051523999', model: 'Galaxy A54 256GB', status: 'reserved', cost: 118_000_000 },
    { imei: '013994005301234', model: 'Xiaomi Redmi 13C', status: 'sold', cost: 62_500_000 },
  ];

  return (
    <Section
      alt={alt}
      title="Table"
      note="اعداد مالی همیشه tabular و راست‌چین؛ IMEI همیشه LTR. سرستون مبلغ هم‌راستای مقادیرش."
    >
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>IMEI</TableHead>
            <TableHead>مدل</TableHead>
            <TableHead>وضعیت</TableHead>
            <TableHead className="text-end">بهای خرید</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((row) => (
            <TableRow key={row.imei}>
              <TableCell>
                <Num value={row.imei} variant="ltr" />
              </TableCell>
              <TableCell>{row.model}</TableCell>
              <TableCell>
                <StatusBadge status={row.status} />
              </TableCell>
              <TableCell className="text-end">
                <Money rial={row.cost} digits="latin" />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </Section>
  );
}

function StateSection({ alt }: { alt?: boolean }) {
  return (
    <Section alt={alt} title="حالت‌های صفحه" note="هر لیست باید حالت خالی و حالت بارگذاری داشته باشد.">
      <Row label="Skeleton">
        <div className="w-full max-w-md space-y-2">
          <Skeleton className="h-4 w-1/3" />
          <Skeleton className="h-4 w-full" />
          <Skeleton className="h-4 w-2/3" />
        </div>
      </Row>

      <Row label="Badge">
        <Badge>پیش‌فرض</Badge>
        <Badge variant="secondary">ثانویه</Badge>
        <Badge variant="outline">خطی</Badge>
        <Badge variant="destructive">خطر</Badge>
      </Row>

      <div className="grid gap-4 lg:grid-cols-2">
        <EmptyState
          icon={WrenchIcon}
          title="تعمیری در جریان نیست"
          description="با ثبت اولین قبض پذیرش، کارتابل تعمیرات همین‌جا نمایش داده می‌شود."
          action={<Button size="sm">ثبت قبض پذیرش</Button>}
        />

        <EmptyState
          variant="search"
          icon={SearchIcon}
          title="نتیجه‌ای برای این فیلتر نیست"
          description="بازه تاریخ را بازتر کنید یا وضعیت را روی «همه» بگذارید."
          action={
            <Button size="sm" variant="outline">
              پاک کردن فیلترها
            </Button>
          }
        />
      </div>
    </Section>
  );
}
