import { page } from '@inertiajs/svelte';
import { get } from 'svelte/store';
import { textFromProps } from '../index.js';

/**
 * text() over the current page's props. Inertia 2 exposes the page as a
 * store and Inertia 3 as a $state object; both are read on each call, so it
 * follows Inertia visits.
 */
export function useText() {
  return (key, source, replace) => {
    const current = typeof page.subscribe === 'function' ? get(page) : page;

    return textFromProps(current?.props)(key, source, replace);
  };
}
