# CASE-инструмент для многоуровневого моделирования

## Описание

CASE-инструмент (Computer-Aided Software Engineering) позволяет создавать, валидировать и визуализировать модели многоуровневого моделирования (M3-M0).

## Расположение

**Код инструмента:** `case-tool/main.py` (в корне проекта)  
**Модели:** `case-tool/models/library_stroll.json` (в корне проекта)

## Функциональность

### 1. Парсинг JSON-модели

Загрузка модели M1 из JSON файла:

```bash
python main.py validate models/library_stroll.json
```

### 2. Валидация модели

Проверка модели по правилам M2:

- Проверка наличия первичных ключей
- Проверка корректности внешних ключей
- Проверка ManyToMany связей на наличие pivot_table
- Проверка полиморфных связей на наличие morph_type
- Обнаружение циклических зависимостей

### 3. Визуализация

Генерация DOT-графа для Graphviz:

```bash
python main.py visualize models/library_stroll.json diagram.dot
dot -Tsvg diagram.dot -o diagram.svg
```

### 4. Экспорт модели

Сохранение модели в различных форматах:

```bash
python main.py export models/library_stroll.json exported.json
```

## Архитектура инструмента

См. файл `structure.md` для подробного описания архитектуры.

### Основные компоненты

1. **M3: Классы метамодели**
   - `Entity`, `Attribute`, `Relationship`, `Constraint`
   - Enum'ы: `DataType`, `RelationshipType`, `ConstraintType`

2. **M2: Валидатор**
   - `MetamodelValidator` - проверка правил M2
   - Методы: `validate_entity()`, `validate_relationship()`

3. **M1: Загрузка модели**
   - `DomainModel` - представление модели предметной области
   - Парсинг JSON, валидация, экспорт

4. **Визуализация**
   - `GraphVisualizer` - генерация DOT-графов
   - Отображение сущностей, связей, атрибутов

## Пример использования

### Валидация модели

```bash
cd case-tool
python main.py validate models/library_stroll.json
```

**Вывод:**
```
Загрузка модели из models/library_stroll.json...
✓ Модель 'Library Stroll Domain Model' загружена (10 сущностей)

Валидация модели...
✓ Модель валидна!
```

### Генерация диаграммы

```bash
python main.py visualize models/library_stroll.json diagram.dot
dot -Tsvg diagram.dot -o diagram.svg
```

Создается файл `diagram.svg` с визуализацией модели.

### Экспорт модели

```bash
python main.py export models/library_stroll.json exported_model.json
```

## Требования

- Python 3.7+
- Graphviz (для визуализации, опционально)

## Установка Graphviz

### Windows

```bash
winget install Graphviz.Graphviz
```

Или скачайте с официального сайта: https://graphviz.org/download/

### Linux

```bash
sudo apt-get install graphviz
```

### macOS

```bash
brew install graphviz
```

## Расширение функциональности

Инструмент можно расширить для:

- Генерации SQL миграций Laravel
- Генерации Eloquent моделей
- Парсинга XML моделей
- Интерактивного редактора моделей
- Экспорта в другие форматы (XMI, YAML)

## Связь с уровнями моделирования

- **Использует M3:** Классы Entity, Attribute, Relationship
- **Применяет M2:** Валидация по правилам M2
- **Работает с M1:** Загрузка и обработка модели Library Stroll
- **Генерирует M0:** Может генерировать SQL миграции (при расширении)

