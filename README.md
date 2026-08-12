# Bitrix → Google Sheets (Python)

Синхронизация смарт-процесса **«Заявка в бухгалтерию»** (`entityTypeId=1106`) в лист **«Снабжения»**.

Воронки: Служебные записки (62), Счета на оплату (66), Командировочные (68).

## Режимы

1. **CLI** — полная подтяжка через входящий webhook Bitrix  
2. **Realtime** — Flask `POST /action` для исходящего webhook

## Важно про события

Нужны события смарт-процесса (не сделки):

- `ONCRMDYNAMICITEMADD`
- `ONCRMDYNAMICITEMUPDATE`
- `ONCRMDYNAMICITEMDELETE`

Handler: `https://<host>/action`  
Токен → `OUTGOING_WEBHOOK_TOKEN`

## Требования

- Python **3.10+** (лучше 3.11/3.12)
- Входящий Bitrix webhook (`crm`)
- Google Service Account + Sheets API
- Таблица расшарена на email сервисного аккаунта (**Редактор**)

## Установка

```bash
python3 -m venv .venv
source .venv/bin/activate   # Windows: .venv\Scripts\activate
pip install -r requirements.txt
cp .env.example .env        # заполнить значения
```

## Запуск

```bash
# полная синхронизация
python -m app.cli --preview
python -m app.cli --dry-run
python -m app.cli

# realtime webhook server
python -m app.server
```

Проверка: `GET /health` → `{"ok":true}`

### Plesk / Passenger

- Application root: папка проекта  
- Startup file / WSGI: `wsgi.py`  
- Entry point: `application`  
- Document root можно оставить отдельно; важно, чтобы запросы шли на приложение  

Либо через gunicorn:

```bash
gunicorn -b 0.0.0.0:3000 wsgi:application
```

## Env

См. `.env.example`:

- `BITRIX_WEBHOOK_URL`
- `OUTGOING_WEBHOOK_TOKEN`
- `SPREADSHEET_ID`
- `SHEET_NAME=Снабжения`
- `GOOGLE_SERVICE_ACCOUNT_PATH`
- `PORT=3000`

## Колонки листа «Снабжения»

Заголовки в строке **18**, данные с **19**:

| A | B | C | D | E | F | G | H | I | J | K |
|---|---|---|---|---|---|---|---|---|---|---|
| пусто | Наименование | пусто | Статья затрат | Поставщик | Сумма | Дата | Комментарии | Статус | Bitrix ID | Воронка |
