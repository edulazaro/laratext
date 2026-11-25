import { usePage } from '@inertiajs/vue3';
import { textFromProps } from '../index.js';

/**
 * text() for <script setup>, reading the current page's props on each call
 * so it follows Inertia visits.
 */
export function useText() {
  const page = usePage();

  return (key, source, replace) => textFromProps(page.props)(key, source, replace);
}

/**
 * Registers $text for templates, reading the page of the component rendering it.
 */
export const laratext = {
  install(app) {
    app.config.globalProperties.$text = function (key, source, replace) {
      return textFromProps(this.$page?.props)(key, source, replace);
    };
  },
};
