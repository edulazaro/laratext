import { pluralIndex } from './plural.js';

const settings = { props: ['texts'] };

let documentTexts = null;

/**
 * The page props an Inertia adapter reads the texts from, merged in order.
 */
export function configure({ props } = {}) {
  if (props) {
    settings.props = Array.isArray(props) ? props : [props];
  }
}

export function configuredProps() {
  return settings.props;
}

/**
 * A text() function over one or more payloads, each one { locale, lines }
 * as Text::frontend() returns it. Later payloads win.
 */
export function createText(...sources) {
  const { locale, lines } = merge(sources);

  return (key, source = '', replace = {}) => translate(lines, locale, key, source, replace);
}

let lastSources = [];
let lastText = createText();

/**
 * createText() over the configured page props, rebuilt only when they change.
 * Used by the Inertia adapters, which call it on every text() call.
 */
export function textFromProps(props) {
  const sources = settings.props.map((name) => props?.[name]);

  if (sources.length !== lastSources.length || sources.some((source, i) => source !== lastSources[i])) {
    lastSources = sources;
    lastText = createText(...sources);
  }

  return lastText;
}

/**
 * text() over the payloads printed by @texts in the page. Read on the first
 * call and again after a Livewire or Turbo navigation swaps the page.
 */
export function text(key, source = '', replace = {}) {
  const { lines, locale } = documentTexts ?? (documentTexts = readDocument());

  return translate(lines, locale, key, source, replace);
}

/**
 * Replaces the texts text() reads, for pages that do not print @texts.
 */
export function setTexts(...sources) {
  documentTexts = merge(sources);
}

export function refreshTexts() {
  documentTexts = null;
}

if (typeof document !== 'undefined') {
  document.addEventListener('livewire:navigated', refreshTexts);
  document.addEventListener('turbo:load', refreshTexts);
}

function readDocument() {
  if (typeof document === 'undefined') {
    return { locale: null, lines: {} };
  }

  const payloads = [...document.querySelectorAll('script[data-laratext]')].map((el) => JSON.parse(el.textContent || '{}'));
  const merged = merge(payloads);

  merged.locale ??= (document.documentElement.lang || '').replace('-', '_') || null;

  return merged;
}

function merge(sources) {
  let locale = null;
  const lines = {};

  for (const source of sources.flat()) {
    if (!source) {
      continue;
    }

    locale = source.locale ?? locale;
    Object.assign(lines, source.lines ?? {});
  }

  return { locale, lines };
}

/**
 * The same choice Text::get() makes in PHP: the stored line when there is
 * one, the source text otherwise, then plural forms and replacements.
 */
export function translate(lines, locale, key, source = '', replace = {}) {
  replace = replace ?? {};

  const line = Object.prototype.hasOwnProperty.call(lines, key) ? lines[key] : null;

  // __() treats an empty line, or one equal to its key, as missing.
  if (typeof line === 'string' && line !== '' && line !== key) {
    if (isNumeric(replace.count) && line.includes('|')) {
      return makeReplacements(choose(line, Number(replace.count), locale), replace);
    }

    return makeReplacements(line, replace);
  }

  let value = source ?? key;

  if (value.includes('|') && isNumeric(replace.count)) {
    value = choose(value, Math.trunc(Number(replace.count)), locale);
  }

  return makeReplacements(value, replace);
}

/**
 * Laravel's Translator::makeReplacements(): :name, :Name and :NAME, longest
 * placeholder first, and a function value wraps <name>...</name>.
 */
export function makeReplacements(line, replace = {}) {
  const entries = Object.entries(replace ?? {});

  if (entries.length === 0) {
    return line;
  }

  const map = {};

  for (const [key, raw] of entries) {
    if (typeof raw === 'function') {
      line = line.replace(new RegExp(`<${key}>(.*?)</${key}>`, 'g'), (_, inner) => raw(inner));
      continue;
    }

    const value = raw === null || raw === undefined ? '' : String(raw);

    map[`:${ucfirst(key)}`] = ucfirst(value);
    map[`:${key.toUpperCase()}`] = value.toUpperCase();
    map[`:${key}`] = value;
  }

  return strtr(line, map);
}

/**
 * Laravel's MessageSelector::choose(), as in the current Laravel 13: spaces
 * allowed inside { } and [ ], and a negative count read by its absolute value.
 */
export function choose(line, number, locale) {
  let segments = line.split('|');

  for (const part of segments) {
    const value = extractFromString(part, number);

    if (value !== null) {
      return phpTrim(value);
    }
  }

  segments = segments.map((part) => part.replace(/^[ \t\n\v\f\r]*[{[][ \t\n\v\f\r]*[-?\d|*,.*]*[ \t\n\v\f\r]*[}\]]/, ''));

  const index = pluralIndex(locale, number);

  if (segments.length === 1 || segments[index] === undefined) {
    return segments[0];
  }

  return segments[index];
}

function extractFromString(part, number) {
  const matches = part.match(/^[ \t\n\v\f\r]*[{[][ \t\n\v\f\r]*([-?\d|*,.*]*)[ \t\n\v\f\r]*[}\]]([\s\S]*)$/);

  if (!matches) {
    return null;
  }

  const [, condition, value] = matches;

  const comma = condition.indexOf(',');

  if (comma !== -1) {
    const from = condition.slice(0, comma);
    const to = condition.slice(comma + 1);

    if (to === '*' && compare(number, from) >= 0) {
      return value;
    } else if (from === '*' && compare(number, to) <= 0) {
      return value;
    } else if (compare(number, from) >= 0 && compare(number, to) <= 0) {
      return value;
    }
  }

  return compare(number, condition) === 0 ? value : null;
}

/**
 * PHP 8 comparison of a number with a string: numerically when the string
 * is numeric, otherwise as strings.
 */
function compare(number, string) {
  if (isNumeric(string)) {
    const other = Number(string);

    return number < other ? -1 : number > other ? 1 : 0;
  }

  const left = phpNumberToString(number);

  return left < string ? -1 : left > string ? 1 : 0;
}

function phpNumberToString(number) {
  return Number.isInteger(number) ? String(number) : String(number).replace('e+', 'E+').replace('e-', 'E-');
}

function isNumeric(value) {
  if (typeof value === 'number') {
    return Number.isFinite(value);
  }

  return typeof value === 'string' && /^[ \t\n\r\v\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?[ \t\n\r\v\f]*$/.test(value);
}

function ucfirst(value) {
  const [first = '', ...rest] = value;

  return first.toUpperCase() + rest.join('');
}

function strtr(subject, map) {
  const keys = Object.keys(map)
    .filter((key) => key !== '')
    .sort((a, b) => b.length - a.length);

  let result = '';
  let position = 0;

  outer: while (position < subject.length) {
    for (const key of keys) {
      if (subject.startsWith(key, position)) {
        result += map[key];
        position += key.length;
        continue outer;
      }
    }

    result += subject[position];
    position++;
  }

  return result;
}

function phpTrim(value) {
  return value.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
}
