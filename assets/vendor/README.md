# Local Vendor Assets — Offline Dependency Guide

This app is built to work **fully offline** on XAMPP. Every external
frontend library has a local-first loading path: if the real file exists
here, it's used with zero network requests; if not, the app automatically
falls back to a CDN (so it still works today, before you've copied
anything in).

**No fake/stub files are included in this folder.** This sandbox has no
network access, so the real library files could not be fetched and placed
here for you — the sections below tell you exactly what to get and where
to put it.

## Status: what's present vs missing right now

| Library | Version | Status |
|---|---|---|
| Bootstrap CSS | 5.3.3 | ❌ Missing — see below |
| Bootstrap JS bundle (incl. Popper) | 5.3.3 | ❌ Missing — see below |
| Bootstrap Icons CSS | 1.11.3 | ❌ Missing — see below |
| Bootstrap Icons font files | 1.11.3 | ❌ Missing — see below |
| html2canvas | 1.4.1 | ❌ Missing — see below |
| Chart.js | 4.4.4 | ✅ You already confirmed this works locally at `assets/js/vendor/chart.umd.min.js` (kept at its own established path — see note at the bottom) |
| Google Fonts (Poppins/Inter) | — | ✅ N/A — removed entirely; the UI now uses a system-font stack (`assets/css/style.css`, `--ts-font-heading`/`--ts-font-body`), so there is nothing to download for fonts |

## Exact files to download and where to place them

All of these are the same versions already referenced by this project's
CDN links, so visuals/behavior should be identical once vendored.

### 1. Bootstrap CSS
- Download: `https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css`
  (or `https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.3/css/bootstrap.min.css`)
- Save as: `assets/vendor/bootstrap/bootstrap.min.css`
- Windows/XAMPP path: `C:\xampp\htdocs\three-sisters\assets\vendor\bootstrap\bootstrap.min.css`

### 2. Bootstrap JS bundle (includes Popper — required for dropdowns/modals/tooltips)
- Download: `https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js`
  (or `https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.3/js/bootstrap.bundle.min.js`)
- Save as: `assets/vendor/bootstrap/bootstrap.bundle.min.js`
- Windows/XAMPP path: `C:\xampp\htdocs\three-sisters\assets\vendor\bootstrap\bootstrap.bundle.min.js`
- **Use the "bundle" file specifically** — the plain `bootstrap.min.js` does NOT include Popper, and dropdown/tooltip positioning will break without it.

### 3. Bootstrap Icons CSS + font files (both required — CSS alone shows blank icon boxes)
- Get the full `font/` folder from the `bootstrap-icons` npm package (version 1.11.3), or download individually:
  - CSS: `https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css`
  - Font files: `https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2`
    and `https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff`
- Save as:
  - `assets/vendor/bootstrap-icons/bootstrap-icons.min.css`
  - `assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2`
  - `assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff`
- Windows/XAMPP paths:
  - `C:\xampp\htdocs\three-sisters\assets\vendor\bootstrap-icons\bootstrap-icons.min.css`
  - `C:\xampp\htdocs\three-sisters\assets\vendor\bootstrap-icons\fonts\bootstrap-icons.woff2`
  - `C:\xampp\htdocs\three-sisters\assets\vendor\bootstrap-icons\fonts\bootstrap-icons.woff`
- **Why the `fonts/` subfolder matters:** `bootstrap-icons.min.css` references its icon glyphs via a relative path — `url("./fonts/bootstrap-icons.woff2...")`. That relative path is resolved against wherever the CSS file itself lives, so the font files MUST sit in a `fonts/` subfolder next to the CSS, exactly as above — not in the same folder as the CSS, and not somewhere else.

### 4. html2canvas (used only by the POS receipt page's "Save JPEG" button)
- Download: `https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js`
  (or `https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js`)
- Save as: `assets/vendor/html2canvas/html2canvas.min.js`
- Windows/XAMPP path: `C:\xampp\htdocs\three-sisters\assets\vendor\html2canvas\html2canvas.min.js`

## How detection works (already implemented, no code change needed after copying files)

`config/constants.php` defines `vendorOrCdn($relativePath, $cdnUrl)`, used by
`components/header.php`, `components/footer.php`, `auth/login.php`, and
`pos/receipt.php`. It checks `file_exists()` on the exact paths above; if
found, that file is served locally (zero network calls); if not, the
existing CDN link is used exactly as before. The moment you copy a file
into place, that dependency switches to local on the very next page load
— nothing else to configure.

## Why Chart.js lives at a different path (`assets/js/vendor/` not `assets/vendor/`)

Chart.js was wired up and verified working in an earlier pass, using
`assets/js/vendor/chart.umd.min.js`. That path is left exactly as-is here
to avoid breaking what you already confirmed works — the new dependencies
in this pass (Bootstrap, Bootstrap Icons, html2canvas) use the sibling
`assets/vendor/` structure instead. Both are valid, permanent locations;
they simply weren't unified retroactively to avoid disturbing a working
setup.

## Remaining external network dependencies after this pass

| URL | Where | Classification |
|---|---|---|
| `cdnjs.cloudflare.com/.../Chart.js/4.4.4/...` | `analytics/index.php` | **(A) Intentional CDN fallback** — only requested if `assets/js/vendor/chart.umd.min.js` is absent; already gated by a server-side `file_exists()` check, unchanged from the prior pass |
| `cdn.jsdelivr.net/npm/chart.js@4.4.4/...` | `analytics/index.php` | **(A) Intentional CDN fallback** — second-choice CDN, only reached if both the local file and the first CDN fail |
| Bootstrap CSS/JS, Bootstrap Icons CSS, html2canvas CDN URLs | `header.php`, `footer.php`, `login.php`, `receipt.php` | **(A) Intentional CDN fallback** — each now wrapped in `vendorOrCdn()`; only used when the corresponding local file (documented above) is absent |

No unnecessary or leftover bare CDN references remain anywhere in the
project (verified by a full-project grep — see the PASS 0 report).
