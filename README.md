# NumberOne (DPLChk2)

Lokaler Dienstplan-Prüfer: PHP-Oberfläche startet Python-Regeln gegen eine JSON-Testdatei.

## Starten

```bash
cp .env.example .env
# In .env lokale DB-Benutzer und Passwörter setzen (nur für Docker auf dem eigenen Rechner)

docker compose up --build
```

- App: http://localhost:8080
- phpMyAdmin: http://localhost:8085

## Lokale `.env`

Passwörter und DB-Zugangsdaten stehen **nicht** in `docker-compose.yaml`, sondern in einer lokalen `.env` (Vorlage: `.env.example`). Die Datei `.env` ist in `.gitignore` und wird nicht committed.

Hinweis: Ältere Commits können noch Klartext-Defaults in der Compose-Datei enthalten. Git-History wurde bewusst nicht umgeschrieben — bei Wiederverwendung derselben Passwörter woanders bitte rotieren.
