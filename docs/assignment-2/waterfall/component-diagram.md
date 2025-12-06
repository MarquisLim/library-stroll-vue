# Диаграмма компонентов - Waterfall модель

## Описание

Диаграмма показывает архитектуру компонентов системы Library Stroll в каскадной модели. Все компоненты разрабатываются последовательно и интегрируются на этапе реализации.

## Диаграмма (Mermaid)

```mermaid
graph TB
    subgraph Frontend["Frontend Layer"]
        VueApp[Vue.js Application]
        Inertia[Inertia.js]
        Components[Vue Components]
        Router[Vue Router]
        Store[Pinia Store]
    end
    
    subgraph Backend["Backend Layer"]
        LaravelApp[Laravel Application]
        Controllers[Controllers]
        Models[Eloquent Models]
        Middleware[Middleware]
        Services[Services]
    end
    
    subgraph API["API Layer"]
        RESTAPI[REST API]
        Sanctum[Sanctum Auth]
        Validation[Request Validation]
    end
    
    subgraph Business["Business Logic"]
        ArtworkService[Artwork Service]
        CommentService[Comment Service]
        MessageService[Message Service]
        ModerationService[Moderation Service]
    end
    
    subgraph Data["Data Layer"]
        Database[(MySQL Database)]
        MediaStorage[(Media Storage)]
        Cache[(Redis Cache)]
    end
    
    subgraph External["External Services"]
        Pusher[Pusher Service]
        Queue[Queue Worker]
    end
    
    VueApp --> Inertia
    Inertia --> Components
    Components --> Router
    Components --> Store
    
    Inertia --> RESTAPI
    RESTAPI --> LaravelApp
    LaravelApp --> Controllers
    Controllers --> Models
    Controllers --> Middleware
    Controllers --> Services
    
    Controllers --> ArtworkService
    Controllers --> CommentService
    Controllers --> MessageService
    Controllers --> ModerationService
    
    Models --> Database
    ArtworkService --> MediaStorage
    LaravelApp --> Cache
    
    MessageService --> Pusher
    LaravelApp --> Queue
    
    style Frontend fill:#e1f5ff
    style Backend fill:#fff3e0
    style API fill:#f3e5f5
    style Business fill:#e8f5e9
    style Data fill:#fce4ec
    style External fill:#f1f8e9
```

## Описание компонентов

### Frontend Layer
- **Vue.js Application** — основное приложение
- **Inertia.js** — мост между Frontend и Backend
- **Vue Components** — переиспользуемые компоненты
- **Vue Router** — маршрутизация
- **Pinia Store** — управление состоянием

### Backend Layer
- **Laravel Application** — ядро приложения
- **Controllers** — обработка HTTP запросов
- **Eloquent Models** — ORM модели
- **Middleware** — промежуточное ПО (auth, CORS)
- **Services** — бизнес-логика

### API Layer
- **REST API** — RESTful endpoints
- **Sanctum Auth** — аутентификация
- **Request Validation** — валидация запросов

### Business Logic
- **Artwork Service** — управление работами
- **Comment Service** — управление комментариями
- **Message Service** — управление сообщениями
- **Moderation Service** — модерация контента

### Data Layer
- **MySQL Database** — основная БД
- **Media Storage** — файловое хранилище
- **Redis Cache** — кэширование

### External Services
- **Pusher Service** — real-time коммуникация
- **Queue Worker** — фоновые задачи

## Особенности архитектуры в Waterfall

- **Монолитная структура** — все компоненты разрабатываются вместе
- **Строгая иерархия** — четкое разделение слоев
- **Централизованная логика** — бизнес-логика в Services
- **Полная интеграция** — все компоненты интегрируются одновременно

