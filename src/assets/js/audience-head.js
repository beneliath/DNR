// The workspace needs account-scoped storage and the saved theme before it paints.
// Load the shared startup helpers in one request, in their original order.
import './page-loading.js';
import './account-context.js';
import './theme-init.js';
import userTime from './user-time.js';
import './form-drafts.js';

window.DnrUserTime = userTime;
