# Диаграмма развертывания - Agile модель

## Описание

Диаграмма показывает физическую архитектуру развертывания системы Library Stroll в Agile модели. Развертывание происходит автоматически после каждого спринта через CI/CD.

## Диаграмма (Mermaid)

```mermaid
graph TB
    subgraph Internet["Internet"]
        Users[Пользователи]
    end
    
    subgraph CDN["CDN Layer"]
        CloudFlare[CloudFlare CDN]
    end
    
    subgraph Production["Production Environment"]
        ProdLB[Load Balancer]
        ProdWeb1[Web Server 1<br/>Auto-scaling]
        ProdWeb2[Web Server 2<br/>Auto-scaling]
        ProdApp1[App Server 1]
        ProdApp2[App Server 2]
        ProdDB[(Database<br/>Master-Slave)]
    end
    
    subgraph Staging["Staging Environment"]
        StagingServer[Staging Server<br/>Автоматическое тестирование]
        StagingDB[(Staging DB)]
    end
    
    subgraph CI_CD["CI/CD Pipeline"]
        GitRepo[Git Repository]
        GitHubActions[GitHub Actions]
        DockerRegistry[Docker Registry]
        K8s[Kubernetes<br/>Orchestration]
    end
    
    subgraph Monitoring["Monitoring & Logging"]
        Prometheus[Prometheus]
        Grafana[Grafana]
        ELK[ELK Stack]
    end
    
    subgraph Shared["Shared Services"]
        Redis[(Redis Cluster)]
        S3Storage[(S3 Storage)]
        QueueWorkers[Queue Workers<br/>Auto-scaling]
    end
    
    subgraph External["External Services"]
        Pusher[Pusher Cloud]
        Sentry[Sentry<br/>Error Tracking]
    end
    
    Users --> CloudFlare
    CloudFlare --> ProdLB
    
    ProdLB --> ProdWeb1
    ProdLB --> ProdWeb2
    ProdWeb1 --> ProdApp1
    ProdWeb2 --> ProdApp2
    
    ProdApp1 --> ProdDB
    ProdApp2 --> ProdDB
    
    GitRepo --> GitHubActions
    GitHubActions --> DockerRegistry
    DockerRegistry --> K8s
    K8s --> StagingServer
    K8s --> ProdApp1
    K8s --> ProdApp2
    
    StagingServer --> StagingDB
    
    ProdApp1 --> Redis
    ProdApp2 --> Redis
    StagingServer --> Redis
    
    ProdApp1 --> S3Storage
    ProdApp2 --> S3Storage
    
    ProdApp1 --> QueueWorkers
    ProdApp2 --> QueueWorkers
    
    ProdApp1 --> Pusher
    ProdApp2 --> Pusher
    
    ProdApp1 --> Prometheus
    ProdApp2 --> Prometheus
    Prometheus --> Grafana
    
    ProdApp1 --> ELK
    ProdApp2 --> ELK
    
    ProdApp1 --> Sentry
    ProdApp2 --> Sentry
    
    style Internet fill:#e1f5ff
    style CDN fill:#fff3e0
    style Production fill:#f3e5f5
    style Staging fill:#e8f5e9
    style CI_CD fill:#fce4ec
    style Monitoring fill:#f1f8e9
    style Shared fill:#fff9c4
    style External fill:#e0f2f1
```

## Особенности развертывания в Agile

- **Автоматическое развертывание** — через CI/CD pipeline
- **Контейнеризация** — Docker и Kubernetes для масштабирования
- **Auto-scaling** — автоматическое масштабирование под нагрузкой
- **Непрерывный мониторинг** — Prometheus, Grafana, ELK
- **Быстрые релизы** — развертывание после каждого спринта

