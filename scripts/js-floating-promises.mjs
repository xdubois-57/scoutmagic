#!/usr/bin/env node
/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Floating promises in public/assets/js/ — the local half of SonarCloud's
// javascript:S9383 (« Promises should not be left unhandled », issue #944).
//
// A promise whose rejection nobody handles is an error the browser keeps to
// itself: a refused request that says nothing to the person who clicked. The
// rule reached SonarCloud's profile after the code it then flagged by the
// hundred, so the gate of `main` went red with no pull request to blame. This
// check is what keeps the next one from arriving the same way: it runs with
// `npm run typecheck`, on every pull request, before SonarCloud sees it.
//
// What counts as handled, statement by statement — the same answers the rule
// accepts:
//   - the chain ends in `.catch(onRejected)`, or in `.then(onFulfilled,
//     onRejected)` — with a handler: `.catch()` alone handles nothing;
//   - the statement is `void promise` — a deliberate « nothing to handle »,
//     which is right for ScoutMagicApi.postJson()/getJson() (they never
//     reject: see api.js) and for a function that handles its own failures;
//   - it is awaited, returned or assigned — then it is somebody else's.
// A promise on either side of `&&`, `||`, `??`, a comma or a `? :` is as
// thrown away as one standing alone, and a value typed `Promise<T> | false`
// is a promise on the branch that matters.
//
// Read with the TypeScript checker over the same tsconfig.json as the type
// check, so « is this a promise » is the compiler's answer and not a guess on
// names: a local function that returns one is caught as surely as fetch().


import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { API, SignatureKind } from 'typescript/unstable/sync';
import { SyntaxKind } from 'typescript/unstable/ast';
import {
    isAssignmentOperator,
    isAwaitExpression,
    isBinaryExpression,
    isCallExpression,
    isConditionalExpression,
    isExpressionStatement,
    isIdentifier,
    isParenthesizedExpression,
    isPropertyAccessExpression,
    isVoidExpression,
} from 'typescript/unstable/ast/is';

// TypeScript 7 (the native compiler) answers type questions through this API
// only, and calls it unstable: if an upgrade moves it, this file is what to
// adapt — never a reason to drop the check, whose job SonarCloud would then
// do alone, after the merge.

/**
 * @param {any} argument
 * @returns {boolean} whether the argument is no handler at all
 */
function isNullish(argument) {
    return argument === undefined
        || argument.kind === SyntaxKind.NullKeyword
        || (isIdentifier(argument) && argument.text === 'undefined')
        || isVoidExpression(argument);
}

/**
 * @param {any} expression
 * @returns {boolean} whether the outermost call handles a rejection
 */
function handlesRejection(expression) {
    if (!isCallExpression(expression) || !isPropertyAccessExpression(expression.expression)) {
        return false;
    }
    const method = expression.expression.name.text;

    return (method === 'catch' && !isNullish(expression.arguments[0]))
        || (method === 'then' && !isNullish(expression.arguments[1]));
}

const VALUE_PASSING_OPERATORS = new Set([
    SyntaxKind.AmpersandAmpersandToken,
    SyntaxKind.BarBarToken,
    SyntaxKind.QuestionQuestionToken,
    SyntaxKind.CommaToken,
]);

/**
 * The expressions of a statement whose value is thrown away, and so whose
 * promise, if they are one, nobody handles: `ready && fetch(url)` and
 * `ok ? save() : null` drop one as surely as `fetch(url)` does, where an
 * assignment keeps it for whoever reads the variable.
 *
 * @param {any} expression
 * @returns {any[]}
 */
function discardedOperands(expression) {
    while (isParenthesizedExpression(expression)) {
        expression = expression.expression;
    }
    if (isVoidExpression(expression) || isAwaitExpression(expression) || handlesRejection(expression)) {
        return [];
    }
    if (isBinaryExpression(expression)) {
        const operator = expression.operatorToken.kind;
        if (isAssignmentOperator(operator) || !VALUE_PASSING_OPERATORS.has(operator)) {
            return [];
        }

        return [...discardedOperands(expression.left), ...discardedOperands(expression.right)];
    }
    if (isConditionalExpression(expression)) {
        return [...discardedOperands(expression.whenTrue), ...discardedOperands(expression.whenFalse)];
    }

    return [expression];
}

/**
 * @param {any} type
 * @param {any} checker
 * @param {any} location
 * @returns {boolean}
 */
function isThenable(type, checker, location) {
    if (!type) {
        return false;
    }
    const definite = checker.getNonNullableType(type) ?? type;
    if (definite.isUnionType?.()) {
        // `Promise<T> | false`: one branch is a promise, and that is enough.
        return (definite.getTypes() ?? []).some((member) => isThenable(member, checker, location));
    }
    const then = checker.getPropertyOfType(definite, 'then');
    if (!then) {
        return false;
    }
    const thenType = checker.getTypeOfSymbolAtLocation(then, location);

    return checker.getSignaturesOfType(thenType, SignatureKind.Call).length > 0;
}

/**
 * Every unhandled promise statement of the files `include` accepts, in the
 * project of `tsconfigPath`.
 *
 * @param {string} tsconfigPath
 * @param {(fileName: string) => boolean} include
 * @returns {Array<{file: string, line: number, text: string}>}
 */
export function findFloatingPromises(tsconfigPath, include) {
    const api = new API({ cwd: path.dirname(tsconfigPath) });
    try {
        const project = api.updateSnapshot({ openProjects: [tsconfigPath] }).getProjects()[0];
        const { program, checker } = project;
        /** @type {Array<{file: string, line: number, text: string}>} */
        const found = [];

        for (const fileName of program.getSourceFileNames()) {
            if (!include(fileName)) {
                continue;
            }
            const source = program.getSourceFile(fileName);
            if (!source) {
                continue;
            }
            /** @type {any[]} */
            const candidates = [];
            /** @param {any} node */
            const visit = (node) => {
                if (isExpressionStatement(node)) {
                    for (const expression of discardedOperands(node.expression)) {
                        candidates.push({ statement: node, expression });
                    }
                }
                node.forEachChild(visit);
            };
            source.forEachChild(visit);
            if (candidates.length === 0) {
                continue;
            }

            const types = checker.getTypeAtLocation(candidates.map((candidate) => candidate.expression));
            const text = source.text ?? source.getText();
            const reported = new Set();
            candidates.forEach((candidate, index) => {
                if (reported.has(candidate.statement) || !isThenable(types[index], checker, candidate.expression)) {
                    return;
                }
                reported.add(candidate.statement);
                const start = candidate.statement.getStart(source);
                const line = text.slice(0, start).split('\n').length;
                found.push({
                    file: fileName,
                    line,
                    text: text.slice(start, candidate.statement.end).split('\n')[0].trim(),
                });
            });
        }

        return found;
    } finally {
        api.close();
    }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const repoRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
    const jsRoot = path.join(repoRoot, 'public', 'assets', 'js') + path.sep;
    const found = findFloatingPromises(
        path.join(repoRoot, 'tsconfig.json'),
        (fileName) => path.resolve(fileName).startsWith(jsRoot)
    );

    for (const { file, line, text } of found) {
        console.log(`${path.relative(repoRoot, file)}:${line}: ${text}`);
    }
    if (found.length > 0) {
        console.error(
            `\n${found.length} promise(s) left unhandled (SonarCloud javascript:S9383, issue #944). Handle the rejection `
            + 'with .catch(...) where the person must hear about it, or mark the statement `void` where nothing can '
            + 'reject or the failure is handled further down.'
        );
        process.exit(1);
    }
    console.log('No unhandled promise.');
}
