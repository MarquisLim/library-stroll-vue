# Отчет: Многоуровневое моделирование предметной области "Library Stroll"

## 1. Описание предметной области

**Library Stroll** — платформа для художников и дизайнеров, предоставляющая возможности:
- Публикации и управления художественными работами (изображения, видео, GIF)
- Организации работ в коллекции
- Социального взаимодействия (комментарии, лайки, сообщения)
- Категоризации через систему тегов
- Модерации контента через систему жалоб
- Управления правами доступа и приватностью

**Сложность предметной области:**
- 10 основных сущностей
- Множественные типы связей (OneToMany, ManyToMany, Polymorphic)
- Рекурсивные связи (комментарии, сообщения)
- Полиморфные связи (комментарии и жалобы могут относиться к разным сущностям)
- Система ролей и прав доступа

---

## 2. Четырехуровневая иерархия моделей

```
┌─────────────────────────────────────────────────────────┐
│ M3: Лингвистическая метамодель                           │
│ Формальная нотация языка моделирования                   │
│ - Entity, Attribute, Relationship, Constraint           │
│ - Типы данных, кардинальности, ограничения              │
└─────────────────────────────────────────────────────────┘
                          ↓ определяет правила для
┌─────────────────────────────────────────────────────────┐
│ M2: Онтологическая метамодель                           │
│ Правила построения моделей                               │
│ - Валидация сущностей и связей                          │
│ - Правила целостности данных                             │
│ - Поведенческие зависимости                              │
└─────────────────────────────────────────────────────────┘
                          ↓ применяется к
┌─────────────────────────────────────────────────────────┐
│ M1: Концептуальная модель предметной области             │
│ Модель Library Stroll                                    │
│ - User, Artwork, Collection, Comment, Tag, etc.         │
│ - Связи между сущностями                                │
│ - Ограничения целостности                               │
└─────────────────────────────────────────────────────────┘
                          ↓ реализуется в
┌─────────────────────────────────────────────────────────┐
│ M0: Модель данных (реализация)                         │
│ Laravel Migrations + Eloquent Models                     │
│ - Таблицы MySQL/SQLite                                   │
│ - Внешние ключи, индексы                                │
│ - Полиморфные связи через *_type и *_id                │
└─────────────────────────────────────────────────────────┘
```

---

## 3. M3: Лингвистическая метамодель

### Формальная нотация

**Алфавит языка моделирования:**

```
Entity ::= 'Entity' '(' name ',' attributes ',' relationships ')'
Attribute ::= 'Attribute' '(' name ',' type ',' constraints ')'
Relationship ::= 'Relationship' '(' type ',' source ',' target ',' cardinality ')'
Constraint ::= 'Constraint' '(' type ',' expression ')'
```

**Типы данных:**
- `Integer`, `String`, `Text`, `Boolean`, `DateTime`, `Enum`, `JSON`

**Типы связей:**
- `OneToOne` (1:1)
- `OneToMany` (1:N)
- `ManyToOne` (N:1)
- `ManyToMany` (N:M)
- `Polymorphic` (полиморфная)

**Кардинальности:**
- `0..1` (ноль или один)
- `1` (ровно один)
- `0..*` (ноль или более)
- `1..*` (один или более)

**Ограничения:**
- `PK` (Primary Key)
- `FK` (Foreign Key)
- `Required` (обязательное)
- `Unique` (уникальное)
- `Cascade` (каскадное удаление)

### Пример в коде (Python)

```python
class Entity:
    name: str
    attributes: List[Attribute]
    relationships: List[Relationship]

class RelationshipType(Enum):
    ONE_TO_MANY = "OneToMany"
    MANY_TO_MANY = "ManyToMany"
    POLYMORPHIC = "Polymorphic"
```

---

## 4. M2: Онтологическая метамодель

### Правила построения моделей

**Правило 1: Обязательность первичного ключа**
```
∀ Entity e: ∃ Attribute a ∈ e.attributes: 
    PK ∈ a.constraints
```

**Правило 2: Внешний ключ должен ссылаться на существующую сущность**
```
∀ Relationship r: 
    r.type = FK → ∃ Entity e: e.name = r.target
```

**Правило 3: ManyToMany требует промежуточную таблицу**
```
∀ Relationship r:
    r.type = ManyToMany → r.pivot_table ≠ null
```

**Правило 4: Полиморфная связь требует morph_type**
```
∀ Relationship r:
    r.polymorphic = true → r.morph_type ≠ null
```

### Поведенческие зависимости

1. **Каскадное удаление:**
   - При удалении `User` → удаляются его `Artwork`, `Collection`
   - При удалении `Artwork` → удаляются связанные `Like`, `Comment`

2. **Блокировка:**
   - При блокировке `User` → скрываются его `Artwork`, `Comment`
   - Реализовано через глобальные scope в Laravel

3. **Публикация:**
   - При публикации `Artwork` → устанавливается `published_at`

### Реализация валидатора (Python)

```python
class MetamodelValidator:
    def validate_entity(self, entity: Entity) -> List[ValidationError]:
        errors = []
        # Проверка наличия PK
        has_pk = any(ConstraintType.PK in attr.constraints 
                    for attr in entity.attributes)
        if not has_pk:
            errors.append("Сущность должна иметь первичный ключ")
        return errors
```

---

## 5. M1: Концептуальная модель предметной области

### Диаграмма сущностей и связей

```
                    ┌─────────────┐
                    │    User     │
                    │  (PK: id)   │
                    └──────┬──────┘
                           │
                    ┌──────▼──────┐         ┌─────────────┐
                    │  Artwork    │◄────────┤    Tag      │
                    │  (PK: id)   │   N:M   │  (PK: id)   │
                    │  (FK: user) │         └─────────────┘
                    └──────┬──────┘
                           │                ┌─────────────┐
                    ┌──────▼──────┐    N:M  │ Collection  │
                    │  Comment   │◄────────┤  (PK: id)   │
                    │  (PK: id)  │         └─────────────┘
                    │  (FK: user)│
                    │  (FK: parent)│
                    └─────────────┘

                    ┌─────────────┐
                    │    Like     │
                    │  (PK: id)   │
                    │  (FK: user) │
                    │  (FK: artwork)│
                    └─────────────┘

                    ┌─────────────┐         ┌─────────────┐
                    │Conversation │◄────────┤   Message   │
                    │  (PK: id)   │   1:N   │  (PK: id)   │
                    └──────┬──────┘         └─────────────┘
                           │
                           │ N:M
                           │
                    ┌──────▼──────┐
                    │    User     │
                    └─────────────┘
```

### Ключевые сущности

#### User (Пользователь)
- **Атрибуты:** id, name, email, password, description, is_blocked, views_count
- **Связи:** 
  - OneToMany → Artwork, Collection, Comment, Like
  - ManyToMany → Conversation
- **Ограничения:** email уникален, каскадное удаление artworks

#### Artwork (Работа)
- **Атрибуты:** id, user_id, title, description, type, is_published, views_count
- **Связи:**
  - ManyToOne → User
  - ManyToMany → Tag, Collection
  - OneToMany → Comment (polymorphic), Like
- **Ограничения:** каскадное удаление likes, comments

#### Comment (Комментарий)
- **Атрибуты:** id, user_id, text, parent_id, commentable_id, commentable_type
- **Связи:**
  - ManyToOne → User
  - ManyToOne → Comment (self-reference для ответов)
  - Polymorphic → Artwork (может быть к другим сущностям)
- **Особенности:** рекурсивная структура для вложенных комментариев

### JSON-представление (фрагмент)

```json
{
  "name": "Artwork",
  "attributes": [
    {
      "name": "id",
      "type": "Integer",
      "constraints": ["PK", "AutoIncrement"]
    },
    {
      "name": "user_id",
      "type": "Integer",
      "constraints": ["FK"],
      "references": "User.id"
    }
  ],
  "relationships": [
    {
      "type": "ManyToOne",
      "target": "User",
      "name": "user",
      "cardinality": "N:1"
    }
  ]
}
```

---

## 6. M0: Модель данных (реализация)

### Реализация в Laravel

**Миграция для таблицы artworks:**

```php
Schema::create('artworks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->string('title')->nullable();
    $table->text('description')->nullable();
    $table->enum('type', ['image', 'video', 'gif'])->default('image');
    $table->boolean('is_published')->default(false);
    $table->timestamps();
});
```

**Eloquent модель:**

```php
class Artwork extends Model {
    public function user() {
        return $this->belongsTo(User::class);
    }
    
    public function tags() {
        return $this->belongsToMany(Tag::class);
    }
}
```

### Соответствие уровней

| M1 (Концептуальная) | M0 (Реализация) |
|---------------------|-----------------|
| Entity: Artwork | Таблица: artworks |
| Attribute: user_id | Колонка: user_id |
| Relationship: ManyToOne → User | Foreign Key: user_id → users.id |
| Constraint: Cascade | onDelete('cascade') |
| Relationship: ManyToMany → Tag | Промежуточная таблица: artwork_tag |

---

## 7. CASE-инструмент

### Функциональность

1. **Парсинг JSON-модели** → загрузка модели M1
2. **Валидация** → проверка правил M2
3. **Визуализация** → генерация DOT-графа
4. **Экспорт** → сохранение в JSON/XML

### Пример использования

```bash
# Валидация модели
$ python main.py validate models/library_stroll.json
✓ Модель 'Library Stroll Domain Model' загружена (10 сущностей)
✓ Модель валидна!

# Генерация диаграммы
$ python main.py visualize models/library_stroll.json diagram.dot
$ dot -Tsvg diagram.dot -o diagram.svg
```

### Архитектура инструмента

```
main.py
├── M3: Классы Entity, Attribute, Relationship, Constraint
├── M2: MetamodelValidator (валидация правил)
├── M1: DomainModel (загрузка/сохранение JSON)
└── GraphVisualizer (генерация DOT)
```

---

## 8. Анализ применимости подхода

### Преимущества многоуровневого моделирования

1. **Абстракция и разделение ответственности**
   - M3 определяет язык моделирования
   - M2 задает правила валидации
   - M1 описывает конкретную предметную область
   - M0 реализует в конкретной СУБД

2. **Переиспользование метамоделей**
   - M2 и M3 можно применять к разным предметным областям
   - Не нужно переизобретать правила для каждой модели

3. **Автоматическая валидация**
   - Правила M2 позволяют автоматически проверять корректность M1
   - Обнаружение ошибок на ранних этапах

4. **Генерация кода**
   - Из M1 можно автоматически генерировать M0 (миграции, модели)
   - Снижение рутинной работы

### Ограничения и сложности

1. **Кривая обучения**
   - Требуется понимание концепций метамоделирования
   - Может быть избыточно для простых проектов

2. **Производительность разработки**
   - Многоуровневая абстракция может замедлить начальную разработку
   - Требуется больше времени на проектирование

3. **Гибкость**
   - Строгие правила M2 могут ограничивать нестандартные решения
   - Сложно моделировать edge cases

### Применимость в реальных проектах

**✅ Подходит для:**
- Крупных корпоративных систем
- Проектов с частыми изменениями требований
- Командной разработки (единый язык моделирования)
- Систем с высокой сложностью бизнес-логики
- Долгосрочных проектов

**❌ Не подходит для:**
- Простых CRUD-приложений
- Прототипов и MVP
- Проектов с жесткими сроками
- Систем с неопределенными требованиями

### Выводы

Многоуровневое моделирование (M3-M0) — мощный подход для сложных предметных областей, требующих:
- Строгой структуризации данных
- Валидации на уровне метамодели
- Автоматической генерации кода
- Долгосрочной поддержки

Для проекта **Library Stroll** подход оправдан из-за:
- Сложности связей (полиморфные, рекурсивные)
- Необходимости валидации целостности данных
- Возможности автоматической генерации миграций и моделей
- Потенциала для расширения функциональности

---

## Приложения

### A. Полная диаграмма ER (текстовое представление)

[См. раздел 5]

### B. JSON-модель Library Stroll

[См. файл `case-tool/models/library_stroll.json`]

### C. Код CASE-инструмента

[См. файл `case-tool/main.py`]

### D. Примеры валидации

```bash
$ python main.py validate models/library_stroll.json
Загрузка модели из models/library_stroll.json...
✓ Модель 'Library Stroll Domain Model' загружена (10 сущностей)

Валидация модели...
✓ Модель валидна!
```

---

**Автор:** [Ваше имя]  
**Дата:** [Дата]  
**Предметная область:** Library Stroll - Платформа для художников

