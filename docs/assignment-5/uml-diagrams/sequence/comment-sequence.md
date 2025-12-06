# Sequence диаграмма - Добавление комментария

## Описание

Диаграмма последовательности показывает взаимодействие объектов при добавлении комментария к работе.

## Диаграмма (Mermaid)

```mermaid
sequenceDiagram
    participant User as Пользователь
    participant Controller as CommentController
    participant Validator as CommentValidator
    participant Service as CommentService
    participant Repository as CommentRepository
    participant ArtworkRepo as ArtworkRepository
    participant Notification as NotificationService
    participant Moderation as ModerationService
    participant DB as База данных
    
    User->>Controller: POST /comments (text, artworkId, parentId?)
    Controller->>Validator: validate(text, artworkId)
    Validator->>ArtworkRepo: artworkExists(artworkId)
    ArtworkRepo->>DB: SELECT artwork
    ArtworkRepo-->>Validator: artwork exists
    Validator-->>Controller: validation result
    
    alt Валидация не прошла
        Controller-->>User: 400 Bad Request
    else Валидация прошла
        Controller->>Service: createComment(text, artworkId, parentId)
        
        Service->>Moderation: requiresModeration(text, userId)
        Moderation->>Moderation: check spam/keywords
        Moderation-->>Service: moderation required: true/false
        
        alt Требуется модерация
            Service->>Repository: createPending(text, artworkId, parentId)
            Repository->>DB: INSERT INTO comments (status='pending')
            Repository-->>Service: commentId
            Service->>Moderation: notifyModerator(commentId)
            Moderation-->>Service: notification sent
            Service-->>Controller: comment pending moderation
            Controller-->>User: 202 Accepted (pending)
        else Модерация не требуется
            Service->>Repository: create(text, artworkId, parentId)
            Repository->>DB: INSERT INTO comments
            Repository-->>Service: commentId
            
            Service->>Notification: notifyArtworkAuthor(artworkId, commentId)
            Notification->>DB: SELECT artwork author
            Notification->>Notification: send notification
            Notification-->>Service: notification sent
            
            alt Есть родительский комментарий
                Service->>Notification: notifyParentAuthor(parentId, commentId)
                Notification->>DB: SELECT parent comment author
                Notification->>Notification: send notification
                Notification-->>Service: notification sent
            end
            
            Service-->>Controller: comment created
            Controller-->>User: 201 Created (comment)
        end
    end
```

## Описание взаимодействия

### Участники

1. **Пользователь** - пользователь, добавляющий комментарий
2. **CommentController** - контроллер для обработки запросов
3. **CommentValidator** - валидатор комментариев (GRASP: Information Expert)
4. **CommentService** - сервис бизнес-логики (GRASP: Controller)
5. **CommentRepository** - репозиторий для работы с комментариями (GRASP: Creator)
6. **ArtworkRepository** - репозиторий для проверки существования работы
7. **ModerationService** - сервис модерации (GRASP: Low Coupling)
8. **NotificationService** - сервис уведомлений (GRASP: High Cohesion)
9. **База данных** - хранилище данных

### Основной поток

1. **Валидация** - проверка текста и существования работы
2. **Проверка модерации** - определение необходимости модерации
3. **Сохранение комментария** - создание записи в БД
4. **Уведомления** - уведомление автора работы и автора родительского комментария (если есть)

### Альтернативные потоки

- **Требуется модерация** → сохранение как ожидающий одобрения
- **Ошибка валидации** → возврат ошибки клиенту

