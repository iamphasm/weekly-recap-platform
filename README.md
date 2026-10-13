# Weekly Recap Platform

A weekly recap quiz platform for teachers, built for plain LAMP hosting (PHP + MySQL, no build step).

- Teachers create weekly tests (single choice, multiple choice, true/false, short text).
- Students enter their school email. On a new device they get a one-time code by email; after that the browser stays signed in.
- Correct answers are shown only after submitting, and a debrief email is sent.
- A monthly recap email goes to each student with activity that month.
- Teachers see per-test results, per-student learning curves, and can set logo, site name and colors.

## Requirements

- Apache with `.htaccess` enabled (`AllowOverride All`)
- PHP 8.0 or newer with the PDO MySQL extension
- MySQL 5.7+ or MariaDB 10.3+
- An SMTP account for sending email (optional; PHP's built-in `mail()` is used if none is set)

PHPMailer is bundled in `lib/`, so Composer is not needed.

## Setup

1. **Upload.** Unzip the project into a folder in your web root, e.g. `public_html/recap/`.
   Everything stays in that one folder. The site will be at `https://yourdomain/recap/`.
2. **Create a database.** In your hosting panel (cPanel, Plesk, etc.) create an empty MySQL database
   and a user with all privileges on it. Note the host (usually `localhost`), database name, user and password.
3. **Check permissions.** PHP must be able to write to `config/` (during install only) and `uploads/` (for the logo).
   Typically folder mode `755` owned by your hosting user is enough; use `775` if the installer reports a problem.
4. **Run the installer.** Open `https://yourdomain/recap/` in a browser. You are redirected to `install.php`, which asks for:
   - site name, student email domain(s) (e.g. `school.no`) and timezone
   - database details
   - SMTP details (optional, can be changed later in `config/config.php`)
   - the first teacher account

   It creates the tables, writes `config/config.php`, logs you in and then refuses to run again.
   If `config/` is not writable, it shows the config file contents so you can create `config/config.php` by hand.
5. **Customize.** Log in at `https://yourdomain/recap/admin/` and open **Settings** to upload a logo and pick colors.
   The logo and colors apply to both login pages and the header.
6. **Create the first test.** Admin > Tests > New test. Add questions, tick *Published*, and set the open/close window.
7. **Tell students the URL**: `https://yourdomain/recap/`.

## Monthly recap email (cron)

Add a cron job in your hosting panel to run on the 1st of every month. It sends the previous month's recap
and never sends twice to the same student for the same month:

```
0 7 1 * * php /home/USER/public_html/recap/cron/monthly.php
```

To send a specific month manually: `php cron/monthly.php 2026-10`

## Writing questions

In the question editor, enter one option per line. Start the correct option(s) with `*`:

```
*Paris
London
Berlin
```

- **Single choice:** exactly one `*` line.
- **Multiple choice:** one or more `*` lines; students must select exactly the correct set.
- **True / False:** pick the correct answer from a dropdown.
- **Short text:** one accepted answer per line (case and extra spaces are ignored).

Questions lock once a student has submitted, so earlier results stay consistent.

## Security notes

- `src/`, `config/`, `sql/`, `cron/` and `lib/` are blocked from web access by `.htaccess`. On nginx, add:
  `location ~ ^/recap/(src|config|sql|cron|lib)/ { deny all; }`
- Student identity is verified with a 6-digit code emailed to the school address (valid 10 minutes). Wrong codes are
  limited to 10 per email per hour (counted across all codes, and serialized in the database so parallel guesses cannot
  beat the limit), at most 5 codes are sent per email per hour, and there is a per-IP request limit.
  A person who knows a classmate's email can therefore temporarily block that student's *new-device* login, but cannot sign in as them.
- After verifying, the browser gets a random token in an HttpOnly cookie (only its hash is stored on the server). It signs in
  silently for up to a year and the token is rotated weekly. "Not you?" forgets the browser and ends any session using it.
  This requires working email sending, so set up SMTP before students use it.
- Teacher login is limited to 10 attempts per IP per hour (counted before the password is checked, so parallel guesses can't beat it, and reset on success). Failures against one account from many addresses only slow guessing down; they never lock the real teacher out. Teacher sessions end after 2 hours of inactivity.
- Pages send `X-Frame-Options: DENY`, `nosniff` and a Referrer-Policy; PHP errors are logged, never shown to visitors.
- The session cookie is named `recap_sid`, HttpOnly, SameSite=Lax, and scoped to the app folder.
- Behind a reverse proxy or CDN, set `'trust_proxy' => true` in `config/config.php` so rate limits use the real client IP
  (otherwise everyone shares the proxy's address). Leave it `false` when the site is reached directly, because the header can be forged.
- The installer is open to whoever visits first, so install right after uploading. It refuses to run again once `config/config.php` exists; you may delete `install.php` afterwards.

## Troubleshooting

| Problem | Fix |
|---|---|
| Redirected to the installer again | `config/config.php` was not written. Create it by hand from the installer output, or make `config/` writable and re-run. |
| "Database error" in the installer | Check host, name, user and password. On some hosts the user and database names have a prefix (e.g. `cpuser_recap`). |
| 500 error | Check the PHP version (8.0+) and that `pdo_mysql` is enabled. See the server's PHP error log. |
| Students can't get a login code | Email sending is not working. Set SMTP details in `config/config.php`, then test by requesting a code. |
| Emails not arriving | Set SMTP details in `config/config.php` (`mail` section). Check spam folders and that the from address is allowed by your SMTP provider. |
| Logo upload fails | Make `uploads/` writable by PHP. Use PNG, JPG, GIF or WebP up to 2 MB. |
| Internal folders reachable in the browser | `.htaccess` is being ignored: enable `AllowOverride All`, or add equivalent deny rules. |

## Upgrading older installs

If your database was created earlier, import the missing upgrade files once: `sql/upgrade_settings.sql` (Settings page) and `sql/upgrade_otp.sql` (email login codes; the app also creates these tables itself on first use if the database user may create tables).
