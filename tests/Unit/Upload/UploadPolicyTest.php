<?php

declare(strict_types=1);

namespace YooperGEDView\Tests\Upload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YooperGEDView\Upload\UploadPolicy;
use ZipArchive;

#[CoversClass(UploadPolicy::class)]
final class UploadPolicyTest extends TestCase
{
    private const string PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";
    private const string GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00!\xf9\x04\x01\x00\x00\x00\x00,";
    private const string JPEG = "\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
    private const string PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
    private const string GEDCOM = "0 HEAD\n1 SOUR test\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME Aino /Maki/\n0 TRLR\n";
    private const string PHP = "<?php echo 'this would run'; ?>\n";
    private const string HTML = "<html><head><title>x</title></head><body><script>alert(1)</script></body></html>";
    private const string SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
    private const string SHELL = "#!/bin/sh\necho this would run\n";

    private string $file;

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'upload');
        self::assertIsString($file);
        $this->file = $file;
    }

    protected function tearDown(): void
    {
        unlink($this->file);
    }

    private function refusal(string $name, string $content): ?string
    {
        file_put_contents($this->file, $content);

        return (new UploadPolicy())->refusal($name, $this->file);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function filesToKeep(): array
    {
        return [
            'a PNG picture' => ['aino.png', self::PNG],
            'a GIF picture' => ['aino.gif', self::GIF],
            'a JPEG picture' => ['Aino Maki 1912.JPG', self::JPEG],
            'a JPEG picture called .jpeg' => ['aino.jpeg', self::JPEG],
            'a picture with dots in its name' => ['aino.maki.1912.png', self::PNG],
            'a picture with a name in another script' => ['äiti ja isä.png', self::PNG],
            'a PDF' => ['census-1920.pdf', self::PDF],
            'a GEDCOM file' => ['family.ged', self::GEDCOM],
            'a GEDCOM file called .gedcom' => ['family.gedcom', self::GEDCOM],
            'a text file' => ['notes.txt', "Notes about the family.\n"],
            'an old format the media code lists, whatever it holds' => ['scan.pcx', "\x0a\x05\x01\x08some bytes"],
            'a sound file' => ['interview.mp3', "ID3\x03\x00\x00\x00\x00\x00\x21"],
        ];
    }

    #[DataProvider('filesToKeep')]
    public function testMediaAndGedcomFilesAreKept(string $name, string $content): void
    {
        self::assertNull($this->refusal($name, $content));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function filesToRefuse(): array
    {
        return [
            'a PHP file' => ['shell.php', self::PHP, 'type "php" are not accepted'],
            'a PHP file in capitals' => ['SHELL.PHP', self::PHP, 'type "php" are not accepted'],
            'an old PHP ending' => ['shell.phtml', self::PHP, 'type "phtml" are not accepted'],
            'a PHP archive' => ['shell.phar', self::PHP, 'type "phar" are not accepted'],
            'PHP hidden before a picture ending' => ['shell.php.jpg', self::JPEG, 'type "php" are not accepted'],
            'PHP hidden in the middle' => ['holiday.php.backup.png', self::PNG, 'type "php" are not accepted'],
            'a PHP ending with a space after it' => ['shell.php .jpg', self::JPEG, 'are not accepted'],
            'a web page' => ['page.html', self::HTML, 'type "html" are not accepted'],
            'a picture format that can hold scripts' => ['drawing.svg', self::SVG, 'type "svg" are not accepted'],
            'a web server settings file' => ['x.htaccess', "AddType application/x-httpd-php .jpg\n", 'type "htaccess" are not accepted'],
            'a settings file with no name before the dot' => ['.htaccess', 'x', 'is not usable'],
            'a PHP settings file' => ['.user.ini', 'auto_prepend_file=x.jpg', 'is not usable'],
            'a script' => ['run.sh', self::SHELL, 'type "sh" are not accepted'],
            'a type nobody listed' => ['data.xyz', 'x', 'type "xyz" are not accepted'],
            'no type at all' => ['README', 'x', 'has no type'],
            'PHP inside a file called a picture' => ['aino.jpg', self::PHP, 'holds a program or a web page'],
            'a web page called a text file' => ['notes.txt', self::HTML, 'holds a program or a web page'],
            'a script called a GEDCOM file' => ['family.ged', self::SHELL, 'holds a program or a web page'],
            'PHP called an old media format' => ['scan.pcx', self::PHP, 'holds a program or a web page'],
            'text called a picture' => ['aino.png', 'just some text', 'is not what its name says'],
            'a picture called a PDF' => ['census.pdf', self::PNG, 'is not what its name says'],
            'a GEDCOM file called a zip archive' => ['tree.zip', self::GEDCOM, 'is not what its name says'],
            'a name with a folder in it' => ['../../index.png', self::PNG, 'is not usable'],
            'a name with a Windows folder in it' => ['..\\index.png', self::PNG, 'is not usable'],
            'a name with a line break' => ["aino\n.png", self::PNG, 'is not usable'],
            'a name with a zero byte' => ["aino.php\x00.png", self::PNG, 'is not usable'],
            'no name' => ['', self::PNG, 'is not usable'],
            'a name that is too long' => [str_repeat('a', 252) . '.png', self::PNG, 'is not usable'],
        ];
    }

    #[DataProvider('filesToRefuse')]
    public function testEverythingElseIsRefusedWithAReason(string $name, string $content, string $reason): void
    {
        $refusal = $this->refusal($name, $content);

        self::assertNotNull($refusal);
        self::assertStringContainsString($reason, $refusal);
    }

    public function testAPictureThatAlsoHoldsPhpIsKeptOnlyUnderAPictureName(): void
    {
        // Such a file is harmless as long as nothing runs it, which the name rules see to.
        $polyglot = self::GIF . self::PHP;

        self::assertNull($this->refusal('aino.gif', $polyglot));
        self::assertNotNull($this->refusal('aino.gif.php', $polyglot));
    }

    public function testAFileThatIsNotThereIsRefused(): void
    {
        $refusal = (new UploadPolicy())->refusal('aino.png', $this->file . '-missing');

        self::assertNotNull($refusal);
        self::assertStringContainsString('could not be read', $refusal);
    }

    public function testAZipArchiveIsKept(): void
    {
        $archive = new ZipArchive();
        self::assertTrue($archive->open($this->file, ZipArchive::OVERWRITE));
        $archive->addFromString('family.ged', self::GEDCOM);
        $archive->close();

        self::assertNull((new UploadPolicy())->refusal('tree.zip', $this->file));
    }
}
