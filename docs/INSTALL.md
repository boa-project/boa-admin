# Installation instructions

## 1. System requirements

To run BoA on your web server you need:

- **PHP 8.2 or 8.3** (`PHP >= 8.2` and `PHP <= 8.3`)
- FTP or other access to upload files to the server

No database is required for a basic install (users and config can be stored on the filesystem).

## 2. Quick install

1. Download or clone the BoA source into an installation folder:

   ```bash
   git clone https://github.com/boa-project/boa.git [installation-folder]
   ```

2. Open `[installation-folder]/src/boa/conf/bootstrap_context.php` (create it from `bootstrap_context.php.sample` if needed) and set:

   - `ADMIN_PASSWORD` — password for the admin user on first run (default in the sample is `admin`).
   - `APP_SECRET_KEY` — required application secret for OpenSSL crypto (constant or environment variable). There is no default; see the [Update guide](UPDATE.md) for details.

3. Open `[installation-folder]/src/boa/conf/bootstrap_repositories.php` and make sure all paths for the configured repositories exist.

4. Make `[installation-folder]/src/data` and its child folders writable by the web server user, for example:

   ```bash
   chown -R www-data [installation-folder]/src/data
   ```

   (Use the appropriate system user for your distribution, e.g. `www-data` or `apache`.)

5. Point a virtual host or alias at `[installation-folder]/src`, reload the web server, then open that URL in a browser and follow the installation wizard.

For further configuration, see below.

## 3. Further configuration

See `[installation-folder]/src/boa/conf/bootstrap_conf.php` for additional options.

### 3.1. Workspaces

A workspace is a folder you want to browse or modify with the application. It does not have to be inside the installation folder; you set it with an absolute path on the server.

You can define as many workspaces as you want. After login you can switch between them, and you can set per-user rights on each workspace (see [Users management](#32-users-management)).

By default, the basic workspace points to the `files` folder under `[installation-folder]/src/data`. You can change it to any absolute path (for example `/home/login/www/location`, or `/C:/myfolder/` on Windows) by editing `[installation-folder]/src/boa/conf/bootstrap_repositories.php`.

A workspace does not need to be reachable from the internet. BoA acts as a proxy between your files and the web.

### 3.2. Users management

BoA includes a users management system. You are encouraged to use it to protect your data, but you can disable it entirely (for example in an already locked-down environment).

If you are new to BoA, change `ADMIN_PASSWORD` before deploying. If you leave the default, you will be prompted to change it on first login — otherwise anyone who knows the default password (`admin`) could sign in.

Add, modify, or delete users by logging in as `admin` and opening **Settings**. For each user you can grant read and/or write access per workspace. A user with no rights on any workspace cannot log in.

For integration with external systems, BoA can pre-authenticate a user from external data or another login system. Users must still exist in BoA (rights and preferences are stored locally), but they will not be able to change their password when it is managed externally.

#### 3.2.1. Basic users configuration

| Option | Value | Meaning |
| --- | --- | --- |
| `ENABLE_USERS` | `1` | Users management is enabled. |
| `ENABLE_USERS` | `0` | Users management is disabled. No login is required; all users share the same preferences. |
| `ALLOW_GUEST_BROWSING` | `1` | In **Settings**, a `guest` user can be given rights like any other user. That user is logged in automatically when nobody is identified. |
| `ALLOW_GUEST_BROWSING` | `0` | When no user is identified, the login screen appears and no workspace is loaded. |

#### 3.2.2. Authentication method

Authentication (like repositories and configuration) is plugin-based. By default, `auth.serial` stores users, rights, and personal data on the filesystem in a serialized format. It performs well for many setups, but may not suit very large user bases or users already managed in an external CMS.

In those cases, consider plugins such as `auth.ldap`, `auth.remote`, or `auth.mysql`, each with different features for managing authentication.
