# GRASP шаблоны проектирования

## Описание

GRASP (General Responsibility Assignment Software Patterns) - это набор шаблонов для распределения ответственности между классами в объектно-ориентированном проектировании.

## Примененные шаблоны

### 1. Information Expert (Эксперт по информации)

**Определение:** Назначать ответственность классу, который имеет информацию, необходимую для выполнения задачи.

**Применение в проекте:**

#### ArtworkValidator
```python
class ArtworkValidator:
    def validate_file(self, file):
        # Знает правила валидации файлов
        if file.size > MAX_FILE_SIZE:
            return ValidationError("File too large")
        if file.type not in ALLOWED_TYPES:
            return ValidationError("Invalid file type")
        return ValidationResult(valid=True)
    
    def validate_metadata(self, metadata):
        # Знает структуру метаданных
        if not metadata.get('title'):
            return ValidationError("Title is required")
        if len(metadata.get('title', '')) > MAX_TITLE_LENGTH:
            return ValidationError("Title too long")
        return ValidationResult(valid=True)
```

**Обоснование:** Валидатор имеет знания о правилах валидации и структуре данных, поэтому он является экспертом по валидации.

### 2. Creator (Создатель)

**Определение:** Назначать ответственность за создание экземпляра класса B классу A, если:
- A содержит или агрегирует B
- A записывает B
- A использует B
- A имеет данные для инициализации B

**Применение в проекте:**

#### ArtworkRepository
```python
class ArtworkRepository:
    def create(self, data):
        # Создает объект Artwork, так как имеет все данные
        artwork = Artwork(
            user_id=data['user_id'],
            title=data['title'],
            description=data.get('description'),
            file_path=data['file_path']
        )
        self.db.save(artwork)
        return artwork
```

**Обоснование:** Репозиторий имеет все данные для создания объекта Artwork и управляет его жизненным циклом в БД.

### 3. Controller (Контроллер)

**Определение:** Назначать ответственность за обработку системных событий классу, представляющему систему или подсистему.

**Применение в проекте:**

#### ArtworkController
```python
class ArtworkController:
    def __init__(self):
        self.artwork_service = ArtworkService()
        self.validator = ArtworkValidator()
    
    def create(self, request):
        # Координирует работу сервисов
        validation = self.validator.validate(
            request.file, 
            request.metadata
        )
        if not validation.is_valid:
            return Response(400, errors=validation.errors)
        
        artwork = self.artwork_service.create_artwork(
            request.file,
            request.metadata
        )
        return Response(201, data=artwork)
```

**Обоснование:** Контроллер координирует работу валидатора и сервиса, обрабатывая HTTP запросы.

### 4. Low Coupling (Низкая связанность)

**Определение:** Минимизировать зависимости между классами.

**Применение в проекте:**

#### ModerationService
```python
class ModerationService:
    def __init__(self):
        self.spam_detector = SpamDetector()
    
    def requires_moderation(self, text, user_id):
        # Изолированная логика модерации
        if self.spam_detector.is_spam(text):
            return True
        if self._contains_profanity(text):
            return True
        return False
```

**Обоснование:** ModerationService изолирован от основной бизнес-логики, что позволяет изменять правила модерации без влияния на другие компоненты.

### 5. High Cohesion (Высокая связность)

**Определение:** Класс должен иметь высокую связность - все его методы должны быть тесно связаны с одной ответственностью.

**Применение в проекте:**

#### NotificationService
```python
class NotificationService:
    def notify_followers(self, user_id, artwork_id):
        # Все методы связаны с уведомлениями
        followers = self._get_followers(user_id)
        for follower in followers:
            self._send_notification(follower.id, artwork_id)
    
    def notify_user(self, user_id, message):
        self._send_notification(user_id, message)
    
    def notify_artwork_author(self, artwork_id, comment_id):
        author = self._get_artwork_author(artwork_id)
        self._send_notification(author.id, comment_id)
```

**Обоснование:** Все методы NotificationService связаны с отправкой уведомлений, что обеспечивает высокую связность.

### 6. Polymorphism (Полиморфизм)

**Определение:** Использовать полиморфизм для обработки альтернативных вариантов поведения.

**Применение в проекте:**

#### FileProcessor
```python
class FileProcessor:
    def process(self, file_path, file_type):
        # Полиморфная обработка разных типов файлов
        if file_type == 'image':
            return self._process_image(file_path)
        elif file_type == 'video':
            return self._process_video(file_path)
        elif file_type == 'gif':
            return self._process_gif(file_path)
    
    def _process_image(self, path):
        # Специфичная обработка изображений
        pass
    
    def _process_video(self, path):
        # Специфичная обработка видео
        pass
```

**Обоснование:** Разные типы файлов обрабатываются полиморфно через единый интерфейс.

## Сводная таблица примененных шаблонов

| Шаблон | Класс | Ответственность | Обоснование |
|--------|-------|----------------|-------------|
| Information Expert | ArtworkValidator | Валидация данных | Имеет знания о правилах валидации |
| Creator | ArtworkRepository | Создание Artwork | Имеет данные для создания объекта |
| Controller | ArtworkController | Координация запросов | Обрабатывает системные события |
| Low Coupling | ModerationService | Модерация | Изолирован от основной логики |
| High Cohesion | NotificationService | Уведомления | Все методы связаны с уведомлениями |
| Polymorphism | FileProcessor | Обработка файлов | Разные типы обрабатываются полиморфно |

## Преимущества применения GRASP

1. **Четкое распределение ответственности** - каждый класс знает свою роль
2. **Низкая связанность** - изменения в одном классе не влияют на другие
3. **Высокая связность** - классы фокусируются на одной задаче
4. **Легкость тестирования** - изолированные компоненты легко тестировать
5. **Расширяемость** - легко добавлять новую функциональность

