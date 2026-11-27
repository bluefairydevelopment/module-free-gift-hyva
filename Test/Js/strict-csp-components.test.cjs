/**
 * Copyright © 2026 Blue Fairy Development. All rights reserved.
 *
 * The Alpine components of the three Hyvä templates, run from each template's inline script.
 * Under Hyvä's strict CSP a template may only name properties and methods, so the logic its
 * attributes used to hold lives in these methods, and per-element settings arrive through
 * data-* attributes. Run: node --test FreeGiftHyva/Test/Js/*.test.cjs
 */
'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const TEMPLATES = path.join(__dirname, '../../view/frontend/templates');

const LABELS = {
    loading: 'Loading gifts...',
    outOfStock: 'Out of stock',
    selectOption: '-- Select --',
    selectOptions: 'Please select all options.',
    rateLimited: 'Too many requests. Please wait and try again.',
    addFailed: 'Unable to add the gift. Please try again.',
    addSuccess: 'Gift added to your cart!',
    noGifts: 'No gifts available right now.'
};

/** Run a template's registered inline script and return what it gave Alpine.data(). */
function load(template, extra = {}) {
    const html = fs.readFileSync(path.join(TEMPLATES, template), 'utf8');
    const match = html.match(/\n<script>\n([\s\S]*?)\n<\/script>\n<\?php \$hyvaCsp->registerInlineScript\(\) \?>/);
    assert.ok(match, `${template}: no registered inline script found`);
    const source = match[1].replace(/<\?=\s*\/\* @noEscape \*\/\s*\$popupLabels\s*\?>/, JSON.stringify(LABELS));
    assert.ok(!/<\?/.test(source), `${template}: PHP left in the script the test runs`);
    const registered = {};
    let onInit = null;
    const sandbox = {
        window: { addEventListener() {}, dispatchEvent() {}, localStorage: extra.localStorage },
        document: {
            addEventListener: (name, fn) => {
                if (name === 'alpine:init') {
                    onInit = fn;
                }
            },
            body: { style: {} },
            activeElement: null
        },
        Alpine: {
            data: (name, fn) => {
                registered[name] = fn;
            }
        },
        fetch: extra.fetch || (() => new Promise(() => {})),
        setInterval: () => 1,
        clearInterval() {},
        setTimeout: () => 1,
        clearTimeout() {},
        FormData: class {
            constructor() {
                this.entries = [];
            }
            append(k, v) {
                this.entries.push([k, v]);
            }
        },
        CustomEvent: class {
            constructor(n, d) {
                this.type = n;
                this.detail = d;
            }
        },
        Date,
        Math,
        JSON,
        console
    };
    sandbox.window.localStorage = extra.localStorage;
    vm.runInNewContext(source, sandbox);
    assert.equal(typeof onInit, 'function', `${template}: nothing listens for alpine:init`);
    onInit();
    return registered;
}

/** A component as Alpine builds it: its data, $el's dataset, and Alpine's helpers. */
function make(factory, dataset) {
    const c = factory();
    c.$el = { dataset };
    c.$watch = () => {};
    c.$nextTick = () => {};
    c.$refs = {};
    return c;
}

/** The x-for scope: reads fall through to the component, the loop variables are its own. */
function scope(component, vars) {
    return Object.assign(Object.create(component), vars);
}

// ---------------------------------------------------------------- countdown

test('countdown: registers freeGiftCountdown and reads its end time from data-end-timestamp', () => {
    const { freeGiftCountdown } = load('widget/countdown.phtml');
    assert.equal(typeof freeGiftCountdown, 'function');
    const end = Math.floor(Date.now() / 1000) + 3 * 86400 + 3600 + 120 + 5;
    const c = make(freeGiftCountdown, { endTimestamp: String(end) });
    c.init();
    assert.equal(c.endTimestamp, end);
    assert.equal(c.expired, false);
    assert.equal(c.isRunning(), true);
    assert.equal(c.days, '3');
    assert.equal(c.hours, '01');
});

test('countdown: a missing or past end shows the ended state', () => {
    const { freeGiftCountdown } = load('widget/countdown.phtml');
    const none = make(freeGiftCountdown, {});
    none.init();
    assert.equal(none.expired, true);
    assert.equal(none.isRunning(), false);

    const past = make(freeGiftCountdown, { endTimestamp: '1000' });
    past.init();
    assert.equal(past.expired, true);
    assert.equal(past.isRunning(), false);
});

// ---------------------------------------------------------------- progress bar

const BAR_DATA = {
    barStateUrl: 'https://store.invalid/bluefairy_freegift/cart/barstate/',
    sectionKey: 'bluefairy_freegift_bars'
};

test('progress bar: registers freeGiftProgressBar and reads its settings from data attributes', () => {
    const { freeGiftProgressBar } = load('cart/progress-bar.phtml');
    const c = make(freeGiftProgressBar, BAR_DATA);
    c.init();
    assert.equal(c.barStateUrl, BAR_DATA.barStateUrl);
    assert.equal(c.sectionKey, 'bluefairy_freegift_bars');
});

test('progress bar: init seeds bars from mage-cache-storage', () => {
    const stored = { bluefairy_freegift_bars: { bars: [{ rule_id: 4 }] } };
    const { freeGiftProgressBar } = load('cart/progress-bar.phtml', {
        localStorage: { getItem: (k) => (k === 'mage-cache-storage' ? JSON.stringify(stored) : null) }
    });
    const c = make(freeGiftProgressBar, BAR_DATA);
    c.init();
    assert.deepEqual(JSON.parse(JSON.stringify(c.bars)), [{ rule_id: 4 }]);
});

test('progress bar: onPrivateContent takes the event and reads its own section key', () => {
    const fetched = [];
    const { freeGiftProgressBar } = load('cart/progress-bar.phtml', {
        fetch: (url) => {
            fetched.push(url);
            return new Promise(() => {});
        }
    });
    const c = make(freeGiftProgressBar, BAR_DATA);
    c.init();
    c.onPrivateContent({ detail: { data: { bluefairy_freegift_bars: { bars: [{ rule_id: 7 }] } } } });
    assert.equal(c.bars.length, 1);
    assert.equal(c.bars[0].rule_id, 7);
    // An empty list is an answer, not a missing payload.
    c.onPrivateContent({ detail: { data: { bluefairy_freegift_bars: { bars: [] } } } });
    assert.equal(c.bars.length, 0);
    assert.deepEqual(fetched, []);
    // An absent key fetches once, from the URL in data-bar-state-url.
    c.onPrivateContent({ detail: { data: { cart: {} } } });
    c.onPrivateContent({ detail: { data: { cart: {} } } });
    assert.deepEqual(fetched, [BAR_DATA.barStateUrl]);
    // No detail: nothing happens.
    c.onPrivateContent({});
    assert.deepEqual(fetched, [BAR_DATA.barStateUrl]);
});

test('progress bar: per-bar methods read the x-for bar', () => {
    const { freeGiftProgressBar } = load('cart/progress-bar.phtml');
    const c = make(freeGiftProgressBar, BAR_DATA);
    const met = scope(c, {
        bar: { is_met: true, message: 'Spend $10 more', message_met: 'You got it', percentage: 140 }
    });
    const open = scope(c, {
        bar: { is_met: false, message: 'Spend $10 more', message_met: 'You got it', percentage: 40 }
    });
    assert.equal(met.barClass(), 'bg-green-50 border-green-200');
    assert.equal(open.barClass(), 'bg-gray-50 border-gray-200');
    assert.equal(met.barIsMet(), '1');
    assert.equal(open.barIsMet(), '0');
    assert.equal(met.messageClass(), 'text-green-700');
    assert.equal(open.messageClass(), 'text-gray-800');
    assert.equal(met.barMessage(), 'You got it');
    assert.equal(open.barMessage(), 'Spend $10 more');
    assert.equal(met.fillClass(), 'bg-green-600');
    assert.equal(open.fillClass(), 'bg-blue-600');
    assert.equal(met.fillStyle(), 'width: 100%;');
    assert.equal(open.fillStyle(), 'width: 40%;');
    assert.equal(scope(c, { bar: { percentage: 'x' } }).fillStyle(), 'width: 0%;');
});

// ---------------------------------------------------------------- gift popup

const POPUP_DATA = {
    ruleId: '12',
    popupUrl: 'https://store.invalid/popup',
    addUrl: 'https://store.invalid/add',
    giftQty: '2',
    formKey: 'fk123',
    giftsTaken: '1'
};

function popup(extra) {
    const { freeGiftPopup } = load('cart/gift-popup.phtml', extra);
    assert.equal(typeof freeGiftPopup, 'function');
    const c = make(freeGiftPopup, POPUP_DATA);
    c.init();
    return c;
}

test('popup: reads its rule settings from data attributes', () => {
    const c = popup();
    assert.equal(c.ruleId, 12);
    assert.equal(c.popupUrl, POPUP_DATA.popupUrl);
    assert.equal(c.addUrl, POPUP_DATA.addUrl);
    assert.equal(c.giftQty, 2);
    assert.equal(c.formKey, 'fk123');
    assert.equal(c.giftsTaken, 1);
    assert.equal(c.titleId(), 'freegift-dialog-title-12');
});

test('popup: trigger, open, close and status class', () => {
    const fetched = [];
    const c = popup({
        fetch: (url) => {
            fetched.push(url);
            return new Promise(() => {});
        }
    });
    assert.equal(c.triggerVisible(), true);
    c.limitReached = true;
    assert.equal(c.triggerVisible(), false);
    c.openDialog();
    assert.equal(c.open, true);
    assert.deepEqual(fetched, [POPUP_DATA.popupUrl]);
    c.close();
    assert.equal(c.open, false);
    c.statusError = true;
    assert.equal(c.statusClass(), 'bg-red-50 border border-red-200 text-red-700');
    c.statusError = false;
    assert.equal(c.statusClass(), 'bg-green-50 border border-green-200 text-green-700');
});

test('popup: empty and list states', () => {
    const c = popup();
    c.loading = true;
    assert.equal(c.showEmpty(), false);
    assert.equal(c.showList(), false);
    c.loading = false;
    assert.equal(c.showEmpty(), true);
    assert.equal(c.showList(), true);
    c.gifts = [{ sku: 'g' }];
    assert.equal(c.showEmpty(), false);
    assert.equal(c.noGiftsLabel(), LABELS.noGifts);
});

test('popup: per-gift methods read the x-for gift', () => {
    const c = popup();
    const g = scope(c, {
        gift: { sku: 'GIFT-1', name: '', image_url: 'javascript:alert(1)', in_stock: true, product_type: 'simple' }
    });
    assert.equal(g.giftName(), 'GIFT-1');
    assert.equal(g.giftImageUrl(), '');
    assert.equal(
        scope(c, { gift: { image_url: 'https://x.invalid/a.jpg' } }).giftImageUrl(),
        'https://x.invalid/a.jpg'
    );
    assert.equal(scope(c, { gift: { name: 'Tote', sku: 'T' } }).giftName(), 'Tote');
    assert.equal(g.hasOptions(), false);
    assert.equal(g.outOfStock(), false);
    assert.equal(g.addDisabled(), false);
    assert.equal(scope(c, { gift: { sku: 'S', in_stock: false } }).outOfStock(), true);
    assert.equal(scope(c, { gift: { sku: 'S', in_stock: false } }).addDisabled(), true);
    c.adding = true;
    assert.equal(g.addDisabled(), true);
});

test('popup: a configurable gift needs every option before it can be added', () => {
    const c = popup();
    const gift = { sku: 'CFG', in_stock: true, product_type: 'configurable', options: [{ id: 93 }, { id: 141 }] };
    const g = scope(c, { gift });
    assert.equal(g.hasOptions(), true);
    assert.equal(g.addDisabled(), true);
    scope(c, { gift, option: gift.options[0] }).onOptionChange({ target: { value: '50' } });
    assert.deepEqual(JSON.parse(JSON.stringify(c.selectedOptions)), { CFG: { 93: '50' } });
    assert.equal(g.addDisabled(), true);
    scope(c, { gift, option: gift.options[1] }).onOptionChange({ target: { value: '7' } });
    assert.equal(g.addDisabled(), false);
    assert.equal(scope(c, { gift: { product_type: 'configurable', options: [] } }).hasOptions(), false);
});

test('popup: addThisGift adds the x-for gift', () => {
    const posted = [];
    const c = popup({
        fetch: (url, init) => {
            posted.push([url, init.body.entries]);
            return new Promise(() => {});
        }
    });
    const gift = { sku: 'GIFT-1', in_stock: true, product_type: 'simple' };
    scope(c, { gift }).addThisGift();
    assert.equal(posted.length, 1);
    assert.equal(posted[0][0], POPUP_DATA.addUrl);
    assert.deepEqual(posted[0][1], [
        ['form_key', 'fk123'],
        ['rule_id', '12'],
        ['sku', 'GIFT-1'],
        ['qty', '1']
    ]);
});
