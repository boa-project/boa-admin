# Quarantined: dibi under PHP 8.3

This compact dibi build is **not** on the boa-admin critical path (default filesystem auth/conf).

First-party callers now use PDO via `BoA\Core\Utils\Utils::pdoConnectFromDibiParams()` and
`Utils::runCreateTablesQuery()` (PDO). Do **not** `require` this file unless you define
`BOA_ALLOW_DIBI` and accept remaining legacy risk.

Prior surgical patches: removed `set_magic_quotes_runtime`, replaced `create_function`.
Drivers for optional SQL plugins (conf.sql / auth.sql) are still incomplete in this tree.
