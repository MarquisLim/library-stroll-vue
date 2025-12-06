# Sequence диаграмма - Загрузка работы

## Описание

Диаграмма последовательности показывает взаимодействие объектов при загрузке художественной работы.

## Диаграмма (Mermaid)

```mermaid
sequenceDiagram
    participant Artist as Художник
    participant Controller as ArtworkController
    participant Validator as ArtworkValidator
    participant Service as ArtworkService
    participant Storage as FileStorageService
    participant Processor as FileProcessor
    participant Repository as ArtworkRepository
    participant TagService as TagService
    participant Notification as NotificationService
    participant DB as База данных
    
    Artist->>Controller: POST /artworks (file, metadata)
    Controller->>Validator: validate(file, metadata)
    Validator-->>Controller: validation result
    
    alt Валидация не прошла
        Controller-->>Artist: 400 Bad Request (errors)
    else Валидация прошла
        Controller->>Service: createArtwork(file, metadata)
        Service->>Storage: uploadFile(file)
        Storage->>Storage: save to disk/storage
        Storage-->>Service: filePath
        
        Service->>Processor: processFile(filePath)
        Processor->>Processor: create preview
        Processor->>Processor: optimize image
        Processor-->>Service: processedFilePath
        
        Service->>Repository: create(metadata, filePath)
        Repository->>DB: INSERT INTO artworks
        DB-->>Repository: artworkId
        
        Service->>TagService: attachTags(artworkId, tags)
        TagService->>DB: INSERT INTO artwork_tag
        TagService-->>Service: tags attached
        
        Service->>Notification: notifyFollowers(userId, artworkId)
        Notification->>DB: SELECT followers
        Notification->>Notification: send notifications
        Notification-->>Service: notifications sent
        
        Service-->>Controller: artwork created
        Controller-->>Artist: 201 Created (artwork)
    end
```

## Описание взаимодействия

### Участники

1. **Художник** - пользователь, загружающий работу
2. **ArtworkController** - контроллер, обрабатывающий HTTP запрос
3. **ArtworkValidator** - валидатор данных (GRASP: Information Expert)
4. **ArtworkService** - сервис бизнес-логики (GRASP: Controller)
5. **FileStorageService** - сервис хранения файлов
6. **FileProcessor** - обработчик файлов (создание превью, оптимизация)
7. **ArtworkRepository** - репозиторий для работы с БД (GRASP: Creator)
8. **TagService** - сервис для работы с тегами
9. **NotificationService** - сервис уведомлений
10. **База данных** - хранилище данных

### Основной поток

1. **Валидация** - проверка файла и метаданных
2. **Загрузка файла** - сохранение файла в хранилище
3. **Обработка файла** - создание превью и оптимизация
4. **Сохранение в БД** - создание записи о работе
5. **Связывание тегов** - добавление тегов к работе
6. **Уведомления** - уведомление подписчиков

### Альтернативные потоки

- **Ошибка валидации** → возврат ошибки клиенту
- **Ошибка загрузки** → откат транзакции

