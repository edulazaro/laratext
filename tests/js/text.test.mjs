import { test } from 'node:test';
import assert from 'node:assert/strict';

const scripts = [];

globalThis.document = {
  documentElement: { lang: 'ca-ES' },
  listeners: {},
  reads: 0,
  addEventListener(name, listener) {
    (this.listeners[name] ??= []).push(listener);
  },
  dispatch(name) {
    for (const listener of this.listeners[name] ?? []) listener();
  },
  querySelectorAll(selector) {
    assert.equal(selector, 'script[data-laratext]');
    this.reads++;
    return scripts;
  },
};

const tag = (payload) => ({ textContent: JSON.stringify(payload) });

const { text, createText, setTexts, refreshTexts, configure, textFromProps } = await import('../../resources/js/index.js');

test('text() reads the @texts tags of the page, the later one winning', () => {
  scripts.push(tag({ locale: 'ca', lines: { 'header.search': 'Cerca', 'common.save': 'Desa' } }));
  scripts.push(tag({ locale: 'ca', lines: { 'common.save': 'Guarda' } }));
  refreshTexts();

  assert.equal(text('header.search', 'Buscar'), 'Cerca');
  assert.equal(text('common.save', 'Guardar'), 'Guarda');
  assert.equal(text('missing', 'Texto de origen'), 'Texto de origen');
});

test('the page is read once, and again after a Livewire navigation', () => {
  refreshTexts();
  const before = document.reads;

  text('header.search', 'Buscar');
  text('header.search', 'Buscar');
  assert.equal(document.reads, before + 1);

  scripts.length = 0;
  scripts.push(tag({ locale: 'ca', lines: { 'header.search': 'Cerca ara' } }));
  document.dispatch('livewire:navigated');

  assert.equal(text('header.search', 'Buscar'), 'Cerca ara');
  assert.equal(document.reads, before + 2);
});

test('without a locale in the tags, plurals follow the html lang attribute', () => {
  scripts.length = 0;
  scripts.push(tag({ lines: {} }));
  refreshTexts();

  assert.equal(text('items', 'un element|:count elements', { count: 1 }), 'un element');
  assert.equal(text('items', 'un element|:count elements', { count: 4 }), '4 elements');
});

test('setTexts() replaces what text() reads', () => {
  setTexts({ locale: 'es', lines: { greeting: 'Hola :name' } });

  assert.equal(text('greeting', 'Hello :name', { name: 'Ana' }), 'Hola Ana');
});

test('a missing, empty or self-named line falls back to the source text', () => {
  const t = createText({ locale: 'es', lines: { empty: '', same: 'same', ok: 'Vale' } });

  assert.equal(t('ok', 'Okay'), 'Vale');
  assert.equal(t('missing', 'Source'), 'Source');
  assert.equal(t('missing'), '');
  assert.equal(t('empty', 'Source'), 'Source');
  assert.equal(t('same', 'Source'), 'Source');
});

test('plural forms come from the stored line or from the source text', () => {
  const t = createText({ locale: 'es', lines: { comments: 'un comentario|:count comentarios' } });

  assert.equal(t('comments', 'one comment|:count comments', { count: 1 }), 'un comentario');
  assert.equal(t('comments', 'one comment|:count comments', { count: 3 }), '3 comentarios');
  assert.equal(t('new', 'una cosa|:count cosas', { count: 2 }), '2 cosas');
  assert.equal(t('new', '{0} nada|[1,*] :count cosas', { count: 0 }), 'nada');
  assert.equal(t('new', 'Ctrl|Alt'), 'Ctrl|Alt');
});

test('plurals follow the current Laravel 13: negative counts and spaces inside conditions', () => {
  const t = createText({ locale: 'en', lines: {} });

  assert.equal(t('x', 'one degree|:count degrees', { count: -1 }), 'one degree');
  assert.equal(t('x', 'one degree|:count degrees', { count: -3 }), '-3 degrees');
  assert.equal(t('x', '{-1} minus one|other', { count: -1 }), 'minus one');
  assert.equal(t('x', ' { 0 } none | [ 1,* ] :count items', { count: 0 }), 'none');
  assert.equal(t('x', ' { 0 } none | [ 1,* ] :count items', { count: 4 }), '4 items');
});

test('replacements follow Laravel', () => {
  const t = createText({ locale: 'es', lines: {} });

  assert.equal(t('x', ':name :Name :NAME', { name: 'ana' }), 'ana Ana ANA');
  assert.equal(t('x', ':name / :name_full', { name: 'Ana', name_full: 'Ana López' }), 'Ana / Ana López');
  assert.equal(t('x', 'Lee los <link>términos</link>', { link: (inner) => `<a>${inner}</a>` }), 'Lee los <a>términos</a>');
  assert.equal(t('x', 'Vacío: [:value]', { value: null }), 'Vacío: []');
});

test('createText() merges payloads in order and ignores empty ones', () => {
  const t = createText({ locale: 'es', lines: { a: 'A1', b: 'B1' } }, null, [{ locale: 'ca', lines: { b: 'B2' } }], undefined);

  assert.equal(t('a', ''), 'A1');
  assert.equal(t('b', ''), 'B2');
});

test('textFromProps() reads the configured props and keeps the same function while they do not change', () => {
  const shared = { locale: 'ca', lines: { 'header.search': 'Cerca' } };
  const page = { locale: 'ca', lines: { 'igi.rate': 'Taxa' } };

  configure({ props: ['texts', 'pageTexts'] });

  const first = textFromProps({ texts: shared, pageTexts: page, other: 1 });
  assert.equal(first('header.search', 'Buscar'), 'Cerca');
  assert.equal(first('igi.rate', 'Tasa'), 'Taxa');
  assert.equal(textFromProps({ texts: shared, pageTexts: page }), first);

  const next = textFromProps({ texts: shared, pageTexts: { locale: 'ca', lines: {} } });
  assert.notEqual(next, first);
  assert.equal(next('igi.rate', 'Tasa'), 'Tasa');

  configure({ props: 'texts' });
  assert.equal(textFromProps({ texts: shared, pageTexts: page })('igi.rate', 'Tasa'), 'Tasa');
});
