# Ausbildungsnachweis – Überarbeitung (Sicherheit & Wartbarkeit)

Diese Version setzt die im Code-Review besprochenen Punkte um. Unten stehen
die Änderungen je Datei sowie die **notwendigen Deployment-Schritte**.

## Notwendige Schritte vor dem Produktivbetrieb

1. **`php -l` über alle PHP-Dateien laufen lassen** (im Container war kein PHP
   verfügbar, daher wurde nur strukturell geprüft, nicht offiziell gelinted):
   ```
   for f in *.php; do php -l "$f"; done
   ```
2. **Umgebungsvariablen setzen**
   - `AUSBILDUNG_DEV_USER` – NUR auf der Testmaschine setzen (z. B. `GI7LBV5`).
     Auf dem Produktivserver **nicht** setzen, sonst ist die Windows-Anmeldung
     ausgehebelt.
   - `AUSBILDUNG_DB_PATH` – empfohlen: Pfad zur SQLite-Datei **außerhalb** des
     Web-Root (z. B. `D:\Daten\ausbildungsnachweis.sqlite`).
3. **`data/`-Ordner**: enthält jetzt `.htaccess` (Apache) und `web.config`
   (IIS), die den HTTP-Zugriff auf die Datenbank sperren. Greift nur, falls die
   DB doch im Web-Root liegt – die Ablage außerhalb (Punkt 2) bleibt die
   bessere Lösung.

## Änderungen je Datei

### Neu: `helpers.php`
Zentrale Hilfsfunktionen, bisher mehrfach dupliziert:
- `e()` – HTML-Escaping
- `ensureSession()`, `csrfToken()`, `csrfField()`, `requireValidCsrfToken()`
  – CSRF-Schutz (Synchronizer-Token-Pattern)
- `currentAccess(PDO)` – einheitliche Ermittlung aller Zugriffsrechte

### `ldap.php`
- `DEV_TEST_USER`-Konstante entfernt. Stattdessen `devTestUser()`, das den Wert
  ausschließlich aus der Umgebungsvariable `AUSBILDUNG_DEV_USER` liest.

### `db.php`
- `getBerufsschultage()` / `saveBerufsschultage()` aus `initSchema()`
  herausgezogen (waren dort als verschachtelte Funktionen definiert) und auf
  echtes Upsert per `ON CONFLICT(user_id)` umgestellt.
- Doppelte Aufrufe von `migrateWochenberichte()` und doppeltes
  `CREATE TABLE historie` entfernt.
- `getDbFilePath()` berücksichtigt `AUSBILDUNG_DB_PATH`.
- Neue Helfer: `isoWeeksInYear()`, `isValidIsoWeek()` (KW-53-Problem),
  `normalizeTagesStunden()` (0 Stunden bei Urlaub/Krank/Feiertag/Schule).
- Neue Gruppe `admin` (`APPROVAL_GROUP_ADMIN`) inkl. CHECK-Constraint-Migration.
  Admins können damit über die DB gepflegt werden – zusätzlich zur statischen
  Liste in `ldap.php`.

### `index.php`
- Nutzt `helpers.php` und `currentAccess()`.
- CSRF-Prüfung am POST-Anfang, CSRF-Feld im Wochenformular.
- Korrekte ISO-KW-Validierung über `isValidIsoWeek()`.
- Stundennormalisierung über `normalizeTagesStunden()`.

### `admin.php`
- Nutzt `helpers.php` und `currentAccess()`.
- CSRF-Prüfung + Felder in allen POST-Formularen.
- **Bugfix:** Die Aktion `berufsschule_speichern` ist jetzt ein eigener Zweig
  (vorher unerreichbarer Code innerhalb von `ausbilder_genehmigen`) und in der
  erlaubten Aktionsliste. Dazu ein neues UI-Panel „Berufsschultage je Azubi".
- Optimistic Locking: Alle Workflow-Updates nutzen
  `WHERE id = :id AND status = :erwartet` und prüfen `rowCount()`.

### `export_pdf_lib.php`, `export_word.php`, `export_pdf.php`
- Nutzen `helpers.php` / `currentAccess()`.
- `?id`-Pfad abgesichert: fremde Berichte werden nur geladen, wenn der Nutzer
  berechtigt ist.
- Generische Fehlermeldungen nach außen, Details via `error_log()`.

### `style.css`
- Kleines Styling für das neue Berufsschultage-Panel.
