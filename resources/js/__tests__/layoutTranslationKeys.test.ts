import { readdirSync, readFileSync, statSync } from 'fs';
import { join, resolve } from 'path';
import { describe, expect, it } from 'vitest';

const ROOT = resolve(__dirname, '../../..');
const PREFIX = 'g7plus-paypal.';

function collectFiles(dir: string, ext: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        return statSync(path).isDirectory() ? collectFiles(path, ext) : path.endsWith(ext) ? [path] : [];
    });
}

function lookup(dictionary: Record<string, unknown>, key: string): unknown {
    return key.split('.').reduce<unknown>(
        (node, part) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined),
        dictionary,
    );
}

function usedKeys(): string[] {
    const sources = [
        ...collectFiles(join(ROOT, 'resources/layouts'), '.g7.html'),
        ...collectFiles(join(ROOT, 'resources/extensions'), '.g7.html'),
        ...collectFiles(join(ROOT, 'resources/js/handlers'), '.ts'),
    ];
    const keys = new Set<string>();

    for (const file of sources) {
        const text = readFileSync(file, 'utf8');
        for (const match of text.matchAll(/\$t:g7plus-paypal\.([A-Za-z0-9_.]+)/g)) {
            keys.add(match[1].replace(/\.$/, ''));
        }
        for (const match of text.matchAll(/\bt\('([A-Za-z0-9_.]+)'/g)) {
            keys.add(match[1]);
        }
    }

    return [...keys].sort();
}

describe('layout translation keys', () => {
    const keys = usedKeys();

    it('finds keys to check', () => {
        expect(keys.length).toBeGreaterThan(20);
    });

    for (const locale of ['ko', 'en']) {
        it(`every ${PREFIX}* key used in layouts exists in ${locale}.json`, () => {
            const dictionary = JSON.parse(readFileSync(join(ROOT, `resources/lang/${locale}.json`), 'utf8'));
            const missing = keys.filter((key) => typeof lookup(dictionary, key) !== 'string');

            expect(missing).toEqual([]);
        });
    }

    it('ko and en dictionaries have the same shape', () => {
        const flatten = (node: unknown, prefix = ''): string[] =>
            node && typeof node === 'object'
                ? Object.entries(node as Record<string, unknown>).flatMap(([k, v]) => flatten(v, prefix ? `${prefix}.${k}` : k))
                : [prefix];

        const ko = flatten(JSON.parse(readFileSync(join(ROOT, 'resources/lang/ko.json'), 'utf8'))).sort();
        const en = flatten(JSON.parse(readFileSync(join(ROOT, 'resources/lang/en.json'), 'utf8'))).sort();

        expect(en).toEqual(ko);
    });
});
