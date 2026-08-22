{{--
    Section 2 — نوار اعتماد.

    ## What this bar is, and what it deliberately is not

    It is **not** a row of icon tiles. The rejected page put five icons-in-boxes here and
    it read as the fourth card grid on a page of card grids; icons in circles were on the
    owner's own list of tells.

    It is a **sentence**, set at heading scale, with a rule running from it to a short
    column of claims — the leader-dot device from a printed price list, which is a thing
    this trade already reads. The four trades the software is for carry the weight of the
    line; the connective words step back to a muted step. That contrast is the whole
    design: type doing the work, no new shape introduced.

    ## Why there is no social proof here

    A trust bar on a SaaS page normally carries customer logos or a shop count. This
    product has **no paying customers yet** (CLAUDE.md, «Environments»), so any number or
    logo on this line would be invented. What is on it instead are three claims that are
    true today and that a shopkeeper can check on the free trial before paying anybody:
    the interface is Persian with a Jalali calendar, nothing gets installed, and the data
    comes back out as Excel whenever they ask. Checkable beats impressive.

    Contrast, both on `--color-page-alt` #edf2f8:
    navy #0e1b2c 15.4:1 · navy-soft #46586d 6.5:1 · navy-mute #5d6b7e 4.8:1 — all AA.
--}}
<section class="trust" aria-labelledby="trust-claim">
    <div class="shell trust__row">
        <p class="trust__claim" id="trust-claim">
            مناسبِ <b>فروش</b>، <b>تعمیرات</b>، <b>اقساط</b> و <b>چک</b>
        </p>

        {{-- The leader rule. Purely a connector between the claim and the proofs, and it
             only exists at the width where the two sit on one line. --}}
        <span class="trust__rule" aria-hidden="true"></span>

        {{-- `role="list"` is not redundant: Safari + VoiceOver drop list semantics from a
             <ul> whose `list-style` is `none`, and `.trust__proofs` is one. Every
             styled-flat list on this page restates it. --}}
        <ul class="trust__proofs" role="list">
            <li>فارسی، با تقویم شمسی</li>
            <li>روی مرورگر — چیزی نصب نمی‌شود</li>
            <li>خروجی اکسل، هر وقت خواستید</li>
        </ul>
    </div>
</section>
