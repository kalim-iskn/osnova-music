# WaveFlow

WaveFlow — современный музыкальный SPA-сервис на **Laravel 13 + Inertia + Vue 3 + PostgreSQL**. Плеер живёт вне контента страницы, поэтому воспроизведение не сбрасывается при переходах между разделами.

## Что внутри

- Laravel 13 + PHP 8.5-ready проект
- PostgreSQL 18
- Inertia.js + Vue 3 для SPA-навигации без полного перезапроса документа
- глобальный плеер с очередью, громкостью, перемоткой, prev / play / pause / next
- лайки треков и раздел `Мои треки`
- поиск по трекам, артистам и альбомам
- адаптивный современный UI
- docker-compose со сборкой фронтенда, миграциями и сидированием на старте

## Быстрый старт

```bash
docker compose up --build
```

После запуска приложение будет доступно на:

- `http://localhost:8080`
- PostgreSQL: `localhost:5432`

## Демо-аккаунт

- email: `demo@waveflow.local`
- password: `password`

## Архитектура

### Backend

- `app/Models` — сущности домена: `Track`, `Artist`, `Album`, `User`
- `app/Services/TrackSearchService.php` — централизованная логика поиска
- `app/Http/Resources` — аккуратная сериализация данных для Inertia-страниц
- `app/Http/Controllers` — тонкие контроллеры
- `database/migrations` — схема БД с индексами и `pg_trgm`

### Frontend

- `resources/js/Layouts/AppLayout.vue` — единый persistent layout
- `resources/js/stores/player.js` — глобальный Pinia store для очереди и состояния плеера
- `resources/js/Components/PlayerBar.vue` — аудиоплеер, который не размонтируется при смене страницы
- `resources/js/Pages/*` — Inertia-страницы

## Почему плеер не сбрасывается

Приложение построено как SPA на Inertia. Контент страниц меняется внутри одного клиентского приложения Vue, а `PlayerBar` находится в общем layout и остаётся смонтированным между переходами.

## Что можно улучшить дальше

- админ-панель для загрузки каталога
- email verification / password reset
- waveforms и media session API
- рекомендации / плейлисты
- real-time presence / collaborative queues
- CDN / object storage для медиа

## Полезные команды внутри контейнера

```bash
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan route:list
docker compose exec app php artisan about
```
