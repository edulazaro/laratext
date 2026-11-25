import { text } from './index.js';

/**
 * Registers $text for templates, over the texts printed by @texts.
 */
export const laratext = {
  install(app) {
    app.config.globalProperties.$text = text;
  },
};
