# UML Диаграмма классов - Метамодель системы

## Описание

Диаграмма классов показывает структуру метамодели системы управления метаданными.

## Диаграмма (Mermaid)

```mermaid
classDiagram
    class MetamodelInterpreter {
        -db: DatabaseConnection
        -cache: Map
        +loadEntity(entityId: String): EntityMetadata
        +generateFormConfig(entityId: String, formName: String): FormConfig
        +generateTableConfig(entityId: String, tableName: String): TableConfig
        +validateFormData(entityId: String, formName: String, data: Map): ValidationResult
        +clearCache(entityId: String): void
        -loadEntityMetadata(entityId: String): EntityMetadata
        -loadAttributes(entityId: String): List~Attribute~
        -loadRelationships(entityId: String): List~Relationship~
        -loadForms(entityId: String): List~Form~
        -loadTables(entityId: String): List~Table~
    }
    
    class EntityMetadata {
        +id: String
        +name: String
        +label: String
        +pluralLabel: String
        +tableName: String
        +attributes: List~Attribute~
        +relationships: List~Relationship~
        +forms: List~Form~
        +tables: List~Table~
    }
    
    class Attribute {
        +id: String
        +name: String
        +label: String
        +type: AttributeType
        +required: Boolean
        +maxLength: Integer
        +defaultValue: Any
        +validationRules: Map
        +formFieldConfig: Map
        +tableColumnConfig: Map
    }
    
    class Relationship {
        +id: String
        +name: String
        +label: String
        +type: RelationshipType
        +targetEntityId: String
        +foreignKey: String
        +pivotTable: String
        +formFieldConfig: Map
    }
    
    class Form {
        +id: String
        +entityId: String
        +name: String
        +label: String
        +fieldsConfig: List~FieldConfig~
        +sectionsConfig: List~SectionConfig~
    }
    
    class Table {
        +id: String
        +entityId: String
        +name: String
        +label: String
        +columnsConfig: List~ColumnConfig~
        +filtersConfig: List~FilterConfig~
        +paginationConfig: Map
    }
    
    class FormConfig {
        +id: String
        +entityId: String
        +name: String
        +label: String
        +sections: Map~String, Section~
        +fields: List~Field~
    }
    
    class TableConfig {
        +id: String
        +entityId: String
        +name: String
        +label: String
        +columns: List~Column~
        +pagination: Map
    }
    
    class Field {
        +id: String
        +name: String
        +label: String
        +type: String
        +required: Boolean
        +value: Any
        +config: Map
        +validation: List~Rule~
        +order: Integer
        +section: String
    }
    
    class Column {
        +id: String
        +name: String
        +label: String
        +type: String
        +sortable: Boolean
        +width: Integer
        +visible: Boolean
        +order: Integer
    }
    
    class ValidationResult {
        +valid: Boolean
        +errors: Map~String, String~
    }
    
    class EntityLoader {
        +load(entityId: String): EntityMetadata
    }
    
    class AttributeLoader {
        +load(entityId: String): List~Attribute~
    }
    
    class RelationshipLoader {
        +load(entityId: String): List~Relationship~
    }
    
    class FormLoader {
        +load(entityId: String): List~Form~
    }
    
    class TableLoader {
        +load(entityId: String): List~Table~
    }
    
    MetamodelInterpreter --> EntityMetadata : uses
    MetamodelInterpreter --> FormConfig : generates
    MetamodelInterpreter --> TableConfig : generates
    MetamodelInterpreter --> ValidationResult : returns
    
    EntityMetadata --> Attribute : contains
    EntityMetadata --> Relationship : contains
    EntityMetadata --> Form : contains
    EntityMetadata --> Table : contains
    
    FormConfig --> Field : contains
    TableConfig --> Column : contains
    
    MetamodelInterpreter --> EntityLoader : uses
    MetamodelInterpreter --> AttributeLoader : uses
    MetamodelInterpreter --> RelationshipLoader : uses
    MetamodelInterpreter --> FormLoader : uses
    MetamodelInterpreter --> TableLoader : uses
    
    EntityLoader --> EntityMetadata : creates
    AttributeLoader --> Attribute : creates
    RelationshipLoader --> Relationship : creates
    FormLoader --> Form : creates
    TableLoader --> Table : creates
```

## Описание классов

### MetamodelInterpreter
Главный класс интерпретатора метамодели. Отвечает за загрузку метаданных из БД и генерацию конфигураций интерфейсов.

**Основные методы:**
- `loadEntity()` - загрузка полных метаданных сущности
- `generateFormConfig()` - генерация конфигурации формы
- `generateTableConfig()` - генерация конфигурации таблицы
- `validateFormData()` - валидация данных формы

### EntityMetadata
Представляет метаданные сущности (Entity).

**Атрибуты:**
- `id` - идентификатор сущности
- `name` - имя класса
- `label` - отображаемое имя
- `attributes` - список атрибутов
- `relationships` - список связей
- `forms` - список форм
- `tables` - список таблиц

### Attribute
Представляет метаданные атрибута.

**Атрибуты:**
- `type` - тип данных (string, integer, text и т.д.)
- `required` - обязательность заполнения
- `validationRules` - правила валидации
- `formFieldConfig` - конфигурация поля формы
- `tableColumnConfig` - конфигурация колонки таблицы

### Relationship
Представляет метаданные связи между сущностями.

**Атрибуты:**
- `type` - тип связи (belongsTo, hasMany и т.д.)
- `targetEntityId` - целевая сущность
- `foreignKey` - внешний ключ
- `pivotTable` - промежуточная таблица (для Many-to-Many)

### Form / Table
Представляют метаданные форм и таблиц соответственно.

### FormConfig / TableConfig
Конфигурации, генерируемые интерпретатором для использования во frontend.

### Loaders
Классы для загрузки метаданных из БД:
- `EntityLoader` - загрузка сущностей
- `AttributeLoader` - загрузка атрибутов
- `RelationshipLoader` - загрузка связей
- `FormLoader` - загрузка форм
- `TableLoader` - загрузка таблиц

