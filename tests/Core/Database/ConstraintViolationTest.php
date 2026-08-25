<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\ConstraintViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConstraintViolationTest extends TestCase
{
    /**
     * A PDOException as PDO really builds one: the driver code lives in
     * errorInfo[1], and getCode() carries the SQLSTATE.
     */
    private function pdoException(string $sqlstate, ?int $driverCode, string $message = 'boom'): \PDOException
    {
        $e = new \PDOException($message);
        $e->errorInfo = [$sqlstate, $driverCode, $message];

        return $e;
    }

    #[DataProvider('callerFaults')]
    public function testAValueTheSchemaRefusedIsTheCallersFault(int $driverCode, string $expected): void
    {
        $this->assertSame(
            $expected,
            ConstraintViolation::classify($this->pdoException('23000', $driverCode))
        );
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function callerFaults(): array
    {
        return [
            'a parent row something still references' => [1451, ConstraintViolation::CONFLICT],
            'a child row whose parent is gone' => [1452, ConstraintViolation::CONFLICT],
            'a duplicate on a unique key' => [1062, ConstraintViolation::CONFLICT],
            'a number wider than the column' => [1264, ConstraintViolation::MALFORMED],
            'a date column handed something else' => [1292, ConstraintViolation::MALFORMED],
            'bytes the charset does not allow' => [1366, ConstraintViolation::MALFORMED],
            'a value longer than the column' => [1406, ConstraintViolation::MALFORMED],
        ];
    }

    /**
     * The distinction the whole class turns on. SQLSTATE 23000 covers
     * both a foreign key a visitor got wrong AND a NOT NULL column this
     * codebase forgot to populate — the second is a bug here, and a bug
     * that stops shouting is a bug that stops being fixed.
     */
    public function testANotNullColumnTheApplicationForgotStaysAFiveHundred(): void
    {
        $this->assertNull(
            ConstraintViolation::classify($this->pdoException('23000', 1048, "Column 'name' cannot be null")),
            'same SQLSTATE as a foreign key violation, and not the caller\'s doing'
        );
    }

    #[DataProvider('serverFaults')]
    public function testTheDatabaseBeingInTroubleIsNotTheCallersFault(string $sqlstate, ?int $driverCode): void
    {
        $this->assertNull(ConstraintViolation::classify($this->pdoException($sqlstate, $driverCode)));
    }

    /**
     * @return array<string, array{0: string, 1: ?int}>
     */
    public static function serverFaults(): array
    {
        return [
            'a table that is not there' => ['42S02', 1146],
            'a column that is not there' => ['42S22', 1054],
            'a syntax error' => ['42000', 1064],
            'the server went away' => ['HY000', 2006],
            'a deadlock, which is retryable and not the caller\'s' => ['40001', 1213],
            'credentials refused' => ['28000', 1045],
            'no driver code at all' => ['HY000', null],
        ];
    }

    public function testAnExceptionThatIsNotAPdoOneIsNotClassified(): void
    {
        $this->assertNull(ConstraintViolation::classify(new \RuntimeException('something else')));
        $this->assertNull(ConstraintViolation::classify(new \ValueError('a null byte somewhere')));
    }

    /**
     * A repository that opens a transaction, catches, rolls back and
     * rethrows keeps the original as $previous — and so does a service
     * that adds context. The verdict has to survive that.
     */
    public function testTheCauseIsFoundThroughAWrapper(): void
    {
        $wrapped = new \RuntimeException(
            'Saving the month failed.',
            0,
            new \RuntimeException('inner', 0, $this->pdoException('23000', 1452))
        );

        $this->assertSame(ConstraintViolation::CONFLICT, ConstraintViolation::classify($wrapped));
    }

    public function testTheStatusCodeMatchesWhatHappened(): void
    {
        $this->assertSame(409, ConstraintViolation::statusCode(ConstraintViolation::CONFLICT));
        $this->assertSame(400, ConstraintViolation::statusCode(ConstraintViolation::MALFORMED));
    }

    /**
     * The visitor's sentence is written here, in French, and never taken
     * from the driver — MySQL names the table, the column and the
     * constraint, in English, and none of that belongs on a page.
     */
    #[DataProvider('verdicts')]
    public function testTheMessageIsFrenchAndNamesNothingInternal(string $verdict): void
    {
        $message = ConstraintViolation::message($verdict);

        $this->assertNotSame('', $message);
        $this->assertMatchesRegularExpression('/[a-z]/', $message);
        $this->assertStringEndsWith('.', $message);

        foreach (['SQLSTATE', 'constraint', 'FOREIGN KEY', 'INSERT', 'Column', 'row', '_id'] as $internal) {
            $this->assertStringNotContainsStringIgnoringCase($internal, $message);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function verdicts(): array
    {
        return [
            'conflict' => [ConstraintViolation::CONFLICT],
            'malformed' => [ConstraintViolation::MALFORMED],
        ];
    }
}
