<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\DatabaseRestorer;
use PHPUnit\Framework\TestCase;

class DatabaseRestorerTest extends TestCase
{
    public function testSplitsSimpleStatements(): void
    {
        $sql = "CREATE TABLE t (id INT);\nINSERT INTO t VALUES (1);";

        $this->assertSame(
            ['CREATE TABLE t (id INT);', 'INSERT INTO t VALUES (1);'],
            DatabaseRestorer::splitStatements($sql)
        );
    }

    public function testDoesNotSplitOnASemicolonInsideAStringLiteral(): void
    {
        $sql = "INSERT INTO notes VALUES ('rendez-vous à 14h; ne pas oublier');";

        $statements = DatabaseRestorer::splitStatements($sql);

        $this->assertCount(1, $statements);
        $this->assertSame($sql, $statements[0]);
    }

    public function testHandlesABackslashEscapedQuoteInsideAStringLiteral(): void
    {
        $sql = "INSERT INTO notes VALUES ('it\\'s a semicolon: ;');";

        $statements = DatabaseRestorer::splitStatements($sql);

        $this->assertCount(1, $statements);
        $this->assertSame($sql, $statements[0]);
    }

    public function testHandlesADoubledQuoteInsideAStringLiteral(): void
    {
        $sql = "INSERT INTO notes VALUES ('it''s a semicolon: ;');";

        $statements = DatabaseRestorer::splitStatements($sql);

        $this->assertCount(1, $statements);
        $this->assertSame($sql, $statements[0]);
    }

    public function testDoesNotSplitOnASemicolonInsideADoubleQuotedString(): void
    {
        $sql = 'INSERT INTO notes VALUES ("a; b");';

        $statements = DatabaseRestorer::splitStatements($sql);

        $this->assertCount(1, $statements);
        $this->assertSame($sql, $statements[0]);
    }

    public function testALineCommentCannotDesynchronizeQuoteTracking(): void
    {
        // The apostrophe in the comment must not be mistaken for the start
        // of a string literal — if it were, the real string literal on the
        // next line would desync the scanner and the statement would be
        // split in the wrong place (or not at all).
        $sql = "-- Peter's favourite table\nINSERT INTO notes VALUES ('fine; still one statement');";

        $statements = DatabaseRestorer::splitStatements($sql);

        $this->assertCount(1, $statements);
        $this->assertSame($sql, $statements[0]);
    }

    public function testAHashLineCommentIsAlsoTracked(): void
    {
        $sql = "# don't split; this\nINSERT INTO t VALUES (1);";

        $statements = DatabaseRestorer::splitStatements($sql);

        $this->assertCount(1, $statements);
    }

    public function testABlockCommentCannotDesynchronizeQuoteTracking(): void
    {
        $sql = "/* it's fine; really */\nINSERT INTO t VALUES (1);";

        $statements = DatabaseRestorer::splitStatements($sql);

        $this->assertCount(1, $statements);
        $this->assertSame($sql, $statements[0]);
    }

    public function testAMysqldumpVersionGatedDirectiveIsItsOwnStatement(): void
    {
        $sql = "/*!40101 SET NAMES utf8mb4 */;\nINSERT INTO t VALUES (1);";

        $statements = DatabaseRestorer::splitStatements($sql);

        $this->assertSame(
            ['/*!40101 SET NAMES utf8mb4 */;', 'INSERT INTO t VALUES (1);'],
            $statements
        );
    }

    public function testBlankInputProducesNoStatements(): void
    {
        $this->assertSame([], DatabaseRestorer::splitStatements("\n\n   \n"));
    }

    /**
     * A trailing comment with no statement after it (and no terminating
     * `;` of its own) still comes back as one "statement" — executing it
     * via PDO::exec() is a harmless no-op, so there's no need for extra
     * logic to filter it out.
     */
    public function testATrailingCommentWithNoFollowingStatementIsKeptAsAHarmlessNoOp(): void
    {
        $this->assertSame(['-- just a comment'], DatabaseRestorer::splitStatements("-- just a comment\n"));
    }

    public function testATrailingStatementWithNoFinalSemicolonIsStillIncluded(): void
    {
        $sql = 'INSERT INTO t VALUES (1)';

        $this->assertSame([$sql], DatabaseRestorer::splitStatements($sql));
    }
}
