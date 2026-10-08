qp_mail (core)

Overview
- `includes/qp-mail.php` provides a central API for sending and queueing email in Qlopy.
- Features included:
  - `qp_mail()` public wrapper for immediate send or enqueue
  - Encryption helpers (`qp_mail_encrypt` / `qp_mail_decrypt`) using libsodium/OpenSSL fallback
  - DB installers and migration SQL in `includes/db-migrations/qp_mail_tables.sql`
  - Queue processing (`qp_mail_enqueue`, `qp_mail_process_queue`) with retries/backoff
  - Logging and debug transcript capture (`qp_mail_log`, `qp_mail_debug` table)
  - PHPMailer integration via `qp_mail_prepare_phpmailer()` (SMTP/mail transport)
  - DKIM signing support (if enabled in settings and private key provided)

Admin
- Admin UI: `admin/mail.php` (Settings / Queue / Logs)
- AJAX endpoints: `includes/admin-ajax-mail.php`
- CLI runner: `tools/run_mail_queue.php`
- Smoke test helper: `tools/run_mail_test.php`

Quick commands
- Enqueue a test mail:
  e:\Wnmp\php\php.exe tools/run_mail_test.php you@example.com "subject" "body"

- Run the queue (CLI):
  e:\Wnmp\php\php.exe tools/run_mail_queue.php 50

- Run smoke tests (CLI):
  `e:\Wnmp\php\php.exe tools/run_smoke_tests.php you@example.com`

Notes & Next Steps
- OAuth2: skeleton fields are present in the admin UI; full interactive OAuth2 flows require registering an OAuth client and adding token exchange code. This is planned next.
 - Provider helpers: `qp_mail_prepare_phpmailer()` includes provider-specific defaults for `sendgrid` and `ses` when `smtp_provider` or `provider` is set in settings.

 - SendGrid: qp_mail will try a SendGrid Web API fallback when PHPMailer fails if `sendgrid_api_key` is configured. For best results use SendGrid SMTP or the Web API with properly formatted attachments.

  - Attachments: the SendGrid API fallback supports attachments passed as full paths or arrays with `['path'=>..., 'filename'=>...]` or inline content `['name'=>..., 'content'=>..., 'is_base64'=>true|false]`. Files will be base64-encoded before upload.

 - Microsoft revoke: qp_mail attempts a best-effort remote revoke for Microsoft accounts by using client credentials and the Microsoft Graph `revokeSignInSessions` endpoint. This requires your app to have appropriate application permissions (admin consent) and may not work for personal Microsoft accounts. qp_mail will always clear the locally stored refresh token regardless.

 - SES: qp_mail provides SMTP defaults for AWS SES. For better reliability, create SMTP credentials in AWS SES and configure them in the admin UI.
- DKIM: implemented by setting PHPMailer DKIM properties using the decrypted private key. Ensure your selector and DNS TXT records are configured correctly.
- Testing: run the smoke test and CLI queue runner to validate behavior in your environment. If `mail()` is unavailable, configure SMTP credentials.

Local development (MailHog)

1. Start MailHog (Docker):

```powershell
docker run --name mailhog -d -p 1025:1025 -p 8025:8025 mailhog/mailhog
```

2. Apply MailHog settings from project root:

```powershell
e:\Wnmp\php\php.exe tools\set_mailhog.php
e:\Wnmp\php\php.exe tools\set_from.php
```

3. Run smoke tests to validate delivery and logs:

```powershell
e:\Wnmp\php\php.exe tools\run_smoke_tests.php you@example.com
```

4. Inspect captured messages in MailHog UI: http://localhost:8025

Debugging notes
- If you see "Could not instantiate mail function." the environment's `mail()` isn't available — switch to SMTP or a provider API.
- If SMTP fails with "MAIL FROM command failed" ensure `from_email` is set in settings.

Security
- Secret fields are stored encrypted using `AUTH_KEY` as entropy. Do not commit secrets to VCS.
- The admin UI does not pre-fill secret fields; leaving them blank preserves existing secrets.
