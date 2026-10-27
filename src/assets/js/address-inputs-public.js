import { initializeAddressInputs } from './address-inputs.mjs';

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initializeAddressInputs());
} else {
    initializeAddressInputs();
}
