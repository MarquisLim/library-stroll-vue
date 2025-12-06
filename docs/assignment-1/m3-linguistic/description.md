# M3: Лингвистическая метамодель

## Описание

Лингвистическая метамодель (M3) определяет формальную нотацию языка моделирования - базовые элементы и конструкции, которые используются для описания моделей.

## Формальная нотация языка моделирования

### Алфавит

**Базовые элементы:**

- **Сущности (Entity)**: `Entity(name, attributes[], relationships[])`
- **Атрибуты (Attribute)**: `Attribute(name, type, constraints[])`
- **Связи (Relationship)**: `Relationship(type, source, target, cardinality, constraints[])`
- **Ограничения (Constraint)**: `Constraint(type, expression)`

### Типы данных

**Примитивные типы:**
- `String` - строка текста
- `Integer` - целое число
- `Boolean` - логическое значение
- `DateTime` - дата и время
- `Text` - многострочный текст

**Сложные типы:**
- `Enum` - перечисление значений
- `Media` - медиа файл (изображение, видео)
- `JSON` - структурированные данные

### Типы связей

- `OneToOne` (1:1) - один к одному
- `OneToMany` (1:N) - один ко многим
- `ManyToOne` (N:1) - многие к одному
- `ManyToMany` (N:M) - многие ко многим
- `Polymorphic` - полиморфная связь

### Кардинальности

- `0..1` - ноль или один
- `1` - ровно один
- `0..*` - ноль или более
- `1..*` - один или более

### Ограничения целостности

- `Required` - обязательное поле
- `Unique` - уникальное значение
- `ForeignKey` - внешний ключ
- `Cascade` - каскадное удаление
- `Index` - индекс для поиска

## Пример формальной записи

```
Entity User {
    Attribute id: Integer [PK, AutoIncrement]
    Attribute name: String [Required, Max:255]
    Attribute email: String [Required, Unique, Max:255]
    
    Relationship artworks: OneToMany → Artwork [Cascade]
}

Entity Artwork {
    Attribute id: Integer [PK]
    Attribute user_id: Integer [FK → User]
    Attribute title: String [Max:255]
    
    Relationship user: ManyToOne → User
    Relationship tags: ManyToMany → Tag [pivot_table: artwork_tag]
}
```

## Реализация в коде (Python)

```python
from enum import Enum
from typing import List, Optional

class DataType(Enum):
    STRING = "String"
    INTEGER = "Integer"
    BOOLEAN = "Boolean"
    DATETIME = "DateTime"
    TEXT = "Text"
    ENUM = "Enum"
    JSON = "JSON"

class RelationshipType(Enum):
    ONE_TO_ONE = "OneToOne"
    ONE_TO_MANY = "OneToMany"
    MANY_TO_ONE = "ManyToOne"
    MANY_TO_MANY = "ManyToMany"
    POLYMORPHIC = "Polymorphic"

class ConstraintType(Enum):
    PK = "PK"
    FK = "FK"
    REQUIRED = "Required"
    UNIQUE = "Unique"
    CASCADE = "Cascade"
    INDEX = "Index"

class Attribute:
    def __init__(self, name: str, type: DataType, constraints: List[ConstraintType] = None):
        self.name = name
        self.type = type
        self.constraints = constraints or []

class Relationship:
    def __init__(self, name: str, type: RelationshipType, target: str, cardinality: str = None):
        self.name = name
        self.type = type
        self.target = target
        self.cardinality = cardinality

class Entity:
    def __init__(self, name: str, attributes: List[Attribute] = None, relationships: List[Relationship] = None):
        self.name = name
        self.attributes = attributes or []
        self.relationships = relationships or []
```

## Связь с другими уровнями

- **M3 → M2:** Элементы M3 используются для построения правил валидации в M2
- **M3 → M1:** Конструкции M3 применяются для описания конкретной предметной области в M1
- **M3 → M0:** Типы данных и связи M3 преобразуются в структуры БД в M0

