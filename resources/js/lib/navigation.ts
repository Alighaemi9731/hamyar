import {
  ArrowLeftRightIcon,
  BanknoteIcon,
  BarChart3Icon,
  BellIcon,
  BoxesIcon,
  ClipboardListIcon,
  CreditCardIcon,
  FileTextIcon,
  LayoutDashboardIcon,
  type LucideIcon,
  ReceiptIcon,
  SettingsIcon,
  ShieldCheckIcon,
  ShoppingCartIcon,
  SmartphoneIcon,
  StoreIcon,
  TagsIcon,
  TruckIcon,
  UsersIcon,
  WrenchIcon,
} from 'lucide-react';

export interface NavItem {
  label: string;
  href: string;
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
        icon: SmartphoneIcon,
        feature: 'module:inventory',
      },
      {
        label: 'حواله‌ها',
        href: '/inventory/transfers',
        icon: ArrowLeftRightIcon,
        feature: 'module:inventory',
      },
      {
        label: 'انبارگردانی',
        href: '/inventory/counts',
        icon: ClipboardListIcon,
        feature: 'module:inventory',
      },
      { label: 'خرید', href: '/purchasing', icon: TruckIcon, feature: 'module:purchasing' },
      /*
        Three working screens — the pending queue, a per-device checklist, and the guide —
        reachable only by typing the URL. No `feature` key, because the routes carry no
        `module:hamta` gate either; adding one here would hide a screen that works.
      */
      { label: 'همتا', href: '/hamta', icon: ShieldCheckIcon },
    ],
  },
  {
    label: 'مالی',
    items: [
      { label: 'خزانه‌داری', href: '/treasury', icon: BanknoteIcon, feature: 'module:treasury' },
      { label: 'چک‌ها', href: '/cheques', icon: FileTextIcon, feature: 'module:cheques' },
      {
        /*
          `/installments` does not exist and never has — the module routes
          `/installments/collections` and `/installments/plans/{plan}`, and nothing at its
          root. This item has 404'd since it was added.

          Repointed at the collections desk rather than given an index, because the desk is
          the screen a shop actually opens: «کدوم قسط‌ها سررسید شده؟» is the daily question,
          and a plans list would be a second screen answering a rarer one. A plans index is
          a real gap, but it is a page to build, not a nav entry to fix.
        */
        label: 'اقساط',
        href: '/installments/collections',
        icon: CreditCardIcon,
        feature: 'module:installments',
      },
      { label: 'گزارش‌ها', href: '/reporting', icon: BarChart3Icon, feature: 'module:reporting' },
    ],
  },
  {
    label: 'ابزارها',
    items: [
      { label: 'پیامک', href: '/messaging', icon: BellIcon, feature: 'module:messaging' },
      { label: 'ویترین', href: '/storefront', icon: StoreIcon, feature: 'module:storefront' },
      { label: 'صورتحساب‌ها', href: '/moadian', icon: ReceiptIcon, feature: 'module:moadian' },
      { label: 'تنظیمات', href: '/settings', icon: SettingsIcon },
    ],
  },
];
