# 4.2B. Пакетная проверка AI и семантического поиска

## Условия

Прогон 21 ноября 2026 г. выполнен в ветке `docker`, на коде `b0c44a5`. Повторно использован изолированный Compose-проект `library-stroll-42a-c1fa67cf` с собственными MySQL, Redis, Qdrant и хранилищем медиа. Перед запуском проверены сеть, монтирования и пустая очередь `ai-42a`. В коллекции уже находилась одна точка из 4.2A. Для поисковых запросов применён фильтр по отдельному тестовому автору, чтобы исключить эту точку.

Выбраны 20 разных локальных JPEG. Проверены уникальные SHA-256, визуальное содержание и признак `isPublicDomain` в официальном каталоге. В выборке есть пейзаж, птица, натюрморт, портреты, фрагменты настенной росписи и музейные предметы. Названия из каталога дополнены номером объекта. Текстовые описания и теги перед анализом оставлены пустыми. [The Met Open Access](https://www.metmuseum.org/hubs/open-access).

Media Library подготовила миниатюры. Каждая работа поставлена в Redis через `ai:index-artwork`, а следующая отправлялась только после завершения предыдущего задания и опустошения очереди. Первые десять успешно проиндексированы, после чего добавлены ещё десять. Использовались установленные `qwen3-vl:4b`, `qwen3:8b` и `qwen3-embedding:0.6b`. Перед расширением свободно 239 МиБ VRAM из 12 ГиБ. Все три модели уже находились в памяти Ollama, обработка оставалась последовательной.

## Итог

| Показатель | Результат |
|---|---|
| Работы | 20 поставлено, 20 проиндексировано, 0 окончательных ошибок |
| Успешный источник анализа | 14 через Vision, 6 через Text fallback |
| Время выполнения на работу, включая неудачные попытки | Среднее 53,20 с, минимум 23,52 с, максимум 211,38 с. Ожидание повтора в Redis не входит |
| Время успешной попытки | Среднее 45,11 с, минимум 23,52 с, максимум 84,80 с |
| Qdrant | 20 новых точек, 21 всего с контрольной точкой 4.2A. У всех новых точек совпали ID и хеш содержимого с MySQL. Размерность 1024, метрика Cosine |
| Ожидаемая работа в топ-5 | FastAPI — 4/5, Laravel Hybrid Search — 4/5. На первом месте — 2/5 |

## Работы

В таблице указано время успешной попытки. У Text fallback поле Vision в сохранённых метаданных пустое. Все векторы созданы моделью `qwen3-embedding:0.6b`.

| ID | Название и источник | Статус · попытки | Источник анализа | Модель Vision | с | Размерность | ID точки Qdrant | Предупреждение или ошибка |
|---|---|---|---|---|---:|---:|---|---|
| 421001 | [The Muse of Painting \[11379\]](https://www.metmuseum.org/art/collection/search/11379) | `indexed` · 1 | `vision` | `qwen3-vl:4b` | 31,29 | 1024 | `e901f617-387a-59c5-b603-f0298026cd21` | — |
| 421002 | [Wall painting fragment \[245840\]](https://www.metmuseum.org/art/collection/search/245840) | `indexed` · 1 | `vision` | `qwen3-vl:4b` | 39,41 | 1024 | `9f8d1304-8532-5353-adb6-f184c2d8a39d` | — |
| 421003 | [Painting \[51776\]](https://www.metmuseum.org/art/collection/search/51776) | `indexed` · 1 | `text_fallback` | — | 54,79 | 1024 | `61a6d24e-098a-5e77-bca4-24c80a84d3e1` | `ValueError` |
| 421004 | [Theorem painting \[14170\]](https://www.metmuseum.org/art/collection/search/14170) | `indexed` · 1 | `text_fallback` | — | 59,27 | 1024 | `b525a08e-194d-58ad-8c69-783cdc2c99a8` | `ValueError` |
| 421005 | [Wall Painting \[449863\]](https://www.metmuseum.org/art/collection/search/449863) | `indexed` · 1 | `vision` | `qwen3-vl:4b` | 40,00 | 1024 | `132b12f3-2d27-560a-a3ec-1d0172aaca86` | — |
| 421006 | [Painting \[53750\]](https://www.metmuseum.org/art/collection/search/53750) | `indexed` · 1 | `text_fallback` | — | 52,09 | 1024 | `98c5eb0b-17fb-5bf9-ac69-43f0545a0be5` | `ValueError` |
| 421007 | [Decorative Painting \[189543\]](https://www.metmuseum.org/art/collection/search/189543) | `indexed` · 1 | `vision` | `qwen3-vl:4b` | 25,09 | 1024 | `aa29376c-74eb-5c0c-8c59-efac122c6f48` | — |
| 421008 | [Wall painting \[256186\]](https://www.metmuseum.org/art/collection/search/256186) | `indexed` · 1 | `vision` | `qwen3-vl:4b` | 23,52 | 1024 | `76784589-8c7d-54cc-9f54-f331680aa8c9` | — |
| 421009 | [Painting Palette \[556954\]](https://www.metmuseum.org/art/collection/search/556954) | `indexed` · 1 | `vision` | `qwen3-vl:4b` | 33,03 | 1024 | `54eccd06-d3c1-5f59-9051-9b62322c7e37` | — |
| 421010 | [Painting \[670172\]](https://www.metmuseum.org/art/collection/search/670172) | `indexed` · 1 | `vision` | `qwen3-vl:4b` | 28,26 | 1024 | `02073483-b83e-58b6-a979-2c5dcc972152` | — |
| 421011 | [Folk Painting \[38023\]](https://www.metmuseum.org/art/collection/search/38023) | `indexed` · 1 | `vision` | `qwen3-vl:4b` | 26,95 | 1024 | `f28f79a7-7a8c-58ed-b073-9c577f5109b9` | — |
| 421012 | [Folk Painting \[38024\]](https://www.metmuseum.org/art/collection/search/38024) | `indexed` · 1 | `vision` | `qwen3-vl:4b` | 45,89 | 1024 | `6c71cdcc-2335-5f73-8675-261004dd05d6` | — |
| 421013 | [Wall painting \[40323\]](https://www.metmuseum.org/art/collection/search/40323) | `indexed` · 1 | `vision` | `qwen3-vl:4b` | 29,47 | 1024 | `3f3d9a5f-7a89-598b-a1a2-c67064c52c05` | — |
| 421014 | [Figure Painting \[51797\]](https://www.metmuseum.org/art/collection/search/51797) | `indexed` · 1 | `vision` | `qwen3-vl:4b` | 34,47 | 1024 | `907f2c23-cb41-584b-ae9d-038718353cd6` | — |
| 421015 | [Painting \[51867\]](https://www.metmuseum.org/art/collection/search/51867) | `indexed` · 1 | `vision` | `qwen3-vl:4b` | 42,12 | 1024 | `87374a3b-edfd-5e6c-b18f-ebaea5228b3e` | — |
| 421016 | [Painting \[51868\]](https://www.metmuseum.org/art/collection/search/51868) | `indexed` · 1 | `vision` | `qwen3-vl:4b` | 35,05 | 1024 | `d6c5a6f4-7dbb-5205-a4a3-a413a3baeea5` | — |
| 421017 | [Painting \[57329\]](https://www.metmuseum.org/art/collection/search/57329) | `indexed` · 1 | `text_fallback` | — | 84,07 | 1024 | `1477199f-60bd-51cf-b894-53770d961ee6` | `ReadTimeout` |
| 421018 | [Poetry Painting \[57339\]](https://www.metmuseum.org/art/collection/search/57339) | `indexed` · 1 | `text_fallback` | — | 83,10 | 1024 | `1bf8bef2-1f6e-5d31-a213-96f9636a760e` | `ReadTimeout` |
| 421019 | [Wall painting \[248130\]](https://www.metmuseum.org/art/collection/search/248130) | `indexed` · 1 | `text_fallback` | — | 84,80 | 1024 | `cda93fa1-232a-5e04-b53e-1cdfbbfe9afe` | `ReadTimeout` |
| 421020 | [Calligraphy Painting \[770740\]](https://www.metmuseum.org/art/collection/search/770740) | `indexed` · 2 | `vision` | `qwen3-vl:4b` | 49,44 | 1024 | `2c609b77-954a-54af-ad9e-be2c336c82b3` | Первая попытка HTTP 502, `ReadTimeout`, 161,94 с |

## Семантический поиск

Выполнены пять заранее выбранных запросов. В первых четырёх полная фраза отсутствовала в заголовках, у `Painting Palette` было одно лексическое совпадение. FastAPI вернул HTTP 200 для всех запросов. Состав и порядок топ-5 FastAPI и Laravel Hybrid Search совпали на каждом запросе, поэтому приведена общая таблица с исходными score Qdrant.

| Запрос | Ожидаемая работа | Позиция FastAPI | В топ-5 обеих выдач |
|---|---|---:|---|
| 1. Женщина в светлом платье сидит под деревьями в тёмной роще | 421001 · The Muse of Painting \[11379\] | 4 | Да |
| 2. Птица на фрагменте античной фрески среди зелёных листьев | 421002 · Wall painting fragment \[245840\] | 1 | Да |
| 3. A quiet ink landscape with distant boats and trees | 421003 · Painting \[51776\] | 2 | Да |
| 4. A still life of fruit framed by orange flowers | 421004 · Theorem painting \[14170\] | 20 | Нет |
| 5. Painting Palette | 421009 · Painting Palette \[556954\] | 1 | Да |

| Запрос | Место | ID | Название | Score Qdrant |
|---:|---:|---:|---|---:|
| 1 | 1 | 421006 | Painting \[53750\] | 0,466218 |
| 1 | 2 | 421018 | Poetry Painting \[57339\] | 0,454722 |
| 1 | 3 | 421017 | Painting \[57329\] | 0,408762 |
| 1 | 4 | 421001 | The Muse of Painting \[11379\] | 0,400429 |
| 1 | 5 | 421003 | Painting \[51776\] | 0,394353 |
| 2 | 1 | 421002 | Wall painting fragment \[245840\] | 0,686052 |
| 2 | 2 | 421007 | Decorative Painting \[189543\] | 0,500997 |
| 2 | 3 | 421008 | Wall painting \[256186\] | 0,480709 |
| 2 | 4 | 421017 | Painting \[57329\] | 0,400057 |
| 2 | 5 | 421001 | The Muse of Painting \[11379\] | 0,385832 |
| 3 | 1 | 421006 | Painting \[53750\] | 0,638762 |
| 3 | 2 | 421003 | Painting \[51776\] | 0,634968 |
| 3 | 3 | 421017 | Painting \[57329\] | 0,634280 |
| 3 | 4 | 421018 | Poetry Painting \[57339\] | 0,600046 |
| 3 | 5 | 421019 | Wall painting \[248130\] | 0,533656 |
| 4 | 1 | 421007 | Decorative Painting \[189543\] | 0,628760 |
| 4 | 2 | 421002 | Wall painting fragment \[245840\] | 0,546731 |
| 4 | 3 | 421017 | Painting \[57329\] | 0,437653 |
| 4 | 4 | 421016 | Painting \[51868\] | 0,434643 |
| 4 | 5 | 421003 | Painting \[51776\] | 0,431625 |
| 5 | 1 | 421009 | Painting Palette \[556954\] | 0,512002 |
| 5 | 2 | 421017 | Painting \[57329\] | 0,475259 |
| 5 | 3 | 421003 | Painting \[51776\] | 0,464531 |
| 5 | 4 | 421019 | Wall painting \[248130\] | 0,449734 |
| 5 | 5 | 421002 | Wall painting fragment \[245840\] | 0,424006 |

## Обнаруженные проблемы

- Шесть работ завершились через Text fallback. У трёх Vision вернул `ValueError`, у трёх истёк таймаут чтения. Для `ValueError` в журнале доступен класс ошибки.
- Первая попытка `421020` завершилась HTTP 502 после таймаутов Vision и Text fallback. Штатный повтор успешно выполнил Vision. Итого 20 успешных и одна неуспешная попытка, без потерянных работ.
- Запрос о натюрморте с оранжевыми цветами поставил ожидаемую работу `421004` на 20-е место. Работа обработана через Text fallback. На запрос о женщине в роще ожидаемая работа заняла четвёртое место. Эти оценки относятся к пяти запросам на данной выборке.

Тестовый проект остановлен. Четыре тома и коллекция сохранены. Журналы `batch-first.jsonl`, `batch-second.jsonl`, `batch-final-job.jsonl`, `batch-search.jsonl`, `batch-storage.json`, `batch-runtime.log` и манифест источников находятся в `%TEMP%/library-stroll-42a-c1fa67cf`. Рабочие хранилища и данные не затронуты. Код приложения не менялся. Выборка готова к оценке качества на этапе 4.3.
