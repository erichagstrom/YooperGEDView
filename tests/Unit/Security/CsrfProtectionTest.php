<?php

declare(strict_types=1);

namespace YooperGEDView\Tests\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YooperGEDView\Security\CsrfProtection;

#[CoversClass(CsrfProtection::class)]
final class CsrfProtectionTest extends TestCase
{
    private const string TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const string FIELD = '<input type="hidden" name="csrf" value="' . self::TOKEN . '">';

    public function testATokenIs64HexadecimalCharactersAndDifferentEachTime(): void
    {
        $csrf = new CsrfProtection();
        $token = $csrf->newToken();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertNotSame($token, $csrf->newToken());
    }

    public function testTheSessionsOwnTokenIsValid(): void
    {
        self::assertTrue((new CsrfProtection())->isValid(self::TOKEN, self::TOKEN));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function tokensThatMustBeRefused(): array
    {
        return [
            'nothing sent' => [self::TOKEN, ''],
            'another token' => [self::TOKEN, str_repeat('f', 64)],
            'the token with something added' => [self::TOKEN, self::TOKEN . '0'],
            'the token in capitals' => [self::TOKEN, strtoupper(self::TOKEN)],
            'a session with no token and nothing sent' => ['', ''],
            'a session whose token is not one of ours' => ['short', 'short'],
        ];
    }

    #[DataProvider('tokensThatMustBeRefused')]
    public function testAnythingElseIsRefused(string $sessionToken, string $sentToken): void
    {
        self::assertFalse((new CsrfProtection())->isValid($sessionToken, $sentToken));
    }

    public function testTheFieldCarriesTheToken(): void
    {
        self::assertSame(self::FIELD, (new CsrfProtection())->field(self::TOKEN));
    }

    public function testAFieldIsNotMadeFromAnythingButAToken(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CsrfProtection())->field('"><script>');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function formsSentByPost(): array
    {
        return [
            'double quotes' => ['<form method="post" action="edit.php">'],
            'single quotes' => ["<form action='edit.php' method='post'>"],
            'no quotes' => ['<form method=post>'],
            'capitals' => ['<FORM METHOD="POST" ACTION="edit.php">'],
            'spaces around the equals sign' => ['<form method = "post">'],
            'over several lines' => ["<form name=\"a\"\n\tmethod=\"post\"\n\taction=\"edit.php\">"],
            'a ">" inside an attribute' => ['<form method="post" onsubmit="return a > b && check(this);">'],
        ];
    }

    #[DataProvider('formsSentByPost')]
    public function testTheFieldIsAddedRightAfterTheOpeningTagOfAPostForm(string $tag): void
    {
        $html = '<p>before</p>' . $tag . '<input name="x"></form><p>after</p>';

        self::assertSame(
            '<p>before</p>' . $tag . self::FIELD . '<input name="x"></form><p>after</p>',
            (new CsrfProtection())->protectForms($html, self::TOKEN),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pagesToLeaveAlone(): array
    {
        return [
            'a form sent by GET' => ['<form method="get" action="search.php"><input name="q"></form>'],
            'a form with no method, which is sent by GET' => ['<form action="search.php"><input name="q"></form>'],
            'a form whose address mentions post' => ['<form action="post.php?method=post2" method="get"></form>'],
            'no form at all' => ['<p>method="post"</p>'],
            'a form shown as text' => ['&lt;form method="post"&gt;'],
            'another element' => ['<formfoo method="post">'],
            'an empty page' => [''],
        ];
    }

    #[DataProvider('pagesToLeaveAlone')]
    public function testNothingElseIsChanged(string $html): void
    {
        self::assertSame($html, (new CsrfProtection())->protectForms($html, self::TOKEN));
    }

    public function testEveryPostFormInAPageGetsTheField(): void
    {
        $html = '<form method="post">a</form><form method="get">b</form><form method="post">c</form>';

        self::assertSame(2, substr_count((new CsrfProtection())->protectForms($html, self::TOKEN), self::FIELD));
    }
}
