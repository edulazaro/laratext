import { text } from './index.js';

/**
 * Alpine.plugin(laratext) gives every component $text('key', 'Source').
 */
export default function laratext(Alpine) {
  Alpine.magic('text', () => text);
}
