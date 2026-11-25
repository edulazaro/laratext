import { usePage } from '@inertiajs/react';
import { textFromProps } from '../index.js';

/**
 * text() over the current page's props. Read from the page being rendered,
 * never from a module-level dictionary: SSR renders several requests in the
 * same process.
 */
export function useText() {
  return textFromProps(usePage().props);
}
