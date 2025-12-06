# CASE-инструмент для многоуровневого моделирования

Инструмент для работы с многоуровневыми моделями (M3-M0) предметной области.

## Установка

```bash
# Требуется Python 3.8+
python --version

# Установка зависимостей (если нужна визуализация)
pip install graphviz  # Для генерации SVG/PNG из DOT
```

## Использование

### 1. Валидация модели

```bash
python main.py validate models/library_stroll.json
```

Проверяет модель на соответствие правилам онтологической метамодели (M2):
- Наличие первичных ключей
- Корректность внешних ключей
- Существование ссылаемых сущностей
- Корректность кардинальностей

### 2. Визуализация модели

```bash
# Генерация DOT файла
python main.py visualize models/library_stroll.json diagram.dot

# Генерация SVG (требуется Graphviz)
dot -Tsvg diagram.dot -o diagram.svg

# Генерация PNG
dot -Tpng diagram.dot -o diagram.png
```

### 3. Экспорт модели

```bash
python main.py export models/library_stroll.json exported_model.json
```

## Структура проекта

```
case-tool/
├── main.py                    # Главный скрипт
├── models/
│   └── library_stroll.json   # Модель Library Stroll
└── README.md                  # Документация
```

## Формат JSON модели

```json
{
  "metamodel": {
    "version": "1.0",
    "name": "Название модели",
    "description": "Описание"
  },
  "entities": [
    {
      "name": "EntityName",
      "description": "Описание сущности",
      "attributes": [
        {
          "name": "id",
          "type": "Integer",
          "constraints": ["PK", "AutoIncrement"],
          "required": true
        }
      ],
      "relationships": [
        {
          "type": "OneToMany",
          "target": "TargetEntity",
          "name": "relationName",
          "cardinality": "1:N"
        }
      ]
    }
  ]
}
```

## Примеры использования

### Валидация модели Library Stroll

```bash
$ python main.py validate models/library_stroll.json
Загрузка модели из models/library_stroll.json...
✓ Модель 'Library Stroll Domain Model' загружена (10 сущностей)

Валидация модели...
✓ Модель валидна!
```

### Генерация диаграммы

```bash
$ python main.py visualize models/library_stroll.json diagram.dot
Загрузка модели из models/library_stroll.json...
Генерация графа связей...
✓ DOT файл сохранен: diagram.dot

$ dot -Tsvg diagram.dot -o diagram.svg
```

## Расширение функциональности

Инструмент можно расширить следующими модулями:

1. **SQL Generator** - генерация SQL миграций из модели
2. **Laravel Generator** - генерация Laravel моделей и миграций
3. **XML Parser** - поддержка XML формата
4. **Interactive Editor** - интерактивное редактирование моделей
5. **Diff Tool** - сравнение версий моделей

## Лицензия

MIT

