# Онтологическая метамодель системы управления метаданными

## Описание

Онтологическая метамодель определяет структуру и правила описания метаданных для динамической генерации интерфейсов системы Library Stroll.

## Концептуальная структура метамодели

### Основные концепты

1. **Entity (Сущность)** - описание сущности предметной области
2. **Attribute (Атрибут)** - описание атрибута сущности
3. **Relationship (Связь)** - описание связи между сущностями
4. **Form (Форма)** - описание формы для редактирования сущности
5. **Table (Таблица)** - описание таблицы для отображения списка сущностей
6. **Validation (Валидация)** - правила валидации данных

## Метаданные Entity (Сущность)

```json
{
  "id": "entity_id",
  "name": "Artwork",
  "label": "Художественная работа",
  "plural_label": "Художественные работы",
  "table_name": "artworks",
  "attributes": [...],
  "relationships": [...],
  "forms": [...],
  "tables": [...]
}
```

### Структура Entity

- **id** (string, уникальный) - идентификатор сущности
- **name** (string) - имя класса сущности (CamelCase)
- **label** (string) - отображаемое имя (единственное число)
- **plural_label** (string) - отображаемое имя (множественное число)
- **table_name** (string) - имя таблицы в БД
- **attributes** (array) - массив атрибутов
- **relationships** (array) - массив связей
- **forms** (array) - массив форм
- **tables** (array) - массив таблиц

## Метаданные Attribute (Атрибут)

```json
{
  "id": "attribute_id",
  "name": "title",
  "label": "Название",
  "type": "string",
  "required": true,
  "max_length": 255,
  "default_value": null,
  "validation_rules": [...],
  "form_field": {
    "type": "text",
    "placeholder": "Введите название работы"
  },
  "table_column": {
    "visible": true,
    "sortable": true,
    "width": 200
  }
}
```

### Структура Attribute

- **id** (string) - идентификатор атрибута
- **name** (string) - имя атрибута (snake_case)
- **label** (string) - отображаемое имя
- **type** (enum) - тип данных: string, integer, float, boolean, date, datetime, text, file, image
- **required** (boolean) - обязательность заполнения
- **max_length** (integer, optional) - максимальная длина
- **default_value** (any, optional) - значение по умолчанию
- **validation_rules** (array) - правила валидации
- **form_field** (object) - настройки поля формы
- **table_column** (object) - настройки колонки таблицы

## Метаданные Relationship (Связь)

```json
{
  "id": "relationship_id",
  "name": "user",
  "label": "Автор",
  "type": "belongsTo",
  "target_entity": "User",
  "foreign_key": "user_id",
  "form_field": {
    "type": "select",
    "display_field": "name"
  }
}
```

### Типы связей

- **belongsTo** - принадлежит (Many-to-One)
- **hasMany** - имеет много (One-to-Many)
- **hasOne** - имеет один (One-to-One)
- **belongsToMany** - многие-ко-многим (Many-to-Many)

### Структура Relationship

- **id** (string) - идентификатор связи
- **name** (string) - имя связи
- **label** (string) - отображаемое имя
- **type** (enum) - тип связи
- **target_entity** (string) - целевая сущность
- **foreign_key** (string, optional) - внешний ключ
- **pivot_table** (string, optional) - промежуточная таблица (для Many-to-Many)
- **form_field** (object) - настройки поля формы

## Метаданные Form (Форма)

```json
{
  "id": "form_id",
  "entity_id": "Artwork",
  "name": "create",
  "label": "Создание работы",
  "fields": [
    {
      "attribute_id": "title",
      "order": 1,
      "section": "Основная информация"
    },
    {
      "attribute_id": "description",
      "order": 2,
      "section": "Основная информация"
    }
  ],
  "sections": [
    {
      "name": "Основная информация",
      "order": 1
    }
  ]
}
```

### Структура Form

- **id** (string) - идентификатор формы
- **entity_id** (string) - ID сущности
- **name** (string) - имя формы (create, edit, view)
- **label** (string) - заголовок формы
- **fields** (array) - массив полей формы
- **sections** (array) - массив секций формы

## Метаданные Table (Таблица)

```json
{
  "id": "table_id",
  "entity_id": "Artwork",
  "name": "list",
  "label": "Список работ",
  "columns": [
    {
      "attribute_id": "id",
      "order": 1,
      "visible": true
    },
    {
      "attribute_id": "title",
      "order": 2,
      "visible": true
    }
  ],
  "filters": [...],
  "pagination": {
    "per_page": 20
  }
}
```

### Структура Table

- **id** (string) - идентификатор таблицы
- **entity_id** (string) - ID сущности
- **name** (string) - имя таблицы
- **label** (string) - заголовок таблицы
- **columns** (array) - массив колонок
- **filters** (array) - массив фильтров
- **pagination** (object) - настройки пагинации

## Правила метамодели

### Правило 1: Уникальность идентификаторов
Каждый элемент метамодели должен иметь уникальный идентификатор в рамках своего типа.

### Правило 2: Ссылочная целостность
Атрибуты и связи должны ссылаться на существующие сущности.

### Правило 3: Типизация
Типы атрибутов должны соответствовать допустимым значениям enum.

### Правило 4: Обязательность
Обязательные атрибуты должны иметь валидацию на заполнение.

### Правило 5: Связность
Связи должны иметь корректные foreign_key и target_entity.

## Пример полной метамодели

```json
{
  "entities": [
    {
      "id": "Artwork",
      "name": "Artwork",
      "label": "Художественная работа",
      "plural_label": "Художественные работы",
      "table_name": "artworks",
      "attributes": [
        {
          "id": "title",
          "name": "title",
          "label": "Название",
          "type": "string",
          "required": true,
          "max_length": 255
        },
        {
          "id": "description",
          "name": "description",
          "label": "Описание",
          "type": "text",
          "required": false
        }
      ],
      "relationships": [
        {
          "id": "user",
          "name": "user",
          "label": "Автор",
          "type": "belongsTo",
          "target_entity": "User",
          "foreign_key": "user_id"
        }
      ],
      "forms": [
        {
          "id": "artwork_create",
          "name": "create",
          "label": "Создание работы",
          "fields": ["title", "description", "user"]
        }
      ],
      "tables": [
        {
          "id": "artwork_list",
          "name": "list",
          "label": "Список работ",
          "columns": ["id", "title", "user"]
        }
      ]
    }
  ]
}
```

