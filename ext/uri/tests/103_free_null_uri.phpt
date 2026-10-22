--TEST--
Freeing URI objects whose construction failed (NULL uri) must not crash
--EXTENSIONS--
uri
--FILE--
<?php
// Constructor failure: the object is freed without ever having parsed a URI.
for ($i = 0; $i < 3; $i++) {
    try {
        new Uri\WhatWg\Url("http://[");
    } catch (Uri\WhatWg\InvalidUrlException $e) {
    }
    try {
        new Uri\Rfc3986\Uri("http://[");
    } catch (Uri\InvalidUriException $e) {
    }
}

// Factory failure: the object is freed after the failed (silent) parse.
var_dump(Uri\WhatWg\Url::parse("http://[", null, $errors));
var_dump(Uri\Rfc3986\Uri::parse("http://["));

// __unserialize failure: the object is freed with a NULL uri.
try {
    unserialize('O:14:"Uri\WhatWg\Url":2:{i:0;a:1:{s:3:"uri";s:8:"http://[";}i:1;a:0:{}}');
} catch (Throwable $e) {
}

// Regular destruction is not broken by the NULL uri handling.
$url = Uri\WhatWg\Url::parse("https://example.com/path");
$clone = $url->withScheme("http");
unset($url, $clone);

echo "ok\n";
?>
--EXPECT--
NULL
NULL
ok
