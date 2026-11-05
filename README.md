# BOARDRIDERS — стартовый набор разработки

Это **не готовый магазин**, а воспроизводимый первый этап: Next.js + Laravel + PostgreSQL + Redis с проверкой соединения API и базы данных. Дизайн пока технический; витрину будем собирать по Figma.

## Что потребуется на Windows

1. Node.js 20.9+ и npm (лучше актуальную LTS).
2. PHP 8.3+ с расширением `pdo_pgsql`.
3. Composer.
4. Docker Desktop (запущен, с Linux containers).
5. PowerShell и доступ в интернет для скачивания зависимостей.

Проверка в PowerShell:

```powershell
node -v
npm -v
php -v
php -m | Select-String pdo_pgsql
composer --version
docker compose version
```

## Установка

Распакуйте ZIP в `D:\coding`: появится папка `D:\coding\boardriders`, содержащая `compose.yaml` и `setup.ps1`.

Открыть PowerShell:

```powershell
cd D:\coding\boardriders
powershell -ExecutionPolicy Bypass -File .\setup.ps1
```

Скрипт поднимет локальные PostgreSQL/Redis в Docker, установит свежий Next.js и Laravel 13, создаст API-роут и запустит миграции. При повторном запуске он не пересоздаёт существующие приложения и не перезаписывает страницу Next.js.

## Запуск после установки

В первом окне PowerShell:

```powershell
cd D:\coding\boardriders\backend
php artisan serve --host=127.0.0.1 --port=8000
```

Во втором окне:

```powershell
cd D:\coding\boardriders\frontend
npm run dev
```

Открыть:

- http://localhost:3000 — проверочная страница Next.js.
- http://127.0.0.1:8000/api/health — `{"service":"boardriders-api","status":"ok","database":"connected"}`.

Если Laravel запущен и PostgreSQL подключён, техническая страница должна показывать «Подключено».

## Локальные порты

| Сервис | Порт на Windows | Порт внутри Docker |
|---|---:|---:|
| Next.js | 3000 | не в Docker |
| Laravel | 8000 | не в Docker |
| PostgreSQL | 55432 | 5432 |
| Redis | 56379 | 6379 |

Порты PostgreSQL/Redis нестандартные намеренно, чтобы не пересекаться с уже работающими локальными проектами.

PostgreSQL для pgAdmin:

- Host: `127.0.0.1`
- Port: `55432`
- Database: `boardriders`
- User: `boardriders`
- Password: `boardriders_dev_only`

**Внимание:** пароль в этом наборе — только для локальной разработки. Никогда не переносить его в production. Для продакшена потребуются отдельные секреты и ограничение доступа.

## Структура после установки

```text
boardriders/
├── frontend/             Next.js, TypeScript, Tailwind
├── backend/              Laravel 13, API, migrations
├── templates/            Шаблоны первого запуска
├── compose.yaml          PostgreSQL + Redis
├── setup.ps1             Одноразовая настройка
└── README.md
```

## Следующая задача

Сделать первую группу Laravel migrations: бренды, категории, атрибуты, товары, товарные варианты (SKU), цены, склады и остатки. Сразу предусмотреть Украину (UAH, украинский язык), расширение на другие валюты и локализации, несколько поставщиков, резервирование товара при заказе.

## Если возникнет ошибка

Пришлите **полный вывод PowerShell** начиная с первой ошибки. Не присылайте содержимое файлов `.env` с рабочими секретами.
