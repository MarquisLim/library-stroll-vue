# Component диаграмма - Архитектура реализации

## Описание

Диаграмма компонентов показывает архитектуру системы Library Stroll на уровне компонентов.

## Диаграмма (Mermaid)

```mermaid
graph TB
    subgraph Presentation["Presentation Layer"]
        WebUI[Web UI<br/>Vue.js]
        MobileApp[Mobile App<br/>React Native]
    end
    
    subgraph API["API Layer"]
        RESTAPI[REST API<br/>Laravel Controllers]
        WebSocketAPI[WebSocket API<br/>Real-time Events]
    end
    
    subgraph Business["Business Logic Layer"]
        ArtworkModule[Artwork Module<br/>ArtworkService]
        CommentModule[Comment Module<br/>CommentService]
        UserModule[User Module<br/>UserService]
        NotificationModule[Notification Module<br/>NotificationService]
        ModerationModule[Moderation Module<br/>ModerationService]
    end
    
    subgraph Data["Data Access Layer"]
        ArtworkRepo[Artwork Repository]
        CommentRepo[Comment Repository]
        UserRepo[User Repository]
        TagRepo[Tag Repository]
    end
    
    subgraph Infrastructure["Infrastructure Layer"]
        FileStorage[File Storage<br/>Local/S3]
        Cache[Cache<br/>Redis]
        Queue[Queue<br/>RabbitMQ]
        Search[Search Engine<br/>Elasticsearch]
    end
    
    subgraph External["External Services"]
        Pusher[Pusher<br/>Real-time]
        EmailService[Email Service<br/>SMTP]
    end
    
    WebUI --> RESTAPI
    MobileApp --> RESTAPI
    WebUI --> WebSocketAPI
    MobileApp --> WebSocketAPI
    
    RESTAPI --> ArtworkModule
    RESTAPI --> CommentModule
    RESTAPI --> UserModule
    RESTAPI --> NotificationModule
    
    WebSocketAPI --> NotificationModule
    
    ArtworkModule --> ArtworkRepo
    ArtworkModule --> TagRepo
    CommentModule --> CommentRepo
    UserModule --> UserRepo
    NotificationModule --> UserRepo
    
    ArtworkModule --> FileStorage
    ArtworkModule --> Cache
    ArtworkModule --> Queue
    ArtworkModule --> Search
    
    CommentModule --> ModerationModule
    CommentModule --> Cache
    
    NotificationModule --> Pusher
    NotificationModule --> EmailService
    NotificationModule --> Queue
    
    ArtworkRepo --> Infrastructure
    CommentRepo --> Infrastructure
    UserRepo --> Infrastructure
    TagRepo --> Infrastructure
    
    style Presentation fill:#e1f5ff
    style API fill:#fff3e0
    style Business fill:#f3e5f5
    style Data fill:#e8f5e9
    style Infrastructure fill:#fce4ec
    style External fill:#f1f8e9
```

## Описание компонентов

### Presentation Layer
- **Web UI** - веб-интерфейс на Vue.js
- **Mobile App** - мобильное приложение на React Native

### API Layer
- **REST API** - RESTful API для синхронных запросов
- **WebSocket API** - WebSocket для real-time событий

### Business Logic Layer
- **Artwork Module** - бизнес-логика работы с работами
- **Comment Module** - бизнес-логика комментариев
- **User Module** - бизнес-логика пользователей
- **Notification Module** - бизнес-логика уведомлений
- **Moderation Module** - бизнес-логика модерации

### Data Access Layer
- **Repositories** - абстракция доступа к данным (GRASP: Creator)

### Infrastructure Layer
- **File Storage** - хранение файлов (локальное или S3)
- **Cache** - кэширование данных (Redis)
- **Queue** - асинхронная обработка задач
- **Search Engine** - полнотекстовый поиск

### External Services
- **Pusher** - сервис real-time уведомлений
- **Email Service** - отправка email

## Принципы проектирования

- **Separation of Concerns** - разделение ответственности по слоям
- **Dependency Inversion** - зависимости направлены к абстракциям
- **Interface Segregation** - модули зависят только от нужных интерфейсов

