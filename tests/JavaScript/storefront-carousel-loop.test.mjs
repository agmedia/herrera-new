import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const read = (path) => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

for (const initiallyLoaded of [false, true]) {
    test('localized loop edge labels apply when Splide is ' + (initiallyLoaded ? 'already loaded' : 'deferred'), () => {
        const listeners = new Map();
        const labels = { prev: 'Prethodna', next: 'Sljedeća', first: 'Sljedeća', last: 'Prethodna' };
        const window = initiallyLoaded ? { Splide: class {} } : {};
        const document = { querySelector: () => ({ addEventListener: (type, listener) => listeners.set(type, listener) }) };
        const script = [...read('resources/views/front/partials/splide-assets.blade.php').matchAll(/<script>([\s\S]*?)<\/script>/g)].at(-1)[1]
            .replace('@json($splideArrowLabels)', JSON.stringify(labels));
        vm.runInNewContext(script, { window, document });
        if (!initiallyLoaded) {
            window.Splide = class {};
            listeners.get('load')();
        }
        assert.deepEqual(JSON.parse(JSON.stringify(window.Splide.defaults.i18n)), labels);
    });
}

test('small related collections retain configured card columns at every viewport', () => {
    const element = {
        dataset: { desktopCols: '6', mobileCols: '2' },
        querySelectorAll: (selector) => selector === '.splide__slide' ? [{}, {}] : [],
    };
    let options;
    const document = {
        readyState: 'complete',
        querySelector: () => null,
        querySelectorAll: (selector) => selector === '[data-related-products-splide]' ? [element] : [],
    };
    const window = { Splide: class {
        constructor(el, value) { options = value; }
        mount() {}
    } };
    vm.runInNewContext(read('public/front-theme/scripts/product-page.js'), { document, window });
    assert.equal(options.type, 'loop');
    assert.equal(options.perPage, 6);
    assert.equal(options.arrows, true);
    assert.equal(options.breakpoints[1280].perPage, 4);
    assert.equal(options.breakpoints[1024].perPage, 3);
    assert.equal(options.breakpoints[860].perPage, 2);
    assert.equal(options.breakpoints[640].perPage, 2);
});

for (const path of [
    'public/front-theme/scripts/product-page.js',
    'resources/views/front/partials/herrera-home-carousel-script.blade.php',
]) {
    test(path + ': returning to a static grid restores original keyboard controls after every loop mount', () => {
        const controls = [null, '0', '-1'].map((tabindex) => ({
            tabindex,
            getAttribute() { return this.tabindex; },
            setAttribute(name, value) { this.tabindex = value; },
            removeAttribute() { this.tabindex = null; },
        }));
        const element = {
            dataset: { desktopCols: '6', mobileCols: '2' },
            querySelectorAll: (selector) => selector === '.splide__slide' ? [{}, {}, {}] : controls,
        };
        let extensions;
        const document = {
            readyState: 'complete',
            querySelector: () => null,
            querySelectorAll: (selector) => ['[data-related-products-splide]', '[data-herrera-home-carousel]'].includes(selector) ? [element] : [],
        };
        const window = { Splide: class { mount(value) { extensions = value; } } };
        const source = path.endsWith('.php') ? read(path).match(/<script>([\s\S]*?)<\/script>/)[1] : read(path);
        vm.runInNewContext(source, { document, window });
        for (let mount = 0; mount < 2; mount += 1) {
            controls.forEach((node) => node.setAttribute('tabindex', '-1'));
            extensions.RestoreCardGridFocus().destroy();
            assert.deepEqual(controls.map((node) => node.tabindex), [null, '0', '-1']);
        }
    });
}

function galleryPage(count) {
    const listeners = new Map();
    const opened = [];
    let options;
    const buttons = Array.from({ length: count }, (_, index) => ({
        dataset: { galleryOpen: String(index) },
        querySelector: () => ({ getAttribute: (name) => name === 'src' ? '/image-' + index + '.jpg' : 'Image ' + index }),
        getAttribute: () => '',
    }));
    const productSplide = { querySelectorAll: () => buttons };
    const galleryRoot = { setAttribute() {}, addEventListener() {} };
    const document = {
        body: { appendChild() {} },
        createElement: () => galleryRoot,
        querySelector(selector) {
            if (selector === '[data-product-detail], [data-mobile-product-gallery], [data-product-detail-form]') return {};
            if (selector === '[data-product-splide]') return productSplide;
            return null;
        },
        querySelectorAll: (selector) => selector === '[data-gallery-open]' ? buttons : [],
        addEventListener(type, listener) { listeners.set(type, listener); },
    };
    const window = {
        location: { protocol: 'https:', origin: 'https://example.test' },
        setTimeout() {},
        lightGallery: () => ({ openGallery: (index) => opened.push(index) }),
        Splide: class {
            constructor(el, value) { options = value; this.events = new Map(); }
            on(type, listener) { this.events.set(type, listener); }
            mount() { this.events.get('mounted')?.(); }
        },
    };
    vm.runInNewContext(read('public/front-theme/scripts/product-detail.js'), { document, window });
    listeners.get('DOMContentLoaded')();
    return { options, opened, click: (button) => listeners.get('click')({ target: { closest: () => button } }) };
}

test('loop gallery clones open the same lightbox item as the original slide', () => {
    const page = galleryPage(3);
    assert.equal(page.options.type, 'loop');
    page.click({ dataset: { galleryOpen: '2' } });
    assert.deepEqual(page.opened, [2]);
});

test('one gallery image remains static with no empty navigation', () => {
    const page = galleryPage(1);
    assert.equal(page.options.type, 'slide');
    assert.equal(page.options.drag, false);
    assert.equal(page.options.arrows, false);
    assert.equal(page.options.pagination, false);
});

function heroImage(index) {
    return {
        dataset: { heroLazySrc: '/hero-' + index + '.jpg', heroLazySrcset: '/hero-' + index + '-large.jpg 1600w' },
        attributes: {},
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) {
            if (name === 'data-hero-lazy-src') delete this.dataset.heroLazySrc;
            if (name === 'data-hero-lazy-srcset') delete this.dataset.heroLazySrcset;
        },
    };
}

function heroSlide(index) {
    const image = heroImage(index);
    return {
        dataset: {}, image,
        querySelectorAll(selector) {
            if (selector === '[data-hero-lazy-srcset]') return image.dataset.heroLazySrcset ? [image] : [];
            if (selector === 'img[data-hero-lazy-src]') return image.dataset.heroLazySrc ? [image] : [];
            return [];
        },
    };
}

for (const path of [
    'resources/views/front/content-blocks/types/full_width_image_slider.blade.php',
    'resources/views/front/content-blocks/instances/desktopfullwidthimageslider.blade.php',
]) {
    test(path + ': wrapping loads the correct original image and its clones', () => {
        const originalSlides = [heroSlide(0), heroSlide(1), heroSlide(2)];
        let slides = [...originalSlides];
        let slider;
        const element = {
            dataset: {},
            querySelectorAll(selector) {
                if (selector === '.splide__slide') return slides;
                const match = selector.match(/^\[data-hero-slide-index="(\d+)"\]$/);
                return match ? slides.filter((slide) => slide.dataset.heroSlideIndex === match[1]) : [];
            },
        };
        const document = {
            querySelectorAll: (selector) => selector === '[data-fullwidth-splide]' ? [element] : [],
            addEventListener() {},
        };
        const window = { Splide: class {
            constructor(el, options) { slider = this; this.options = options; this.events = new Map(); }
            on(type, listener) { this.events.set(type, listener); }
            mount() {
                const clones = originalSlides.map((original, index) => {
                    const clone = heroSlide(index);
                    clone.dataset = { ...original.dataset };
                    return clone;
                });
                slides = [...clones, ...originalSlides, ...clones.map((original, index) => {
                    const clone = heroSlide(index);
                    clone.dataset = { ...original.dataset };
                    return clone;
                })];
                this.events.get('mounted')?.();
            }
        } };
        const script = [...read(path).matchAll(/<script>([\s\S]*?)<\/script>/g)].at(-1)[1]
            .replaceAll('{{ $autoplayMs }}', '5000');
        vm.runInNewContext(script, { document, window });
        assert.equal(slider.options.type, 'loop');
        assert.equal(slides.filter((slide) => slide.dataset.heroSlideIndex === '0' && slide.image.attributes.src === '/hero-0.jpg').length, 3);
        slider.events.get('move')(-1);
        assert.equal(slides.filter((slide) => slide.dataset.heroSlideIndex === '2' && slide.image.attributes.src === '/hero-2.jpg').length, 3);
        assert.equal(originalSlides[1].image.attributes.src, undefined);
        slider.events.get('move')(4);
        assert.equal(slides.filter((slide) => slide.dataset.heroSlideIndex === '1' && slide.image.attributes.src === '/hero-1.jpg').length, 3);
    });
}
