# Changelog

## 0.2.0 - 2026-09-18

- Add native PHP TCP access through `connectTcp`, `connectTcpAuth`, and
  `connectTcpTls`, without `ext-ffi`, `ext-sockets`, or `libkoutendb.so`.
- Support named-ring writes, typed IDs, JSON and binary reads, projections,
  health checks, password/token/secret-key authentication, and verified TLS.
- Validate wire versions, bound frame sizes and redirects, retry reads at most
  once, and report unknown write outcomes without automatically replaying writes.
- Make FFI an optional Composer dependency. Existing FFI factories and their
  behavior remain unchanged.
- Add PHP 8.2/8.3 native TCP and FFI regression matrices. Each native run covers
  27 scripted protocol scenarios and six real-server configurations, including
  malformed responses, interrupted I/O, authentication failures, and TLS errors.
- Add transport selection, server setup, application fallback, and testing docs.

Native TCP intentionally exposes the common data API, not the full FFI
administrative API. PHP 8.2+ is required; native TCP requires a 64-bit runtime.
