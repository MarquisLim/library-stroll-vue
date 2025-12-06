# UML Диаграмма классов

## Описание

Диаграмма классов, полученная трансформацией из DFD модели процесса "Управление работами".

## Диаграмма (Mermaid)

```mermaid
classDiagram
    class FileValidator {
        +validate(file: File): ValidationResult
    }
    
    class ImageProcessor {
        +process(image: Image): ProcessedImage
        +resize(image: Image, width: int, height: int): Image
        +createThumbnail(image: Image): Image
    }
    
    class StorageService {
        +save(file: File, path: String): String
        +get(filePath: String): File
        +delete(filePath: String): void
    }
    
    class ArtworkRepository {
        +create(data: ArtworkData): Artwork
        +findById(id: int): Artwork
        +update(id: int, data: ArtworkData): Artwork
        +delete(id: int): void
    }
    
    class Artwork {
        -id: int
        -title: String
        -description: String
        -filePath: String
        -userId: int
        +getFile(): File
        +updateMetadata(data: ArtworkData): void
    }
    
    class ArtworkService {
        -validator: FileValidator
        -processor: ImageProcessor
        -storage: StorageService
        -repository: ArtworkRepository
        +uploadArtwork(file: File, metadata: ArtworkData): Artwork
        +getArtwork(id: int): Artwork
        +updateArtwork(id: int, data: ArtworkData): Artwork
        +deleteArtwork(id: int): void
    }
    
    class Database {
        +save(entity: Entity): void
        +find(entityType: Type, id: int): Entity
        +update(entityType: Type, id: int, data: Data): void
        +delete(entityType: Type, id: int): void
    }
    
    class FileStorage {
        +save(file: File, path: String): String
        +get(path: String): File
        +delete(path: String): void
    }
    
    ArtworkService --> FileValidator : uses
    ArtworkService --> ImageProcessor : uses
    ArtworkService --> StorageService : uses
    ArtworkService --> ArtworkRepository : uses
    ArtworkRepository --> Database : uses
    StorageService --> FileStorage : uses
    ArtworkRepository --> Artwork : creates/returns
```

## Описание классов

### ArtworkService
Главный сервисный класс, координирующий все операции с работами. Соответствует процессу "2.0 Управление работами" из DFD.

### FileValidator
Класс для валидации файлов. Соответствует процессу "2.1 Валидация файла".

### ImageProcessor
Класс для обработки изображений. Соответствует процессу "2.2 Обработка изображения".

### StorageService
Сервис для работы с файловым хранилищем. Соответствует процессу "2.3 Сохранение в хранилище".

### ArtworkRepository
Репозиторий для работы с базой данных. Соответствует процессам "2.4 Создание записи", "2.5 Получение", "2.6 Обновление", "2.7 Удаление".

### Artwork
Модель данных работы. Соответствует хранилищу данных "База данных (artworks)".

### Database
Абстракция базы данных. Соответствует хранилищу данных "База данных".

### FileStorage
Абстракция файлового хранилища. Соответствует хранилищу данных "Файловое хранилище".

