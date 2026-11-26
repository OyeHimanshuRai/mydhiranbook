// MyDhiranBook UX layer: each module is self-contained, runs on turbo:load and cleans up after itself.
import '../../css/ux.css';
import './palette.js';
import './shortcuts.js';
import './inline-validate.js';
import './recovery.js';
import './skeleton.js';
import './bulk.js';
import { loadWhen } from '../lazy.js';

// drafts exist only on forms that ask for them
loadWhen('form[data-autosave]', () => import('./autosave.js'));
