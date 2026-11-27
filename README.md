# BlueFairy_FreeGiftHyva

**Free and open source (MIT).** The Hyvä theme companion for the paid `bluefairy/module-free-gift` extension. Published on packagist.org; the core module is sold separately.


> **[Free Gift — Hyvä Companion — Installation & Usage Guide (PDF)](docs/FreeGiftHyvaGuide.pdf)** — this README and
> `FEATURES.md`, typeset with the storefront and admin screenshots.

Hyvä **Theme** companion for BlueFairy_FreeGift (a storefront built on `Hyva_Theme`; it does not need, and does not touch, Hyvä Checkout). On the Hyvä cart page (layout handle `hyva_checkout_cart_index`, which Hyvä Theme gives the cart page) it removes the core module's Luma progress bar, gift popup and countdown and puts Alpine.js / Tailwind CSS versions in `checkout.cart.form.before`; on the Hyvä product page (`hyva_catalog_product_view`) it swaps in its own badge template.

---

## Requirements

| Requirement | Version |
|------------|---------|
| BlueFairy_FreeGift (core module) | Required, version 1.2 or later (`bluefairy/module-free-gift ^1.2`): the countdown's store-time-zone end of day, its cache tag, the Countdown End Date precedence and the `GiftLabel` badge block this page describes are in the base from 1.2.0 |
| Hyva_Theme | Required (`hyva-themes/magento2-theme-module ^1.3.11`, as `composer.json` declares). The templates work under Hyvä's strict Content Security Policy (the `Hyva/default-csp` theme) and on the plain `Hyva/default` theme |
| Magento 2 | 2.4.8 or 2.4.9 (`magento/framework ~103.0.8`) |
| PHP | 8.4 or 8.5 (not 8.3) |
| Alpine.js | Included with Hyva_Theme |
| Tailwind CSS | Included with Hyva_Theme |

---

## Installation

### 1. Require the package

This companion is free and open source, published on public Packagist. It needs no repository
configuration and no credentials.

```bash
composer require bluefairy/module-free-gift-hyva
```

The core module it extends, `bluefairy/module-free-gift` (`^1.2`), is a paid extension. Install it
from wherever you bought it: your Private Packagist repository, or the Adobe Commerce Marketplace
with your Marketplace access keys. Composer will tell you if it is not reachable.

### 2. Enable the module

```bash
php bin/magento module:enable BlueFairy_FreeGiftHyva
```

### 3. Run setup and compile

```bash
php bin/magento setup:upgrade
php -d memory_limit=2G bin/magento setup:di:compile
php bin/magento cache:flush
```

### 4. Verify installation

```bash
php bin/magento module:status BlueFairy_FreeGiftHyva
# Expected: Module is enabled
```

---

## Components

Everything below is read from the templates in `view/frontend/templates/` and the two layout files in
`view/frontend/layout/`.

### Gift Popup (`freeGiftPopup`)

**Template:** `view/frontend/templates/cart/gift-popup.phtml` (cart page; view model `BlueFairy\FreeGift\ViewModel\Cart\GiftPopup`)

For a *Customer Selects Gift* rule that applies to the cart:

- **Fetches the rule's gifts when the shopper first opens it** (`openDialog()` -> `fetchGifts()`, a POST to `bluefairy_freegift/cart/giftpopup`), not on component init, and never on a timer.
- **Never opens by itself** — the shopper opens it with its trigger button (auto-opening put an overlay over the cart on every load).
- **Shows each gift's** image, name (its SKU when it has no name) and description.
- **Configurable gifts** — one select per option; *Add to Cart* stays disabled until every option is chosen (`canAdd()`), and for a gift out of stock.
- **Adds by POST** (`addGift()`, to `bluefairy_freegift/cart/giftadd`) with the gift's SKU and chosen options, and the form key the view model renders into the component.
- **Withdraws its trigger at the rule's Gift Quantity** — for a rule whose limit is already reached this template renders nothing on the page (the base module's Luma template renders a hidden wrapper marked `data-limit-reached` instead); after an add the component takes the count of gifts from the store's answer to the add (`gifts_taken`, `limit_reached`) and hides the trigger when the limit is reached.
- **On success** dispatches `reload-customer-section-data` (so Hyvä refreshes the cart sections) and closes after 0.8 s; a failure is shown in the dialog.
- **Accessibility** — focus is kept inside the open dialog and returned when it closes.

Styling: a fixed overlay `bg-black/50` behind a white, rounded, `shadow-2xl` card (`max-w-lg`); success and error messages in green / red panels; disabled buttons at half opacity.

### Progress Bar (`freeGiftProgressBar`)

**Template:** `view/frontend/templates/cart/progress-bar.phtml` (cart page; view model `BlueFairy\FreeGift\ViewModel\Cart\ProgressBar`)

How close the cart is to each applicable rule's threshold.

- **No fetch on init.** `init()` seeds the bars from Hyvä's stored customer-section data
  (`mage-cache-storage`, section `bluefairy_freegift_bars`) - a localStorage read, no request.
- **Updates on `private-content-loaded`** - the event Hyvä dispatches on every page load and
  after every cart POST, carrying the same section. There is no timer and no debounce.
- **One fallback request** (`GET bluefairy_freegift/cart/barstate`), at most once per component,
  only when the event's payload has no section of ours at all (a shopper whose stored data
  predates it). An empty bar list is an answer and does not trigger it.
- **Message** — the rule's progress-bar message with the remaining amount, or the met message.

Styling: a grey track (`bg-gray-200`) with a **blue** fill (`bg-blue-600`) that turns **green** (`bg-green-600`) when the threshold is met, its width animated (`transition-all duration-500`); the panel is grey, green when met.

### Countdown Timer (`freeGiftCountdown`)

**Template:** `view/frontend/templates/widget/countdown.phtml` (block `BlueFairy\FreeGift\Block\Widget\Countdown`)

- **Server-rendered end** — the block renders the end timestamp into the component: the end (23:59:59) of the rule's Countdown End Date when it is set, otherwise of its To Date, in the store's time zone (`Model/Timer/CountdownProvider`; see the core README's *Countdown End Date* row). A page with the timer carries the countdown cache tag, so a rule change purges it from the full-page cache (core README, *Full-Page Cache*).
- **Ticks every second** (`setInterval`), showing days, hours, minutes and seconds; plain JavaScript, no library.
- **On expiry** clears its interval and shows *"Promotion has ended"* in red.

Styling: a yellow panel (`bg-yellow-50`, `border-yellow-300`), bold tabular numerals with d / h / m / s labels.

### Product Badge

**Template:** `view/frontend/templates/product/gift-label.phtml`, set on the core module's
`bluefairy.freegift.product.label` block by `hyva_catalog_product_view.xml` (and
`hyva_checkout_cart_index.xml`). The same rules and markup as the core badge (README of
`BlueFairy_FreeGift`, "Product Badges" - active, in-date rules that list the product as a gift),
each in an inline flex wrapper. No JavaScript.

---

## Template Override

Besides the layouts, `etc/frontend/di.xml` sets the countdown block's default template:

```xml
<type name="BlueFairy\FreeGift\Block\Widget\Countdown">
    <arguments>
        <argument name="data" xsi:type="array">
            <item name="template" xsi:type="string">BlueFairy_FreeGiftHyva::widget/countdown.phtml</item>
        </argument>
    </arguments>
</type>
```

This is frontend-area configuration, not tied to a theme: while `BlueFairy_FreeGiftHyva` is enabled,
**every** countdown on the storefront - a Countdown widget placed in a CMS page, in any store view,
Luma ones included - renders the Alpine.js template, which needs Alpine. On an installation that
serves Luma and Hyvä store views side by side, a Countdown widget on a Luma page will not run.

---

## Customization

### Tailwind CSS Classes

All components use Tailwind CSS classes. You can customize colors, spacing, and typography by overriding template files: copy a component template to your custom theme and modify the Tailwind class names.

Example: change the progress bar's fill from blue to indigo:
- Locate `bar.is_met ? 'bg-green-600' : 'bg-blue-600'` in `progress-bar.phtml`.
- Replace `bg-blue-600` with `bg-indigo-600` (a class new to your build needs the Tailwind build re-run; this module registers its templates with Hyvä's config generator, `Observer/RegisterModuleForHyvaConfig`).

### Alpine.js Component Extension

To extend or customize the Alpine.js behavior:

1. **Create a custom template** in your theme: `app/design/frontend/YourVendor/YourTheme/BlueFairy_FreeGiftHyva/templates/cart/gift-popup.phtml`.
2. **Modify the `x-data` and component logic** — add custom event handlers, validation, or API calls.
3. **Ensure form security** — post a form key with every POST (this template posts the one its view model renders; `hyva.getFormKey()` gives the same value in a custom template).

---

## Troubleshooting

### Hyva_Theme is not installed

**Issue:** Components do not render; Alpine.js errors in browser console.

**Solution:**
1. Verify Hyva_Theme is installed: `php bin/magento module:status Hyva_Theme`.
2. Ensure `BlueFairy_FreeGiftHyva` module sequence includes `Hyva_Theme` in `etc/module.xml`.
3. Flush cache and static files: `php bin/magento cache:flush && rm -rf pub/static/frontend/*`.

### Module load order conflict

**Issue:** FreeGiftHyva templates are not being used; Luma templates appear instead.

**Solution:**
1. Check module sequence in `etc/module.xml` — `BlueFairy_FreeGiftHyva` must load after `BlueFairy_FreeGift` and `Hyva_Theme`.
2. Recompile DI: `php bin/magento setup:di:compile`.
3. Clear cache and static files: `php bin/magento cache:flush && rm -rf pub/static/frontend/*`.

### Alpine.js component not initializing

**Issue:** Gift popup or progress bar does not appear; console shows "freeGiftPopup is not defined" or similar.

**Solution:**
1. Verify Alpine.js is loaded — open browser DevTools (F12) and check that `window.Alpine` is defined.
2. Check the page is rendered by a Hyvä theme: the components are added only on the `hyva_checkout_cart_index` (cart) and `hyva_catalog_product_view` (product) handles. The templates are rendered on the server; they are not in `pub/static`.
3. Hard-refresh the page (Ctrl+Shift+R or Cmd+Shift+R).
4. Ensure the rule's Scenario is "Customer Selects Gift" and the rule conditions match the current cart.

### Progress bar does not update after cart changes

**Issue:** Progress bar shows stale data or does not animate.

**Solution:**
1. Verify the rule has "Show Progress Bar" enabled in the admin.
2. Check browser console (F12) for JavaScript errors in the progress-bar.phtml component.
3. The bar updates on Hyvä's `private-content-loaded` event (section `bluefairy_freegift_bars`), which Hyvä dispatches on every page load and after every cart POST: check the `bluefairy_freegift_bars` section is in the customer-data response.
4. Clear Magento cache: `php bin/magento cache:flush`.

### Countdown timer does not appear

**Issue:** Countdown timer block is not visible on the cart page.

**Solution:**
1. Verify the rule has "Show Countdown Timer" enabled in the admin.
2. Confirm the rule has a *Countdown End Date* or a *To Date* in the future (the countdown needs an end, and is not shown once it has passed).
3. Check layout XML handles — this package's `view/frontend/layout/hyva_checkout_cart_index.xml` places the countdown block `bluefairy.freegift.hyva.countdown` in `checkout.cart.form.before`.
4. Flush static files and cache: `rm -rf pub/static/frontend/* && php bin/magento cache:flush`.
5. Check browser console for JavaScript errors in the countdown component.

