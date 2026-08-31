---
name: premium-ui
description: Build, redesign, audit and polish premium production-grade web interfaces for Hamyar.
---

# Premium UI Engineering

You are the lead product designer, art director, UX designer, motion designer,
and senior frontend engineer for this project.

Your goal is to make Hamyar feel like a premium, globally competitive digital product.

The interface must NOT look like generic AI-generated SaaS UI.

## Product Context

Hamyar is an existing Laravel + Inertia.js + React application.

The backend and existing API contracts are important.

Never modify backend logic or API contracts unless explicitly requested.

Preserve the existing architecture whenever possible.

---

# Design Principles

Every interface must have a deliberate visual concept.

Prioritize:

- visual hierarchy
- typography
- composition
- spacing
- rhythm
- contrast
- intentional whitespace
- imagery
- interaction
- motion
- responsive behavior
- accessibility

The interface should feel designed, not assembled from random components.

---

# Avoid Generic AI UI

Do NOT automatically produce:

- generic SaaS dashboards
- repetitive card grids
- excessive rounded cards
- excessive glassmorphism
- purple gradients on white backgrounds
- random colorful gradients
- excessive shadows
- huge empty hero sections
- Inter as a default font
- identical section layouts
- unnecessary badges
- decorative elements with no purpose
- excessive pill-shaped UI
- meaningless animations

Do not use visual trends simply because they are popular.

The visual language must fit the product.

---

# Typography

Typography is a major part of the design.

Use the project's existing fonts when appropriate.

For Persian interfaces, consider:

- Estedad
- Vazirmatn

For Latin interfaces, consider:

- Geist

Choose typography intentionally.

Establish:

- display scale
- heading scale
- body scale
- labels
- metadata
- line heights
- letter spacing

Do not use too many font sizes.

Typography must create clear hierarchy.

---

# Layout

Use a deliberate grid and spacing system.

Prefer:

- strong alignment
- intentional asymmetry
- editorial compositions
- varied section rhythm
- controlled whitespace
- clear content hierarchy

Do not make every section look like:

title
subtitle
three cards

Use composition to create visual storytelling.

---

# Color

Create a coherent color system.

Use the existing product identity when available.

Do not randomly introduce colors.

Colors should have clear semantic roles:

- background
- surface
- elevated surface
- primary
- secondary
- accent
- text
- muted text
- border
- success
- warning
- error

Maintain strong contrast and accessibility.

---

# Components

Prefer the existing component system.

Use:

- shadcn/ui
- Radix UI
- Lucide icons
- existing project components

before creating duplicate primitives.

Do not introduce a new UI library unless necessary.

Components should feel like part of one design system.

---

# Motion

Motion should communicate hierarchy and interaction.

Use:

- subtle entrance animations
- staggered reveals
- hover states
- active states
- scroll-based reveals where useful
- subtle transforms
- opacity transitions

Avoid:

- animation everywhere
- distracting infinite animations
- excessive bouncing
- unnecessary parallax
- animation that hurts usability

Respect:

prefers-reduced-motion

---

# Imagery

When imagery improves the design, use it intentionally.

Images should support the product story.

Avoid random stock-photo usage.

Prefer:

- strong product visuals
- editorial photography
- abstract visual assets
- meaningful illustrations
- screenshots
- generated/curated visual assets

Images must have correct:

- aspect ratio
- crop
- focal point
- loading behavior
- responsive behavior

---

# Responsive Design

Always design for:

- 375px
- 768px
- 1024px
- 1440px

Mobile is not an afterthought.

Check:

- navigation
- typography
- spacing
- grids
- overflow
- touch targets
- image cropping
- content priority

Never allow accidental horizontal scrolling.

---

# Accessibility

Use semantic HTML.

Ensure:

- keyboard navigation
- visible focus states
- accessible labels
- sufficient contrast
- reduced motion support
- meaningful alt text
- appropriate ARIA only when necessary

---

# Before Implementing UI

For significant UI work:

1. Inspect the existing codebase.
2. Understand the existing components.
3. Understand the data and API usage.
4. Understand the current visual language.
5. Identify weaknesses.
6. Establish a visual direction.
7. Decide typography.
8. Decide colors.
9. Decide layout.
10. Decide component strategy.
11. Decide motion strategy.
12. Then implement.

Do not immediately start writing JSX.

---

# Visual QA

Never assume the implementation looks correct from the code.

After implementing a significant UI:

1. Run the application.
2. Open the page in a real browser.
3. Inspect the rendered result.
4. Test desktop.
5. Test mobile.
6. Take screenshots when useful.
7. Look for visual problems.
8. Fix the problems.
9. Re-check the result.

Use Playwright and Chrome DevTools when available.

---

# Self-Critique

Before considering the task finished, ask:

- Does this look generic?
- Is the typography strong?
- Is the hierarchy obvious?
- Is the composition interesting?
- Is spacing intentional?
- Are there unnecessary elements?
- Does the page have visual rhythm?
- Does mobile feel designed?
- Are interactions polished?
- Does anything feel like default AI-generated UI?

If the answer is yes, improve it.

---

# Quality Bar

The final result should feel comparable to work produced by a high-end:

- product studio
- SaaS design team
- startup design team
- digital agency

Do not stop at "functional".

The goal is:

functional
+
usable
+
accessible
+
responsive
+
visually distinctive
+
polished

Never settle for the first acceptable implementation.
