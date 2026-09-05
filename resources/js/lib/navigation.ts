import {
  ArrowLeftRightIcon,
  BadgeCheckIcon,
  BoxesIcon,
  CalendarClockIcon,
  ChartColumnIcon,
  ClipboardCheckIcon,
  FileSignatureIcon,
  IdCardIcon,
  LayoutDashboardIcon,
  type LucideIcon,
  MessageSquareTextIcon,
  ReceiptTextIcon,
  SettingsIcon,
  ShoppingCartIcon,
  StoreIcon,
  TagsIcon,
  TruckIcon,
  UsersIcon,
  WalletIcon,
  WrenchIcon,
} from 'lucide-react';

export interface NavItem {
  label: string;
  href: string;
  /**
   * The item's mark. The sidebar draws it on a tile (`.nav-chip`), which is the only
   * thing on the row a hurried eye actually reads — so a glyph that merely belongs to
   * the neighbourhood is not good enough. Each one below names the *thing*: «چک‌ها» is a
   * signed instrument, not a document; «شناسنامهٔ IMEI» is an identity card, not a phone;
   * «اقساط» is money owed on dates, not a bank card.
   */
  icon: LucideIcon;
  /**
   * Module key (`module:<code>`) gating this item. Undefined = always visible.
   *
   * Answers "have we switched this module on", not "does this shop's plan include it" —
   * every plan includes every module. Hiding here is convenience; the route is guarded by
   * EnsureModuleEnabled too.
   */
  feature?: string;
}

export interface NavSection {
  label: string;
  items: NavItem[];
}

/**
 * Tenant sidebar navigation, grouped the way a shop thinks about its day:
 * what happens at the counter, what is in the back room, where the money is,
 * and everything else.
 */
export const NAVIGATION: NavSection[] = [
  {
    label: 'روزانه',
    items: [
      { label: 'داشبورد', href: '/dashboard', icon: LayoutDashboardIcon },
      { label: 'فروش و صندوق', href: '/sales', icon: ShoppingCartIcon, feature: 'module:sales' },
      { label: 'تعمیرات', href: '/repairs', icon: WrenchIcon, feature: 'module:repairs' },
      { label: 'مشتریان', href: '/crm', icon: UsersIcon, feature: 'module:crm' },
    ],
  },
  {
    label: 'کالا و انبار',
    items: [
      { label: 'کالاها', href: '/catalog', icon: TagsIcon, feature: 'module:catalog' },
      { label: 'انبار', href: '/inventory', icon: BoxesIcon, feature: 'module:inventory' },
      {
        label: 'شناسنامه IMEI',
        href: '/inventory/units',
        /*
          An identity card, not a phone. The register answers «این دستگاه از کجا آمد و به
          کجا رفت» — bought from whom, sold to whom, repaired when — which is a document
          about a device, not the device. A `SmartphoneIcon` here said "phones", and so
          did the four neighbours around it.
        */
        icon: IdCardIcon,
        feature: 'module:inventory',
      },
      {
        label: 'حواله‌ها',
        href: '/inventory/transfers',
        /*
          Two arrows, and deliberately symmetric: stock moving between two of the shop's
          own places has no reading-order side, so the glyph mirrors correctly by having
          nothing to mirror. Lucide offers nothing closer — a package with a directional
          arrow would read better in a bigger tile and worse at 18px, and would raise the
          `check-rtl-arrows` question this one does not.
        */
        icon: ArrowLeftRightIcon,
        feature: 'module:inventory',
      },
      {
        label: 'انبارگردانی',
        href: '/inventory/counts',
        // A count is ticked, not listed: the clipboard leaves the office full of boxes
        // to check off, which is the difference between this and «انبار» beside it.
        icon: ClipboardCheckIcon,
        feature: 'module:inventory',
      },
      { label: 'خرید', href: '/purchasing', icon: TruckIcon, feature: 'module:purchasing' },
      /*
        Three working screens — the pending queue, a per-device checklist, and the guide —
        reachable only by typing the URL. No `feature` key, because the routes carry no
        `module:hamta` gate either; adding one here would hide a screen that works.

        A certification badge, not a shield: همتا registers a handset with the regulator so
        it is legal on the network. A shield says the shop is being protected from
        something, which is not what the queue on that screen is about.
      */
      { label: 'همتا', href: '/hamta', icon: BadgeCheckIcon },
    ],
  },
  {
    label: 'مالی',
    items: [
      {
        label: 'خزانه‌داری',
        href: '/treasury',
        // Where the shop's money sits — صندوق, کارتخوان and the bank accounts together.
        // A banknote named only the first of the three.
        icon: WalletIcon,
        feature: 'module:treasury',
      },
      {
        label: 'چک‌ها',
        href: '/cheques',
        /*
          The signature is what makes a چک a چک; every other paper in this shop is a
          document too, and `FileTextIcon` said only that.

          Lucide has no cheque and neither does any icon set of this kind — a cheque is a
          local instrument, not a UI concept. A hand-drawn one was considered and dropped:
          at 18px the parts that identify it (the payee line, the amount box, the date)
          collapse into a rectangle with a squiggle, which is `CreditCardIcon` with worse
          edges. A signed instrument is the honest reading at this size.
        */
        icon: FileSignatureIcon,
        feature: 'module:cheques',
      },
      {
        /*
          `/installments` does not exist and never has — the module routes
          `/installments/collections` and `/installments/plans/{plan}`, and nothing at its
          root. This item has 404'd since it was added.

          Repointed at the collections desk rather than given an index, because the desk is
          the screen a shop actually opens: «کدوم قسط‌ها سررسید شده؟» is the daily question,
          and a plans list would be a second screen answering a rarer one. A plans index is
          a real gap, but it is a page to build, not a nav entry to fix.

          A dated clock, not a bank card: an قسط is money owed on a day. The card glyph
          this replaces means «کارتخوان», which is a treasury account two rows up.
        */
        label: 'اقساط',
        href: '/installments/collections',
        icon: CalendarClockIcon,
        feature: 'module:installments',
      },
      { label: 'گزارش‌ها', href: '/reporting', icon: ChartColumnIcon, feature: 'module:reporting' },
    ],
  },
  {
    label: 'ابزارها',
    items: [
      {
        label: 'پیامک',
        href: '/messaging',
        // A message, not a bell. The module sends پیامک to customers; it is not the
        // shop's notification tray, and a bell promised one that does not exist.
        icon: MessageSquareTextIcon,
        feature: 'module:messaging',
      },
      { label: 'ویترین', href: '/storefront', icon: StoreIcon, feature: 'module:storefront' },
      {
        label: 'صورتحساب‌ها',
        href: '/moadian',
        // A ruled invoice rather than a till slip: what goes to مودیان is the formal
        // document, and the counter's receipt lives under «فروش و صندوق».
        icon: ReceiptTextIcon,
        feature: 'module:moadian',
      },
      { label: 'تنظیمات', href: '/settings', icon: SettingsIcon },
    ],
  },
];
