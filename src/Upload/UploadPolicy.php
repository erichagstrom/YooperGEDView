<?php

declare(strict_types=1);

namespace YooperGEDView\Upload;

use finfo;

/**
 * Decides whether a file sent to the site may be kept. The inherited pages saved uploads under the name the
 * sender gave them, whatever it was, in a folder the web server serves: a file called "x.php" became a page
 * of the site that ran as code. Here the name must end in a known media or GEDCOM type, no part of it may be
 * a type that web servers run or browsers treat as a page, and what the file holds must agree with its name.
 */
final class UploadPolicy
{
    /**
     * The types the inherited media code knows, less those a browser shows as a page or a system runs
     * (htm, html, swf, scr), plus GEDCOM files, zip archives and a few current media types.
     */
    private const array ALLOWED_EXTENSIONS = [
        'a11', 'acb', 'adc', 'adf', 'afm', 'ai', 'aif', 'aiff', 'amg', 'anm', 'ans', 'apd', 'asf', 'au', 'avi', 'awm',
        'bga', 'bmp', 'bob', 'bpt', 'bw', 'cal', 'cdr', 'cel', 'cgm', 'cmp', 'cmv', 'cmx', 'cpi', 'cur', 'cut', 'cvs',
        'cwk', 'dcs', 'dib', 'dmf', 'dng', 'doc', 'docx', 'dsm', 'dwg', 'dxf', 'emf', 'enc', 'eps', 'fac', 'fax', 'fit',
        'fla', 'flc', 'fli', 'flv', 'fpx', 'ftk', 'ged', 'gedcom', 'gif', 'gmf', 'hdf', 'iax', 'ica', 'icb', 'ico',
        'idw', 'iff', 'img', 'jbg', 'jbig', 'jfif', 'jp2', 'jpe', 'jpeg', 'jpg', 'jtf', 'jtp', 'lwf', 'm4a', 'm4v',
        'mac', 'mid', 'midi', 'miff', 'mki', 'mmm', 'mod', 'mov', 'mp2', 'mp3', 'mp4', 'mpg', 'mpt', 'msk', 'msp',
        'mus', 'mvi', 'nap', 'ogg', 'pal', 'pbm', 'pcc', 'pcd', 'pcf', 'pct', 'pcx', 'pdd', 'pdf', 'pfr', 'pgm', 'pic',
        'pict', 'pk', 'pm3', 'pm4', 'pm5', 'png', 'ppm', 'ppt', 'ps', 'psd', 'psp', 'pxr', 'qt', 'qxd', 'ras', 'rgb',
        'rgba', 'rif', 'rip', 'rla', 'rle', 'rpf', 'rtf', 'sdc', 'sdd', 'sdw', 'sgi', 'sid', 'sng', 'tex', 'text',
        'tga', 'tif', 'tiff', 'tub', 'txt', 'ul', 'vda', 'vis', 'vob', 'vpg', 'vst', 'wav', 'wdb', 'webp', 'win',
        'wk1', 'wks', 'wma', 'wmf', 'wmv', 'wp4', 'wp5', 'wp6', 'wpd', 'wpg', 'wpp', 'wxf', 'xbm', 'xls', 'xpm', 'xwd',
        'yuv', 'zgm', 'zip',
    ];

    /**
     * Never allowed anywhere in a name, not only at its end: some web servers run "x.php.jpg" as PHP.
     */
    private const array ACTIVE_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'phtml', 'phar', 'inc', 'cgi', 'pl', 'py', 'rb',
        'sh', 'bash', 'exe', 'com', 'bat', 'cmd', 'dll', 'so', 'jsp', 'jspx', 'asp', 'aspx', 'cfm', 'shtml', 'htm',
        'html', 'xhtml', 'xht', 'svg', 'svgz', 'js', 'mjs', 'swf', 'scr', 'htaccess', 'htpasswd', 'ini', 'user',
    ];

    /** What a file is found to hold that is never kept, whatever it is called. */
    private const array ACTIVE_CONTENT = [
        'text/x-php', 'application/x-php', 'application/x-httpd-php', 'text/html', 'application/xhtml+xml',
        'image/svg+xml', 'text/x-shellscript', 'text/javascript', 'application/javascript', 'application/x-executable',
        'application/x-sharedlib', 'application/x-dosexec', 'application/x-mach-binary', 'text/x-perl',
        'text/x-python', 'text/x-ruby', 'application/x-shockwave-flash',
    ];

    /** Types whose content can be told for sure: the file must hold what its name says. */
    private const array CONTENT_FOR_EXTENSION = [
        'jpg' => 'image/', 'jpeg' => 'image/', 'jpe' => 'image/', 'jfif' => 'image/', 'png' => 'image/',
        'gif' => 'image/', 'bmp' => 'image/', 'tif' => 'image/', 'tiff' => 'image/', 'webp' => 'image/',
        'pdf' => 'application/pdf', 'zip' => 'application/zip',
    ];

    /**
     * Why the file may not be kept, in a sentence for the person who sent it; null when it may.
     *
     * @param string $name the name the sender gave the file
     * @param string $path where the uploaded file is for now
     */
    public function refusal(string $name, string $path): ?string
    {
        if ($name === '' || strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $name) === 1 || str_starts_with($name, '.')) {
            return 'The name of the file is not usable.';
        }
        $parts = explode('.', strtolower($name));
        if (count($parts) < 2) {
            return 'The file "' . $name . '" has no type at the end of its name.';
        }
        $extension = $parts[count($parts) - 1];
        foreach (array_slice($parts, 1) as $part) {
            if (in_array(trim($part), self::ACTIVE_EXTENSIONS, true)) {
                return 'Files of type "' . $part . '" are not accepted.';
            }
        }
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return 'Files of type "' . $extension . '" are not accepted.';
        }

        $content = is_file($path) ? (new finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        if ($content === false) {
            return 'The file "' . $name . '" could not be read.';
        }
        if (in_array($content, self::ACTIVE_CONTENT, true)) {
            return 'The file "' . $name . '" holds a program or a web page, which is not accepted.';
        }
        $expected = self::CONTENT_FOR_EXTENSION[$extension] ?? null;
        if ($expected !== null && !str_starts_with($content, $expected)) {
            return 'The file "' . $name . '" is not what its name says it is.';
        }

        return null;
    }
}
