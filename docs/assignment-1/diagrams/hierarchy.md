# Диаграмма 1: Четырехуровневая иерархия моделей (M3-M0)

Скопируйте код ниже и вставьте в [Mermaid Live Editor](https://mermaid.live/)

```mermaid
graph LR
    subgraph M3_Level[" "]
        M3_Label["<b>M3</b><br/>Лингвистическая<br/>метамодель"]
        M3_Content["Entity, Attribute, Relationship, Constraint. Типы данных: Integer, String, Text, Boolean, DateTime, Enum. Типы связей: OneToOne, OneToMany, ManyToMany, Polymorphic"]
        M3_Label --- M3_Content
    end
    
    subgraph M2_Level[" "]
        M2_Label["<b>M2</b><br/>Онтологическая<br/>метамодель"]
        M2_Content["Правила валидации: PK, FK, pivot_table, morph_type. MetamodelValidator: validate_entity(), validate_relationship(). Поведенческие зависимости: каскадное удаление, блокировка"]
        M2_Label --- M2_Content
    end
    
    subgraph M1_Level[" "]
        M1_Label["<b>M1</b><br/>Концептуальная<br/>модель"]
        M1_Content["Сущности: User, Artwork, Collection, Tag, Comment, Like, Conversation, Message, Complaint. Связи: User→Artwork (1:N), Artwork→Tag (N:M), Comment→Artwork (Polymorphic). Ограничения: уникальность email, каскадное удаление"]
        M1_Label --- M1_Content
    end
    
    subgraph M0_Level[" "]
        M0_Label["<b>M0</b><br/>Модель данных<br/>Laravel"]
        M0_Content["Таблицы: users, artworks, collections, tags, comments, likes, conversations, messages. Внешние ключи: artworks.user_id→users.id, comments.user_id→users.id. Промежуточные: artwork_tag, artwork_collection, conversation_user. Полиморфные: comments.commentable_type/id, complaints.complaintable_type/id"]
        M0_Label --- M0_Content
    end
    
    M3_Content --> M2_Content
    M2_Content --> M1_Content
    M1_Content --> M0_Content
    
    style M3_Label fill:#01579b,stroke:#01579b,stroke-width:4px,color:#fff,font-size:18px,font-weight:bold
    style M2_Label fill:#e65100,stroke:#e65100,stroke-width:4px,color:#fff,font-size:18px,font-weight:bold
    style M1_Label fill:#4a148c,stroke:#4a148c,stroke-width:4px,color:#fff,font-size:18px,font-weight:bold
    style M0_Label fill:#1b5e20,stroke:#1b5e20,stroke-width:4px,color:#fff,font-size:18px,font-weight:bold
    
    style M3_Level fill:#e1f5ff,stroke:#01579b,stroke-width:3px
    style M2_Level fill:#fff3e0,stroke:#e65100,stroke-width:3px
    style M1_Level fill:#f3e5f5,stroke:#4a148c,stroke-width:3px
    style M0_Level fill:#e8f5e9,stroke:#1b5e20,stroke-width:3px
```

## Описание структуры диаграммы

Диаграмма показывает четырехуровневую иерархию моделей, расположенную слева направо. Каждый уровень представлен цветным блоком с названием модели слева и её содержимым справа.

**M3 (синий блок)** — определяет базовые элементы языка моделирования: Entity, Attribute, Relationship, Constraint, а также типы данных и связей. Стрелка ведет от M3 к M2, показывая, что элементы M3 используются для построения правил M2.

**M2 (оранжевый блок)** — содержит правила валидации (PK, FK, pivot_table, morph_type), класс MetamodelValidator с методами проверки и поведенческие зависимости. Стрелка от M2 к M1 показывает применение этих правил к концептуальной модели.

**M1 (фиолетовый блок)** — описывает конкретную предметную область Library Stroll: сущности (User, Artwork, Collection и др.), связи между ними (User→Artwork, Artwork→Tag) и ограничения целостности. Стрелка от M1 к M0 показывает преобразование концептуальной модели в физическую реализацию.

**M0 (зеленый блок)** — содержит физическую реализацию: таблицы БД (users, artworks и др.), внешние ключи, промежуточные таблицы для ManyToMany связей и полиморфные связи через пары колонок.

Стрелки между уровнями показывают направление преобразования: от абстрактных элементов языка (M3) через правила валидации (M2) к конкретной модели предметной области (M1) и её физической реализации (M0).

