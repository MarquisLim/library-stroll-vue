# Инструкция по выполнению задания по многоуровневому моделированию

## Что уже готово

1. ✅ **Описание предметной области** - `docs/metamodeling-plan.md`
2. ✅ **JSON-модель Library Stroll** - `case-tool/models/library_stroll.json`
3. ✅ **CASE-инструмент** - `case-tool/main.py`
4. ✅ **Пример отчета** - `docs/report-example.md`

## Пошаговый план выполнения

### Шаг 1: Изучите материалы

1. Прочитайте `docs/metamodeling-plan.md` - там описана ваша предметная область и все уровни M3-M0
2. Изучите `case-tool/models/library_stroll.json` - это ваша модель M1
3. Посмотрите `case-tool/main.py` - это CASE-инструмент

### Шаг 2: Запустите CASE-инструмент

```bash
cd case-tool

# Валидация модели
python main.py validate models/library_stroll.json

# Генерация диаграммы
python main.py visualize models/library_stroll.json diagram.dot

# Если установлен Graphviz, создайте SVG:
dot -Tsvg diagram.dot -o diagram.svg
```

### Шаг 3: Создайте диаграмму четырехуровневой иерархии

Используйте любой инструмент (draw.io, Lucidchart, или даже PowerPoint):
- M3 (верхний уровень) - Лингвистическая метамодель
- M2 (второй уровень) - Онтологическая метамодель  
- M1 (третий уровень) - Концептуальная модель Library Stroll
- M0 (нижний уровень) - Реализация в Laravel

Пример структуры есть в `docs/report-example.md` (раздел 2).

### Шаг 4: Подготовьте фрагменты метамодели

#### UML диаграмма классов для M2 (онтологическая метамодель)

Покажите классы:
- `Entity` (с атрибутами: name, attributes, relationships)
- `Attribute` (name, type, constraints)
- `Relationship` (type, source, target, cardinality)
- `Constraint` (type, expression)

#### Код метамодели

Уже есть в `case-tool/main.py`:
- Классы `Entity`, `Attribute`, `Relationship`, `Constraint`
- Enum'ы `DataType`, `RelationshipType`, `ConstraintType`
- Валидатор `MetamodelValidator`

### Шаг 5: Опишите концептуальную модель M1

Используйте раздел 5 из `docs/report-example.md`:
- Диаграмма ER (можно использовать DOT из инструмента)
- Описание ключевых сущностей
- JSON-представление (уже готово)

### Шаг 6: Покажите реализацию M0

Используйте существующие миграции Laravel:
- `database/migrations/2024_12_13_014037_create_artworks_table.php`
- `database/migrations/2024_12_13_014051_create_comments_table.php`
- И т.д.

Покажите соответствие:
- M1 Entity → M0 Table
- M1 Attribute → M0 Column
- M1 Relationship → M0 Foreign Key

### Шаг 7: Расширьте CASE-инструмент (опционально)

Можно добавить:
- Генерацию SQL миграций
- Генерацию Laravel моделей
- XML парсер
- Интерактивный редактор

### Шаг 8: Напишите анализ применимости

Используйте раздел 8 из `docs/report-example.md`:
- Преимущества подхода
- Ограничения
- Когда применять / не применять
- Выводы для вашего проекта

## Структура отчета

1. **Титульный лист**
2. **Описание предметной области** (1-2 страницы)
3. **Диаграмма четырехуровневой иерархии** (1 страница)
4. **M3: Лингвистическая метамодель** (2-3 страницы)
   - Формальная нотация
   - Примеры в коде
5. **M2: Онтологическая метамодель** (2-3 страницы)
   - Правила построения моделей
   - UML диаграмма классов
   - Код валидатора
6. **M1: Концептуальная модель** (3-4 страницы)
   - ER-диаграмма
   - Описание сущностей
   - JSON-представление
7. **M0: Модель данных** (2-3 страницы)
   - Реализация в Laravel
   - Соответствие уровням
8. **CASE-инструмент** (2-3 страницы)
   - Описание функциональности
   - Примеры использования
   - Архитектура
9. **Анализ применимости** (2-3 страницы)
   - Преимущества и ограничения
   - Выводы
10. **Приложения**
    - Полная ER-диаграмма
    - JSON-модель
    - Код инструмента

**Итого: ~20-25 страниц**

## Полезные команды

```bash
# Валидация модели
python case-tool/main.py validate case-tool/models/library_stroll.json

# Генерация DOT диаграммы
python case-tool/main.py visualize case-tool/models/library_stroll.json diagram.dot

# Экспорт модели
python case-tool/main.py export case-tool/models/library_stroll.json exported.json

# Создание SVG из DOT (требуется Graphviz)
dot -Tsvg diagram.dot -o diagram.svg

# Создание PNG из DOT
dot -Tpng diagram.dot -o diagram.png
```

## Дополнительные материалы

- **План моделирования:** `docs/metamodeling-plan.md`
- **Пример отчета:** `docs/report-example.md`
- **Структура CASE-инструмента:** `docs/case-tool-structure.md`
- **JSON-модель:** `case-tool/models/library_stroll.json`
- **CASE-инструмент:** `case-tool/main.py`

## Вопросы?

Если что-то непонятно, обращайтесь за помощью!

