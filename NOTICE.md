# NOTICE

This repository is an unofficial, security-patched fork of PHP (php-src).

It is not affiliated with, endorsed by, or supported by The PHP Group or php.net.

## Modified files

Relative to the upstream php-src 8.5.x baseline, this fork modifies:

- `ext/session/php_session.h` — removed cached `zval*` bucket pointer fields and
  refcount-1 alias zvals from `php_session_rfc1867_progress` (use-after-free class fix)
- `ext/session/session.c` — rfc1867 upload-progress handler rewritten to resolve
  all session-tree buckets through fresh, guarded lookups; no pointer is held
  across the decode/re-init lifecycle (fixes the use-after-free read/write pair)
- `ext/session/mod_files.c` — session ID validation enforced in
  `ps_files_path_create()` for all call sites (hardening)
- `README.md`, `NOTICE.md` — fork documentation

## Licensing

All code in this tree is licensed under the PHP License 3.01 (Zend Engine parts
under the Zend Engine License), inherited from upstream php-src and unchanged by
these modifications. The modifications are distributed as part of this derivative
work under the same license.

Upstream: https://github.com/php/php-src — Copyright (c) The PHP Group.
