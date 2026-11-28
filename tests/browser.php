<?php

declare(strict_types=1);

// Requests pages and fills in forms the way a browser would. For the test scripts in this folder: the script
// that loads this file defines fail(string $message): never, which says what went wrong and stops.

const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/100.0';

/**
 * @param array<string, string>|null $post
 */
function request(string $url, string $cookieJar, ?array $post = null): string
{
    $curl = curl_init($url);
    if ($curl === false) {
        fail('cannot start a request to ' . $url);
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_TIMEOUT => 120,
    ]);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($curl);
    if (!is_string($body)) {
        fail('no answer from ' . $url . ': ' . curl_error($curl));
    }
    if (preg_match('/(Fatal error|Uncaught \w+): .{0,200}/', $body, $match) === 1) {
        fail($url . ' answered with ' . $match[0]);
    }

    return $body;
}

/**
 * The fields a browser would send for the named form, with their default values.
 *
 * @return array{action: string, fields: array<string, string>}
 */
function formDefaults(string $html, string $formName): array
{
    libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $document->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($document);
    $forms = $xpath->query(sprintf("//form[@name='%s']", $formName));
    $form = $forms === false ? null : $forms->item(0);
    if (!$form instanceof DOMElement) {
        fail('form "' . $formName . '" not found');
    }

    $fields = [];
    $controls = $xpath->query('.//input|.//select|.//textarea', $form);
    foreach ($controls === false ? [] : $controls as $control) {
        if (!$control instanceof DOMElement || $control->getAttribute('name') === '') {
            continue;
        }
        $name = $control->getAttribute('name');
        $type = strtolower($control->getAttribute('type'));
        if ($control->nodeName === 'select') {
            $options = $xpath->query('.//option[@selected]', $control);
            $option = $options === false ? null : $options->item(0);
            if (!$option instanceof DOMElement) {
                $options = $xpath->query('.//option', $control);
                $option = $options === false ? null : $options->item(0);
            }
            if ($option instanceof DOMElement) {
                $fields[$name] = $option->hasAttribute('value') ? $option->getAttribute('value') : $option->textContent;
            }
        } elseif ($control->nodeName === 'textarea') {
            $fields[$name] = $control->textContent;
        } elseif (in_array($type, ['submit', 'button', 'image', 'file', 'reset'], true)) {
            continue;
        } elseif (!in_array($type, ['checkbox', 'radio'], true) || $control->hasAttribute('checked')) {
            $fields[$name] = $control->getAttribute('value');
        }
    }

    return ['action' => $form->getAttribute('action'), 'fields' => $fields];
}
