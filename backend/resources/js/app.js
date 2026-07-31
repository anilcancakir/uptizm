import Alpine from 'alpinejs';

import initReveal from './reveal';

// This bundle serves the MARKETING surface only: the landing page's mobile nav
// disclosure and its scroll reveal. The product itself is the Flutter client on
// its own host, so nothing here is application state. The public status page
// loads the stylesheet but NOT this file, so nothing that page depends on may
// end up here.
window.Alpine = Alpine;

Alpine.start();

initReveal();
