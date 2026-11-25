export type ReplacementValue = string | number | boolean | null | undefined | ((inner: string) => string);

export type Replacements = Record<string, ReplacementValue>;

/** What Text::frontend() returns and @texts prints. */
export interface TextPayload {
  locale: string | null;
  lines: Record<string, string>;
}

export type TextSource = TextPayload | null | undefined | TextSource[];

export type TextFunction = (key: string, source?: string, replace?: Replacements) => string;

export function configure(options?: { props?: string | string[] }): void;

export function configuredProps(): string[];

export function createText(...sources: TextSource[]): TextFunction;

export function textFromProps(props: Record<string, unknown> | null | undefined): TextFunction;

export const text: TextFunction;

export function setTexts(...sources: TextSource[]): void;

export function refreshTexts(): void;

export function translate(
  lines: Record<string, string>,
  locale: string | null,
  key: string,
  source?: string,
  replace?: Replacements,
): string;

export function makeReplacements(line: string, replace?: Replacements): string;

export function choose(line: string, number: number, locale: string | null): string;
