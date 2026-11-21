# 4.3B. Эксперимент с качеством AI-описаний

На 12 смысловых запросах число попаданий целевой работы в топ-5 выросло с 10 до 12, а MRR@5 — с 0,729 до 0,819. Число первых мест осталось 8 из 12. Среди трёх контролей по названию число первых мест снизилось с трёх до двух.

## Методика

Использован сохранённый Compose-проект `library-stroll-42a-c1fa67cf`. Сверка MySQL подтвердила 20 публичных работ `421001–421020` со статусом `indexed`, исходными ID точек и хешами. SHA-256 каждого из шести локальных оригиналов совпал с данными 4.3A. Описания составлены по видимым деталям и музейным карточкам, зафиксированы до измерения и после него не редактировались. Полные формулировки смысловых запросов в поисковые тексты не включались.

- **A** — исходная коллекция `librarystroll_artworks_42a_c1fa67cf`. Фильтр автора `user_id=2` исключает контрольную работу `420001` из 4.2A.
- **B** — отдельная коллекция `librarystroll_artworks_43b_descriptions_c1fa67cf`. Все 20 ID точек и payload совпали с A. У 14 работ Vision векторы совпали покоординатно. Шесть векторов Text fallback пересчитаны моделью `qwen3-embedding:0.6b`. Размерность — 1024, метрика — Cosine.
- Поисковый текст собран в порядке `AnalyzeAndIndexArtworkJob`: название, описание публикации, `summary`, английские и русские AI-теги, теги публикации, `objects`, `themes`, `styles`, `mood`, `colors`. Названия, описания и теги публикаций взяты из MySQL. Обновлены все перечисленные AI-поля шести работ. Исходные хеши ревизий и прочий payload оставлены в B для контроля состава.
- Использованы те же 15 запросов и ожидаемые ID из 4.3A. Для каждого запроса модель вычислила один вектор, который передан обеим коллекциям через штатный `QdrantService.search()`, с лимитом 50 и одинаковым фильтром автора и публичности. Каждый вызов вернул одни и те же 20 ID. Допуск всех 20 работ проверен чтением Laravel/MySQL. Метрики относятся к семантическому ранжированию, включая запросы по названию. Laravel Hybrid Search в этом эксперименте отдельно не вызывался.

## Метрики

| Набор | Показатель | Исходный индекс | Улучшенные описания | Изменение |
|---|---|---:|---:|---:|
| Смысловые (12) | Hit@1 | 8/12 (66,7 %) | 8/12 (66,7 %) | 0 п. п. |
| Смысловые (12) | Hit@5 | 10/12 (83,3 %) | 12/12 (100 %) | +16,7 п. п. |
| Смысловые (12) | MRR@5 | 0,729 | 0,819 | +0,090 |
| По названию (3) | Hit@1 | 3/3 (100 %) | 2/3 (66,7 %) | −33,3 п. п. |
| По названию (3) | Hit@5 | 3/3 (100 %) | 3/3 (100 %) | 0 п. п. |
| По названию (3) | MRR@5 | 1,000 | 0,833 | −0,167 |
| Все (15) | Hit@1 | 11/15 (73,3 %) | 10/15 (66,7 %) | −6,7 п. п. |
| Все (15) | Hit@5 | 13/15 (86,7 %) | 15/15 (100 %) | +13,3 п. п. |
| Все (15) | MRR@5 | 0,783 | 0,822 | +0,039 |

Hit@k считает запросы с попаданием ожидаемого ID в первые k позиций. MRR@5 усредняет обратный ранг ожидаемого ID, с нулём при позиции ниже пятой. Для каждого запроса размечена одна цель, поэтому изменение позиции само по себе не определяет релевантность других работ.

## Позиции ожидаемых работ

Контроли по названию — № 5, 14 и 15. Score — оценка Qdrant для ожидаемой работы. Полный порядок с оценками всех 20 кандидатов сохранён в JSON.

| № | Запрос | Ожидаемый ID | Позиция A → B | Score A → B |
|---:|---|---:|---:|---:|
| 1 | Женщина в светлом платье сидит под деревьями в тёмной роще | 421001 | 4 → 1 | 0,4004 → 0,4004 |
| 2 | Птица на фрагменте античной фрески среди зелёных листьев | 421002 | 1 → 1 | 0,6861 → 0,6861 |
| 3 | A quiet ink landscape with distant boats and trees | 421003 | 2 → 1 | 0,6350 → 0,6099 |
| 4 | A still life of fruit framed by orange flowers | 421004 | 20 → 1 | 0,3295 → 0,6420 |
| 5 | Painting Palette | 421009 | 1 → 1 | 0,5120 → 0,5120 |
| 6 | Охристая настенная роспись на штукатурке с повторяющимися многоугольниками | 421005 | 1 → 2 | 0,5378 → 0,5378 |
| 7 | Seated figure in a blue patterned robe on a plain background | 421006 | 17 → 1 | 0,3613 → 0,5918 |
| 8 | Французская роспись в стиле шинуазри с цветами и пагодами | 421007 | 1 → 3 | 0,4625 → 0,4625 |
| 9 | Bold dark Persian calligraphy over tiny painted scenes | 421020 | 1 → 1 | 0,6520 → 0,6520 |
| 10 | Гравюра с художником у настольного мольберта и сидящей моделью в шляпе | 421010 | 1 → 1 | 0,5876 → 0,5876 |
| 11 | Two standing figures flanking a seated person against a blue background | 421011 | 1 → 2 | 0,5018 → 0,5018 |
| 12 | Тёмная сидящая фигура с нимбом на серой фреске | 421013 | 1 → 2 | 0,5075 → 0,5075 |
| 13 | A quiet monochrome ink sketch of a crouching figure beside a smoking vessel | 421016 | 1 → 1 | 0,6630 → 0,6630 |
| 14 | The Muse of Painting [11379] | 421001 | 1 → 1 | 0,4770 → 0,4770 |
| 15 | Calligraphy Painting [770740] | 421020 | 1 → 2 | 0,5550 → 0,5550 |

<details>
<summary>Топ-5 по всем запросам</summary>

| № | Исходный индекс A | Улучшенные описания B |
|---:|---|---|
| 1 | 421006, 421018, 421017, 421001, 421003 | 421001, 421010, 421003, 421007, 421016 |
| 2 | 421002, 421007, 421008, 421017, 421001 | 421002, 421007, 421008, 421004, 421019 |
| 3 | 421006, 421003, 421017, 421018, 421019 | 421003, 421018, 421016, 421007, 421020 |
| 4 | 421007, 421002, 421017, 421016, 421003 | 421004, 421007, 421002, 421018, 421016 |
| 5 | 421009, 421017, 421003, 421019, 421002 | 421009, 421004, 421018, 421002, 421016 |
| 6 | 421005, 421013, 421008, 421009, 421019 | 421004, 421005, 421013, 421019, 421008 |
| 7 | 421013, 421011, 421014, 421015, 421016 | 421006, 421013, 421011, 421017, 421014 |
| 8 | 421007, 421008, 421005, 421014, 421013 | 421004, 421019, 421007, 421008, 421017 |
| 9 | 421020, 421008, 421009, 421011, 421015 | 421020, 421018, 421008, 421009, 421006 |
| 10 | 421010, 421011, 421014, 421013, 421001 | 421010, 421011, 421006, 421017, 421014 |
| 11 | 421011, 421014, 421013, 421016, 421015 | 421006, 421011, 421014, 421017, 421013 |
| 12 | 421013, 421016, 421009, 421010, 421015 | 421006, 421013, 421017, 421016, 421009 |
| 13 | 421016, 421015, 421013, 421010, 421009 | 421016, 421017, 421015, 421006, 421013 |
| 14 | 421001, 421008, 421017, 421010, 421013 | 421001, 421004, 421018, 421019, 421008 |
| 15 | 421020, 421017, 421015, 421013, 421009 | 421018, 421020, 421017, 421003, 421006 |

</details>

## Что изменилось в выдаче

- **Улучшились № 1, 3, 4, 7.** Натюрморт `421004` поднялся с 20-го до 1-го места по запросу № 4, score вырос с 0,3295 до 0,6420. Портрет `421006` поднялся с 17-го до 1-го места по № 7, score вырос с 0,3613 до 0,5918.
- По № 3 пейзаж `421003` поднялся со 2-го до 1-го места, хотя его собственный score снизился с 0,6350 до 0,6099. Прежний лидер `421006`, ошибочно описанный как пейзаж, ушёл из топ-5. По № 1 работа Vision `421001` поднялась с 4-го места на 1-е при неизменном score. Три прежних лидера с Text fallback ушли из топ-5.
- **Ухудшились № 6, 8, 11, 12, 15.** По № 6 натюрморт обошёл фреску `421005` (1 → 2). По № 8 натюрморт и римская роспись `421019` обошли шинуазри `421007` (1 → 3). Портрет `421006` вытеснил с первого места ожидаемые работы по № 11 и 12, связанные с сидящими фигурами.
- **Позиции целей сохранились в № 2, 5, 9, 10, 13, 14.** Score всех 14 неизменённых работ совпал для каждого запроса. Изменения в их позициях вызваны конкуренцией с шестью обновлёнными векторами. Порядок или состав топ-5 изменился во всех 15 запросах.
- Среди контролей по названию цели № 5 и 14 сохранили первое место. По № 15 `Calligraphy Painting [770740]` опустилась на второе место с прежним score, а первое заняла `421018` с новым описанием японской каллиграфии.

На этой выборке визуально точные описания устранили два промаха смыслового поиска и одновременно снизили позиции нескольких прежних лидеров. Улучшать качество описаний стоит до расширения индекса. При измерении эффекта важно сохранять независимые смысловые запросы и контроли по названию. Выборка из 20 работ и одна размеченная цель на запрос ограничивают вывод о качестве всей выдачи.

## Исходные и улучшенные описания

Ниже приведено поле `summary` до и после. Остальные AI-поля и оба полных варианта `searchable_text` сохранены в `description-experiment-results.json`.

### 421003 · Painting \[51776\]

**Исходное.** The artwork is a painting titled 'Painting [51776]'. It features a serene landscape with a river flowing through a valley surrounded by tall trees. The sky is clear with soft clouds, and the overall atmosphere is peaceful and tranquil. The painting uses a soft color palette with shades of blue, green, and white to create a calming effect.

**Улучшенное.** On Bi Chang’s upright album leaf, fine ink lines delineate drooping willows on banks separated by a broad stretch of unpainted water. Three small craft sit low in the composition, and a narrow bridge leads toward the far grove. The warm paper and faint strokes give the distant shore a soft edge. [Карточка The Met](https://www.metmuseum.org/art/collection/search/51776).

### 421004 · Theorem painting \[14170\]

**Исходное.** The artwork 'Theorem painting [14170]' is an abstract representation that blends mathematical concepts with artistic expression. It features geometric shapes and lines that suggest a theorem or equation, possibly symbolizing the relationship between logic and creativity. The piece is rendered in a minimalist style, emphasizing clean lines and precise forms. The color palette is dominated by neutral tones with subtle accents of blue and white, contributing to a calm and intellectual mood. The composition evokes a sense of order and balance, inviting contemplation on the intersection of art and mathematics.

**Улучшенное.** A tilted rust-red tabletop holds a white decorated dish overflowing with fruit. Clusters of pale grapes, a striped melon and round orange and red fruit sit beside it. Stencilled sprays of orange roses curl along the sides and across the lower edge of the peach-colored cloth. [Карточка The Met](https://www.metmuseum.org/art/collection/search/14170).

### 421006 · Painting \[53750\]

**Исходное.** The artwork is a painting titled 'Painting [53750]'. It features a serene landscape with a river flowing through a valley surrounded by tall trees. The scene is bathed in soft morning light, creating a peaceful and tranquil atmosphere. The painting showcases a detailed and realistic depiction of nature, with a focus on the interplay of light and shadow. The overall mood is calm and reflective, inviting the viewer to contemplate the beauty of the natural world.

**Улучшенное.** A Japanese portrait places a cross-legged man low on an open tan field. His broad cobalt sleeves are covered in repeating gold-colored motifs. Pale green trousers, pink-edged layers and a flat black cap pick out the silhouette. A small red seal sits near the lower left. [Карточка The Met](https://www.metmuseum.org/art/collection/search/53750).

### 421017 · Painting \[57329\]

**Исходное.** The artwork is a painting titled 'Painting [57329]'. It features a serene landscape with a river flowing through a valley surrounded by tall trees. The sky is clear with soft clouds, and the overall atmosphere is peaceful and tranquil. The painting is rendered in a realistic style with detailed textures and natural lighting.

**Улучшенное.** Shibata Zeshin sketches a person in profile leaning into a step, with a rounded dark cap and a thin stick in hand. Sparse strokes of grass mark the ground around the feet. Most of the pale paper remains open, emphasizing the contour and the loose brushwork. [Карточка The Met](https://www.metmuseum.org/art/collection/search/57329).

### 421018 · Poetry Painting \[57339\]

**Исходное.** A poetic painting titled 'Poetry Painting [57339]' captures a serene and contemplative scene. The artwork features a solitary figure sitting on a bench under a tree, surrounded by a peaceful landscape. The atmosphere is calm and reflective, evoking a sense of introspection and connection with nature. The painting uses soft, muted tones to enhance the tranquil mood.

**Улучшенное.** Lines of Japanese calligraphy occupy the upper portion of the long composition. Below, two small plants in containers stand on a low table, and a tall vessel with a spout and a loop handle sits beneath it. The outlines are faint against the pale gray paper. [Карточка The Met](https://www.metmuseum.org/art/collection/search/57339).

### 421019 · Wall painting \[248130\]

**Исходное.** The artwork is a wall painting titled [248130]. It features a vibrant depiction of a coastal landscape with a focus on the interaction between the sea and the sky. The painting captures the essence of a calm yet dynamic environment, with waves gently crashing against the shore and a clear blue sky above. The scene is rendered in a style that suggests a blend of realism and artistic interpretation, emphasizing the natural beauty of the coastal setting.

**Улучшенное.** A narrow Roman fresco fragment depicts part of a column. Reddish-brown stripes run down either side of the warm ochre center, and dark curved bands cross it at intervals. Small squared bosses cast painted shadows, and fine cracks run through the worn surface. [Карточка The Met](https://www.metmuseum.org/art/collection/search/248130).

## Воспроизводимость и целостность

Файлы сохранены в `%TEMP%\library-stroll-42a-c1fa67cf`. Для создания экспериментального индекса использован `description-experiment.py`. Повторный прогон в режиме чтения выполнен через `description-experiment-replay.py`. Оба прогона дали одинаковый SHA-256 JSON с выдачей, метриками и описаниями. Топ-5, их score и позиции целей A во всех 15 запросах совпали с сохранёнными результатами 4.3A.

| Артефакт | SHA-256 |
|---|---|
| `search-baseline-plan.json` | `2aa281f553e7b8c73b7027ca7f178244f5310befffd30a88c6d88290c0a387d4` |
| `description-experiment-input.json` | `8014ec490189ad616793d8168f6fcd1a2d4c1bc432e5fe756437bbc7cc79ead6` |
| `description-experiment-results.json` и `description-experiment-replay.json` | `3164166dc2eafbdd67458254b4277e755d70500dc4208d933b29267e4712dac3` |
| Снимки MySQL до и после | `1708d9a45c5bfa806538d6ddaff2cf8cbc0ed9cc4b984e42302f649dcdacf743` |
| Payload и векторы 21 исходной точки Qdrant до и после | `9565459e8b1071c8bf5056d8d039ad44ef4af26d3fde088a1fe2761074062bfc` |

Digest модели Ollama в обоих прогонах — `ac6da0dfba84a81fdbfbaf330198c33cd77c4cdfc53e8bc50eb581914a15621d`. Подтверждение относится к запросам и новым векторам эксперимента. Снимок SQL и контрольная сумма исходной коллекции не изменились. В экспериментальной коллекции сохранены 20 точек. Тестовые контейнеры остановлены без удаления томов. Redis, workers, Scheduler и Vision не запускались.

Для повтора достаточно запустить сохранённый тестовый Qdrant в сети `library-stroll-42a-c1fa67cf_default` и иметь доступ к Ollama на Windows. Из корня репозитория после проверки сети и тома тестового контейнера можно выполнить сборщик чтения.

```powershell
$study = Join-Path $env:TEMP 'library-stroll-42a-c1fa67cf'
$appSource = (Resolve-Path -LiteralPath 'ai-service/app').Path
$runner = @('--rm', '--pull', 'never', '--network', 'library-stroll-42a-c1fa67cf_default',
    '--add-host', 'host.docker.internal:host-gateway',
    '--mount', "type=bind,source=$appSource,target=/service/app,readonly",
    '--mount', "type=bind,source=$study,target=/experiment,readonly",
    '--entrypoint', 'python', 'library-stroll-ai:local',
    '-B', '/experiment/description-experiment-replay.py')
docker run @runner
```
