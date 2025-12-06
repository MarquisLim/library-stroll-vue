# M2: Онтологическая метамодель

## Описание

Онтологическая метамодель (M2) определяет правила построения моделей, валидацию и поведенческие зависимости. Она использует элементы M3 для создания правил, которые применяются к моделям M1.

## Правила построения моделей

### Мета-сущности

1. **Entity** — абстракция реальной сущности
   - Должна иметь имя и минимум один атрибут
   - Может иметь связи с другими сущностями
   - Может иметь поведенческие методы

2. **Attribute** — свойство сущности
   - Имеет имя и тип
   - Может иметь ограничения (required, unique)
   - Может иметь значения по умолчанию

3. **Relationship** — связь между сущностями
   - Имеет тип (OneToOne, OneToMany, ManyToMany, Polymorphic)
   - Имеет кардинальность
   - Может иметь каскадные операции

## Правила валидации

### Правило 1: Обязательность первичного ключа

```
∀ Entity e: ∃ Attribute a ∈ e.attributes: 
    PK ∈ a.constraints
```

Каждая сущность должна иметь хотя бы один атрибут с ограничением PK (Primary Key).

### Правило 2: Корректность внешних ключей

```
∀ Relationship r: 
    r.type = FK → ∃ Entity e: e.name = r.target
```

Внешний ключ должен ссылаться на существующую сущность.

### Правило 3: ManyToMany требует промежуточную таблицу

```
∀ Relationship r:
    r.type = ManyToMany → r.pivot_table ≠ null
```

Связь типа ManyToMany должна иметь указанную промежуточную таблицу.

### Правило 4: Полиморфная связь требует morph_type

```
∀ Relationship r:
    r.polymorphic = true → r.morph_type ≠ null
```

Полиморфная связь должна иметь указанный тип морфизма (morph_type).

### Правило 5: Циклические зависимости

Циклические зависимости разрешены только для рекурсивных связей (self-reference).

## Поведенческие зависимости

### Каскадное удаление

- При удалении `User` → каскадно удаляются его `Artwork`, `Collection`
- При удалении `Artwork` → удаляются связанные `Like`, `Comment`
- При удалении `Conversation` → удаляются связанные `Message`

### Блокировка контента

- При блокировке `User` → скрываются его `Artwork`, `Comment`
- При блокировке `Artwork` → скрывается из публичного доступа
- Реализовано через глобальные scope в Laravel

### Автоматические действия

- При публикации `Artwork` → устанавливается `published_at`
- При создании `Comment` → обновляется счетчик комментариев
- При добавлении `Like` → обновляется счетчик лайков

## Реализация валидатора (Python)

```python
from typing import List

class ValidationError:
    def __init__(self, entity: str, message: str):
        self.entity = entity
        self.message = message

class MetamodelValidator:
    def validate_entity(self, entity: 'Entity') -> List[ValidationError]:
        """Валидация сущности по правилам M2"""
        errors = []
        
        # Проверка наличия PK
        has_pk = any(
            ConstraintType.PK in attr.constraints 
            for attr in entity.attributes
        )
        if not has_pk:
            errors.append(
                ValidationError(
                    entity.name, 
                    "Сущность должна иметь первичный ключ"
                )
            )
        
        # Проверка наличия хотя бы одного атрибута
        if not entity.attributes:
            errors.append(
                ValidationError(
                    entity.name,
                    "Сущность должна иметь хотя бы один атрибут"
                )
            )
        
        return errors
    
    def validate_relationship(self, relationship: 'Relationship', entities: List['Entity']) -> List[ValidationError]:
        """Валидация связи по правилам M2"""
        errors = []
        
        # Проверка существования целевой сущности
        target_exists = any(
            e.name == relationship.target 
            for e in entities
        )
        if not target_exists:
            errors.append(
                ValidationError(
                    relationship.name,
                    f"Целевая сущность '{relationship.target}' не найдена"
                )
            )
        
        # Проверка ManyToMany на наличие pivot_table
        if relationship.type == RelationshipType.MANY_TO_MANY:
            if not hasattr(relationship, 'pivot_table') or not relationship.pivot_table:
                errors.append(
                    ValidationError(
                        relationship.name,
                        "ManyToMany связь должна иметь pivot_table"
                    )
                )
        
        # Проверка полиморфной связи на наличие morph_type
        if relationship.type == RelationshipType.POLYMORPHIC:
            if not hasattr(relationship, 'morph_type') or not relationship.morph_type:
                errors.append(
                    ValidationError(
                        relationship.name,
                        "Полиморфная связь должна иметь morph_type"
                    )
                )
        
        return errors
    
    def check_circular_dependencies(self, entities: List['Entity']) -> List[ValidationError]:
        """Проверка циклических зависимостей"""
        errors = []
        # Реализация проверки циклов в графе зависимостей
        # ...
        return errors
```

## Связь с другими уровнями

- **M2 использует M3:** Правила M2 основаны на элементах M3 (Entity, Attribute, Relationship)
- **M2 применяется к M1:** Правила валидации M2 проверяют корректность модели M1
- **M2 влияет на M0:** Поведенческие зависимости M2 реализуются в M0 (каскадное удаление, триггеры)

