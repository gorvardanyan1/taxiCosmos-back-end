import fs from 'node:fs';
import path from 'node:path';
import ts from 'typescript';

/**
 * Static checks behind the "no hardcoded user-visible strings" rule (P13-T11), shared by the Vitest
 * guard and the key-coverage test. Visible text must go through t() / tc() (inside components) or be
 * marked with tk() (module-level constants that are translated where they are rendered).
 */

export const SOURCE_ROOT = path.resolve(import.meta.dirname, '..');

/** JSX attributes whose string value is shown to the user (or read out by a screen reader). */
const VISIBLE_ATTRIBUTES = new Set([
    'placeholder', 'title', 'aria-label', 'alt', 'label', 'description', 'confirmLabel', 'allLabel',
    'searchPlaceholder', 'resultLabel', 'confirm', 'hint', 'message', 'subtitle', 'text', 'tooltip', 'emptyLabel', 'error',
]);

/** Object keys whose string value ends up on screen (columns, menu items, cards, empty states). */
const VISIBLE_KEYS = new Set([
    'label', 'title', 'header', 'description', 'message', 'placeholder', 'hint', 'allLabel', 'body', 'subtitle',
    'text', 'tooltip', 'emptyLabel', 'caption', 'heading', 'confirmLabel', 'searchPlaceholder',
]);

/** Translation calls: their first argument is a key, never hardcoded text. */
export const TRANSLATION_FUNCTIONS = new Set(['t', 'tc', 'tk', 'tn']);

/** Words and symbols that are the same in every language (brands, units, codes). */
const UNIVERSAL = /^(\{.*\}|[A-Z][a-z]+\/[A-Za-z_]+|[A-Z0-9-]{4,}|English|Русский|Հայերեն|TaxiKosmos|Stripe|Authorize\.Net|PayPal|Google|Google Maps|2GIS|Leaflet|Horizon|Apple|Facebook|Visa|Mastercard|Amex|AMD|USD|EUR|RUB|GEL|SMS|PDF|IMG|CSV|ID|API|URL|IBAN|SWIFT|OTP|2FA|QR|UTC|GMT|ETA|km|min|h|s|m|d|x|vs|N\/A|[A-Z]{1,4}-?\d*|⌘K|ESC)$/;

export interface Finding {
    file: string;
    line: number;
    text: string;
    rule: string;
    /** Source range of the offending node (used by the codemod and to de-duplicate). */
    start: number;
    end: number;
}

export function sourceFiles(root = SOURCE_ROOT): string[] {
    const out: string[] = [];
    const walk = (dir: string) => {
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            const full = path.join(dir, entry.name);
            if (entry.isDirectory()) {
                if (!['test', 'types'].includes(entry.name)) walk(full);
            } else if (/\.(ts|tsx)$/.test(entry.name) && !/\.test\.(ts|tsx)$/.test(entry.name) && !entry.name.endsWith('.d.ts')) {
                out.push(full);
            }
        }
    };
    walk(root);
    return out.sort();
}

const hasWords = (text: string): boolean => /\p{L}{2,}/u.test(text);
const visible = (text: string): boolean => {
    const trimmed = text.replace(/\s+/g, ' ').trim();
    if (trimmed === '' || !hasWords(trimmed) || UNIVERSAL.test(trimmed)) return false;

    // "TaxiKosmos · AMD": nothing to translate when every piece is a brand, a code or a symbol.
    return trimmed.split(/\s*[·|/•–—-]\s*|\s+/).some((token) => hasWords(token) && !UNIVERSAL.test(token));
};

function calleeName(call: ts.CallExpression): string {
    const callee = call.expression;
    if (ts.isIdentifier(callee)) return callee.text;
    if (ts.isPropertyAccessExpression(callee)) return callee.name.text;
    return '';
}

function insideTranslationCall(node: ts.Node): boolean {
    for (let current = node.parent; current; current = current.parent) {
        if (ts.isCallExpression(current) && TRANSLATION_FUNCTIONS.has(calleeName(current)) && current.arguments.some((arg) => arg.pos <= node.pos && node.end <= arg.end)) {
            return true;
        }
    }
    return false;
}

/** Literals that are data, not text: comparisons, switch cases, object keys, call arguments, element access. */
function isDataPosition(node: ts.Node): boolean {
    const parent = node.parent;
    if (!parent) return false;
    if (ts.isBinaryExpression(parent) && [ts.SyntaxKind.EqualsEqualsEqualsToken, ts.SyntaxKind.ExclamationEqualsEqualsToken, ts.SyntaxKind.EqualsEqualsToken, ts.SyntaxKind.ExclamationEqualsToken, ts.SyntaxKind.InKeyword].includes(parent.operatorToken.kind)) return true;
    if (ts.isCaseClause(parent) || ts.isElementAccessExpression(parent) || ts.isPropertyAssignment(parent) && parent.name === node) return true;
    if (ts.isCallExpression(parent) && parent.arguments.includes(node as ts.Expression)) {
        return !['confirm', 'alert', 'prompt'].includes(calleeName(parent));
    }
    return false;
}

/** Text-bearing literals inside an expression that ends up rendered (ternaries, ||, ??, templates). */
function collectRendered(expression: ts.Node, report: (node: ts.Node, text: string) => void): void {
    const visit = (node: ts.Node) => {
        if (ts.isCallExpression(node)) {
            const name = calleeName(node);
            if (TRANSLATION_FUNCTIONS.has(name)) return;
            if (['confirm', 'alert', 'prompt'].includes(name)) node.arguments.forEach(visit);
            return; // other calls: their arguments are data (label(), formatDate(), …)
        }
        if (ts.isJsxElement(node) || ts.isJsxSelfClosingElement(node) || ts.isJsxFragment(node)) return; // handled by the JSX walk
        if (ts.isArrowFunction(node) || ts.isFunctionExpression(node)) return;
        if ((ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) && !isDataPosition(node)) {
            if (visible(node.text)) report(node, node.text);
            return;
        }
        if (ts.isTemplateExpression(node)) {
            const text = [node.head.text, ...node.templateSpans.map((span) => span.literal.text)].join(' ');
            if (visible(text)) report(node, node.getText());
            node.templateSpans.forEach((span) => visit(span.expression));
            return;
        }
        if (ts.isBinaryExpression(node) && [ts.SyntaxKind.EqualsEqualsEqualsToken, ts.SyntaxKind.ExclamationEqualsEqualsToken].includes(node.operatorToken.kind)) return;
        ts.forEachChild(node, visit);
    };
    visit(expression);
}

/**
 * Looks like words for a person rather than a code or a CSS class: a capitalised word ("Save",
 * "Driver's license") or a sentence ending ("in progress…"). Catches text kept in arrays and lookup
 * objects that are rendered later through a variable.
 */
function isProse(text: string): boolean {
    const trimmed = text.trim();
    if (!visible(trimmed) || /^[a-z0-9_.:/#?=&{}-]+$/i.test(trimmed) && !/[A-Z][a-z]/.test(trimmed)) return false;
    if (/^(https?:|\/|\.\/|@\/)/.test(trimmed)) return false;
    return /(^|[\s(])[A-Z\u0400-\u04FF\u0531-\u0556][a-z\u0430-\u044F\u0561-\u0587']+/.test(trimmed) || /[a-z…][.?!…]$/.test(trimmed);
}

/** className, style, href, key, … : never text. */
function isNonVisibleAttribute(node: ts.Node): boolean {
    for (let current: ts.Node | undefined = node.parent; current; current = current.parent) {
        if (ts.isJsxAttribute(current)) return !VISIBLE_ATTRIBUTES.has(current.name.getText());
        if (ts.isPropertyAssignment(current) && ['className', 'style', 'href', 'key', 'id', 'type', 'method', 'url', 'fontFamily', 'background', 'color'].includes(current.name.getText())) return true;
        if (ts.isCallExpression(current) || ts.isJsxElement(current) || ts.isFunctionLike(current)) return false;
    }
    return false;
}

function isImportSpecifier(node: ts.Node): boolean {
    return ts.isImportDeclaration(node.parent) || ts.isExportDeclaration(node.parent) || ts.isExternalModuleReference(node.parent) || ts.isLiteralTypeNode(node.parent);
}

export function scanFile(file: string): Finding[] {
    return scanText(path.relative(SOURCE_ROOT, file), fs.readFileSync(file, 'utf8'));
}

/** The same check on source text (used to test the scanner itself). `name` ends in .tsx or .ts. */
export function scanText(name: string, text: string): Finding[] {
    const source = ts.createSourceFile(name, text, ts.ScriptTarget.Latest, true, name.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS);
    const findings: Finding[] = [];
    const rel = name;
    const report = (rule: string) => (node: ts.Node, text: string) => {
        if (insideTranslationCall(node)) return;
        if (findings.some((f) => f.start === node.getStart() && f.end === node.getEnd())) return;
        findings.push({ file: rel, line: source.getLineAndCharacterOfPosition(node.getStart()).line + 1, text: text.replace(/\s+/g, ' ').trim(), rule, start: node.getStart(), end: node.getEnd() });
    };

    const visit = (node: ts.Node) => {
        if (ts.isJsxText(node) && visible(node.text)) {
            report('jsx-text')(node, node.text);
        } else if (ts.isJsxAttribute(node) && VISIBLE_ATTRIBUTES.has(node.name.getText()) && node.initializer) {
            const init = node.initializer;
            if (ts.isStringLiteral(init)) {
                if (visible(init.text)) report('jsx-attribute')(init, init.text);
            } else if (ts.isJsxExpression(init) && init.expression) {
                collectRendered(init.expression, report('jsx-attribute'));
            }
        } else if (ts.isJsxExpression(node) && node.parent && (ts.isJsxElement(node.parent) || ts.isJsxFragment(node.parent)) && node.expression) {
            collectRendered(node.expression, report('jsx-child'));
        } else if (ts.isPropertyAssignment(node) && VISIBLE_KEYS.has(node.name.getText().replace(/['"]/g, ''))) {
            collectRendered(node.initializer, report('visible-property'));
        } else if ((ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) && isProse(node.text) && !isDataPosition(node) && !isNonVisibleAttribute(node) && !isImportSpecifier(node)) {
            report('prose-literal')(node, node.text);
        } else if (ts.isCallExpression(node) && TRANSLATION_FUNCTIONS.has(calleeName(node)) && calleeName(node) !== 'tk') {
            // t()/tc() read the loaded dictionary: calling them at module level would freeze English.
            let inFunction = false;
            for (let current = node.parent; current; current = current.parent) {
                if (ts.isFunctionLike(current)) { inFunction = true; break; }
            }
            if (!inFunction) findings.push({ file: rel, line: source.getLineAndCharacterOfPosition(node.getStart()).line + 1, text: node.getText(), rule: 'module-level-t', start: node.getStart(), end: node.getEnd() });
        }
        ts.forEachChild(node, visit);
    };
    visit(source);

    return findings;
}

/** Every key passed as a literal to t(), tc() or tk() in the admin source. */
export function usedKeys(files = sourceFiles()): Map<string, string> {
    const keys = new Map<string, string>();
    for (const file of files) {
        const source = ts.createSourceFile(file, fs.readFileSync(file, 'utf8'), ts.ScriptTarget.Latest, true, file.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS);
        const visit = (node: ts.Node) => {
            if (ts.isCallExpression(node) && TRANSLATION_FUNCTIONS.has(calleeName(node)) && node.arguments[0]) {
                const first = node.arguments[0];
                if (ts.isStringLiteral(first) || ts.isNoSubstitutionTemplateLiteral(first)) {
                    keys.set(first.text, `${path.relative(SOURCE_ROOT, file)}:${source.getLineAndCharacterOfPosition(node.getStart()).line + 1}`);
                } else {
                    keys.set(`<non-literal key> ${first.getText()}`, `${path.relative(SOURCE_ROOT, file)}:${source.getLineAndCharacterOfPosition(node.getStart()).line + 1}`);
                }
            }
            ts.forEachChild(node, visit);
        };
        visit(source);
    }
    return keys;
}
