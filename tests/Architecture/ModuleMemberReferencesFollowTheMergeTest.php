<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Core\Member\Duplicate\MemberMergeListener;
use Core\Member\Duplicate\RepointingMergeListener;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1462: a module's rows written under the absorbed identity of a
 * merge vanished from view, and the merge screen said nothing of it. Every
 * module whose schema holds a member — a `…member_id` column, or a foreign
 * key onto `members(id)` — now either ships a MemberMergeListener the
 * composition root registers, or says in its module.json
 * (`member_merge_stays`) what stays behind, which the merge screen lists.
 * A RepointingMergeListener is held to more: each of the module's member
 * columns is in its UNIQUE or PLAIN list.
 */
final class ModuleMemberReferencesFollowTheMergeTest extends TestCase
{
    /** @return array<string, list<string>> module id => sorted list of « table.column » */
    private static function memberColumnsByModule(): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        foreach (glob($root . '/modules/*/schema.sql') ?: [] as $file) {
            $module = basename(dirname($file));
            $schema = (string) preg_replace('/--[^\n]*/', '', (string) file_get_contents($file));
            preg_match_all(
                '/CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?\s*\((.*?)\n\)/s',
                $schema,
                $tables,
                PREG_SET_ORDER
            );
            foreach ($tables as [, $table, $body]) {
                preg_match_all('/^\s*`?(\w*member_id)`?\s+(?:INT|BIGINT)/m', $body, $columns);
                preg_match_all('/FOREIGN KEY\s*\(`?(\w+)`?\)\s*REFERENCES\s+`?members`?\s*\(\s*`?id/', $body, $keys);
                foreach (array_unique(array_merge($columns[1], $keys[1])) as $column) {
                    $found[$module][] = $table . '.' . $column;
                }
            }
        }
        foreach ($found as &$columns) {
            sort($columns);
        }

        return $found;
    }

    /** @return array<string, class-string<MemberMergeListener>> module id => its listener */
    private static function listenersByModule(): array
    {
        $root = dirname(__DIR__, 2);
        $listeners = [];
        foreach (glob($root . '/modules/*/src/Service/*MergeListener.php') ?: [] as $file) {
            preg_match('/^namespace ([\w\\\\]+);/m', (string) file_get_contents($file), $namespace);
            $class = $namespace[1] . '\\' . basename($file, '.php');
            if (is_subclass_of($class, MemberMergeListener::class)) {
                $listeners[basename(dirname($file, 3))] = $class;
            }
        }

        return $listeners;
    }

    public function testEveryModuleHoldingAMemberFollowsTheMergeOrSaysWhatStays(): void
    {
        $root = dirname(__DIR__, 2);
        $composition = (string) file_get_contents($root . '/public/index.php');
        $columns = self::memberColumnsByModule();
        $listeners = self::listenersByModule();
        $this->assertArrayHasKey('presences', $columns, 'the schema parse found no member column at all');

        $forgotten = [];
        foreach (array_keys($columns) as $module) {
            $manifest = json_decode((string) file_get_contents($root . "/modules/{$module}/module.json"), true);
            $stays = is_array($manifest) && is_string($manifest['member_merge_stays'] ?? null);
            $listener = $listeners[$module] ?? null;
            $registered = $listener !== null && str_contains($composition, '\\' . $listener . '(');
            if (!$registered && !$stays) {
                $forgotten[] = $module;
            }
        }

        $this->assertSame(
            [],
            $forgotten,
            'A module holds a member and neither follows a merge (a MemberMergeListener registered through '
            . '$registerMemberMergeListener in public/index.php) nor says what stays behind (module.json '
            . '`member_merge_stays`, shown on the merge screen).'
        );
    }

    public function testARepointingListenerRepointsEveryMemberColumnOfItsModule(): void
    {
        $columns = self::memberColumnsByModule();
        $checked = 0;
        foreach (self::listenersByModule() as $module => $listener) {
            if (!is_subclass_of($listener, RepointingMergeListener::class)) {
                continue;
            }
            $class = new \ReflectionClass($listener);
            $handled = array_merge(
                array_map(
                    static fn(string $table, array $rule): string => $table . '.' . $rule[0],
                    array_keys($class->getConstant('UNIQUE')),
                    array_values($class->getConstant('UNIQUE'))
                ),
                array_map(static fn(array $pair): string => $pair[0] . '.' . $pair[1], $class->getConstant('PLAIN'))
            );
            sort($handled);

            $this->assertSame(
                $columns[$module] ?? [],
                $handled,
                "{$listener} does not repoint exactly the member columns of module {$module}."
            );
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'no RepointingMergeListener was found to check');
    }
}
