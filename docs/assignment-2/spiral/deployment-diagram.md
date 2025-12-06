# Диаграмма развертывания - Spiral модель

## Описание

Диаграмма показывает физическую архитектуру развертывания системы Library Stroll в спиральной модели. Развертывание происходит поэтапно по мере завершения итераций.

## Диаграмма (Mermaid)

```mermaid
graph TB
    subgraph Internet["Internet"]
        Users[Пользователи]
        BetaUsers[Бета-тестеры]
    end
    
    subgraph Production["Production Environment"]
        ProdLB[Production Load Balancer]
        ProdWeb1[Web Server 1]
        ProdWeb2[Web Server 2]
        ProdApp[Application Server]
        ProdDB[(Production DB)]
    end
    
    subgraph Staging["Staging Environment"]
        StagingServer[Staging Server<br/>Тестирование итераций]
        StagingDB[(Staging DB)]
    end
    
    subgraph Development["Development Environment"]
        DevServer1[Dev Server 1<br/>Итерация 1-2]
        DevServer2[Dev Server 2<br/>Итерация 3-4]
        DevDB[(Dev DB)]
    end
    
    subgraph Shared["Shared Services"]
        Redis[(Redis Cache)]
        FileStorage[(File Storage)]
        QueueWorkers[Queue Workers]
    end
    
    subgraph External["External Services"]
        Pusher[Pusher Cloud]
        Monitoring[Monitoring Tools]
    end
    
    Users --> ProdLB
    BetaUsers --> StagingServer
    
    ProdLB --> ProdWeb1
    ProdLB --> ProdWeb2
    ProdWeb1 --> ProdApp
    ProdWeb2 --> ProdApp
    
    ProdApp --> ProdDB
    StagingServer --> StagingDB
    DevServer1 --> DevDB
    DevServer2 --> DevDB
    
    ProdApp --> Redis
    StagingServer --> Redis
    DevServer1 --> Redis
    DevServer2 --> Redis
    
    ProdApp --> FileStorage
    StagingServer --> FileStorage
    
    ProdApp --> QueueWorkers
    StagingServer --> QueueWorkers
    
    ProdApp --> Pusher
    StagingServer --> Pusher
    
    ProdApp --> Monitoring
    StagingServer --> Monitoring
    
    style Internet fill:#e1f5ff
    style Production fill:#fff3e0
    style Staging fill:#f3e5f5
    style Development fill:#e8f5e9
    style Shared fill:#fce4ec
    style External fill:#f1f8e9
```

## Особенности развертывания в Spiral

- **Поэтапное развертывание** — каждая итерация развертывается отдельно
- **Множественные окружения** — Development, Staging, Production
- **Раннее тестирование** — Staging для тестирования перед Production
- **Постепенный рост** — инфраструктура расширяется по мере необходимости

