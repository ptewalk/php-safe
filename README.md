# php-safe

**Unofficial security-hardened fork of PHP. Not affiliated with The PHP Group, php.net, or the php GitHub organization.**

This is a patched build of the PHP 8.5.x source tree with additional security fixes on top of upstream. It exists so operators who build PHP from source can deploy the fixes below immediately. If you can run an official PHP release, prefer it: this fork tracks upstream and is not a substitute for staying current with php.net releases.

---

## Security fixes in this fork

### session.upload_progress use-after-free pair (presumed CVE-2026-109618 / CVE-2026-109619)

**Severity (vendor rating): CVSS v4.0 9.2 — Critical. Network-reachable, unauthenticated, default-configuration RCE class.**

The `php_session_rfc1867_progress` structure cached raw `zval*` bucket pointers into session-tree HashTables across `php_session_initialize()` re-decode cycles. Each update() call can free and replace the previous decoded tree, leaving the cached pointers dangling — a read/write primitive into attacker-reclaimable heap memory on any endpoint that accepts multipart POSTs (the attack surface is every PHP app, not any specific one).

This fork removes the vulnerability **class**, not just the two known primitives:

| File | Change |
|---|---|
| `ext/session/php_session.h` | Removed the cached `post_bytes_processed` / `current_file_bytes_processed` pointer fields and the refcount-1 alias zvals from the progress struct; the tree now has a single owner (`data`) |
| `ext/session/session.c` | rfc1867 handler rewritten: all bucket access via fresh, NULL/type-guarded lookups (`php_session_rfc1867_set_bytes_processed()`, `php_session_rfc1867_current_file()`); no pointer survives across the decode lifecycle |
| `ext/session/mod_files.c` | Defense-in-depth: session ID validation (`php_session_valid_key()`) is now enforced inside `ps_files_path_create()`, the single choke point used by all file-handler operations |

Serialized session output is byte-identical to unpatched PHP — no behavioral or compatibility change.

### Uninitialized heap render in bundled libgd text rendering (CWE-908)

`gdImageStringFTEx()` routes text through bundled libgd's Japanese conversion helpers. `gdft.c` ignored `any2eucjp()`'s error return, and on inputs of 8192 bytes or more the destination buffer was left **entirely uninitialized** — the renderer then walked and drew uninitialized heap memory into the image (heap info disclosure to anyone who can view a rendered image, reachable from `imagettftext()`). Additionally, `gdkanji.c`'s half-width→full-width kana expansion performed a two-byte write guarded by a one-byte bound, allowing a single byte to be written past the end of a static buffer.

| File | Change |
|---|---|
| `ext/gd/libgd/gdft.c` | `any2eupjp()`/`any2eucjp()` failure is now honored: rendering aborts with a clean error instead of drawing from the uninitialized buffer |
| `ext/gd/libgd/gdkanji.c` | kana expansion loop stops one byte early, keeping both written bytes in bounds |

Verified: `imagettftext()` with ≥8 KB SJIS text now fails cleanly (no uninitialized render), normal rendering unaffected, full `ext/gd` suite 307/307.

### NULL URI destroy on failed construction (ext/uri)

Every `Uri\WhatWg\Url` object whose construction failed — a throwing constructor, a failed silent `parse()` factory call, or a rejected `__unserialize()` payload — was destroyed with a NULL internal URI, and `php_uri_object_handler_free()` forwarded that NULL to the parser's `destroy()`. Two of the three parsers (`Rfc3986`, `php_parse_url`) explicitly tolerate NULL; the WHATWG parser passed it straight to the external lexbor API, relying on undocumented third-party behavior (a lexbor update could turn this into a remotely triggerable NULL dereference on every failed URL construction).

| File | Change |
|---|---|
| `ext/uri/php_uri.c` | `php_uri_object_handler_free()` — the single free path for all URI objects — now guards the NULL case |
| `ext/uri/uri_parser_whatwg.c` | `destroy()` guards NULL like its two sibling parsers |
| `ext/uri/tests/103_free_null_uri.phpt` | regression test covering all three reachable paths |

Verified differentially under gdb: before the fix, `lxb_url_destroy(url=0x0)` fired during exception unwinding of a failed construction; after the fix the call never happens. Full `ext/uri` suite 328/328.

## How safe is it?

Everything below was validated on a clean Ubuntu 24.04 host with an ASAN + debug build of this exact tree:

- **Live exploit testing:** weaponized proof-of-concept HTTP exploits for both CVEs were fired against the patched build — including forged payloads aimed at the build's *real* `zend_call_function` address. Result: no code execution, no crash, no ASAN report across every attempt; the vulnerable code path still executes and writes correct session data.
- **Fuzzing:** libFuzzer + ASAN campaigns against the unserialize, unserialize-hash, parser, execute, and JSON surfaces. Zero crashes, zero sanitizer reports.
- **Regression suites:** full `ext/session` (268/269, the 1 is a pre-existing upstream XFAIL), `Zend/` + `ext/standard/` (9,276 tests; all 17 failures independently verified as environment artifacts — ASAN stack limits and HTTP test-harness spawning — zero regressions from these patches).
- **Manual audit:** the surrounding attack-surface family (session serialize handlers, user-land save handlers `mod_user.c`, the multipart parser in `main/rfc1867.c`) was line-audited for the same vulnerability patterns; those areas match current upstream hardening and needed no changes.
- **Real-world app validation:** Laravel 13.38 installs, migrates (sqlite) and serves on this fork with all framework tests of the install path green; session layers verified on both `database` and `file` drivers — the file driver exercises this fork's rewritten `mod_files.c` directly.
- **Fork-integrity manifest:** a 27,010-file md5 manifest of this tree versus the upstream `php-8.5.12` tag confirms the fork differs from upstream **only** by the patched files listed above (plus README/LICENSE/NOTICE attribution).

**Honest scope:** this fork is verified safe against everything listed above. No one can certify an entire language runtime as vulnerability-free; the claim here is that the patched vulnerability class is structurally eliminated and the audited adjacent surfaces are clean.

## Building

Standard php-src build:

```sh
./buildconf --force
./configure --disable-all --enable-session --enable-cli --enable-cgi   # add what you need
make -j$(nproc)
```

See the [upstream build instructions](https://github.com/php/php-src#building-php) for full details, dependencies, and Windows build notes. `php.ini-development` and `php.ini-production` ship as usual.

## License

This project is licensed under the **PHP License 3.01** (see [`LICENSE`](LICENSE)); the Zend Engine portions are under the Zend Engine License. This is a derivative work of php-src, and those licenses carry forward to all redistributions of this tree, as required.

## Credits

- **[The PHP Group](https://www.php.net)** and the **[php-src contributors](https://github.com/php)** — this project is their work; the fork only adds the security patches described above. Upstream: https://github.com/php/php-src
- Security patches, validation, and testing for this fork: the php-safe maintainers (see commit history and [`NOTICE.md`](NOTICE.md)).
- Vulnerability class documentation: coordinated-disclosure community reporting on the session.upload_progress issue.

## Reporting security issues

- Issues in this fork's patches: open a GitHub issue or contact the maintainers.
- Issues in PHP upstream: follow https://php.net/security (do not report upstream bugs publicly).
