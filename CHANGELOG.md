# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/).

## 1.0.0 - 2026-10-31

### Added

- `haskimail` mail transport for Laravel 11, 12 and 13 (Mailables, notifications, queues, failover).
- Message streams, templates (`TemplateMailable`, `haskimailTemplate()`), tags, metadata,
  open/link tracking, custom headers, attachments and inline images.
- `Haskimail` facade and `Contracts\Client` for `/email`, `/email/batch`,
  `/email/withTemplate` and `/email/batchWithTemplates`.
- Typed exceptions with failover-aware semantics, `isRetryable()` and `mightHaveBeenSent()`.
- Bounded, duplicate-safe automatic retries.
- Webhook endpoint with signature and Basic authentication, deduplication, optional queue
  processing and Laravel events.
- `Haskimail::fake()` test double.
