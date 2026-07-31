import Alpine from 'alpinejs';

import initHero from './hero-monitor';

// This bundle serves the MARKETING surface only. The product itself is the Flutter
// client on its own host, so nothing here is application state, and the public status
// page loads the stylesheet but NOT this file, so nothing that page depends on may
// end up here.
window.Alpine = Alpine;

Alpine.start();

initHero();
