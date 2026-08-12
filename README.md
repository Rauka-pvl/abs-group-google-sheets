# Bitrix → Google Sheets (Бухгалтерия)

Синхронизация карточек смарт-процесса **«Заявка в бухгалтерию»** (`entityTypeId=1106`) в лист Google Таблицы **«Снабжения»**.

Воронки: Служебные записки (62), Счета на оплату (66), Командировочные (68).

Два режима:

1. **CLI** (`npm run sync`) — полная подтяжка через **входящий** webhook Bitrix
2. **Realtime** (`npm start`) — сервер `POST /action` для **исходящего** webhook Bitrix

## Важно про события

Эти воронки — **не классические сделки**, а смарт-процесс.  
`ONCRMDEALADD / ONCRMDEALUPDATE / ONCRMDEALDELETE` для них **не сработают**.

В исходящем вебхуке укажите:

- `ONCRMDYNAMICITEMADD`
- `ONCRMDYNAMICITEMUPDATE`
- `ONCRMDYNAMICITEMDELETE`

Handler: `https://<ваш-хост>/action`  
Токен исходящего вебхука → `OUTGOING_WEBHOOK_TOKEN` в `.env`.

## Что делает

- Пишет/обновляет строки в Google Sheets по колонке **Bitrix ID**
- При удалении карточки ставит статус **`Удалено`**
- Фильтрует только воронки 62 / 66 / 68 внутри SPA 1106
- Направление только Bitrix → Google

## Настройка

### 1. Bitrix incoming webhook

Входящий webhook с правом **`crm`** (для CLI и чтения карточек по событию).

### 2. Bitrix outgoing webhook

1. URL обработчика: `https://<host>/action`
2. События: `ONCRMDYNAMICITEMADD`, `ONCRMDYNAMICITEMUPDATE`, `ONCRMDYNAMICITEMDELETE`
3. Токен → `OUTGOING_WEBHOOK_TOKEN`

### 3. Google Service Account

1. Включить **Google Sheets API**
2. Создать Service Account → JSON в `credentials/service-account.json`
3. Расшарить таблицу на email сервисного аккаунта (**Редактор**)

### 4. Env

```bash
cp .env.example .env
```

- `BITRIX_WEBHOOK_URL` — входящий webhook
- `OUTGOING_WEBHOOK_TOKEN` — токен исходящего webhook
- `SPREADSHEET_ID`, `SHEET_NAME=Снабжения`
- `GOOGLE_SERVICE_ACCOUNT_PATH`
- `PORT=3000`

## Установка и запуск

Нужен **Node.js 18+** (лучше 20 LTS). На Plesk/хостинге в настройках Node.js приложения выберите версию **18/20**, не 10/12/14.

```bash
npm install          # соберёт dist/ через postinstall
npm start            # production: node dist/server.js
npm run sync         # полная синхронизация
npm run sync:dry
npm run sync:preview
```

Локальная разработка:

```bash
npm run dev          # tsx watch
npm run dev:sync
```

На хостинге startup-файл: `dist/server.js` (или команда `npm start`).  
Проверка: `GET /health` → `{ "ok": true }`

Если ошибка `SyntaxError: Unexpected token {` в `tsx` — это старый Node. Обновите версию Node в панели хостинга.

## Колонки листа «Снабжения»

Заголовки в **строке 18**, данные с **19** (до блока «Примечания»).

| A | B | C | D | E | F | G | H | I | J | K |
|---|---|---|---|---|---|---|---|---|---|---|
| _(пусто)_ | Наименование | _(пусто)_ | Статья затрат | Название поставщика | Сумма | Дата | Комментарии | Статус | Bitrix ID | Воронка |

- Колонки **A** и **C** — разделители шаблона
- **Название поставщика** пока пусто
- **Дата** = `createdTime`
- Новые карточки пишутся в свободные строки (пустой B)
- J/K добавляются автоматически
