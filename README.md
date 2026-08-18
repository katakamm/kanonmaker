# Kanonmaker

Nástroj pro sestavení maturitního seznamu četby podle školního kánonu.

- **Studentský web:** http://kata.doma.slimak.cz/
- **Administrace:** http://kata-admin.doma.slimak.cz/

## Dokumentace

- Návrh: [`docs/superpowers/specs/2026-08-18-kanonmaker-design.md`](docs/superpowers/specs/2026-08-18-kanonmaker-design.md)
- Plány: [`docs/superpowers/plans/`](docs/superpowers/plans/)

## Vývoj

Vše běží v kontejneru `kanon-www`:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www vendor/bin/phpunit   # testy
sudo docker exec -w /data/www/kanonmaker kanon-www php bin/migrate      # migrace
sudo docker exec -w /data/www/kanonmaker kanon-www php bin/import       # import kánonu
sudo docker exec -w /data/www/kanonmaker kanon-www php bin/create-admin <e-mail> <heslo> <jméno>
```

Kontejnery se spouštějí z `startup/`:

```bash
cd startup && sudo docker-compose up -d
```

Pozor: `docker-compose` v1 na tomto serveru neumí kontejner *znovu vytvořit*
(`KeyError: 'ContainerConfig'`). Když je potřeba změnit `docker-compose.yaml`:

```bash
sudo docker rm -f kanon-www $(sudo docker ps -aq --filter name=_kanon-www)
cd startup && sudo docker-compose up -d
```

## Co je potřeba mít vytvořené

- `config.local.php` — přístup k databázi (není v gitu, vzor je `config.local.php.example`)
- `startup/.env` — hesla k databázi (není v gitu, vzor je `startup/.env.example`)
- `session/` a `tmp/` — PHP je používá; bez `session/` se tiše rozbijí formuláře

## Barvy

Celá paleta je v `public/assets/tokens.css`. Nikde jinde v projektu nesmí být
konkrétní barva — výměna palety je úprava jediného souboru.

## Administrace

`http://kata-admin.doma.slimak.cz/` — jen pro účty s rolí `admin`.

- **Ke kontrole** — potvrzování značek, které doplnil import. Skupiny jsou
  seřazené podle důležitosti; „Potvrdit všech N“ vyřídí celou skupinu naráz.
- **Díla** — úprava názvu, poznámky a značek jednoho díla.
- **Pravidla** — čísla ze školních kritérií. Uložit nejde nic, co by kontrola
  neuměla vyhodnotit.
- **Import** — nejdřív nanečisto, pak doopravdy.
- **Uživatelé** — role a blokování účtů.

Prvního správce založí:

```bash
sudo docker exec -w /data/www/kanonmaker kanon-www php bin/create-admin <e-mail> <heslo> <jméno>
```
