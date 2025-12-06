# Class диаграмма - Логическая структура системы

## Описание

Диаграмма классов показывает логическую структуру системы Library Stroll с применением GRASP шаблонов.

## Диаграмма (Mermaid)

```mermaid
classDiagram
    class ArtworkController {
        -artworkService: ArtworkService
        -validator: ArtworkValidator
        +create(request): Response
        +update(id, request): Response
        +delete(id): Response
        +show(id): Response
    }
    
    class ArtworkService {
        -repository: ArtworkRepository
        -fileStorage: FileStorageService
        -fileProcessor: FileProcessor
        -tagService: TagService
        -notificationService: NotificationService
        +createArtwork(file, metadata): Artwork
        +updateArtwork(id, metadata): Artwork
        +deleteArtwork(id): void
        +getArtwork(id): Artwork
    }
    
    class ArtworkValidator {
        +validateFile(file): ValidationResult
        +validateMetadata(metadata): ValidationResult
        +validate(file, metadata): ValidationResult
    }
    
    class ArtworkRepository {
        -db: Database
        +create(data): Artwork
        +findById(id): Artwork
        +update(id, data): Artwork
        +delete(id): void
        +findByUser(userId): List~Artwork~
    }
    
    class FileStorageService {
        -storagePath: String
        +uploadFile(file): String
        +deleteFile(path): void
        +getFileUrl(path): String
    }
    
    class FileProcessor {
        +createPreview(filePath): String
        +optimizeImage(filePath): String
        +processVideo(filePath): String
    }
    
    class TagService {
        -tagRepository: TagRepository
        +attachTags(artworkId, tags): void
        +detachTags(artworkId, tags): void
        +getTags(artworkId): List~Tag~
    }
    
    class TagRepository {
        -db: Database
        +findOrCreate(name): Tag
        +attachToArtwork(artworkId, tagId): void
    }
    
    class NotificationService {
        -userRepository: UserRepository
        +notifyFollowers(userId, artworkId): void
        +notifyUser(userId, message): void
    }
    
    class CommentController {
        -commentService: CommentService
        -validator: CommentValidator
        +create(request): Response
        +reply(parentId, request): Response
    }
    
    class CommentService {
        -repository: CommentRepository
        -moderationService: ModerationService
        -notificationService: NotificationService
        +createComment(text, artworkId, parentId): Comment
        +getComments(artworkId): List~Comment~
    }
    
    class CommentValidator {
        +validateText(text): ValidationResult
        +validateArtworkExists(artworkId): ValidationResult
    }
    
    class CommentRepository {
        -db: Database
        +create(data): Comment
        +findById(id): Comment
        +findByArtwork(artworkId): List~Comment~
    }
    
    class ModerationService {
        -spamDetector: SpamDetector
        +requiresModeration(text, userId): boolean
        +moderateComment(commentId): void
    }
    
    class Artwork {
        +id: int
        +userId: int
        +title: String
        +description: String
        +filePath: String
        +isPublished: boolean
    }
    
    class Comment {
        +id: int
        +userId: int
        +artworkId: int
        +text: String
        +parentId: int
        +status: String
    }
    
    class Tag {
        +id: int
        +name: String
    }
    
    ArtworkController --> ArtworkService : uses
    ArtworkController --> ArtworkValidator : uses
    ArtworkService --> ArtworkRepository : uses (Creator)
    ArtworkService --> FileStorageService : uses
    ArtworkService --> FileProcessor : uses
    ArtworkService --> TagService : uses
    ArtworkService --> NotificationService : uses
    ArtworkValidator --> ArtworkRepository : uses (Information Expert)
    ArtworkRepository --> Artwork : creates
    TagService --> TagRepository : uses
    TagRepository --> Tag : creates
    
    CommentController --> CommentService : uses
    CommentController --> CommentValidator : uses
    CommentService --> CommentRepository : uses (Creator)
    CommentService --> ModerationService : uses (Low Coupling)
    CommentService --> NotificationService : uses
    CommentValidator --> ArtworkRepository : uses (Information Expert)
    CommentRepository --> Comment : creates
```

## GRASP шаблоны в диаграмме

### 1. Controller (ArtworkController, CommentController)
- **Ответственность:** Обработка HTTP запросов и координация работы сервисов
- **Применение:** Контроллеры обрабатывают запросы и делегируют работу сервисам

### 2. Creator (ArtworkRepository, CommentRepository)
- **Ответственность:** Создание объектов Artwork и Comment
- **Применение:** Репозитории создают объекты, так как имеют всю информацию для их создания

### 3. Information Expert (ArtworkValidator, CommentValidator)
- **Ответственность:** Валидация данных на основе знаний о структуре данных
- **Применение:** Валидаторы знают правила валидации и структуру данных

### 4. Low Coupling (ModerationService)
- **Ответственность:** Минимизация зависимостей между классами
- **Применение:** ModerationService изолирован от основной бизнес-логики

### 5. High Cohesion (NotificationService, TagService)
- **Ответственность:** Высокая связность внутри класса
- **Применение:** Каждый сервис отвечает за одну область функциональности

