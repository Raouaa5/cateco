/**
 * Cateco Navbar — Interactive Behaviours
 * All functions are exposed on `window` so inline onclick="" attributes work.
 *
 * Handles: mobile nav drawer, categories drawer + accordion, cart sidebar,
 *          wishlist modal, and registration email pre-fill.
 */

/* ═══════════════════════════════════════════════════════════════════════════════
   MOBILE NAV DRAWER  (hamburger ☰ → slide-in panel)
   ═══════════════════════════════════════════════════════════════════════════════ */

window.openMobileNav = function () {
    document.getElementById('mobile-nav-drawer').classList.add('open');
    document.getElementById('mobile-nav-overlay').classList.add('open');
    document.body.style.overflow = 'hidden';
};

window.closeMobileNav = function () {
    document.getElementById('mobile-nav-drawer').classList.remove('open');
    document.getElementById('mobile-nav-overlay').classList.remove('open');
    document.body.style.overflow = '';
};

/* ═══════════════════════════════════════════════════════════════════════════════
   MOBILE CATEGORIES DRAWER  (≤ 1024 px)
   ═══════════════════════════════════════════════════════════════════════════════ */

window.openCatDrawer = function () {
    document.getElementById('cat-drawer').classList.add('open');
    document.getElementById('cat-drawer-overlay').classList.add('open');
    document.body.style.overflow = 'hidden';
};

window.closeCatDrawer = function () {
    document.getElementById('cat-drawer').classList.remove('open');
    document.getElementById('cat-drawer-overlay').classList.remove('open');
    document.body.style.overflow = '';
};

/**
 * Toggle a categories accordion item.
 * @param {string} id  id of the .cat-accordion-item element
 */
window.toggleCatAccordion = function (id) {
    const item = document.getElementById(id);
    if (item) item.classList.toggle('open');
};

/* ═══════════════════════════════════════════════════════════════════════════════
   CART SIDEBAR DRAWER
   ═══════════════════════════════════════════════════════════════════════════════ */

window.toggleCartSidebar = function () {
    const route   = document.body.dataset.route || '';

    // On checkout pages go directly to cart instead of opening sidebar
    if (route.startsWith('sylius_shop_checkout')) {
        const cartUrl = document.getElementById('cart-toggle-btn').dataset.cartUrl;
        window.location.href = cartUrl;
        return;
    }

    const sidebar = document.getElementById('cart-sidebar');
    const overlay = document.getElementById('cart-overlay');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('open');
    document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
};

/**
 * Optimistic cart item removal — animates row out immediately, then POSTs to our custom Sylius endpoint.
 * @param {string|number} itemId
 * @param {HTMLElement}   btn     the remove button (used to find the row)
 */
window.removeCartItem = function (itemId, btn) {
    const row = btn.closest('.cart-item-row');

    if (row) {
        btn.disabled = true;
        btn.style.opacity = '0.4';
        row.style.transition = 'opacity 0.2s, transform 0.2s';
        row.style.opacity    = '0';
        row.style.transform  = 'translateX(20px)';
    }

    const locale  = document.documentElement.lang || 'fr';
    const cartUrl = '/' + locale + '/cart/remove-item/' + itemId;

    fetch(cartUrl, {
        method  : 'POST',
        headers : { 'X-Requested-With': 'XMLHttpRequest' },
        redirect: 'follow',
    })
    .finally(() => {
        // ALWAYS reload the page so the cart totals, mini-badge, and sidebar synchronize fully.
        setTimeout(() => {
            window.location.reload();
        }, 150);
    });
};

/* ═══════════════════════════════════════════════════════════════════════════════
   WISHLIST MODAL
   ═══════════════════════════════════════════════════════════════════════════════ */

window.showLoginModal = function () {
    document.getElementById('wishlist-login-modal').classList.add('active');
};

window.hideLoginModal = function () {
    document.getElementById('wishlist-login-modal').classList.remove('active');
};

/* ═══════════════════════════════════════════════════════════════════════════════
   REGISTRATION EMAIL AUTO-FILL  (from newsletter redirect query param)
   ═══════════════════════════════════════════════════════════════════════════════ */

document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    const email  = params.get('email');

    if (email && (window.location.pathname.includes('/register') || window.location.pathname.includes('/inscription'))) {
        const emailInput = document.querySelector('input[type="email"][name$="[email]"]');
        if (emailInput) emailInput.value = email;
    }
});
