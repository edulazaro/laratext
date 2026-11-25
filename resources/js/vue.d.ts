import type { App } from 'vue';
import type { TextFunction } from './index';

export const laratext: { install(app: App): void };

declare module 'vue' {
  interface ComponentCustomProperties {
    $text: TextFunction;
  }
}
