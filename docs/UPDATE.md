# Update guide

This document describes the changes required to run BoA on **PHP 8.2 or 8.3** (`PHP >= 8.2` and `PHP <= 8.3`).
Apply these steps when upgrading an existing installation to a PHP version in that range.

Always keep a backup of your current configuration before editing.

## Sync `bootstrap_context.php` with the sample

Existing installations usually keep a customized copy of:

- `src/boa/conf/bootstrap_context.php` (your live config; do not overwrite blindly)

Use this as the reference:

- `src/boa/conf/bootstrap_context.php.sample`

**Do not replace your live file with the sample.** Compare both files and merge the new blocks into your existing `bootstrap_context.php`, while preserving your local values (paths, passwords, debug flags, and secrets).

### Required: `APP_SECRET_KEY`

OpenSSL crypto now requires an application secret. There is **no default**. If the key is missing or empty, BoA stops after autoload and reports an error (`missing_app_secret_key`).

You must provide the secret in one of these ways:

1. **Define the constant** in `bootstrap_context.php` (or another early config file loaded before the check), for example:

   ```php
   define('APP_SECRET_KEY', 'your-long-random-secret');
   ```

2. **Or set the environment variable** `APP_SECRET_KEY` (used only when the constant is not defined or is empty).

### `APP_SECRET_KEY` validation

Add the resolution and failure handling from the sample. Typical placement:

1. **Before** `spl_autoload_register('APP_autoload');` — resolve or flag a missing secret:

   ```php
   // Application secret for OpenSSL Crypto. Required: define APP_SECRET_KEY or set the env var.
   // There is no default. A missing key is reported after autoload via ConfService.
   $__boaMissingSecret = false;
   if (!defined('APP_SECRET_KEY') || APP_SECRET_KEY === '') {
       $__boaSecret = getenv('APP_SECRET_KEY');
       if ($__boaSecret === false || $__boaSecret === '' || defined('APP_SECRET_KEY')) {
           $__boaMissingSecret = true;
       } else {
           define('APP_SECRET_KEY', $__boaSecret);
       }
   }
   ```

2. **After** autoload registration and `use BoA\Core\Services\ConfService;` — abort if the secret is still missing:

   ```php
   if (!empty($__boaMissingSecret)) {
       $__boaMessage = ConfService::getCoreMessage('missing_app_secret_key');
       if (PHP_SAPI !== 'cli' && !headers_sent()) {
           header('HTTP/1.1 500 Internal Server Error');
           header('Content-Type: text/plain; charset=UTF-8');
       }
       die($__boaMessage);
   }
   ```

#### Upgrade checklist for the secret

- [ ] Ensure `APP_SECRET_KEY` is set (constant or environment variable) before restarting the application.
- [ ] Prefer a long, random value; do not commit real secrets to version control.
- [ ] If you already encrypt data with a given secret, **keep using the same key**. Changing it will prevent decryption of existing ciphertext.
- [ ] After merging, confirm the app starts (web and CLI). A missing key returns HTTP 500 with a clear message in web requests, or prints the same message and exits in CLI.

### Optional: `DCOFOLDER_SUFFIX`

If your deployment needs a global default suffix for Digital Content Object (DCO) folders, you may define it in `bootstrap_context.php` (often near the other application defines):

```php
// DCO default suffix.
define("DCOFOLDER_SUFFIX", "@localhost.co");
```

Adjust the value to your domain. If this constant is not defined, the DCO access driver falls back to its own default. Repository or plugin configuration can still override the suffix.

---

*Further update steps will be documented in later sections as additional changes are released.*
