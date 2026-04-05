<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\ForbiddenPatternValidator;
use App\Domain\NickServ\Entity\ForbiddenVhost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForbiddenPatternValidator::class)]
final class ForbiddenPatternValidatorTest extends TestCase
{
    private ForbiddenPatternValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ForbiddenPatternValidator();
    }

    #[Test]
    public function isValidReturnsTrueForValidPatternWithWildcard(): void
    {
        self::assertTrue($this->validator->isValid('*.example.com'));
    }

    #[Test]
    public function isValidReturnsTrueForValidPatternWithQuestionMark(): void
    {
        self::assertTrue($this->validator->isValid('test?.example.com'));
    }

    #[Test]
    public function isValidReturnsTrueForSimpleDomain(): void
    {
        self::assertTrue($this->validator->isValid('badhost.com'));
    }

    #[Test]
    public function isValidReturnsTrueForPatternWithHyphen(): void
    {
        self::assertTrue($this->validator->isValid('*.bad-host.com'));
    }

    #[Test]
    public function isValidReturnsTrueForPatternStartingWithWildcard(): void
    {
        self::assertTrue($this->validator->isValid('*.com'));
    }

    #[Test]
    public function isValidReturnsTrueForPatternWithMultipleWildcards(): void
    {
        self::assertTrue($this->validator->isValid('*.*.example.com'));
    }

    #[Test]
    public function isValidReturnsTrueForPatternWithMixedWildcards(): void
    {
        self::assertTrue($this->validator->isValid('*.test?.example.com'));
    }

    #[Test]
    public function isValidReturnsTrueForMaxLengthPattern(): void
    {
        $pattern = str_repeat('a', ForbiddenVhost::MAX_PATTERN_LENGTH);
        self::assertTrue($this->validator->isValid($pattern));
    }

    #[Test]
    public function isValidReturnsFalseForNullPattern(): void
    {
        self::assertFalse($this->validator->isValid(null));
    }

    #[Test]
    public function isValidReturnsFalseForEmptyPattern(): void
    {
        self::assertFalse($this->validator->isValid(''));
    }

    #[Test]
    public function isValidReturnsFalseForWhitespaceOnlyPattern(): void
    {
        self::assertFalse($this->validator->isValid('   '));
    }

    #[Test]
    public function isValidReturnsFalseForPatternExceedingMaxLength(): void
    {
        $pattern = str_repeat('a', ForbiddenVhost::MAX_PATTERN_LENGTH + 1);
        self::assertFalse($this->validator->isValid($pattern));
    }

    #[Test]
    public function isValidReturnsFalseForPatternWithInvalidChars(): void
    {
        self::assertFalse($this->validator->isValid('*.example!.com'));
    }

    #[Test]
    public function isValidReturnsFalseForPatternStartingWithDot(): void
    {
        self::assertFalse($this->validator->isValid('.example.com'));
    }

    #[Test]
    public function isValidReturnsFalseForPatternWithDoubleDots(): void
    {
        self::assertFalse($this->validator->isValid('*..example.com'));
    }

    #[Test]
    public function isValidReturnsTrueForSingleLabelPattern(): void
    {
        self::assertTrue($this->validator->isValid('badhost'));
    }

    #[Test]
    public function isValidReturnsTrueForPatternEndingWithWildcard(): void
    {
        self::assertTrue($this->validator->isValid('example.*'));
    }

    #[Test]
    public function isValidReturnsTrueForPatternWithNumbers(): void
    {
        self::assertTrue($this->validator->isValid('*.test123.com'));
    }
}
