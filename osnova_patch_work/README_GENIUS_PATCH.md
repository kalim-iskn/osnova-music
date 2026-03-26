# Genius integration patch for osnova-music

Что внутри:
- nullable unique `genius_id` для artists / albums / tracks
- `description_preview` для artists и tracks
- `language` + `genius_url` для tracks
- backend sync-сервис для Genius
- обновлённый `tracks:parse` с матчингом Muzofond -> Genius
- сохранение множественных исполнителей через `artist_track`
- новые роуты и страницы трека
- frontend-вывод нескольких исполнителей и описания артиста

Как применить:
1. Забери свежий репозиторий `master`.
2. Скопируй файлы из этого набора поверх проекта.
3. Выполни миграции.
4. При необходимости добавь в `.env`:
   - `GENIUS_API_BASE_URL=https://genius.com/api`
   - `GENIUS_TIMEOUT=20`
   - `GENIUS_CACHE_TTL=86400`
   - `GENIUS_MAX_PAGES=25`
5. Прогони `php artisan tracks:parse muzofond <url>`.

Важно:
- в предоставленных ответах Genius API нет полноценного текста lyrics; патч выводит расширенные метаданные и ссылку/точку входа на Genius, но не сохраняет массово тексты песен в БД
- для matched-артистов сохраняются только те треки Muzofond, для которых найден уверенный матч в Genius
