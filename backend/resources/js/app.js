import Alpine from 'alpinejs';

// Alpine drives the marketing surface only: the landing page's mobile nav. The
// product itself is the Flutter client on its own host, so nothing here is
// application state.
window.Alpine = Alpine;

Alpine.start();
