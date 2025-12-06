# M1: Концептуальная модель предметной области

## Описание

**Library Stroll** — платформа для художников и дизайнеров, позволяющая:
- Публиковать и делиться художественными работами (изображения, видео, GIF)
- Организовывать работы в коллекции
- Взаимодействовать через комментарии, лайки и сообщения
- Использовать систему тегов для категоризации
- Модерировать контент через систему жалоб
- Управлять правами доступа и приватностью

### Ключевые сущности:
1. **User** (Пользователь) — художник/дизайнер
2. **Artwork** (Работа) — произведение искусства
3. **Collection** (Коллекция) — набор работ
4. **Tag** (Тег) — метка для категоризации
5. **Comment** (Комментарий) — отзыв/обсуждение
6. **Like** (Лайк) — оценка работы
7. **Conversation** (Беседа) — диалог или группа
8. **Message** (Сообщение) — сообщение в беседе
9. **Complaint** (Жалоба) — заявка на модерацию
10. **ComplaintType** (Тип жалобы) — категория нарушения

---

## Концептуальная модель Library Stroll

### Формальная нотация языка моделирования

**Алфавит:**
- **Сущности (Entity)**: `Entity(name, attributes[], relationships[])`
- **Атрибуты (Attribute)**: `Attribute(name, type, constraints[])`
- **Связи (Relationship)**: `Relationship(type, source, target, cardinality, constraints[])`
- **Ограничения (Constraint)**: `Constraint(type, expression)`

**Типы данных:**
- Примитивные: `String`, `Integer`, `Boolean`, `DateTime`, `Text`
- Сложные: `Enum`, `Media`, `JSON`

**Типы связей:**
- `OneToOne` (1:1)
- `OneToMany` (1:N)
- `ManyToMany` (N:M)
- `Polymorphic` (полиморфная)

**Кардинальности:**
- `0..1` (ноль или один)
- `1` (ровно один)
- `0..*` (ноль или более)
- `1..*` (один или более)

**Ограничения целостности:**
- `Required` (обязательное поле)
- `Unique` (уникальное значение)
- `ForeignKey` (внешний ключ)
- `Cascade` (каскадное удаление)
- `Index` (индекс для поиска)

---

### Диаграмма сущностей и связей

```
┌─────────────┐
│    User     │
├─────────────┤
│ id (PK)     │
│ name        │
│ email       │
│ description │
│ is_blocked  │
│ views_count │
└──────┬──────┘
       │
       │ 1
       │
       │ N
┌──────▼──────┐         ┌─────────────┐
│  Artwork    │◄────────┤    Tag      │
├─────────────┤    N:M  └─────────────┘
│ id (PK)     │
│ user_id (FK)│         ┌─────────────┐
│ title       │    N:M  │ Collection  │
│ description │◄────────┤             │
│ type        │         │ id (PK)     │
│ is_published│         │ user_id(FK) │
│ is_blocked  │         │ name        │
└──────┬──────┘         └─────────────┘
       │
       │ 1
       │
       │ N
┌──────▼──────┐
│   Comment   │
├─────────────┤
│ id (PK)     │
│ user_id(FK) │
│ text        │
│ parent_id   │ (self-reference)
└─────────────┘

┌─────────────┐
│    Like     │
├─────────────┤
│ id (PK)     │
│ user_id(FK) │
│ artwork_id  │
└─────────────┘

┌─────────────┐         ┌─────────────┐
│ Conversation│◄────────┤   Message    │
├─────────────┤   1:N   └─────────────┘
│ id (PK)     │
│ type        │         ┌─────────────┐
│ title       │    N:M  │ Complaint   │
└──────┬──────┘◄────────┤             │
       │               │ id (PK)     │
       │ N:M           │ type_id(FK) │
       │               │ user_id(FK) │
┌──────▼──────┐         └─────────────┘
│    User     │
└─────────────┘
```

### Описание сущностей

#### 1. User (Пользователь)
- **Атрибуты:**
  - `id`: Integer (PK, Auto-increment)
  - `name`: String (Required, Max:255)
  - `email`: String (Required, Unique, Max:255)
  - `password`: String (Required, Hashed)
  - `description`: Text (Nullable)
  - `is_blocked`: Boolean (Default: false)
  - `views_count`: Integer (Default: 0)
- **Связи:**
  - `artworks`: OneToMany → Artwork
  - `collections`: OneToMany → Collection
  - `comments`: OneToMany → Comment
  - `likes`: OneToMany → Like
  - `conversations`: ManyToMany → Conversation
- **Ограничения:**
  - Email должен быть уникальным
  - При удалении → каскадно удаляются artworks, collections

#### 2. Artwork (Работа)
- **Атрибуты:**
  - `id`: Integer (PK)
  - `user_id`: Integer (FK → User, Required)
  - `title`: String (Nullable, Max:255)
  - `description`: Text (Nullable)
  - `type`: Enum['image', 'video', 'gif'] (Default: 'image')
  - `is_published`: Boolean (Default: false)
  - `allow_download`: Boolean (Default: true)
  - `allow_comments`: Boolean (Default: true)
  - `is_adult`: Boolean (Default: false)
  - `has_ai`: Boolean (Default: false)
  - `is_private`: Boolean (Default: false)
  - `is_blocked`: Boolean (Default: false)
  - `views_count`: Integer (Default: 0)
  - `published_at`: DateTime (Nullable)
- **Связи:**
  - `user`: ManyToOne → User
  - `tags`: ManyToMany → Tag
  - `collections`: ManyToMany → Collection
  - `comments`: OneToMany → Comment (Polymorphic)
  - `likes`: OneToMany → Like
  - `complaints`: OneToMany → Complaint (Polymorphic)
- **Ограничения:**
  - При удалении → каскадно удаляются likes, comments
  - При блокировке → скрывается из публичного доступа

#### 3. Collection (Коллекция)
- **Атрибуты:**
  - `id`: Integer (PK)
  - `user_id`: Integer (FK → User, Required)
  - `name`: String (Required, Max:255)
  - `is_private`: Boolean (Default: false)
- **Связи:**
  - `user`: ManyToOne → User
  - `artworks`: ManyToMany → Artwork
- **Ограничения:**
  - При удалении User → каскадно удаляется коллекция

#### 4. Tag (Тег)
- **Атрибуты:**
  - `id`: Integer (PK)
  - `name`: String (Required, Unique, Max:255)
- **Связи:**
  - `artworks`: ManyToMany → Artwork
- **Ограничения:**
  - Имя тега должно быть уникальным

#### 5. Comment (Комментарий)
- **Атрибуты:**
  - `id`: Integer (PK)
  - `user_id`: Integer (FK → User, Required)
  - `text`: Text (Required)
  - `parent_id`: Integer (FK → Comment, Nullable)
  - `commentable_id`: Integer (Required)
  - `commentable_type`: String (Required)
  - `is_blocked`: Boolean (Default: false)
- **Связи:**
  - `user`: ManyToOne → User
  - `parent`: ManyToOne → Comment (Self-reference)
  - `replies`: OneToMany → Comment (Self-reference)
  - `commentable`: Polymorphic → Artwork
  - `complaints`: OneToMany → Complaint (Polymorphic)
- **Ограничения:**
  - Полиморфная связь: может быть к Artwork или другим сущностям
  - Рекурсивная связь: комментарий может быть ответом на комментарий

#### 6. Like (Лайк)
- **Атрибуты:**
  - `id`: Integer (PK)
  - `user_id`: Integer (FK → User, Required)
  - `artwork_id`: Integer (FK → Artwork, Required)
- **Связи:**
  - `user`: ManyToOne → User
  - `artwork`: ManyToOne → Artwork
- **Ограничения:**
  - Уникальная пара (user_id, artwork_id)
  - При удалении Artwork → каскадно удаляются лайки

#### 7. Conversation (Беседа)
- **Атрибуты:**
  - `id`: Integer (PK)
  - `type`: Enum['dialog', 'group'] (Required)
  - `title`: String (Nullable, Max:255)
  - `avatar`: String (Nullable)
  - `last_message_id`: Integer (FK → Message, Nullable)
- **Связи:**
  - `users`: ManyToMany → User (с pivot: joined_at, role, last_read_at)
  - `messages`: OneToMany → Message
  - `lastMessage`: ManyToOne → Message
- **Ограничения:**
  - Диалог должен иметь ровно 2 участника
  - Группа может иметь любое количество участников

#### 8. Message (Сообщение)
- **Атрибуты:**
  - `id`: Integer (PK)
  - `conversation_id`: Integer (FK → Conversation, Required)
  - `user_id`: Integer (FK → User, Required)
  - `body`: Text (Required)
  - `reply_to_id`: Integer (FK → Message, Nullable)
  - `artwork_id`: Integer (FK → Artwork, Nullable)
  - `has_attachments`: Boolean (Default: false)
- **Связи:**
  - `conversation`: ManyToOne → Conversation
  - `user`: ManyToOne → User
  - `replyTo`: ManyToOne → Message (Self-reference)
  - `replies`: OneToMany → Message (Self-reference)
  - `attachments`: OneToMany → MessageAttachment
  - `reactions`: OneToMany → MessageReaction
  - `artwork`: ManyToOne → Artwork
- **Ограничения:**
  - При удалении Conversation → каскадно удаляются сообщения

#### 9. Complaint (Жалоба)
- **Атрибуты:**
  - `id`: Integer (PK)
  - `complaint_type_id`: Integer (FK → ComplaintType, Required)
  - `user_id`: Integer (FK → User, Required)
  - `complaintable_id`: Integer (Required)
  - `complaintable_type`: String (Required)
  - `status`: Enum['pending', 'approved', 'rejected'] (Default: 'pending')
  - `reason`: Text (Nullable)
- **Связи:**
  - `type`: ManyToOne → ComplaintType
  - `user`: ManyToOne → User
  - `complaintable`: Polymorphic → Artwork, Comment, User
- **Ограничения:**
  - Полиморфная связь: может быть к Artwork, Comment или User

#### 10. ComplaintType (Тип жалобы)
- **Атрибуты:**
  - `id`: Integer (PK)
  - `slug`: String (Required, Unique, Max:255)
  - `name`: String (Required, Max:255)
- **Связи:**
  - `complaints`: OneToMany → Complaint
- **Ограничения:**
  - Slug должен быть уникальным

---

## JSON-модель

Полная JSON-модель Library Stroll находится в файле:
`../../case-tool/models/library_stroll.json` (в корне проекта)

**Примечание:** Файл находится в корне проекта, не в папке assignment-1.

### Пример структуры JSON

```json
{
  "name": "Library Stroll Domain Model",
  "version": "1.0",
  "entities": [
    {
      "name": "User",
      "attributes": [
        {"name": "id", "type": "Integer", "constraints": ["PK", "AutoIncrement"]},
        {"name": "name", "type": "String", "constraints": ["Required", "Max:255"]},
        {"name": "email", "type": "String", "constraints": ["Required", "Unique", "Max:255"]}
      ],
      "relationships": [
        {
          "type": "OneToMany",
          "target": "Artwork",
          "name": "artworks",
          "cardinality": "1:N",
          "onDelete": "Cascade"
        }
      ]
    }
  ]
}
```

## Связь с другими уровнями

- **M1 использует M2:** Правила валидации M2 применяются к модели M1
- **M1 использует M3:** Конструкции M3 (Entity, Attribute, Relationship) используются для описания M1
- **M1 реализуется в M0:** Все сущности и связи M1 преобразуются в таблицы и внешние ключи M0

