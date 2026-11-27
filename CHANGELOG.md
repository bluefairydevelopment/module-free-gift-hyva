# Changelog — BlueFairy_FreeGiftHyva

## 1.3.0 — 2026-11-27

**This package is now free and open source under the MIT licence, and lives in its own public
repository**, `github.com/bluefairydevelopment/module-free-gift-hyva`, published on packagist.org.
It was a package inside the paid `module-free-gift` monorepo and declared `proprietary`.

No component, template or behaviour changes. What changes is how you get it and what you may do
with it:

- `composer require bluefairy/module-free-gift-hyva` now works with no repository configuration and
  no credentials, because the package is on public Packagist. The README's install step said the
  opposite - "distributed **exclusively through Private Packagist**", followed by the repository
  and `auth.json` credential steps - which stopped being true the moment this was published.
- The core module it extends, `bluefairy/module-free-gift` (`^1.2`), is still a paid extension and
  is installed from wherever you bought it. Adobe's Marketplace repository carries no
  `hyva-themes/*` packages, which is why a Hyvä companion cannot be listed there and is
  distributed this way instead.
- `LICENSE.txt` is the MIT licence. It was the paid end-user licence agreement, which defined the
  Software as the core module **and this package together**; that is no longer the arrangement.
- `docs/FreeGiftHyvaGuide.pdf` is regenerated from the corrected README. The guide that shipped
  with 1.2.2 carried the Private Packagist steps and the sentence "credentials email sent with your
  purchase", so publishing it unchanged would have contradicted the package it documents.
- `docs-build/build-guides.py` comes with the package, adapted for a repository that *is* one
  package rather than three, so the guide can be rebuilt here.

### Development

- The repository carries a Prettier config (`.prettierrc`), and its JavaScript, CSS and JSON files
  follow it. `.prettierrc` is export-ignored, so it is not in the package archive. No behaviour
  change. (Carried over from the monorepo's unreleased work, released here.)

## 1.2.2 — 2026-11-10

### Fixed
- **The countdown, the progress bar and the gift popup now work on a Hyvä store with a strict Content Security Policy** (the `Hyva/default-csp` theme). Before, the browser blocked their scripts there. They work as before on the plain `Hyva/default` theme.

### Changed
- **Requires `hyva-themes/magento2-theme-module` 1.3.11 or later** (was `^1.0`), the first release that supports strict Content Security Policy.

## 1.2.1 — 2026-10-15

Follows the base package, `BlueFairy_FreeGift` 1.2.1.

### Fixed
- **The gift popup's button no longer comes back after the shopper has taken the allowed gifts.** The Hyvä popup now withdraws its trigger once the rule's *Gift Quantity* of gifts is in the cart, straight after the last allowed add and on later page loads (it used to stay, and the next add was refused with "Gift limit reached for this rule."). After an add it takes the count of gifts from the store's answer to the add, so a pick that only enlarges a gift line the cart already holds does not hide the trigger early.

## 1.2.0 — 2026-10-14

Follows the base package, `BlueFairy_FreeGift` 1.2.0. Update both together.

### Changed

- **Requires the base `bluefairy/module-free-gift ^1.2`** (was `^1.0`). The README describes base behaviour that
  exists only from 1.2.0: the countdown ending at the end of its day in the store's time zone, its cache tag, the
  Countdown End Date taking precedence over the To Date, and the `GiftLabel` badge block. Composer now refuses to
  install this package on a base release that lacks them.
- **`magento/framework` is required directly, as `~103.0.8`.** It was only reached through the base package. The range
  admits Magento 2.4.8 and 2.4.9 and refuses a 103.1 framework and the next major.
- **Magento 2.4.8 support is declared.** The README's *Requirements* says "2.4.8 or 2.4.9".

### Fixed

- **Cached Hyvä product and category pages follow a gift rule.** The badge block this package re-templates
  (`bluefairy.freegift.product.label`) is now the base package's `Block/Product/GiftLabel`, whose cache tags include the
  rule's, so a rule change or a date passing no longer leaves a stale badge on a cached page. The template and the block
  name are unchanged. (Base package)
- **The countdown's end follows the base package's fix:** the rule's Countdown End Date when it is set, otherwise the
  end of its To Date (it was the other way round). (Base package)
- **The countdown ends at the end of its day in the store's time zone, and a cached page follows it.** The end the
  base block renders into this package's countdown is 23:59:59 of the rule's Countdown End Date (else To Date) in the
  store's time zone; it was 00:00 UTC of that day. A page with a countdown carries the countdown cache tag.
- **The README and FEATURES now describe the templates as they are.** This is a Hyvä **Theme** companion (it uses the
  Hyvä Theme cart handle `hyva_checkout_cart_index` and needs no Hyvä Checkout). The progress bar fills blue and turns
  green when met, updates on Hyvä's `private-content-loaded` with at most one fallback request, and is not debounced.
  The gift popup fetches its gifts when first opened, never opens by itself, and shows image, name and description (not
  price or SKU). The countdown shows seconds and ends with "Promotion has ended" in red. The countdown template is set
  in `etc/frontend/di.xml` through the block's `data` argument and applies to every frontend countdown while the module
  is enabled, Luma store views included. The troubleshooting steps name the real layout file, field names and events.
  The guide PDF is regenerated.

## 1.1.0 — 2026-09-19

### Added

- **Unit tests for this package, wired into the default suite.** The package shipped none and its test directory was not
  listed in `phpunit.xml.dist`. They check that every layout file is `hyva_`-prefixed (and that the removals which justify
  the rule still exist) and that the stylesheet's Tailwind `@source` line still scans this package's templates; without
  that line every utility class used only in these templates is absent from the compiled CSS, silently.

### Fixed

- **The progress bar re-fetched its state about every 2.5 seconds.** The bar is the rendering, so it cannot defer its fetch
  to a later moment, and Alpine discards a component's state each time Hyvä replaces the cart DOM. The template now reads
  bar state from Hyvä's `private-content-loaded` event, under the `bluefairy_freegift_bars` customer section that the base
  package publishes, and fetches nothing on a timer. One fetch survives on purpose: Hyvä serves a cached
  `mage-cache-storage` payload without going to the network as long as it holds `cart` and `customer`, so a shopper whose
  storage predates this section gets an event with no key of ours. That case fetches `barstate` once, behind a flag. An
  empty bar list is a real answer and does not trigger it.
- **The gift dialog had no focus trap and no scroll lock.** The dialog carried `x-trap.noscroll`, a directive belonging to
  Alpine's Focus plugin, which is not installed in the Hyvä theme. Alpine logged "You can't use [x-trap] without first
  installing the Focus plugin" 41 times per cart page and did nothing: keyboard focus escaped the dialog and the page
  scrolled behind it. Both behaviours are now implemented in the component itself, so merchants need not add an Alpine
  plugin to their theme build. `destroy()` releases the trap, because Hyvä rebuilds this component on every cart refresh
  and a teardown while open would otherwise leave the page scroll-locked.
- **The whole gift list was re-fetched every ~2.5 seconds for shoppers who never opened the popup.** The trigger button sat
  inside `x-if="gifts.length > 0"`, so the component had to load every gift just to decide whether to draw a button. The
  button no longer waits for the client to confirm that the rule is offered; the list is fetched on first open, and an
  empty result is shown as a message. (The cart reload cycle itself is Hyvä's own `checkCartShouldUpdate` and runs with
  this module disabled.)
- **Both layout files applied on every theme, including Luma.** `checkout_cart_index.xml` and `catalog_product_view.xml`
  carried no `hyva_` prefix, so Magento loaded them everywhere. They remove the base module's `progressbar`, `giftpopup`
  and `countdown` and replace them with Alpine.js templates, so a Luma shopper lost the working cart UI and was served
  Alpine markup with nothing to drive it. Renamed to `hyva_checkout_cart_index.xml` and `hyva_catalog_product_view.xml`.

## 1.0.2 — 2026-09-06

### Added

- Gift descriptions in the Hyv&auml; selection popup, shown as text.
- Hyv&auml; versions of the product label template and the cart countdown.

### Fixed

- The gift popup opens over the cart without obscuring it.
- The module registers itself with the Hyv&auml; Tailwind build, so the utility classes used
  in its templates are retained by the theme's CSS build.

### Changed

- Requirements: Magento 2.4.9, PHP 8.4 or 8.5, and a current Hyv&auml; theme release.

## 1.0.1 — 2026-08-11

### Fixed
- Add `declare(strict_types=1)` to all phtml templates

## 1.0.0 — 2026-08-11

### Added
- Alpine.js gift selection popup for Hyvä Checkout — appears when a customer-select rule qualifies
- Tailwind CSS styled modal with semi-transparent overlay and smooth animations
- Configurable product support in popup — dropdown selectors per required attribute; Add to Cart disabled until all options selected
- Single simple gift auto-open — popup opens automatically when only one eligible simple gift exists
- Cart refresh after gift is added without full page reload
- Progress bar Alpine.js component showing subtotal progress toward gift threshold
- Countdown timer Alpine.js component for time-limited gift promotions
