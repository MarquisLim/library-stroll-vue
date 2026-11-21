# 4.4A. Первый пакет исследовательского набора

Все **25 работ** получили `indexed` с первой попытки. Vision обработал 13 работ, Text fallback — 12. Восемь смысловых запросов дали **Hit@1 7/8, Hit@5 8/8 и MRR@5 0,917**.

## Набор

На ветке `docker` (`06d292d`) отобраны 25 разных локальных оригиналов из демонстрационного каталога. Сверены SHA-256 всех файлов и ключи источника. С образцами 4.2B и 4.3 пересечений нет. Категории включают пейзажи, животных, портреты, архитектуру, натюрморты, музейные предметы, абстракцию и декор.

По сохранённым карточкам и метаданным импорта 19 работ имеют лицензию CC0, 2 отмечены как Public Domain, 4 доступны по CC BY. Для CC BY в манифесте сохранены версия лицензии, автор и атрибуция. Семь работ получили описания по музейным карточкам, остальные 18 — по доступным полям каталога, включая автора, дату, технику и предметные теги. Полные фразы контрольных запросов в заголовках и описаниях отсутствовали. [Политика The Met Open Access](https://www.metmuseum.org/hubs/open-access).

| ID | Название | Категория | Анализ | Время, с | Предупреждение Vision | Источник и лицензия |
|---:|---|---|---|---:|---|---|
| 441001 | Italian Landscape with Umbrella Pines | Пейзаж | Vision | 43,64 | — | [Commons](<https://commons.wikimedia.org/wiki/File:Hendrik_Voogd_-_Italian_landscape_with_Umbrella_Pines.jpg>) · Public Domain |
| 441002 | Landscape along the Laugavegur trail | Пейзаж | Vision | 20,07 | — | [Commons](<https://commons.wikimedia.org/wiki/File:Landscape_during_Laugavegur_hiking_trail_2-CA_reduced.jpg>) · CC BY 3.0 |
| 441003 | Farm Workers and Mount Williamson | Пейзаж | Vision | 35,65 | — | [Commons](<https://commons.wikimedia.org/wiki/File:Ansel_Adams_-_Farm_workers_and_Mt._Williamson.jpg>) · Public Domain |
| 441004 | Landscape (recto); Landscape (verso) | Пейзаж | Text fallback | 57,53 | `ValueError` | [The Met](<https://www.metmuseum.org/art/collection/search/340468>) · CC0 |
| 441005 | Mynah Birds | Животные | Text fallback | 55,01 | `ReadTimeout` | [The Met](<https://www.metmuseum.org/art/collection/search/912024>) · CC0 |
| 441006 | Nesting Birds | Животные | Vision | 29,08 | — | [The Met](<https://www.metmuseum.org/art/collection/search/544584>) · CC0 |
| 441007 | Landsvale (Barn Swallow) | Животные | Text fallback | 50,23 | `ValueError` | [Commons](<https://commons.wikimedia.org/wiki/File:Landsvale.jpg>) · CC BY 2.5 |
| 441008 | Dead Birds | Животные | Text fallback | 50,35 | `ValueError` | [The Met](<https://www.metmuseum.org/art/collection/search/338621>) · CC0 |
| 441009 | Self-Portrait in Costume | Люди и портреты | Vision | 32,96 | — | [The Met](<https://www.metmuseum.org/art/collection/search/285630>) · CC0 |
| 441010 | Self-Portrait of Roger Fenton | Люди и портреты | Vision | 26,59 | — | [The Met](<https://www.metmuseum.org/art/collection/search/286386>) · CC0 |
| 441011 | Portrait of the artist | Люди и портреты | Text fallback | 51,67 | `ValueError` | [The Met](<https://www.metmuseum.org/art/collection/search/811859>) · CC0 |
| 441012 | Self Portrait - Eyes Shut | Люди и портреты | Vision | 35,06 | — | [Flickr / Openverse](<https://www.flickr.com/photos/58724736@N00/5329756763>) · CC BY 2.0 |
| 441013 | Plate from "Varie Architecture" | Архитектура | Vision | 26,82 | — | [The Met](<https://www.metmuseum.org/art/collection/search/398892>) · CC0 |
| 441014 | Design for Garden Architecture | Архитектура | Vision | 29,60 | — | [The Met](<https://www.metmuseum.org/art/collection/search/340410>) · CC0 |
| 441015 | Landscape with Ruined Architecture | Архитектура | Text fallback | 49,89 | `ValueError` | [The Met](<https://www.metmuseum.org/art/collection/search/459949>) · CC0 |
| 441016 | Stage Design, Antique Buildings | Архитектура | Vision | 22,35 | — | [Cooper Hewitt](<https://collection.cooperhewitt.org/view/objects/asitem/id/62320>) · CC0 |
| 441017 | Still Life with Cake | Натюрморт | Vision | 32,95 | — | [The Met](<https://www.metmuseum.org/art/collection/search/11737>) · CC0 |
| 441018 | Still Life with Peaches | Натюрморт | Text fallback | 49,99 | `ValueError` | [The Met](<https://www.metmuseum.org/art/collection/search/437429>) · CC0 |
| 441019 | Still Life: Fish | Натюрморт | Text fallback | 50,68 | `ValueError` | [The Met](<https://www.metmuseum.org/art/collection/search/10476>) · CC0 |
| 441020 | Fruit bowl | Музейный предмет | Vision | 21,92 | — | [The Met](<https://www.metmuseum.org/art/collection/search/196631>) · CC0 |
| 441021 | Cup with historical figures | Музейный предмет | Text fallback | 49,97 | `ValueError` | [The Met](<https://www.metmuseum.org/art/collection/search/46412>) · CC0 |
| 441022 | Jenny Lind Paper Doll Costume, Singer in Concert-Toilette | Музейный предмет | Text fallback | 51,57 | `ValueError` | [Cooper Hewitt](<https://collection.cooperhewitt.org/view/objects/asitem/id/106458>) · CC0 |
| 441023 | Decorative Drawing | Абстракция и декор | Text fallback | 49,87 | `ValueError` | [The Met](<https://www.metmuseum.org/art/collection/search/450385>) · CC0 |
| 441024 | Webtreats Tileable Light Blurs and Abstract Circle Patterns Red 1 | Абстракция и декор | Text fallback | 49,40 | `ValueError` | [Flickr / Openverse](<https://www.flickr.com/photos/44071822@N08/4470784292>) · CC BY 2.0 |
| 441025 | Textile Fragment with Sailing Ships | Абстракция и декор | Vision | 29,48 | — | [The Met](<https://www.metmuseum.org/art/collection/search/450495>) · CC0 |

## Индексация

Проект `library-stroll-44-dataset` использовал собственные сеть и четыре тома для MySQL, Redis, Qdrant и медиа. Проверены реальные монтирования контейнеров и адреса сервисов. Использовались база `library_stroll_44_dataset`, очередь `ai-44-dataset` и коллекция `librarystroll_artworks_44_dataset`. Рабочий контур не задействовался. Из демонстрационного каталога прочитаны только файлы оригиналов и манифеста.

После создания публикаций через Laravel и Media Library сборщик вызывал штатную команду `ai:index-artwork` для каждого ID. Следующая работа отправлялась после завершения предыдущей и проверки, что Redis-очередь пуста. Индексацию выполнил один `worker-ai`. На старте наблюдалось 8 257 МиБ свободной VRAM из 12 288 МиБ на RTX 3060, во время Vision — 3 133 МиБ в одной контрольной точке замера.

| Показатель | Результат |
|---|---|
| Поставлено / успешно / окончательно failed | 25 / 25 / 0 |
| Источник анализа | Vision `qwen3-vl:4b` — 13. Text fallback `qwen3:8b` — 12 |
| Время задания | В среднем 40,09 с, диапазон 20,07–57,53 с |
| Среднее по источнику | Vision — 29,71 с. Text fallback с попыткой Vision — 51,35 с |
| Весь пакет | 17 мин 03 с от первой постановки до завершения с учётом опроса очереди |
| Qdrant | 25 точек, 1024 измерения, Cosine, `qwen3-embedding:0.6b` |
| Сверка с MySQL | 25 совпадений по ID работы, ID точки и хешу содержимого |

В таблице работ указано время задания по `ai_processing_runs`. Для каждой работы в `index.jsonl` сохранены статус, источник и имена моделей, время, одна попытка, размерность 1024, ID точки Qdrant и предупреждения.

## Семантический поиск

После индексации зафиксированы четыре русских и четыре английских запроса с одной ожидаемой работой на каждый. Разметка опирается на оригиналы и сведения источников. FastAPI получил восемь запросов через штатный Laravel-клиент с фильтром тестового автора `user_id=1`. Для каждого запроса проверены 25 результатов. Ниже приведены первые пять с оценкой Qdrant.

| № | Язык | Запрос | Ожидаемый ID и источник анализа | Место | Топ-5, ID и score |
|---:|---|---|---|---:|---|
| 1 | ru | Под серым небом ручей петляет по каменистому дну между зелёными склонами | 441002 · Vision | 1 | 441002 (0,3901), 441001 (0,3057), 441004 (0,3028), 441019 (0,2968), 441006 (0,2796) |
| 2 | en | A blue bird with a forked tail perches sideways on a wooden rail | 441007 · Text fallback | 3 | 441006 (0,4548), 441008 (0,4079), 441007 (0,4040), 441005 (0,3787), 441019 (0,3318) |
| 3 | ru | Старинный фотопортрет мужчины в большом кудрявом парике и сценическом костюме | 441009 · Vision | 1 | 441009 (0,5330), 441010 (0,5227), 441011 (0,4607), 441012 (0,4539), 441016 (0,3863) |
| 4 | en | A deep arch frames a distant facade between rows of stone columns | 441013 · Vision | 1 | 441013 (0,5325), 441016 (0,4641), 441014 (0,4445), 441015 (0,3838), 441006 (0,3547) |
| 5 | ru | Несколько рыб на тарелке и рядом с ней, почти чёрный фон | 441019 · Text fallback | 1 | 441019 (0,5198), 441006 (0,3618), 441015 (0,2872), 441025 (0,2837), 441024 (0,2778) |
| 6 | en | A clear glass bowl on a short pedestal with a scalloped edge | 441020 · Vision | 1 | 441020 (0,6213), 441021 (0,4169), 441019 (0,4162), 441017 (0,3827), 441018 (0,3490) |
| 7 | ru | Россыпь жёлтых светящихся кругов на красно-чёрном фоне | 441024 · Text fallback | 1 | 441024 (0,5156), 441019 (0,3234), 441025 (0,3147), 441003 (0,3042), 441002 (0,2982) |
| 8 | en | Farm labourers tending parallel furrows beneath a jagged mountain ridge | 441003 · Vision | 1 | 441003 (0,5075), 441002 (0,4087), 441015 (0,3486), 441004 (0,3089), 441001 (0,2759) |

| Набор | Запросов | Hit@1 | Hit@5 | MRR@5 |
|---|---:|---:|---:|---:|
| Все запросы | 8 | 7/8 | 8/8 | 0,917 |
| Цель обработана Vision | 5 | 5/5 | 5/5 | 1,000 |
| Цель обработана Text fallback | 3 | 2/3 | 3/3 | 0,778 |

MRR@5 — среднее обратное место ожидаемой работы, с нулём при позиции ниже пятой. Все пять контрольных целей Vision стали первыми. Среди трёх целей Text fallback первое место получили натюрморт с рыбами и абстрактная графика. Ласточка `441007` стала третьей после двух других изображений птиц. Группы различаются по темам и размеру, поэтому этот замер показывает поведение на восьми запросах и не изолирует эффект источника анализа.

## Проблемы и продолжение

Vision переключился на Text fallback на 12 из 25 работ (48 %). В одиннадцати случаях FastAPI зафиксировал `ValueError` без подробной причины в предупреждении, в одном — `ReadTimeout` у `441005`. Каждая работа всё равно получила `indexed` с первой попытки задания. Выборку можно расширять до 50 в том же проекте, сохраняя метаданные источника и отдельно отмечая случаи Text fallback.

Исходные коллекции 4.3A и 4.3B не использовались в эксперименте. Поиск не изменил снимки тестовой MySQL и Qdrant. Проект 4.4 остановлен, его тома, коллекция и результаты сохранены.

## Артефакты

Файлы находятся в `storage/app/ai-stage-04-04`.

- `manifest.json` и `images/` — 25 исходных файлов, ключи, лицензии, ссылки, SHA-256 и тексты, переданные при создании работ.
- `index.jsonl` и `search.jsonl` — журнал каждого задания и полная выдача восьми запросов. `metrics.json` содержит сводные расчёты.
- `query-plan.json` — тексты запросов, ожидаемые ID и визуальные основания разметки. SHA-256 плана — `9a86120d8bf62c3a7ce002108e351c21f83237834b92b025599b61d8aa876946`.
- `integrity.json` и `shutdown.json` — контрольные суммы, параметры моделей и подтверждение сохранения изолированных томов.
- `compose.dataset.yaml` и `isolated.env` — настройки тестового проекта. `seed.php`, `index.php`, `measure.php` — локальные сборщики. Они создают тестовые записи, вызывают штатную команду индексации и измеряют выдачу соответственно.
