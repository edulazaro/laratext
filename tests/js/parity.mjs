// Run by JavascriptTest: computes in JavaScript the cases PHP computed, for comparison.
import { readFileSync } from 'node:fs';
import { choose, makeReplacements, translate } from '../../resources/js/index.js';

const cases = JSON.parse(readFileSync(process.argv[2], 'utf8'));

process.stdout.write(JSON.stringify({
  choose: cases.choose.map(([line, number, locale]) => choose(line, number, locale)),
  replace: cases.replace.map(([line, replace]) => makeReplacements(line, replace)),
  translate: cases.translate.map(([lines, locale, key, source, replace]) => translate(lines, locale, key, source, replace)),
}));
