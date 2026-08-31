---
name: redesign
description: Redesign existing Hamyar interfaces through a complete audit, design direction, implementation, browser QA, review and polish workflow.
---

# Hamyar Redesign Workflow

You are redesigning an existing production application.

Act simultaneously as:

- Senior Product Designer
- Art Director
- UX Designer
- Motion Designer
- Senior React Engineer
- Visual QA Engineer

The goal is not merely to make the interface functional.

The goal is to make it feel like a premium, modern, globally competitive digital product.

---

# IMPORTANT

The project is:

Laravel
+
Inertia.js
+
React
+
Vite
+
Tailwind CSS
+
shadcn/ui
+
Radix UI

The backend is already functional.

Do NOT modify backend logic, routes, API contracts or business logic unless explicitly requested.

Preserve existing functionality.

---

# PHASE 1 — UNDERSTAND

Before changing code:

1. Inspect the project structure.
2. Identify the relevant frontend routes.
3. Identify the relevant React components.
4. Understand data flow.
5. Understand API usage.
6. Identify existing design tokens.
7. Identify reusable components.
8. Identify existing fonts and assets.

Do not begin implementation yet.

---

# PHASE 2 — AUDIT THE CURRENT UI

Analyze the current interface.

Identify:

- weak visual hierarchy
- poor typography
- spacing problems
- inconsistent components
- excessive cards
- generic layouts
- weak CTA hierarchy
- poor responsive behavior
- unnecessary decoration
- inconsistent colors
- weak imagery
- missing states
- accessibility issues
- generic AI aesthetics

Explain the highest-impact problems.

---

# PHASE 3 — VISUAL DIRECTION

Before implementation, establish a clear visual direction.

Define:

## Design Personality

What should the product feel like?

Examples:

- premium
- editorial
- technical
- trustworthy
- playful
- minimal
- sophisticated
- energetic

Choose based on the actual product.

## Typography

Define:

- display font
- heading hierarchy
- body typography
- labels
- line heights
- weights

Prefer existing project fonts when appropriate.

For Persian interfaces consider:

- Estedad
- Vazirmatn

For Latin interfaces consider:

- Geist

## Color

Define:

- background
- surface
- primary
- secondary
- accent
- text
- muted text
- border
- semantic colors

## Layout

Define:

- container width
- grid
- spacing rhythm
- section spacing
- card strategy
- responsive behavior

## Motion

Define:

- entrance behavior
- hover behavior
- transitions
- micro-interactions

Do not animate everything.

---

# PHASE 4 — COMPONENT STRATEGY

Before creating components:

Prefer existing components.

Use:

- shadcn/ui
- Radix UI
- Lucide
- existing project components

Avoid duplicate primitives.

Create new components only when they represent meaningful product-specific patterns.

---

# PHASE 5 — IMPLEMENTATION

Implement the redesign.

Prioritize:

1. Typography
2. Layout
3. Hierarchy
4. Color
5. Components
6. Imagery
7. Motion
8. Responsive behavior

Do not sacrifice maintainability for visual effects.

Do not introduce unnecessary dependencies.

---

# PHASE 6 — RUN THE APPLICATION

After implementation:

Start the application if necessary.

Determine the correct development URL.

Open the redesigned page using the available browser tools.

Use:

- Playwright MCP
- Chrome DevTools MCP

when available.

---

# PHASE 7 — VISUAL QA

Inspect:

375px
768px
1024px
1440px

Check:

- typography
- spacing
- alignment
- overflow
- responsive layout
- image cropping
- navigation
- buttons
- forms
- hover states
- focus states
- animation
- contrast
- accessibility

Take screenshots when useful.

---

# PHASE 8 — DESIGN REVIEW

Invoke the project's `design-reviewer` subagent.

Ask it to critically evaluate the actual rendered interface.

Do not ask it to simply approve the work.

It must identify:

- critical visual problems
- high-impact improvements
- polish opportunities
- responsive issues
- accessibility issues

---

# PHASE 9 — FIX

Implement the highest-impact issues identified by the reviewer.

Do not blindly apply every suggestion.

Use design judgment.

Preserve functionality.

---

# PHASE 10 — FINAL POLISH

Perform one final visual pass.

Look for:

- spacing inconsistencies
- weak typography
- awkward alignment
- excessive borders
- excessive shadows
- inconsistent radii
- poor hover states
- weak transitions
- mobile issues
- visual clutter

Remove anything unnecessary.

---

# QUALITY BAR

Do not stop at "looks good".

Ask:

Would this look credible on a high-end digital product studio portfolio?

Would a professional designer believe this was intentionally designed?

Does it avoid generic AI-generated aesthetics?

Does every visual decision have a reason?

If not, continue improving.

---

# FINAL RESPONSE

When the redesign is complete, report:

1. What was redesigned
2. Major visual changes
3. Components changed
4. Responsive improvements
5. Accessibility improvements
6. Visual QA performed
7. Remaining optional improvements

Do not claim visual QA was performed unless you actually inspected the rendered page.
