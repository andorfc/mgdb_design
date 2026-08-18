# Handoff: MaizeGDB page header

## Overview
A reusable page header ("header bubble") for modernized MaizeGDB pages. A rounded
card whose left portion is a solid light wheat field carrying the title and
description, blending on the right into a corn-field photograph. It replaces the
previous dark maroon/orange gradient header, which was too visually dominant.

Two variants are included:
- **With logo column** (`Header Bubble.dc.html`) — 200px left panel holding the
  MaizeGDB logo, blended into the cream field; text panel to its right.
- **No logo, full-bleed text** (`Header Bubble v2.dc.html`) — single panel, text
  starts at the left edge. This is the leading candidate.

## About the design files
The files in this bundle are **design references authored in HTML** — prototypes
showing intended look and behavior, not production code to ship as-is. The task
is to recreate this header in the target codebase using its existing patterns
(shared stylesheet, template partial, component framework — whatever MaizeGDB
pages already use). `header-snippet.html` is the closest thing to production
code: it is plain, dependency-free HTML + CSS with custom properties, and can be
adapted almost directly into a shared stylesheet plus a template partial.

`*.dc.html` files are the interactive prototypes. They use a proprietary preview
runtime (`support.js`, `image-slot.js`) and image placeholder components — **do
not port those**. Read them only as the visual source of truth.

## Fidelity
**High-fidelity.** Colors, typography, spacing, gradient stops, and radii are
final. Recreate pixel-accurately using the codebase's existing stylesheet
conventions.

## Screens / views

### Page header (single component, two variants)
- **Purpose**: Identifies the page — title, one-line lede, short descriptive
  paragraph — with agricultural imagery for MaizeGDB's visual identity.
- **Layout (v2, no logo)**: One `<header>`, `position: relative`,
  `overflow: hidden`, `border-radius: 22px`, `min-height: 210px`, background
  `#F6E8CB`. Three stacked layers:
  1. **Photo layer** — absolutely positioned, `inset: 0`, `object-fit: cover`,
     `filter: saturate(1.12) contrast(1.08)`.
  2. **Tint layer** — absolutely positioned, `inset: 0`,
     `pointer-events: none`, left-to-right gradient (see Design tokens) that is
     fully opaque `#F6E8CB` to 60% of the width, then fades to fully transparent
     by 86%. This is the core visual idea: a *solid* color under the text that
     blends into the photo, not a translucent scrim over the whole image.
  3. **Text layer** — `position: relative` (so it paints above the tint),
     `padding: 26px 40px`, `max-width: 60%`, `display: flex`,
     `flex-direction: column`, `gap: 10px`.
- **Layout (with logo)**: outer `display: grid`,
  `grid-template-columns: 200px 1fr`. Left cell background
  `linear-gradient(90deg, #FCF7EA 0%, #F8EFD9 70%, #F6E8CB 100%)`, centered
  content, `padding: 22px`. No divider border — the cream continues into the
  right cell's tint. The logo image is 104×104px with
  `mix-blend-mode: multiply` and a soft radial mask
  (`radial-gradient(circle at 50% 50%, #000 52%, transparent 78%)`) so it melts
  into the cream instead of sitting in a hard square. Right cell is the
  three-layer stack above, with text padding `26px 40px 26px 16px` and
  `max-width: 66%`.

### Components
| Element | Type / size | Color | Notes |
|---|---|---|---|
| Card | `min-height: 210px`, radius 22px | bg `#F6E8CB` | shadow `0 1px 2px rgba(60,40,20,.08), 0 12px 32px rgba(60,40,20,.10)` |
| Photo | fills card, `object-fit: cover` | — | interesting subject must sit right-of-center; the left 60% is covered |
| Tint | fills card | `#F6E8CB` → transparent | `pointer-events: none` |
| Title (`h1`) | 38px / line-height 1.05 / weight 900 / letter-spacing −0.02em | `#4A1710` | `white-space: nowrap` — intentional, keeps the card one line tall. A longer title needs the size reduced. |
| Lede (`p`) | 20px / 1.35 / weight 700 | `#5C2418` | one sentence |
| Body (`p`) | 17px / 1.5 / weight 400 | `#58483C` | `text-wrap: pretty` |
| Logo (variant A) | 104×104px | — | multiply blend + radial mask |

Exact copy used in the prototype:
- Title: "Shared interface components"
- Lede: "The reusable building blocks for modernized MaizeGDB pages."
- Body: "This reference renders every component defined in the shared stylesheet
  so that spacing, contrast, keyboard behavior, and responsive breakpoints can be
  verified in one place before a pattern is applied to a production page."

This copy is per-page content, not part of the component. Expose title, lede, and
body as parameters/slots.

## Interactions & behavior
Static, non-interactive — no hover, focus, click, or animation states. It is a
banner, not a control.

**Responsive** (`max-width: 760px`): the horizontal blend fails at narrow widths,
so the layout flips. The photo becomes a top band (`height: 45%`), the tint
becomes a vertical gradient (transparent → 85% at 30% → solid at 55%), the text
gets `max-width: 100%` with `padding: 20px 22px`, and the title drops to 28px
with `white-space: normal`. In the logo variant, the logo column should stack
above the text or be dropped at this breakpoint.

**Accessibility**
- The photo is decorative: `alt=""`.
- Text contrast is only guaranteed where the tint is opaque. Do **not** let any
  text line extend past ~60% of the card width — an earlier iteration failed
  here, with body text landing on bright foliage at roughly 1.5:1 contrast. If
  the text column is widened, push `--mgdb-header-fade-start` right to match.
- `h1` should be the page's single top-level heading.

## State management
None. No state, no data fetching.

## Design tokens
Colors:
- Tint / card background: `#F6E8CB`
- Logo panel gradient: `#FCF7EA` → `#F8EFD9` (70%) → `#F6E8CB`
- Title: `#4A1710`
- Lede: `#5C2418`
- Body: `#58483C`
- Accent gold (used for eyebrow labels elsewhere in the system): `#9A6B18`
- Brand maroon (buttons elsewhere): `#7A2E1B`, hover `#94391F`
- Shadow: `rgba(60,40,20,.08)` and `rgba(60,40,20,.10)`

Tint gradient (canonical, left→right):
```
linear-gradient(90deg,
  #F6E8CB 0%,
  #F6E8CB 60%,
  rgba(246,232,203,.90) 70%,
  rgba(246,232,203,.25) 80%,
  rgba(246,232,203,0) 86%)
```

Spacing: 10px (stack gap), 16 / 22 / 26 / 40 / 48px paddings.
Type scale: 38 / 20 / 17px (mobile title 28px).
Radius: 22px card, 6px logo tile.
Typography: **Source Sans 3** (weights 400 / 700 / 900), fallback
`Helvetica, Arial, sans-serif`. Available from Google Fonts; substitute the
codebase's existing sans if one is already loaded, keeping the weights.

`header-snippet.html` exposes all of the above as `--mgdb-header-*` custom
properties on `.mgdb-header`, so a page can override tint, height, text width,
fade geometry, paddings, title size, and photo filter without touching the
stylesheet. Note it uses `color-mix()` for the derived fade stops — if MaizeGDB
must support older browsers, replace those with literal `rgba()` values from the
canonical gradient above.

## Assets
- `assets/cornfield-sample.png` — the corn-field photo used in the prototype
  (1516×692, backlit corn rows at golden hour). **Provenance unconfirmed** —
  it was pasted into the design session, so treat it as a placeholder. Before
  launch, replace it with a MaizeGDB/USDA-owned image or a public-domain /
  CC0 photo (USDA ARS Image Gallery is a good source). Ship at ~1600–2000px
  wide, WebP or JPEG, ideally under 300KB, with the subject right-of-center.
- `assets/maizegdb-logo.webp` — the MaizeGDB kernel-rosette logo used in the
  logo variant. Use the canonical asset from the MaizeGDB brand files if a
  higher-resolution or SVG version exists; this copy came from the prototype.

## Files
- `header-snippet.html` — production-shaped HTML + CSS with custom properties.
  Start here.
- `Header Bubble v2.dc.html` — prototype, no-logo variant (preferred direction).
- `Header Bubble.dc.html` — prototype, logo-column variant.
- `assets/` — sample photo and logo.

## Suggested implementation
1. Move the `.mgdb-header` rules from `header-snippet.html` into the shared
   MaizeGDB stylesheet.
2. Make the markup a template partial/component taking `title`, `lede`, `body`,
   `photoUrl` (and optionally `logoUrl` for the logo variant).
3. Host the photo under the site's image path and reference it from the partial.
4. Verify at 1440px, 1024px, 760px, and 375px that no text line crosses into the
   photo, and that the title still fits one line at the largest width.
