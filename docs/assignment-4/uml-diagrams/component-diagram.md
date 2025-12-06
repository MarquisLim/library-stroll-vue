# UML Диаграмма компонентов - Система управления метаданными

## Описание

Диаграмма компонентов показывает архитектуру системы управления метаданными (DSM-подход).

## Диаграмма (Mermaid)

```mermaid
graph TB
    subgraph Frontend["Frontend Layer"]
        DynamicForm[Динамическая форма<br/>Vue Component]
        DynamicTable[Динамическая таблица<br/>Vue Component]
        FormBuilder[Form Builder<br/>Генератор форм]
        TableBuilder[Table Builder<br/>Генератор таблиц]
    end
    
    subgraph API["API Layer"]
        MetaAPI[Meta API<br/>REST Endpoints]
        FormAPI[Form API<br/>Конфигурация форм]
        TableAPI[Table API<br/>Конфигурация таблиц]
    end
    
    subgraph Interpreter["Interpreter Layer"]
        MetamodelInterpreter[Metamodel Interpreter<br/>Интерпретатор метамодели]
        FormGenerator[Form Generator<br/>Генератор форм]
        TableGenerator[Table Generator<br/>Генератор таблиц]
        Validator[Validator<br/>Валидатор данных]
    end
    
    subgraph Metadata["Metadata Layer"]
        EntityLoader[Entity Loader<br/>Загрузка сущностей]
        AttributeLoader[Attribute Loader<br/>Загрузка атрибутов]
        RelationshipLoader[Relationship Loader<br/>Загрузка связей]
        FormLoader[Form Loader<br/>Загрузка форм]
        TableLoader[Table Loader<br/>Загрузка таблиц]
    end
    
    subgraph Database["Database Layer"]
        MetaDB[(Meta Database<br/>Метаданные)]
        AppDB[(Application Database<br/>Данные приложения)]
    end
    
    subgraph Cache["Cache Layer"]
        MetadataCache[(Metadata Cache<br/>Кэш метаданных)]
    end
    
    DynamicForm --> FormBuilder
    DynamicTable --> TableBuilder
    FormBuilder --> FormAPI
    TableBuilder --> TableAPI
    
    FormAPI --> MetamodelInterpreter
    TableAPI --> MetamodelInterpreter
    
    MetamodelInterpreter --> FormGenerator
    MetamodelInterpreter --> TableGenerator
    MetamodelInterpreter --> Validator
    
    FormGenerator --> EntityLoader
    FormGenerator --> AttributeLoader
    FormGenerator --> RelationshipLoader
    FormGenerator --> FormLoader
    
    TableGenerator --> EntityLoader
    TableGenerator --> AttributeLoader
    TableGenerator --> RelationshipLoader
    TableGenerator --> TableLoader
    
    EntityLoader --> MetaDB
    AttributeLoader --> MetaDB
    RelationshipLoader --> MetaDB
    FormLoader --> MetaDB
    TableLoader --> MetaDB
    
    EntityLoader --> MetadataCache
    AttributeLoader --> MetadataCache
    RelationshipLoader --> MetadataCache
    
    FormGenerator --> AppDB
    TableGenerator --> AppDB
    
    style Frontend fill:#e1f5ff
    style API fill:#fff3e0
    style Interpreter fill:#f3e5f5
    style Metadata fill:#e8f5e9
    style Database fill:#fce4ec
    style Cache fill:#f1f8e9
```

## Описание компонентов

### Frontend Layer
- **DynamicForm** - Vue компонент для динамического отображения форм
- **DynamicTable** - Vue компонент для динамического отображения таблиц
- **FormBuilder** - Генератор конфигурации форм на основе метаданных
- **TableBuilder** - Генератор конфигурации таблиц на основе метаданных

### API Layer
- **MetaAPI** - REST API для работы с метаданными
- **FormAPI** - API для получения конфигурации форм
- **TableAPI** - API для получения конфигурации таблиц

### Interpreter Layer
- **MetamodelInterpreter** - Главный интерпретатор метамодели
- **FormGenerator** - Генератор конфигурации форм
- **TableGenerator** - Генератор конфигурации таблиц
- **Validator** - Валидатор данных на основе метаданных

### Metadata Layer
- **EntityLoader** - Загрузка метаданных сущностей
- **AttributeLoader** - Загрузка метаданных атрибутов
- **RelationshipLoader** - Загрузка метаданных связей
- **FormLoader** - Загрузка метаданных форм
- **TableLoader** - Загрузка метаданных таблиц

### Database Layer
- **Meta Database** - База данных метаданных (meta_entities, meta_attributes и т.д.)
- **Application Database** - База данных приложения (artworks, users и т.д.)

### Cache Layer
- **Metadata Cache** - Кэш метаданных для быстрого доступа

## Особенности архитектуры

- **Динамическая генерация:** Интерфейсы генерируются на основе метаданных из БД
- **Без перекомпиляции:** Изменение метаданных в БД сразу отражается в интерфейсе
- **Кэширование:** Метаданные кэшируются для оптимизации производительности
- **Разделение слоев:** Четкое разделение между метаданными и данными приложения

