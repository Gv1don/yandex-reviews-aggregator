# Yandex Reviews Aggregator

Laravel + Vue 3 SPA для сбора и просмотра отзывов организаций с Яндекс.Карт.

Демо: [https://rating.kvv.dev](https://rating.kvv.dev) — `test@example.com` / `password`

---

## Возможности

- Добавление любой организации с Яндекс.Карт по ссылке
- Парсинг всех доступных отзывов (до ~600), среднего рейтинга, количества оценок
- Постраничный просмотр отзывов (50 на страницу)
- Добавление нескольких организаций, переключение между ними
- Обновление данных по кнопке Refresh

## Стек

- **Backend**: Laravel 13, PHP 8.4, MySQL 8.0
- **Frontend**: Vue 3 (Composition API, `<script setup>`), Vue Router, Axios, Tailwind CSS 4
- **Auth**: Laravel Sanctum (SPA cookie-based)
- **Scraper**: Puppeteer (Chromium headless) — отдельный Docker-сервис
- **Infrastructure**: Docker Compose (4 контейнера: app, frontend, scraper, mysql)

## Быстрый старт

```bash
# 1. Клонировать и запустить
git clone <repo-url> && cd laravel-rating-service
cp .env.example .env   # настроить при необходимости
docker compose up -d --build

# 2. Установка зависимостей и настройка
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed

# 3. Открыть http://localhost:3000
#    Логин: test@example.com / password
```

## Переменные окружения

| Переменная | По умолчанию | Описание |
|---|---|---|
| `APP_URL` | `http://localhost` | URL приложения для CORS/Sanctum |
| `DB_HOST` | `mysql` | Хост БД (Docker service name) |
| `DB_DATABASE` | `rating` | Название БД |
| `DB_USERNAME` | `rating` | Пользователь БД |
| `DB_PASSWORD` | `root` | Пароль БД |
| `SCRAPER_BASE_URL` | `http://scraper:3099` | URL headless-скрапера |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost,127.0.0.1` | Домены для SPA-аутентификации |

## Архитектура

```
Пользователь → Nginx (frontend:3000) → Laravel API (app:8000) → MySQL
                                            ↕
                                     Headless scraper (scraper:3099)
```

- **Frontend** (nginx, порт 3000): раздаёт собранный Vue SPA, проксирует `/api/*` и `/sanctum/*` в Laravel
- **Backend** (Laravel, порт 8000): API, аутентификация, бизнес-логика
- **Scraper** (Node.js/Puppeteer, порт 3099): headless-браузер для парсинга
- Sanctum SPA-аутентификация: cookie-сессия, CSRF-защита

## API Endpoints

| Метод | Путь | Auth | Описание |
|---|---|---|---|
| POST | `/api/login` | — | Вход (email + password) |
| POST | `/api/logout` | Да | Выход |
| GET | `/api/user` | Да | Текущий пользователь |
| GET | `/api/organizations` | Да | Список организаций |
| POST | `/api/organization` | Да | Добавить/обновить организацию |
| GET | `/api/organizations/{id}` | Да | Данные организации |
| DELETE | `/api/organizations/{id}` | Да | Удалить организацию |
| GET | `/api/reviews?organization_id=&page=` | Да | Отзывы (50/стр) |

## Подход к парсингу

Официального API у Яндекс.Карт нет. Парсинг — в два этапа:

### 1. HTML-скрапинг (PHP, `tryHtmlScraping`)

Прямой HTTP-запрос к странице организации с русским User-Agent и Accept-Language. Из HTML извлекаются:
- Structured data (`application/ld+json`) — название, адрес, рейтинг, часть отзывов
- Мета-теги (`og:title`, `og:description`) — название, адрес
- DOM-парсинг блоков `.business-review-view` — текст отзывов, автор, дата, оценка

**Плюсы**: быстро (секунды), не требует браузера.
**Минусы**: отзывы грузятся лениво, доступно только ~100 из ~600.

### 2. Headless-браузер (Node.js/Puppeteer, `tryHeadlessBrowser`)

Отдельный Docker-сервис на Puppeteer с Chromium. Открывает страницу `/reviews/` и скроллит контейнер `.scroll__container` до 80 итераций, дожидаясь подгрузки всех ленивых отзывов.

**Плюсы**: все отзывы (до ~600), полные данные (автор, дата, текст, оценка).
**Минусы**: медленнее (20–60 сек), потребляет больше ресурсов.

Результаты двух методов объединяются: данные организации из HTML, отзывы — из headless-браузера (приоритет).

### Обход защиты

- User-Agent реального браузера Chrome 125
- `Accept-Language: ru-RU,ru;q=0.9`
- Headless-браузер с viewport 1440×900
- Пауза 3 секунды перед чтением DOM
- Скроллинг с `scrollBy` для триггера lazy-load

## Структура БД

```
users
  id, name, email, password, ...

organizations
  id, user_id, org_id, yandex_url, name, address,
  average_rating, ratings_count, reviews_count, parsed_at

reviews
  id, organization_id, author, date, text, rating,
  external_id (unique per organization),
  UNIQUE(external_id, organization_id)
```

## Что можно улучшить

- **Фоновая очередь для парсинга**: сейчас парсинг синхронный — пока scraper собирает данные, пользователь ждёт. Сделать через Laravel Queue (Redis) с вебсокет-уведомлением о готовности.
- **Кэширование**: сохранять результаты парсинга и не перезапрашивать при каждом открытии страницы (сейчас данные кэшируются в БД, но при каждом просмотре отдаются из БД).
- **Автоматическое обновление**: шедулер для периодического обновления данных организаций (Cron + Queue).
- **Уведомления об ошибках**: если парсер упал (изменилась разметка, сайт недоступен), писать в лог и оповещать администратора.
- **Кросс-провайдерность**: поддержка не только Яндекс.Карт, но и 2ГИС, Google Maps, Zoon.
- **Экспорт**: выгрузка отзывов в CSV/Excel.
- **Покрытие тестами**: Feature-тесты для API, Unit-тесты для парсера.
- **CI/CD**: GitHub Actions для автоматического тестирования и деплоя.

## Команды

```bash
docker compose exec app php artisan debug:run       # Проверить парсер
docker compose exec app php artisan demo:generate   # Сгенерировать демо-данные
docker compose exec app php artisan migrate:fresh   # Сбросить БД
```
