# Branchify

A link-in-bio SaaS MVP in a single PHP file. Users claim a username, build a page of links, style it, share it, and see who clicks what.

> 🎶 **Vibe coded.** A fun weekend project built by chatting with [Claude Code](https://claude.com/claude-code): modern PHP + SQLite, no frameworks, no build step, everything in one `index.php`. Not production-hardened; use it to learn, tinker and have fun.

## Run it

```bash
php -S localhost:8000 index.php
```

Open http://localhost:8000. Requires PHP 8.4+ (built and tested on 8.5) with `pdo_sqlite` and `gd`.

Pass `index.php` as the router script. That enables pretty profile URLs (`/juno`) and makes sure every request goes through the app, so nothing in `data/` can be downloaded.

On first request the app creates `data/` with the SQLite database, a secret key, avatar storage and the mail log, and seeds a demo account:

| | |
|---|---|
| Email | `juno@branchify.test` |
| Password | `branchify-demo` |
| Public page | http://localhost:8000/juno |

The login page also has a one-click "Open the demo account" button in development mode. To start fresh, delete the `data/` folder.

## Features

- **Accounts:** sign up, log in with email or username, optional 30-day "keep me logged in", log out, change email/username/password, delete account.
- **Password reset:** single-use, hashed tokens that expire after 1 hour. The response is the same whether or not the email exists.
- **Mail catcher:** in development every email is stored and shown at `?page=mail`, and also appended to `data/mail.log`. Set `BRANCHIFY_MAIL=mail` to also send through PHP `mail()`.
- **Links:** add links or section headers, edit, highlight, show/hide, delete, and reorder by drag-and-drop or arrow buttons. Links accept `https://`, `mailto:` and `tel:`; `javascript:` and other schemes are rejected.
- **Appearance:** profile photo upload (re-encoded to a 400×400 WebP), display name, bio, 8 themes, 4 button styles, 3 corner shapes, 4 fonts and 11 social icons. Changes appear in the live phone preview before you save.
- **Public page:** QR code and share sheet, Open Graph tags, and a 404 page that offers the unclaimed username.
- **Analytics:** views, unique visitors, clicks and click rate over 7, 30 or 90 days. Also a daily chart with a table view, clicks per link, traffic sources and devices. Bots, the owner's own visits and preview traffic aren't counted. Visitors are de-duplicated with a daily-rotating keyed hash, so raw IP addresses are never stored.

## Security

- Sessions are strict-mode, HttpOnly and SameSite=Lax, and Secure on HTTPS. The session ID is regenerated on login and on password change.
- Remember-me uses the selector/validator pattern with hashed validators. Tokens rotate on every use and are revoked when the password changes.
- Every POST is CSRF-protected, including the fetch-based ones.
- Logins are rate-limited per account and per IP, and so are reset requests and sign-ups. Sign-up also has a honeypot field.
- Bcrypt via `password_hash`, rehashed automatically when the default changes. Logins for unknown accounts take the same time as real ones.
- CSP with per-request script nonces, `X-Frame-Options`, `nosniff` and a referrer policy.
- Email links use `BRANCHIFY_URL` when set, so a forged `Host` header can't redirect reset links.

## Configuration (environment variables)

| Variable | Effect |
|---|---|
| `BRANCHIFY_ENV=production` | Hides the mail catcher, demo login and dev hints; turns off error display |
| `BRANCHIFY_URL=https://example.com` | Canonical origin for emailed links, QR codes and share URLs |
| `BRANCHIFY_MAIL=mail` | Also deliver email through PHP `mail()` |
| `BRANCHIFY_PRETTY=1` | Generate `/username` URLs outside the built-in server (needs a rewrite rule that sends all requests to `index.php`) |

Without pretty URLs, profiles live at `/?u=username`. Under Apache, the generated `data/.htaccess` denies web access to the data folder.

## License

[MIT](LICENSE)
