# Bitrix → Google Sheets (PHP)

Простой HTTP-обработчик исходящего вебхука Bitrix: пришёл запрос → записали в лог → обновили Google Sheets.

Точка входа: [`action.php`](action.php)

## Что делает

- Принимает POST от Bitrix
- Логирует **весь входящий запрос** в `logs/requests.log`
- Обрабатывает смарт-процесс **«Заявка в бухгалтерию»** (`entityTypeId=1106`)
- Воронки: 62 / 66 / 68
- **Название поставщика**: Компания; если пусто — Контакт; если оба пусты — пусто
- Лист выбирается по полю Bitrix **«Отдел»**
- Заголовок ищется автоматически (`Наименование`), поддерживаются `Комментарии` и `Комментарий`
- При смене отдела строка удаляется со старого листа и добавляется на новый
- При удалении карточки в Bitrix строка **удаляется** из таблицы (очищается)
- Строка заголовков: 18

## События в исходящем вебхуке Bitrix

- `ONCRMDYNAMICITEMADD`
- `ONCRMDYNAMICITEMUPDATE`
- `ONCRMDYNAMICITEMDELETE`

URL обработчика:

```text
https://abs-group.kazgame-control.su/action.php
```

Токен исходящего вебхука → `OUTGOING_WEBHOOK_TOKEN` в `.env`.

## Установка на хостинг

```bash
cd /var/www/abs-group.kazgame-control.su
composer install --no-dev
cp .env.example .env   # заполнить
mkdir -p logs credentials
chmod 775 logs
# положить credentials/service-account.json
```

Проверка в браузере:

```text
https://abs-group.kazgame-control.su/action.php
```

Ожидается JSON `{"ok":true,...}`.

Смотреть логи:

```bash
tail -f logs/requests.log
```

## Env

- `BITRIX_WEBHOOK_URL` — входящий webhook Bitrix (`crm`)
- `OUTGOING_WEBHOOK_TOKEN` — токен исходящего webhook
- `SPREADSHEET_ID`
- `GOOGLE_SERVICE_ACCOUNT_PATH=./credentials/service-account.json`
- `LOG_FILE=./logs/requests.log`

### Маппинг Отдел → лист

| Bitrix «Отдел» | Лист Google |
|---|---|
| Отдел снабжения | Снабжения |
| Отдел ПТО | ПТО |
| Отдел МОП | Отдел продажа |
| Отдел спецтехники | Отдел спецтехники |
| Отдел производства | Отдел энергетики |
| **Любой другой отдел** | **АУП** |

Если отдел не выбран — строка не пишется (и удаляется, если была).

Колонки: B–I бизнес-поля, **J** Bitrix ID, **K** Воронка, **L** Отделение (название отдела из Bitrix).

## Логи

Каждый запрос пишет в `logs/requests.log`:

- method / uri / ip / content-type
- raw body
- parsed payload
- результат обработки / ошибки

## Требования

- PHP **8.1+**
- расширения: `curl`, `json`, `mbstring`, `openssl`
- Composer
- Google Sheets API + service account с правом **Редактор** на таблицу
