# Herrera staff restoration

Staff identities use `account_type=staff` and the original OpenCart `admin_username`; customer identities stay separate even when the email matches. Existing native maintenance passwords remain unchanged. Newly restored staff can use their original OpenCart password at `/login`; successful authentication replaces the legacy verifier with the current password hash. Reset or upgraded credentials are never revived by a repeated import.

`herrera:import-staff` defaults to a non-mutating preflight. Apply only to the isolated local migration database or the verified staging shop, after a private database backup and the two staff migrations. On staging, `--legacy-config=/home/herrera/public_html/upload/config.php` reads the existing trusted configuration in process. Source queries run inside a read-only transaction; no hashes or connection credentials are printed.

The reviewed source groups are Administrator and Order Manager. Order Managers get assigned-customer and order access, invoices, currency/language maintenance and read-only geographic zones. Country/custom-field screens and OpenCart API accounts have no identical module in this application; the original permission list remains in staff metadata. OpenCart API-account permission does not grant the unrelated wholesale API configuration permission.

Staff/customer mappings and source-owned assignments are applied atomically. Missing customer mappings and conflicting identities stop the import. The explicit `--include-missing-customers` prerequisite imports only unmapped assigned customers, using existing verified customer-group mappings. It does not reimport existing customer records or the catalog.

In Users, select Order Manager and use **Prijavi se kao**. Only a permitted administrator can start a preview of an enabled Order Manager. Preview regenerates the session, logs entry/exit, prevents nesting and exposes **Vrati se u svoj admin račun**. Order Managers cannot alter roles, login email, password or verification. Revoked original administrators cannot be restored.
