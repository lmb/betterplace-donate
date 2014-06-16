Techbikers Donation Dashboard
===

This is a quick website to integrate Techbikers.de with Betterplace.org, tracking
individual riders' fundraising efforts.

Getting started
---

1. Do a git clone of this repository
2. `cd <repo> && composer install` (requires composer installed somewhere)
3. Edit `config/base.php.sample`, `config/dev.php.sample`, etc.
4. Load `schema.sql` into your database
6. Create your own public/style.css`
5. Put a `SetEnv APP_ENV <dev/prod>` in your .htaccess or similar if you want
   to switch between config files

Adding users
---

Use trusty PhpMyAdmin or similar to add users to the users table.

License
---

The code is licensed according to the Apache Public License v2.0.