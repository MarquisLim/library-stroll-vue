# M0: Модель данных (реализация)

## Описание

Модель данных (M0) представляет физическую реализацию концептуальной модели M1 в конкретной системе управления базами данных (MySQL/SQLite) через Laravel Migrations и Eloquent Models.

## Структура базы данных

### Соответствие уровней

| M1 (Концептуальная) | M0 (Реализация) |
|---------------------|-----------------|
| Entity: Artwork | Таблица: `artworks` |
| Attribute: user_id | Колонка: `user_id` |
| Relationship: ManyToOne → User | Foreign Key: `user_id → users.id` |
| Constraint: Cascade | `onDelete('cascade')` |
| Relationship: ManyToMany → Tag | Промежуточная таблица: `artwork_tag` |
| Relationship: Polymorphic → Comment | Колонки: `commentable_type`, `commentable_id` |

## Примеры миграций Laravel

### Таблица artworks

```php
Schema::create('artworks', function (Blueprint $table) {
    $table->id();  // M1: Attribute id [PK, AutoIncrement]
    $table->foreignId('user_id')  // M1: Relationship ManyToOne → User
          ->constrained()
          ->onDelete('cascade');  // M2: Поведенческая зависимость
    
    $table->string('title')->nullable();  // M1: Attribute title [Max:255]
    $table->text('description')->nullable();  // M1: Attribute description
    $table->enum('type', ['image', 'video', 'gif'])->default('image');  // M1: Attribute type [Enum]
    $table->boolean('is_published')->default(false);  // M1: Attribute is_published
    $table->boolean('is_blocked')->default(false);  // M1: Attribute is_blocked
    $table->integer('views_count')->default(0);  // M1: Attribute views_count
    $table->timestamp('published_at')->nullable();  // M1: Attribute published_at
    
    $table->timestamps();
    
    // Индексы для оптимизации
    $table->index('user_id');
    $table->index('is_published');
    $table->index('is_blocked');
});
```

### Промежуточная таблица для ManyToMany

```php
// M1: Relationship ManyToMany Artwork ↔ Tag
Schema::create('artwork_tag', function (Blueprint $table) {
    $table->id();
    $table->foreignId('artwork_id')->constrained()->onDelete('cascade');
    $table->foreignId('tag_id')->constrained()->onDelete('cascade');
    
    $table->unique(['artwork_id', 'tag_id']);  // Уникальность пары
});
```

### Полиморфная связь

```php
// M1: Relationship Polymorphic Comment → Artwork
Schema::create('comments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->text('text');  // M1: Attribute text [Required]
    
    // Полиморфная связь
    $table->unsignedBigInteger('commentable_id');  // M1: Relationship Polymorphic
    $table->string('commentable_type');  // M1: morph_type
    
    // Рекурсивная связь (self-reference)
    $table->foreignId('parent_id')->nullable()  // M1: Relationship OneToMany → Comment
          ->constrained('comments')
          ->onDelete('cascade');
    
    $table->boolean('is_blocked')->default(false);
    $table->timestamps();
    
    // Индексы для полиморфной связи
    $table->index(['commentable_type', 'commentable_id']);
});
```

## Eloquent модели

### Модель Artwork

```php
class Artwork extends Model
{
    protected $fillable = [
        'user_id', 'title', 'description', 'type',
        'is_published', 'is_blocked', 'views_count', 'published_at'
    ];
    
    // M1: Relationship ManyToOne → User
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    // M1: Relationship ManyToMany → Tag
    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }
    
    // M1: Relationship ManyToMany → Collection
    public function collections()
    {
        return $this->belongsToMany(Collection::class);
    }
    
    // M1: Relationship OneToMany → Comment (Polymorphic)
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
    
    // M1: Relationship OneToMany → Like
    public function likes()
    {
        return $this->hasMany(Like::class);
    }
    
    // M2: Поведенческая зависимость - глобальный scope для блокировки
    protected static function booted()
    {
        static::addGlobalScope('not_blocked', function (Builder $builder) {
            $builder->where('is_blocked', false);
        });
    }
}
```

### Модель Comment с полиморфной связью

```php
class Comment extends Model
{
    protected $fillable = [
        'user_id', 'text', 'parent_id',
        'commentable_id', 'commentable_type', 'is_blocked'
    ];
    
    // M1: Relationship ManyToOne → User
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    // M1: Relationship Polymorphic → Artwork (и другие)
    public function commentable()
    {
        return $this->morphTo();
    }
    
    // M1: Relationship OneToMany → Comment (self-reference)
    public function parent()
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }
    
    public function replies()
    {
        return $this->hasMany(Comment::class, 'parent_id');
    }
}
```

## Реализация поведенческих зависимостей M2

### Каскадное удаление

Реализовано через `onDelete('cascade')` в миграциях:

```php
$table->foreignId('user_id')
      ->constrained()
      ->onDelete('cascade');  // M2: При удалении User → удаляются Artworks
```

### Блокировка контента

Реализовано через глобальные scope:

```php
// В модели Artwork
protected static function booted()
{
    static::addGlobalScope('not_blocked', function (Builder $builder) {
        $builder->where('is_blocked', false);
    });
}
```

### Автоматические действия

Реализовано через события модели:

```php
protected static function booted()
{
    static::creating(function ($artwork) {
        if ($artwork->is_published) {
            $artwork->published_at = now();  // M2: Автоматическая установка даты
        }
    });
}
```

## Соответствие M1 → M0

### Сущности

- `User` → таблица `users`
- `Artwork` → таблица `artworks`
- `Collection` → таблица `collections`
- `Tag` → таблица `tags`
- `Comment` → таблица `comments`
- `Like` → таблица `likes`
- `Conversation` → таблица `conversations`
- `Message` → таблица `messages`
- `Complaint` → таблица `complaints`
- `ComplaintType` → таблица `complaint_types`

### Связи

- `OneToMany` → Foreign Key в дочерней таблице
- `ManyToMany` → Промежуточная таблица с двумя Foreign Keys
- `Polymorphic` → Пара колонок `*_type` и `*_id`
- `Self-reference` → Foreign Key на ту же таблицу

### Ограничения

- `PK` → `$table->id()` или `$table->primary()`
- `FK` → `$table->foreignId()->constrained()`
- `Unique` → `$table->unique()`
- `Cascade` → `->onDelete('cascade')`
- `Index` → `$table->index()`

## Связь с другими уровнями

- **M0 реализует M1:** Все сущности и связи M1 преобразуются в таблицы и внешние ключи M0
- **M0 применяет M2:** Поведенческие зависимости M2 реализуются через каскадное удаление и триггеры
- **M0 использует M3:** Типы данных M3 преобразуются в типы колонок БД

